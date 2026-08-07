# Laporan Gap Invoice vs PKB (Invoice vs Work Order Gap Report) — Design

## 1. Tujuan

Laporan read-only yang membandingkan nilai setiap Invoice terhadap PKB (Work Order) asalnya, untuk mendeteksi selisih (gap) yang terjadi akibat edit line item invoice (tambah/hapus/ubah harga atau qty) atau penerapan diskon/pajak setelah invoice dibuat dari PKB. Ini adalah placeholder Laporan ke-4 yang diaktifkan di proyek ini, dan modul pertama yang membandingkan dua entitas (PKB vs Invoice) alih-alih melaporkan satu entitas saja.

## 2. Arsitektur

- **Modul standalone, single-action**: `App\Http\Controllers\InvoicePkbGapReportController@index`.
- **Route**: `GET /reports/invoice-pkb-gap` → `reports.invoice-pkb-gap.index`, didaftarkan di dalam grup `Route::prefix('reports')->name('reports.')` yang sudah ada di `routes/web.php`, tepat setelah baris route `invoices.index`.
- **Tidak ada migration baru, tidak ada Policy baru.** Query murni Eloquent/query builder di atas tabel yang sudah ada: `invoices`, `work_orders`, `work_order_service_lines`, `work_order_sparepart_lines`, `invoice_details`.
- **Basis data**: setiap `Invoice` yang punya `work_order_id` terisi (secara praktik selalu terisi — satu-satunya jalur pembuatan Invoice adalah `InvoiceService::createFromWorkOrder()`, dipanggil dari `InvoiceController::store()`). Query tetap eksplisit memfilter `whereNotNull('work_order_id')` sebagai penjagaan, bukan asumsi diam-diam.

## 3. Mekanisme Inti — Perhitungan `pkb_total` via Correlated Subquery

`work_orders` **tidak menyimpan kolom total** (tidak seperti `invoices.grand_total`) — nilai PKB hanya ada sebagai `SUM(line_total)` di dua tabel line (`work_order_service_lines`, `work_order_sparepart_lines`), keduanya immutable setelah PKB keluar dari status DRAFT (kebijakan `WorkOrderPolicy::update()` sudah membatasi edit hanya untuk status DRAFT).

`pkb_total` dihitung sebagai kolom terhitung memakai dua correlated subquery yang di-`UNION`-jumlahkan via `selectRaw`, dikorelasikan ke `invoices.work_order_id`:

```php
$pkbTotalExpr = '(
    COALESCE((SELECT SUM(line_total) FROM work_order_service_lines WHERE work_order_service_lines.work_order_id = invoices.work_order_id), 0)
    +
    COALESCE((SELECT SUM(line_total) FROM work_order_sparepart_lines WHERE work_order_sparepart_lines.work_order_id = invoices.work_order_id), 0)
)';
```

Ekspresi ini **satu-satunya sumber kebenaran** untuk `pkb_total`, dipakai ulang di tiga tempat:
1. **Filter Status Selisih** — dibandingkan terhadap `invoices.grand_total` via `whereRaw`, dieksekusi di level SQL sebelum paginasi (bukan filter PHP setelah data diambil).
2. **Kolom Selisih per baris** (mode Rekap) — di-`select` sebagai kolom tambahan (`pkb_total`, dan `selisih_amount` = `grand_total - pkb_total`) pada query yang sama yang di-paginate.
3. **Summary cards** — di-`SUM()`-kan pada query `selectRaw` terpisah (clone dari query yang sudah difilter, sebelum `simplePaginate`), mengikuti konvensi proyek yang sudah dipakai di Laporan Invoice/Piutang (satu `selectRaw` agregat, tidak menjumlah di PHP lintas halaman).

