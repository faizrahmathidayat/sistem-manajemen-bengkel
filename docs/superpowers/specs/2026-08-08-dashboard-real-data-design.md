# Desain: Dashboard Data Real-Time (Cards, Charts, Tabbed Widgets)

**Tanggal:** 2026-08-08
**Status:** Draft untuk direview

## 1. Latar Belakang & Tujuan

Halaman `/dashboard` (`DashboardController`, `resources/views/dashboard/*.blade.php`) sudah punya
infrastruktur AJAX yang solid: satu endpoint (`GET /dashboard`) yang merespons HTML penuh atau JSON
(`wantsJson()`) untuk refresh partial saat filter cabang/sparepart berubah, dengan overlay loading dan
`session()`-persisted branch selection. Yang belum solid adalah **isinya** — sebagian besar angka masih
di-hardcode lewat method `dummy*()`.

Milestone ini mengganti seluruh data dummy dengan query real ke database, sambil **mempertahankan**
kontrak AJAX yang sudah ada (bentuk payload JSON, ID elemen DOM, alur `fetchDashboard()`/`applyPayload()`
di JS) supaya perubahan minim-risiko terhadap UX yang sudah berjalan.

## 2. Temuan Eksplorasi Penting

Sebelum menulis ulang apa pun, berikut peta **status real vs dummy saat ini** di `DashboardController`:

| Bagian | Method saat ini | Status |
|---|---|---|
| Card 1 — Stok Tersedia | `computeStockOverview()` | **Sudah real** — agregat `SUM(on_hand_qty)`/`SUM(reserved_qty)` dari `sparepart_branch_stocks` |
| Card 2 — Alert Stok Kritis | `computeCriticalStockCount()` | **Sudah real** — count `(on_hand-reserved) < minimum_stock` |
| Card 3 — Status PKB Hari Ini | `dummyPkbStatus()` | Dummy, hardcoded `open=8, shortage=2, completed=15` |
| Card 4 — Pendapatan & Piutang | `dummyReceivables()` | Dummy, hardcoded `revenue=42.500.000, unpaid=7.300.000` |
| Chart — Tren PKB vs Invoice Posted | `dummyChartTrend()` | Dummy, angka acak 6 pekan |
| Chart — Komposisi Piutang | `dummyChartReceivables()` | Dummy, 4 angka tetap |
| Tab 1 — Status PKB & Invoice | `dummyPkbInvoiceRows()` | Dummy, 4 baris statis. Filter search/status/tanggal di view sudah ada markup-nya tapi **`disabled`** |
| Tab 2 — Kartu Stok | `computeKartuStok()` + `recentMutationRows()` | **Sudah real** — termasuk dropdown sparepart dinamis & 5 mutasi terakhir dari `inventory_movements` |
| Tab 3 — Audit Log | `dummyAuditLogRows()` | Dummy, 3 baris statis. Field `impact` (LOW/MEDIUM/HIGH) **tidak ada kolomnya** di tabel `audit_logs` |

Jadi scope kerja nyata: **Card 3, Card 4, 2 chart, Tab 1, Tab 3** — Card 1/2/Tab 2 tidak disentuh
(hanya dirapikan jika refactor `buildPayload()` menyentuhnya secara struktural).

## 3. Keputusan Arsitektur

### 3.1 Scoping cabang per-widget mengikuti permission masing-masing modul

`scopedBranchIds()` saat ini hardcode `sparepart.view` — cocok untuk Card 1/2/Tab 2, tapi **salah**
kalau dipakai apa adanya untuk PKB (`pkb.view`), Invoice/piutang (`invoice.view`), atau Audit Log
(`audit_log.view`, dan ini **permission global, bukan branch-scoped** — lihat §3.5). Pola yang sudah
konsisten dipakai di seluruh controller lain (`WorkOrderController::index()`,
`InvoiceController::index()`, dst) adalah `$user->branchesWithPermission('<modul>.view')`.

Keputusan: setiap widget menghitung **scoped branch ids miliknya sendiri**, dari interseksi
`branch_ids` yang dipilih user (parameter request, sama seperti sekarang) dengan
`branchesWithPermission('<permission-modul-itu>')`. Ini mencegah kebocoran data (user melihat status
PKB cabang yang dia sebenarnya tidak punya `pkb.view`) dan mencegah widget lain diam-diam kosong hanya
karena user tidak punya `sparepart.view` di cabang itu.

```php
protected function scopedBranchIdsFor(User $user, array $selectedBranchIds, string $permissionCode): array
{
    $permittedBranchIds = $user->branchesWithPermission($permissionCode)->pluck('id')->all();

    return array_values(array_intersect($selectedBranchIds, $permittedBranchIds));
}
```

Method lama `scopedBranchIds()` (hardcode `sparepart.view`) tetap ada, dipakai khusus Card 1/2/Tab 2,
tapi dipanggil lewat `scopedBranchIdsFor($user, $selectedBranchIds, 'sparepart.view')` supaya satu jalur
kode saja.

### 3.2 Definisi bisnis "Pendapatan" & "Piutang" — reuse dari `ReceivableReportController`

`ReceivableReportController` (laporan piutang, sudah teruji lewat test suite yang ada) sudah
mendefinisikan konvensi ini persis:

- **Piutang aktif** (`unpaid`): invoice berstatus `POSTED` atau `PARTIALLY_PAID`,
  `SUM(grand_total - paid_amount)`.
- Invoice `PAID` otomatis tidak menyumbang piutang (`grand_total - paid_amount = 0`).

Untuk Card 4, "Pendapatan" didefinisikan sebagai **total nilai semua invoice yang sudah diposting**
(status `POSTED` + `PARTIALLY_PAID` + `PAID` — tiga-tiganya berasal dari aksi posting yang sama,
bedanya cuma progres pembayaran), `SUM(grand_total)`. "Piutang" memakai definisi `unpaid` di atas persis
(`POSTED` + `PARTIALLY_PAID`, `SUM(grand_total - paid_amount)`).

```php
protected function computeReceivablesSummary(array $scopedBranchIds): array
{
    if (empty($scopedBranchIds)) {
        return ['revenue' => 0.0, 'unpaid' => 0.0];
    }

    $revenue = Invoice::whereIn('branch_id', $scopedBranchIds)
        ->whereIn('status', [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID])
        ->sum('grand_total');

    $unpaid = Invoice::whereIn('branch_id', $scopedBranchIds)
        ->whereIn('status', [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID])
        ->selectRaw('COALESCE(SUM(grand_total - paid_amount), 0) as total')
        ->value('total');

    return ['revenue' => (float) $revenue, 'unpaid' => (float) $unpaid];
}
```

### 3.3 Status PKB Hari Ini — breakdown 4 status, bukan 3

Kartu saat ini cuma menampilkan Open/Shortage/Selesai. Spesifikasi meminta breakdown termasuk **Draft**.
`Cancelled` sengaja **tidak** dihitung (PKB batal bukan "aktivitas hari ini" yang relevan untuk KPI ini).

```php
protected function computePkbStatusToday(array $scopedBranchIds): array
{
    $defaults = ['draft' => 0, 'open' => 0, 'shortage' => 0, 'completed' => 0];
    if (empty($scopedBranchIds)) {
        return $defaults;
    }

    $counts = WorkOrder::whereIn('branch_id', $scopedBranchIds)
        ->whereDate('work_order_date', now()->toDateString())
        ->whereIn('status', [WorkOrderStatus::DRAFT, WorkOrderStatus::OPEN, WorkOrderStatus::SHORTAGE, WorkOrderStatus::COMPLETED])
        ->selectRaw('status, COUNT(*) as total')
        ->groupBy('status')
        ->pluck('total', 'status');

    return [
        'draft' => (int) ($counts[WorkOrderStatus::DRAFT] ?? 0),
        'open' => (int) ($counts[WorkOrderStatus::OPEN] ?? 0),
        'shortage' => (int) ($counts[WorkOrderStatus::SHORTAGE] ?? 0),
        'completed' => (int) ($counts[WorkOrderStatus::COMPLETED] ?? 0),
    ];
}
```