**Threshold "Sesuai"**: exact equality (`invoices.grand_total = pkb_total`). Aman tanpa epsilon karena kedua nilai adalah `decimal` (bukan float) hasil `round(..., 2)` di kedua sisi penulisannya (`InvoiceService::createFromWorkOrder()`/`updateInvoice()` dan `WorkOrderServiceLine`/`WorkOrderSparepartLine.line_total` yang sudah `decimal:2`).

**Selisih %** (ditampilkan di mode Rekap): `(selisih_amount / pkb_total) * 100`, dihitung di PHP per baris hasil (bukan di SQL) karena hanya perlu untuk 15 baris per halaman. Guard pembagi-nol: jika `pkb_total == 0.0`, tampilkan `—` (bukan `INF`/error) — kasus tepi yang mungkin terjadi jika sebuah PKB seluruh line-nya bernilai Rp 0.

## 4. Otorisasi

Branch-scoped, mengikuti pola persis Laporan Invoice/PKB/Piutang — **bukan** `$this->authorize()` mentah (permission `report.invoice_pkb_gap.view` sudah ter-seed dengan `is_branch_scoped => true` di `MenuPermissionSeeder`, baris 251):

```php
$permittedBranches = $user->branchesWithPermission('report.invoice_pkb_gap.view');
if ($permittedBranches->isEmpty()) {
    return view('reports.invoice-pkb-gap.no-access');
}
```

Base query di-scope ke `whereIn('invoices.branch_id', $permittedBranches->pluck('id'))` lebih dulu; filter `branch_ids[]` dari form hanya mempersempit (di-intersect terhadap set yang diizinkan), tidak pernah dipercaya sendirian — pola identik dengan Laporan Invoice.

## 5. Filter Form

Form `GET` dengan field berikut (semua query param, form re-submit penuh — tidak ada AJAX, konsisten dengan laporan lain):

| Field | Query param | Tipe | Default |
|---|---|---|---|
| Cabang | `branch_ids[]` | multiselect (partial `branch-multiselect-filter`) | kosong = semua cabang yang diizinkan |
| Status Selisih | `gap_status` | select | `ada_selisih` |
| Mode Tampilan | `mode` | select | `rekap` |
| Customer / No. PKB / No. Invoice | `search` | text | kosong |
| Tanggal Invoice Dari | `date_from` | date | kosong |
| Tanggal Invoice Sampai | `date_to` | date | kosong |

**Status Selisih (`gap_status`)** — 5 opsi, reject-to-safe-default seperti pola `mode`:
- `ada_selisih` (default) → `WHERE grand_total <> pkb_total`
- `invoice_gt_pkb` → `WHERE grand_total > pkb_total`
- `invoice_lt_pkb` → `WHERE grand_total < pkb_total`
- `sesuai` → `WHERE grand_total = pkb_total`
- `semua` → tanpa filter tambahan

Nilai selain 5 di atas jatuh ke default `ada_selisih` (bukan `semua`) — beda dari pola `mode` di Laporan Invoice/PKB yang defaultnya adalah tampilan "aman"; di sini default yang **berguna** (menunjukkan hanya transaksi bermasalah) yang dipilih sebagai default, sesuai instruksi eksplisit di poin 3 pengguna.

**Search (`search`)**: mencocokkan salah satu dari — nomor invoice (`invoices.number`), nomor PKB (`work_orders.number`, via `whereHas('workOrder', ...)`), atau nama customer (`invoices.customer` relasi, sama seperti Laporan Invoice) — memakai `addcslashes($term, '%_\\')` escaping yang sama seperti laporan lain.

**Rentang Tanggal**: terhadap `invoices.invoice_date` (bukan `work_order_date`) — sesuai poin 3 pengguna ("Rentang Tanggal Invoice").

## 6. Summary Cards

Satu `selectRaw` agregat di atas query yang sudah difilter (clone sebelum paginasi), memakai ekspresi `pkb_total` yang sama:

```php
$summary = (clone $query)->selectRaw(
    'COUNT(*) as total_transaksi, ' .
    "COALESCE(SUM({$pkbTotalExpr}), 0) as total_nilai_pkb, " .
    'COALESCE(SUM(invoices.grand_total), 0) as total_nilai_invoice, ' .
    "COALESCE(SUM(invoices.grand_total - {$pkbTotalExpr}), 0) as total_varian_netto"
)->first();
```

4 stat card (memakai class `.stat-card`/`.stat-value`/`.stat-label`/`.stat-icon` yang sudah ada, tidak membuat markup baru):
1. **Total Transaksi Terhubung** — `total_transaksi`
2. **Total Nilai PKB** — `total_nilai_pkb`
3. **Total Nilai Invoice** — `total_nilai_invoice`
4. **Total Varian Netto** — `total_varian_netto` (bisa negatif — invoice lebih kecil dari PKB secara agregat; ditampilkan dengan tanda minus apa adanya, tanpa pewarnaan khusus untuk menjaga scope tetap sederhana)

## 7. Tampilan Dual-Mode

Mode ditentukan via `mode` query param, pola reject-to-safe-default identik dengan Laporan Invoice/PKB:
```php
$mode = request('mode') === 'detail' ? 'detail' : 'rekap';
```

### 7.1 Mode Rekap (default)

Satu baris per pasangan Invoice–PKB. Kolom:

| Kolom | Sumber |
|---|---|
| No. PKB | `work_orders.number` (link ke `work-orders.show`) |
| No. Invoice | `invoices.number` (link ke `invoices.show`) |
| Tanggal Invoice | `invoices.invoice_date` |
| Customer | `invoices.customer.name` |
| Total PKB | `pkb_total` (hasil subquery) |
| Total Invoice | `invoices.grand_total` |
| Selisih (Rp) | `grand_total - pkb_total`, format dengan tanda `+`/`-` eksplisit |
| Selisih (%) | dihitung PHP, `—` jika `pkb_total = 0` |
| Status Gap | badge, lihat 7.3 |

### 7.2 Mode Detail

Satu baris per **perbandingan line item** (bukan per line PKB atau per line Invoice saja — union keduanya, lihat taksonomi di bawah). Kolom identifikasi invoice (No. PKB, No. Invoice, Tanggal, Customer) diulang di setiap baris line — tanpa `rowspan`, pola sama dengan mode Detail Laporan Invoice/PKB.

Kolom tambahan per baris line:

| Kolom | Deskripsi |
|---|---|
| Tipe Item | Jasa / Sparepart |
| Nama Item | dari `InvoiceDetail.description` jika ada pasangan, dari `WorkOrderServiceLine.description`/`WorkOrderSparepartLine.item_name_snapshot` jika hanya ada di PKB |
| Qty PKB / Harga PKB | dari baris PKB asal, `—` jika kategori "Ditambahkan di Invoice" |
| Qty Invoice / Harga Invoice | dari `InvoiceDetail`, `—` jika kategori "Dihapus dari Invoice" |
| Kategori | 4 nilai, lihat 7.4 |

**Algoritma pencocokan** (per Invoice, dieksekusi di controller setelah eager-load, bukan di SQL — hanya untuk baris invoice pada halaman aktif, jumlah line per PKB kecil):

1. Ambil semua `WorkOrderServiceLine`/`WorkOrderSparepartLine` milik PKB (`$workOrder->serviceLines`, `$workOrder->sparepartLines`).
2. Ambil semua `InvoiceDetail` milik Invoice, index by `work_order_service_line_id`/`work_order_sparepart_line_id` (null-key dikumpulkan terpisah).
3. Untuk setiap PKB line: cari `InvoiceDetail` dengan `work_order_*_line_id` yang cocok.
   - Tidak ditemukan → kategori **Dihapus dari Invoice**.
   - Ditemukan, `qty` dan `unit_price` sama persis → kategori **Sesuai**.
   - Ditemukan, `qty` dan/atau `unit_price` beda → kategori **Harga/Qty Berubah**.