View: baris kecil di Card 3 diperluas jadi `Draft {d} · Open {o} · Shortage {s} · Selesai {c}`. Angka
besar kartu = total keempatnya.

### 3.4 Tren mingguan — PKB "dibuat" vs Invoice "diposting" pakai timestamp yang akurat

`Invoice` **tidak punya kolom `posted_at`** — hanya `status` saat ini. Memakai `invoice_date` sebagai
proxy "tanggal posting" tidak akurat (itu tanggal transaksi yang bisa diisi manual, bukan waktu sistem
memproses posting).

Keputusan: pakai `audit_logs` sebagai sumber kebenaran waktu posting — `InvoiceService::postInvoice()`
sudah mencatat `AuditEvent::INVOICE_POSTED` dengan `branch_id` invoice dan `created_at` = waktu posting
sesungguhnya (lihat `app/Services/InvoiceService.php:346` & `AuditLogger::log()`). Untuk garis "PKB
dibuat", pakai `work_orders.created_at` (waktu sistem, bukan `work_order_date` yang merupakan field
tanggal servis yang bisa berbeda dari waktu input).

- **Rentang:** 8 pekan terakhir (ujung atas rentang "6-8 pekan" di spesifikasi — lebih banyak titik data,
  masih relevan/recent).
- **Pengelompokan:** per pekan kalender ISO (`YEARWEEK(..., 3)` di MySQL — mode 3 = ISO 8601, Senin
  sebagai awal pekan, konsisten dengan `WEEK()` default locale-independent).
- **Label:** tanggal Senin awal pekan, format `d M` (mis. `04 Agu`) — lebih informatif daripada label
  generik "Pekan 1..6" karena sekarang datanya riil dan pekan yang ditampilkan berubah tiap hari.

```php
protected function computeWeeklyTrend(array $pkbScopedBranchIds, array $invoiceScopedBranchIds): array
{
    $weekStarts = collect(range(7, 0))->map(fn ($i) => now()->subWeeks($i)->startOfWeek());
    $labels = $weekStarts->map(fn ($d) => $d->translatedFormat('d M'))->all();

    $pkbCounts = empty($pkbScopedBranchIds) ? collect() : WorkOrder::whereIn('branch_id', $pkbScopedBranchIds)
        ->where('created_at', '>=', $weekStarts->first())
        ->selectRaw('YEARWEEK(created_at, 3) as yw, COUNT(*) as total')
        ->groupBy('yw')->pluck('total', 'yw');

    $invoiceCounts = empty($invoiceScopedBranchIds) ? collect() : AuditLog::whereIn('branch_id', $invoiceScopedBranchIds)
        ->where('event', AuditEvent::INVOICE_POSTED)
        ->where('created_at', '>=', $weekStarts->first())
        ->selectRaw('YEARWEEK(created_at, 3) as yw, COUNT(*) as total')
        ->groupBy('yw')->pluck('total', 'yw');

    $pkb = $weekStarts->map(fn ($d) => (int) ($pkbCounts[(int) $d->format('oW')] ?? 0))->all();
    $invoice = $weekStarts->map(fn ($d) => (int) ($invoiceCounts[(int) $d->format('oW')] ?? 0))->all();

    return ['labels' => $labels, 'pkb' => $pkb, 'invoice' => $invoice];
}
```

> Catatan implementasi: `YEARWEEK(x, 3)` MySQL dan `date('oW')` PHP sama-sama ISO-8601 (`o` = tahun ISO,
> `W` = nomor pekan ISO 2 digit) — kuncinya dipakai konsisten di kedua sisi agar pencocokan key tidak
> meleset di sekitar pergantian tahun.

### 3.5 Audit Log — permission global (bukan per-cabang), severity dari mapping baru

`administrasi.audit_log` di seeder adalah `is_branch_scoped: false` — konsisten dengan
`AuditLogController::index()` yang memanggil `$this->authorize('audit_log.view')` **tanpa** argumen
model kedua (lolos lewat `Gate::before` yang mengecek `hasPermissionTo()`, bukan
`hasPermissionToInBranch()`). Jadi Tab Audit Log di dashboard **berbeda pola** dari widget lain:

- **Visibilitas tab**: `auth()->user()->hasPermissionTo('audit_log.view')` — satu pengecekan global,
  bukan per-cabang. Tab disembunyikan total (bukan cuma dikosongkan) kalau user tidak punya izin ini.
- **Filter data**: begitu tab terlihat, datanya tetap difilter oleh `branch_ids` yang sedang dipilih di
  UI (`whereIn('branch_id', $selectedBranchIds)`) — tapi ini murni filter tampilan, bukan pengecekan
  otorisasi tambahan.

**Severity tidak ada kolomnya** di tabel `audit_logs` (hanya `event`, `auditable_type/id`, `old_values`,
`new_values`, `ip_address`, `user_agent`, timestamps). Menambah kolom baru lewat migration adalah
solusi paling "benar" tapi mengubah data historis yang sudah ada (won't retroactively backfill secara
bermakna). Keputusan: **derive severity dari `event` code** lewat mapping statis baru, mengikuti pola
`AuditEvent::LABELS` yang sudah ada — tidak butuh migration, tidak butuh backfill.

```php
// app/Support/AuditEvent.php — tambahan
const SEVERITIES = [
    self::INVOICE_POSTED => 'LOW',
    self::INVOICE_CANCELLED => 'MEDIUM',
    self::PAYMENT_RECEIPT_CREATED => 'LOW',
    self::PAYMENT_RECEIPT_VOIDED => 'MEDIUM',
    self::STOCK_ADJUSTMENT_POSTED => 'MEDIUM',
    self::STOCK_TRANSFER_DISPATCHED => 'LOW',
    self::STOCK_TRANSFER_RECEIVED => 'LOW',
    self::STOCK_TRANSFER_VOIDED => 'MEDIUM',
    self::USER_BRANCH_PERMISSION_GRANTED => 'HIGH',
    self::USER_BRANCH_PERMISSION_REVOKED => 'HIGH',
];
```

Rasional pemetaan: event yang mengubah **hak akses** (grant/revoke permission) = HIGH karena berdampak
keamanan langsung; event yang **membatalkan/void** transaksi yang sudah diposting = MEDIUM karena
mengubah kondisi finansial/stok yang sudah final; event **rutin maju** (posting, terima transfer) = LOW.
Event tanpa mapping (masa depan) fallback ke `'LOW'`.

Query Tab 3:

```php
protected function computeAuditLogRows(array $selectedBranchIds): array
{
    return AuditLog::with(['user'])
        ->whereIn('branch_id', $selectedBranchIds)
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->limit(15)
        ->get()
        ->map(fn (AuditLog $log) => [
            'timestamp' => $log->created_at->format('d/m/Y H:i'),
            'user' => optional($log->user)->name ?? 'Sistem',
            'event' => $log->event,
            'eventLabel' => AuditEvent::LABELS[$log->event] ?? $log->event,
            'description' => $this->describeAuditLog($log),
            'severity' => AuditEvent::SEVERITIES[$log->event] ?? 'LOW',
        ])->all();
}
```

`description` (kolom "Deskripsi" yang diminta) dibentuk dari `auditable_type`/`auditable_id` +
`new_values` yang sudah tersimpan (mis. `"Invoice #{id} diposting"` — tanpa perlu load ulang model
`auditable`, cukup pakai data yang sudah ada di baris audit log itu sendiri, hindari N+1).

### 3.6 Tab 1 — gabungan PKB & Invoice, filter live, tanpa paginasi bertingkat

**Kenapa gabung, bukan UNION SQL:** `work_orders` dan `invoices` beda skema total (kolom status beda
domain nilai, kolom tanggal beda nama). `UNION` SQL butuh kedua sisi punya jumlah & tipe kolom yang
sama — bisa dipaksakan tapi rapuh. Pendekatan yang dipilih: **dua query terpisah, di-map ke bentuk baris
yang sama, digabung & diurutkan di PHP**, masing-masing query sudah dibatasi `limit()` di level SQL
(bukan fetch-all-lalu-filter-di-PHP) supaya tetap efisien.

- Query PKB: `WorkOrder::with(['customer', 'vehicle'])->whereIn('branch_id', pkbScopedBranchIds)->limit(15)->...`
- Query Invoice: `Invoice::with(['customer', 'workOrder.vehicle'])->whereIn('branch_id', invoiceScopedBranchIds)->limit(15)->...`
- Gabung kedua koleksi (masing-masing sudah dipetakan ke bentuk `{type, number, customer, plate, branch,
  status, statusLabel, date}`), urutkan gabungan berdasarkan `date` menurun, ambil 15 teratas.

**Filter (search / status / tanggal), semuanya diterapkan di level query, bukan di PHP setelah fetch:**

- **Pencarian (`q`)** — cocok ke `number` (PKB atau Invoice), `customer.name`, atau
  `vehicle.plate_number`/`workOrder.vehicle.plate_number` (`LIKE` pada masing-masing query).
- **Status (`type_status`)** — dropdown gabungan berformat `pkb:<status>` atau `invoice:<status>` (mis.
  `pkb:shortage`, `invoice:posted`) supaya satu filter bisa memilih status dari domain manapun tanpa
  ambigu. Kalau filter dipilih dari satu jenis (mis. `pkb:*`), query Invoice dilewati sepenuhnya (hasil
  gabungan otomatis cuma berisi PKB) — begitu juga sebaliknya.
- **Rentang tanggal (`date_from`/`date_to`)** — PKB difilter ke `work_order_date`, Invoice ke
  `invoice_date` (field tanggal bisnis masing-masing, konsisten dengan laporan yang sudah ada).

**Tanpa paginasi bertingkat di dalam widget** — limit tetap (15 baris gabungan terbaru sesuai filter),
karena laman penuh dengan paginasi lengkap **sudah ada** (`/work-orders`, `/invoices`). Widget dashboard
ini untuk sekilas-lihat, bukan pengganti laporan. Ditambahkan link "Lihat Semua PKB" / "Lihat Semua
Invoice" di header tab yang mengarah ke halaman penuh (membawa filter cabang yang sama).

### 3.7 Endpoint & kontrak AJAX tetap satu — parameter baru dinamai jelas

Endpoint tetap `GET /dashboard` (mendukung HTML & JSON via `wantsJson()`), **tidak** ditambah endpoint
baru — mengikuti pola yang sudah ada (`sparepart_id` untuk Tab 2 sudah lewat endpoint yang sama). Filter
Tab 1 memicu fetch ulang seluruh payload lewat mekanisme `fetchDashboard()` yang sudah ada (dengan
debounce untuk kotak pencarian, lihat §5). Parameter baru:

| Parameter | Dipakai untuk |
|---|---|
| `pkb_invoice_q` | pencarian teks Tab 1 |
| `pkb_invoice_status` | filter status Tab 1 (`pkb:*` / `invoice:*`) |
| `pkb_invoice_date_from`, `pkb_invoice_date_to` | filter rentang tanggal Tab 1 |

Tidak disimpan di session (beda dari `branch_ids`) — reset natural saat halaman di-reload penuh, sesuai
ekspektasi UX kotak pencarian pada umumnya.

### 3.8 Tab 3 — filter User/Event tetap di luar cakupan milestone ini

Spesifikasi eksplisit hanya meminta "query data aktivitas terbaru ... sesuai cabang aktif" untuk Tab 3
— tidak menyebut filter interaktif (beda dari Tab 1 yang eksplisit minta 3 filter). Dropdown "Semua
User"/"Semua Jenis Event" di view **tetap `disabled`** untuk milestone ini (data di baliknya sudah real,
hanya kontrol filternya belum diaktifkan) — YAGNI, dan `AuditLogController` sudah menyediakan halaman
penuh dengan filter lengkap untuk siapa yang butuh drill-down (`/audit-logs`, link "Lihat Semua" bisa
ditambahkan di header tab, sama seperti Tab 1).

## 4. Ringkasan Perubahan `DashboardController`

`buildPayload()` dirombak jadi:

```php
protected function buildPayload(User $user, array $selectedBranchIds, ?int $sparepartId, array $pkbInvoiceFilters): array
{
    $stockScopedIds = $this->scopedBranchIdsFor($user, $selectedBranchIds, 'sparepart.view');
    $pkbScopedIds = $this->scopedBranchIdsFor($user, $selectedBranchIds, 'pkb.view');
    $invoiceScopedIds = $this->scopedBranchIdsFor($user, $selectedBranchIds, 'invoice.view');

    return [
        'selectedBranchIds' => $selectedBranchIds,
        'stockOverview' => $this->computeStockOverview($stockScopedIds),
        'criticalStockCount' => $this->computeCriticalStockCount($stockScopedIds),
        'pkbStatus' => $this->computePkbStatusToday($pkbScopedIds),
        'receivables' => $this->computeReceivablesSummary($invoiceScopedIds),
        'chartTrend' => $this->computeWeeklyTrend($pkbScopedIds, $invoiceScopedIds),
        'chartReceivables' => $this->computeReceivablesAging($invoiceScopedIds),
        'pkbInvoiceRows' => $this->computePkbInvoiceRows($pkbScopedIds, $invoiceScopedIds, $pkbInvoiceFilters),
        'canViewAuditLog' => $user->hasPermissionTo('audit_log.view'),
        'auditLogRows' => $user->hasPermissionTo('audit_log.view') ? $this->computeAuditLogRows($selectedBranchIds) : [],
        'kartuStok' => $this->computeKartuStok($stockScopedIds, $sparepartId),
    ];
}
```

Method `dummy*()` dihapus seluruhnya. `computeReceivablesAging()` (donut chart) memakai pendekatan
bucket serupa `ReceivableReportController::withAgingLabel()` tapi diagregasi via `CASE WHEN` di SQL
(bukan loop PHP) supaya efisien untuk KPI ringkasan:

```php
protected function computeReceivablesAging(array $scopedBranchIds): array
{
    $labels = ['Belum Jatuh Tempo', '1-30 Hari', '31-60 Hari', '>60 Hari'];
    if (empty($scopedBranchIds)) {
        return ['labels' => $labels, 'values' => [0, 0, 0, 0]];
    }

    $row = Invoice::whereIn('branch_id', $scopedBranchIds)
        ->whereIn('status', [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID])
        ->selectRaw("
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, invoice_date)) < 0 THEN grand_total - paid_amount ELSE 0 END), 0) as not_due,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, invoice_date)) BETWEEN 0 AND 30 THEN grand_total - paid_amount ELSE 0 END), 0) as d1_30,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, invoice_date)) BETWEEN 31 AND 60 THEN grand_total - paid_amount ELSE 0 END), 0) as d31_60,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, invoice_date)) > 60 THEN grand_total - paid_amount ELSE 0 END), 0) as d60_plus
        ")->first();

    return ['labels' => $labels, 'values' => [(float) $row->not_due, (float) $row->d1_30, (float) $row->d31_60, (float) $row->d60_plus]];
}
```

## 5. Ringkasan Perubahan View

- `_tab_pkb_invoice.blade.php`: 3 input filter jadi aktif (`id="pkbInvoiceSearch"`,
  `id="pkbInvoiceStatus"`, `id="pkbInvoiceDateFrom"`/`id="pkbInvoiceDateTo"`), kolom "No. PKB/Invoice"
  dapat badge tipe kecil (PKB/Invoice) di depan nomor, tombol "Lihat Semua" per jenis di header tab.
- `_tab_audit_log.blade.php`: dibungkus `@if ($canViewAuditLog)` — kalau `false`, tab "Audit Log" di
  `dashboard/index.blade.php` juga tidak dirender sama sekali (bukan cuma disembunyikan CSS). Baris
  severity dipetakan ke warna (`status-danger` utk HIGH, `status-warning` utk MEDIUM, `status-active`
  utk LOW — konsisten dengan pola badge status di halaman lain).