4. Untuk setiap `InvoiceDetail` yang `work_order_*_line_id`-nya `null` → kategori **Ditambahkan di Invoice**.

Invoice yang PKB-nya tidak punya line sama sekali dan invoice-nya juga tidak punya detail (kasus tepi ekstrem, praktiknya tidak mungkin karena `createFromWorkOrder` selalu menyalin minimal 1 line) tetap menampilkan 1 baris placeholder `—` — pola sama dengan mode Detail Laporan Invoice/PKB untuk invoice tanpa `details`.

### 7.3 Badge Status Gap (mode Rekap)

| Kondisi | Badge |
|---|---|
| `grand_total = pkb_total` | `<span class="status-dot status-active">Sesuai</span>` |
| `grand_total > pkb_total` | `<span class="status-dot status-warning">Invoice &gt; PKB</span>` |
| `grand_total < pkb_total` | `<span class="status-dot status-danger">Invoice &lt; PKB</span>` |

### 7.4 Badge Kategori (mode Detail)

| Kategori | Badge |
|---|---|
| Sesuai | `<span class="status-dot status-active">Sesuai</span>` |
| Harga/Qty Berubah | `<span class="status-dot status-warning">Berubah</span>` |
| Dihapus dari Invoice | `<span class="status-dot status-danger">Dihapus</span>` |
| Ditambahkan di Invoice | `<span class="status-dot status-warning">Ditambahkan</span>` |

## 8. Paginasi

`simplePaginate(15)` di level **Invoice** (bukan level line item, bahkan di mode Detail) — 15 pasangan Invoice-PKB per halaman, konsisten dengan instruksi poin 6 pengguna dan pola mode Detail Laporan Invoice/PKB (paginasi selalu berbasis entitas induk, jumlah baris line per halaman bisa bervariasi). `withQueryString()` dipakai agar link paginasi membawa filter aktif.

## 9. Sidebar Wiring

`resources/views/partials/sidebar.blade.php:161-167` — placeholder saat ini:
```blade
@if ($user->branchesWithPermission('report.invoice_pkb_gap.view')->isNotEmpty())
<li class="nav-item">
    <span class="nav-link nav-link-disabled">
        <i class="bi bi-bar-chart-steps me-2"></i> PKB vs Invoice
        <span class="badge-soon">Segera Hadir</span>
    </span>
</li>
@endif
```
Diganti dengan link nyata ke `reports.invoice-pkb-gap.index`, mempertahankan ikon `bi-bar-chart-steps` dan label teks "PKB vs Invoice" apa adanya (bukan "Gap Invoice vs PKB" — mengikuti teks placeholder yang sudah ada agar tidak ada test/assertion lain yang perlu disesuaikan di luar scope).

## 10. Halaman No-Access

`resources/views/reports/invoice-pkb-gap/no-access.blade.php` — pola identik dengan 3 laporan sebelumnya: judul + ikon `bi-bar-chart-steps`, pesan "Anda belum memiliki akses laporan gap invoice vs PKB di cabang manapun."

## 11. Ruang Lingkup yang Sengaja Dikecualikan

- **Tidak ada filter Status Invoice terpisah** (Draft/Diposting/dll) — semua status Invoice ikut dihitung secara default, sesuai keputusan di sesi brainstorming (mengikuti preseden Laporan Invoice/PKB, bukan Piutang). Filter "Status Selisih" murni soal besaran gap.
- **Tidak ada pewarnaan/threshold "signifikan" untuk Total Varian Netto** di summary card — ditampilkan sebagai angka mentah dengan tanda.
- **Tidak ada drill-down/aksi apa pun dari laporan ini** (murni read-only) — tautan No. PKB/No. Invoice mengarah ke halaman detail PKB/Invoice yang sudah ada, tidak ada aksi edit/cancel dari laporan ini sendiri.
- **Tidak ada export** (CSV/PDF) — sama seperti 3 laporan sebelumnya, di luar scope saat ini.