- Card 3: baris kecil ditambah `Draft {d} ·`.
- `dashboard/index.blade.php` (JS): kotak pencarian Tab 1 pakai **debounce 400ms** sebelum memanggil
  `fetchDashboard()` (mengikuti pola `select2-ajax-picker.js` yang sudah dipakai di modul lain untuk
  input pencarian, standar UX proyek ini) — dropdown status & date range langsung trigger tanpa
  debounce (perubahan diskrit, bukan ketikan).

## 6. Testing Strategy

File baru `tests/Feature/DashboardControllerTest.php` (test lama, jika ada, dicek dulu — belum ditemukan
saat eksplorasi, jadi ini file baru):

1. Card 3 menghitung breakdown 4 status PKB hari ini dengan benar, mengabaikan PKB kemarin/besok dan
   PKB `Cancelled`.
2. Card 4: revenue = SUM grand_total invoice Posted/PartiallyPaid/Paid; unpaid = SUM sisa piutang
   Posted/PartiallyPaid saja (konsisten `ReceivableReportController`).
3. Chart tren: PKB dihitung dari `work_orders.created_at`, Invoice Posted dihitung dari
   `audit_logs` (`event=invoice.posted`) — bukan dari `invoice_date`/status saat ini (invoice yang
   sudah lunas tetap terhitung di pekan saat ia *diposting*, bukan hilang begitu status berubah).
4. Chart piutang: aging bucket benar di sekitar batas (`due_date` pas H, H+30, H+31, H+61).
5. Tab 1: filter `q` cocok ke nomor/customer/plat baik untuk baris PKB maupun Invoice; filter
   `pkb:shortage` hanya mengembalikan baris PKB; filter tanggal membatasi kedua jenis sesuai kolom
   tanggal masing-masing; hasil gabungan terurut tanggal menurun dan dibatasi 15 baris.
6. Tab 3: `canViewAuditLog=false` untuk user tanpa `audit_log.view` → tab tidak ada di HTML & payload
   JSON `auditLogRows` kosong. User dengan izin melihat severity termapping benar per event.
7. Setiap widget hormati `scopedBranchIdsFor()` miliknya sendiri — user dengan `sparepart.view` tapi
   **tanpa** `pkb.view` di suatu cabang tetap melihat Card 1/2 terisi tapi Card 3 & Tab 1 nol/kosong
   untuk cabang itu (regresi utama yang dicegah keputusan §3.1).
8. Payload JSON (`wantsJson()`) dan render HTML awal menghasilkan angka yang sama persis (tidak ada
   logic bercabang tersembunyi antara dua jalur render).

## 7. Manifest File

**Diubah:**
- `app/Http/Controllers/DashboardController.php` (rombak total `buildPayload()` + method pendukung baru,
  hapus semua `dummy*()`)
- `app/Support/AuditEvent.php` (+`SEVERITIES` const)
- `resources/views/dashboard/index.blade.php` (Card 3 breakdown, JS debounce Tab 1, kondisi tab Audit Log)
- `resources/views/dashboard/_tab_pkb_invoice.blade.php` (filter aktif, badge tipe, link "Lihat Semua")
- `resources/views/dashboard/_tab_kartu_stok.blade.php` (tidak berubah secara fungsional — sudah real)
- `resources/views/dashboard/_tab_audit_log.blade.php` (bungkus kondisi permission, warna severity)

**Baru:**
- `tests/Feature/DashboardControllerTest.php`

## 8. Di Luar Scope

- Filter User/Event Tab 3 (dropdown tetap `disabled` — lihat §3.8).
- Endpoint AJAX terpisah per-widget (tetap satu endpoint `/dashboard`, sesuai desain yang sudah ada).
- Kolom `severity` fisik di tabel `audit_logs` (dipakai mapping statis by-event, lihat §3.5) — migration
  kolom baru bisa jadi milestone terpisah kalau ke depan butuh severity custom per baris (bukan per
  jenis event).
- Perubahan apa pun ke Card 1, Card 2, Tab 2 (Kartu Stok) — sudah real, tidak disentuh selain
  dilewatkan lewat `scopedBranchIdsFor()` yang baru untuk konsistensi kode.
