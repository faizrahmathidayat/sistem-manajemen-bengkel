# Laporan Performance Bengkel (Tampilan Mekanik & Invoice Detail) — Design Spec

**Date:** 2026-08-11
**Status:** Draft

## 1. Background

Milestone baru: laporan performance bengkel yang mengukur kontribusi tiap mekanik (jumlah customer, qty jasa/sparepart, diskon, subtotal, grand total) dan detail transaksi per invoice dalam format side-by-side Jasa vs Sparepart. Dua tampilan berbeda struktur (bukan sekadar rekap/detail dari tabel yang sama seperti laporan lain):

- **Tampilan Mekanik** (`view_type=mechanic`): satu baris agregat per mekanik.
- **Tampilan Invoice Detail** (`view_type=invoice_detail`): satu blok per invoice, berisi meta header + tabel side-by-side 11 kolom (Jasa vs Sparepart, dipasangkan per index) + baris Total.

Referensi format persis dari dua file contoh yang diberikan user:
- `new format laporan performance mekanik (per mekanik).xlsx` — kolom: Mekanik, Total Customer, Total Qty Jasa, Total Discount Jasa (Rp), Subtotal Jasa, Total Qty Sparepart, Total Discount Sparepart (Rp), Subtotal Sparepart, Grand Total. Formula `Grand Total = Subtotal Sparepart + Subtotal Jasa`.
- `new format laporan performance mekanik (per invoice).xlsx` — per invoice: baris meta (No. Invoice, Tanggal, Status, Customer, Mekanik, Cabang), lalu tabel 11 kolom (`Jasa | Harga Satuan Jasa | Qty | Diskon (%) | Subtotal Jasa | Sparepart | Harga Satuan Sparepart | Qty | Diskon (%) | Subtotal Sparepart | Subtotal Line`) dengan baris Jasa & Sparepart dipasangkan per-index (item ke-N Jasa sejajar dengan item ke-N Sparepart; sisi yang lebih pendek diisi `-`/0), lalu baris `Total` (`Subtotal Jasa = SUM(...)`, `Subtotal Sparepart = SUM(...)`, `Subtotal Line = Subtotal Sparepart + Subtotal Jasa`).

Tidak ada perubahan skema database — seluruh data sumber sudah tersedia dari milestone-milestone sebelumnya (`mechanics.nip`/`display_label`, `invoice_details.discount_percent`/`discount_amount`/`item_type`, dst).

## 2. Codebase Audit (ringkasan)

- Pola laporan existing (`PkbReportController`, `InvoiceReportController`, `InvoicePkbGapReportController`) semuanya: `index()` (Rekap/Detail via `?mode=`), `exportExcel()`, `previewPdf()`/`downloadPdf()` → `renderPdf($disposition)` lewat trait `HandlesReportExport` (`authorizeExport()`, `capRows()`, `streamPdf()`). Filter selalu `branch_ids[]` (multi-select, lihat `partials.branch-multiselect-filter`), `date_from`/`date_to`, dan untuk Invoice juga `status`. Laporan baru ini akan **mengikuti pola nama parameter yang sama** (`branch_ids[]`, `date_from`, `date_to`, `status`) — **kecuali** parameter mode tampilan, yang akan bernama `view_type` dengan value `mechanic`/`invoice_detail` (bukan `mode`/`rekap`/`detail`), karena dua tampilan ini benar-benar berbeda struktur data, bukan varian rekap/detail dari tabel yang sama.
- `Invoice` model: `belongsTo(WorkOrder::class)` via `workOrder()`, `belongsTo(Branch)`, `belongsTo(Customer)`, `hasMany(InvoiceDetail)` via `details()` (ordered by `sort_order`), `getIsDirectSaleAttribute()` (`work_order_id IS NULL`).
- `InvoiceDetail`: field relevan — `item_type` (`service`/`sparepart`, via `App\Support\InvoiceDetailItemType`), `description`, `qty` (decimal:3), `unit_price` (decimal:2), `discount_percent` (decimal:2, disimpan sebagai angka persen literal misal `10` = 10%, **bukan** pecahan `0.10` — dikonfirmasi dari test existing `InvoiceReportExportTest::test_pdf_preview_detail_mode_shows_branch_mechanic_and_discount`), `discount_amount` (decimal:2), `line_total` (decimal:2, sudah net setelah diskon).
- `Mechanic::getDisplayLabelAttribute()` (`app/Models/Mechanic.php:45`, dari milestone sebelumnya) — `"{nip} - {name}"` atau fallback `name` jika `nip` kosong. Dipakai ulang di sini.
- `WorkOrder::mechanic()`, `WorkOrder::invoice()` (hasOne) — invoice ↔ mekanik hanya bisa diakses lewat `$invoice->workOrder->mechanic` (invoice tidak punya `mechanic_id` langsung). Invoice Direct Sales (`work_order_id NULL`) → `$invoice->workOrder` = `null` → tidak punya mekanik.
- `App\Support\InvoicePkbGapComparator::build(Invoice $invoice): array` (`app/Support/InvoicePkbGapComparator.php`) — preseden persis untuk kebutuhan "pairing baris per invoice": static helper class dengan method `build()` yang menghasilkan array baris terstruktur dari satu invoice, dipakai bersama oleh Blade index (repeating block per invoice), PDF Blade, dan Excel export `map()`. Pola yang sama akan dipakai untuk pairing Jasa/Sparepart.
- Export existing (`PkbReportExport`, `InvoiceReportExport`, `InvoicePkbGapReportExport`) semua `implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents` dengan `registerEvents()` → `AfterSheet` dipakai HANYA untuk menyisipkan baris 1 (filter summary, merged cell, bold). Nilai kolom lain semua angka statis hasil hitung PHP (bukan formula Excel `=...`) — **tidak ada preseden formula Excel lintas-sel di codebase ini.**
- `layouts.print` (dipakai semua PDF laporan) — halaman A4 landscape, `@section('table')` berisi `<table class="print-table">` polos, font kecil, terbukti aman menampung banyak kolom (laporan PKB Detail sudah 13 kolom).
- `MenuPermissionSeeder` (`database/seeders/MenuPermissionSeeder.php:240-280`) — setiap laporan = 1 entry array `{code, name, is_branch_scoped: true, permissions: [{code: 'report.X.view', resource: 'report', action: 'X.view', description}]}`.
- `resources/views/partials/sidebar.blade.php:154-190` — blok "Reporting", tiap link dibungkus `@if ($user->branchesWithPermission('report.X.view')->isNotEmpty())`.
- `routes/web.php:222-243` — grup `Route::prefix('reports')->name('reports.')`, 4 route per laporan (`index`, `export-excel`, `pdf-preview`, `pdf-download`).
- Test pattern: tiap `*ReportControllerTest.php`/`*ReportExportTest.php` punya `grantBranchPermission()` lokal (copy-paste) + scenario builder (`makeInvoice()` yang POST lewat HTTP asli: `/work-orders` → confirm → complete → `/invoices` → `/invoices/{id}/post`). `Tests\Concerns\ExtractsPdfText` untuk assertion PDF, dengan `preg_replace('/\s+/', ' ', ...)` untuk menghindari word-wrap PdfParser pada tabel banyak kolom.

## 3. Design

### 3.1 Routing, Permission, Menu

- **Controller:** `App\Http\Controllers\WorkshopPerformanceReportController` (pola identik `HandlesReportExport` trait).
- **Routes** (di dalam grup `reports` existing, `routes/web.php`):
  ```php
  Route::get('/workshop-performance', [WorkshopPerformanceReportController::class, 'index'])->name('workshop-performance.index');
  Route::get('/workshop-performance/export-excel', [WorkshopPerformanceReportController::class, 'exportExcel'])->name('workshop-performance.export-excel');
  Route::get('/workshop-performance/pdf-preview', [WorkshopPerformanceReportController::class, 'previewPdf'])->name('workshop-performance.pdf-preview');
  Route::get('/workshop-performance/pdf-download', [WorkshopPerformanceReportController::class, 'downloadPdf'])->name('workshop-performance.pdf-download');
  ```
- **Permission:** kode baru `report.workshop_performance.view` (resource `report`, action `workshop_performance.view`), ditambahkan sebagai entry baru di `MenuPermissionSeeder`:
  ```php
  [
      'code' => 'reporting.workshop_performance',
      'name' => 'Laporan Performance Bengkel',
      'is_branch_scoped' => true,
      'permissions' => [
          ['code' => 'report.workshop_performance.view', 'resource' => 'report', 'action' => 'workshop_performance.view', 'description' => 'Melihat laporan performance bengkel'],
      ],
  ],
  ```
  Ditempatkan setelah entry `reporting.invoice` (sebelum `reporting.receivable`).
- **Sidebar:** link baru di `partials/sidebar.blade.php`, pola identik link lain, ditempatkan setelah link "Laporan Invoice":
  ```blade
  @if ($user->branchesWithPermission('report.workshop_performance.view')->isNotEmpty())
  <li class="nav-item">
      <a href="{{ route('reports.workshop-performance.index') }}" class="nav-link {{ request()->routeIs('reports.workshop-performance.*') ? 'active' : '' }}">
          <i class="bi bi-speedometer2 me-2"></i> Laporan Performance Bengkel
      </a>
  </li>
  @endif
  ```
  Kondisi gabungan di baris pembuka blok "Reporting" (baris 154) juga perlu menambahkan `|| $user->branchesWithPermission('report.workshop_performance.view')->isNotEmpty()`.

### 3.2 Filter Bar

Sama seperti Laporan Invoice, ditambah `view_type`:

| Param | UI | Sumber |
|---|---|---|
| `branch_ids[]` | Multi-select cabang (`partials.branch-multiselect-filter`) | Cabang yang diizinkan user (`branchesWithPermission('report.workshop_performance.view')`) |
| `status` | Dropdown status invoice, opsi sama persis `App\Support\InvoiceStatus` (Draft/Posted/Dibayar Sebagian/Lunas/Dibatalkan) | — |
| `date_from` / `date_to` | Input tanggal, filter ke `invoices.invoice_date` | — |
| `view_type` | Dropdown "Tampilan": `mechanic` (default) / `invoice_detail` | — |

`resolveFilters()`/`buildQuery()`/`filterSummaryText()` mengikuti struktur identik `InvoiceReportController`, hanya field `mode` diganti nama `viewType` dan value validnya `mechanic`/`invoice_detail` (default `mechanic` jika query param tidak valid/kosong).

### 3.3 Query Design

Base filter (branch, status, tanggal) selalu di atas tabel `invoices`, sama persis `InvoiceReportController::buildQuery()` — **tidak** ada `whereNotNull('work_order_id')` di level base query, supaya invoice Direct Sales tetap ikut ter-filter dengan benar untuk tampilan Invoice Detail (lihat §3.6 untuk perlakuannya).

**Tampilan Mekanik** — query agregat, beda total dari base filter di atas karena harus JOIN ke `work_orders`+`mechanics`+`invoice_details` dan `GROUP BY` per mekanik (otomatis mengecualikan invoice Direct Sales karena `INNER JOIN work_orders`):

```php
protected function buildMechanicQuery(array $filters, SupportCollection $permittedBranches)
{
    return Invoice::query()
        ->join('work_orders', 'work_orders.id', '=', 'invoices.work_order_id')
        ->join('mechanics', 'mechanics.id', '=', 'work_orders.mechanic_id')
        ->join('invoice_details', 'invoice_details.invoice_id', '=', 'invoices.id')
        ->whereIn('invoices.branch_id', $permittedBranches->pluck('id'))
        ->when($filters['branchIds'], fn ($q) => $q->whereIn('invoices.branch_id', $filters['branchIds']))
        ->when($filters['status'], fn ($q) => $q->where('invoices.status', $filters['status']))
        ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoices.invoice_date', '>=', $filters['dateFrom']))
        ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoices.invoice_date', '<=', $filters['dateTo']))
        ->groupBy('mechanics.id')
        ->select([
            'mechanics.id as mechanic_id',
            'mechanics.name as mechanic_name',
            'mechanics.nip as mechanic_nip',
            DB::raw('COUNT(DISTINCT invoices.customer_id) as total_customer'),
            DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'service' THEN invoice_details.qty ELSE 0 END), 0) as total_qty_jasa"),
            DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'service' THEN invoice_details.discount_amount ELSE 0 END), 0) as total_discount_jasa"),
            DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'service' THEN invoice_details.line_total ELSE 0 END), 0) as subtotal_jasa"),
            DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'sparepart' THEN invoice_details.qty ELSE 0 END), 0) as total_qty_sparepart"),
            DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'sparepart' THEN invoice_details.discount_amount ELSE 0 END), 0) as total_discount_sparepart"),
            DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'sparepart' THEN invoice_details.line_total ELSE 0 END), 0) as subtotal_sparepart"),
            DB::raw('MAX(invoices.invoice_date) as last_invoice_date'),
        ]);
}
```

`mechanics.name`/`mechanics.nip` boleh diselect tanpa fungsi agregat meski `GROUP BY mechanics.id` karena functional dependency pada primary key (MySQL mengizinkan ini walau `ONLY_FULL_GROUP_BY` aktif). `Grand Total` **tidak** diselect di SQL — dihitung di PHP (`subtotal_jasa + subtotal_sparepart`) supaya satu sumber kebenaran yang sama dipakai baik untuk kolom tampilan maupun basis penulisan formula Excel `=E+H`.

Hasil `->get()` dieksekusi sekali (bukan `simplePaginate`, karena `GROUP BY` + `simplePaginate`'s "ada halaman berikutnya?" probe query tidak reliable untuk grouped query) → dibungkus manual jadi `LengthAwarePaginator` (15/halaman, kompatibel dengan `{{ $rows->links() }}` yang sudah dipakai semua laporan lain). Jumlah mekanik aktif dalam praktiknya kecil (puluhan), jadi eager-load semua baris ke memory aman.

**Urutan default:** `orderByDesc('last_invoice_date')` — mekanik dengan aktivitas invoice terbaru tampil duluan (konsisten dengan konvensi seluruh laporan lain yang order by tanggal desc, bukan by nama atau by nilai).

**Tampilan Invoice Detail** — query identik `InvoiceReportController::buildQuery()` (Eloquent `Invoice::query()`, base filter branch/status/tanggal), eager-load `['branch', 'customer', 'workOrder.mechanic', 'details']`, `orderByDesc('invoice_date')->orderByDesc('id')`, `simplePaginate(15)` (pola sama persis Invoice Report).

### 3.4 Pairing Jasa/Sparepart per Invoice

Helper baru `App\Support\WorkshopPerformanceLinePairer::build(Invoice $invoice): array`, pola identik `InvoicePkbGapComparator::build()`:

```php
class WorkshopPerformanceLinePairer
{
    public static function build(Invoice $invoice): array
    {
        $services = $invoice->details->where('item_type', InvoiceDetailItemType::SERVICE)->values();
        $spareparts = $invoice->details->where('item_type', InvoiceDetailItemType::SPAREPART)->values();
        $rowCount = max($services->count(), $spareparts->count());

        $rows = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $service = $services->get($i);
            $sparepart = $spareparts->get($i);
            $serviceSubtotal = $service ? (float) $service->line_total : 0.0;
            $sparepartSubtotal = $sparepart ? (float) $sparepart->line_total : 0.0;

            $rows[] = [
                'jasa_desc' => $service ? $service->description : '-',
                'jasa_price' => $service ? (float) $service->unit_price : 0.0,
                'jasa_qty' => $service ? (float) $service->qty : 0.0,
                'jasa_discount_percent' => $service ? (float) $service->discount_percent : 0.0,
                'jasa_subtotal' => $serviceSubtotal,
                'sparepart_desc' => $sparepart ? $sparepart->description : '-',
                'sparepart_price' => $sparepart ? (float) $sparepart->unit_price : 0.0,
                'sparepart_qty' => $sparepart ? (float) $sparepart->qty : 0.0,
                'sparepart_discount_percent' => $sparepart ? (float) $sparepart->discount_percent : 0.0,
                'sparepart_subtotal' => $sparepartSubtotal,
                'subtotal_line' => $serviceSubtotal + $sparepartSubtotal,
            ];
        }

        return $rows;
    }
}
```

Dipakai bersama oleh: index Blade (repeating card per invoice), PDF Blade, dan Excel `AfterSheet` writer (§3.6). Jika invoice tidak punya line item sama sekali (`$rowCount === 0`), tampilkan 1 baris placeholder `-`/0 (pola sama seperti baris `@empty` existing di laporan lain).

### 3.5 Tampilan "Mekanik" — Index, PDF, Excel

**Index (`reports/workshop-performance/index.blade.php`, `view_type=mechanic`):** tabel flat, 1 baris per mekanik, kolom persis sesuai urutan template: Mekanik (`{$row->mechanic_nip} - {$row->mechanic_name}` via helper yang sama seperti `Mechanic::display_label`, lihat catatan di §4), Total Customer, Total Qty Jasa, Total Discount Jasa (Rp), Subtotal Jasa, Total Qty Sparepart, Total Discount Sparepart (Rp), Subtotal Sparepart, Grand Total (`number_format`, 0 desimal, format Indonesia).

**PDF (`reports/workshop-performance/pdf.blade.php`, blok `@if ($viewType === 'mechanic')`):** tabel sama, extends `layouts.print` (landscape), pola identik laporan Rekap lain.

**Excel (`app/Exports/WorkshopPerformanceMechanicExport.php`):** karena butuh formula `Grand Total = Subtotal Jasa + Subtotal Sparepart` yang mereferensi sel spesifik pada baris yang sama, export ini **tidak** memakai `WithMapping` (yang tidak memberi akses ke nomor baris absolut) — memakai pola `FromArray` (return `[]`, hanya untuk memenuhi interface) + seluruh isi sheet ditulis manual di `registerEvents()` → `AfterSheet`:
1. Tulis baris 1 (filter summary, merged, bold) — sama seperti export existing.
2. Tulis baris 2: heading (9 kolom).
3. Loop baris data mulai baris 3: kolom A–H nilai statis (`setCellValue`), kolom I (`Grand Total`) formula `="=E{$row}+H{$row}"`.
4. `ShouldAutoSize` untuk lebar kolom.

### 3.6 Tampilan "Invoice Detail" — Index, PDF, Excel

**Direct Sales invoices** (`workOrder === null`) **tetap tampil** di tampilan ini (konsisten dengan Laporan Invoice existing) — kolom Mekanik → `-` (via `optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-'`), Jasa/Sparepart tetap dipasangkan dari `$invoice->details` seperti biasa (Direct Sales bisa saja berisi baris `service` maupun `sparepart` — `InvoiceDetail` tidak bergantung pada `work_order_id`).

**Index (`reports/workshop-performance/index.blade.php`, blok `@if ($viewType === 'invoice_detail')`):** per invoice = 1 card (`class="card mb-3"`):
- Card header: 6 field meta dalam 1 baris (`No. Invoice`, `Tanggal`, `Status` [pakai status-badge existing], `Customer`, `Mekanik`, `Cabang`).
- Card body: tabel 11 kolom (`Jasa | Harga | Qty | Diskon % | Subtotal` ×2 + `Subtotal Line`), baris dari `WorkshopPerformanceLinePairer::build($invoice)`, ditutup baris `<tfoot>` "Total" (`SUM` PHP dari kolom `jasa_subtotal`/`sparepart_subtotal`/`subtotal_line`).

**PDF (`reports/workshop-performance/pdf.blade.php`, blok `@if ($viewType === 'invoice_detail')`):** struktur block-per-invoice yang sama direplikasi sebagai HTML table bersarang (bukan 1 tabel flat lintas-invoice seperti laporan lain, karena baris meta + pairing + total membentuk unit visual per invoice) — pola: `@foreach ($invoices as $invoice)` → 1 `<table class="print-table mb-2">` kecil berisi baris meta + header 11 kolom + baris pairing + baris Total, diulang per invoice.

**Excel (`app/Exports/WorkshopPerformanceInvoiceDetailExport.php`):** sama seperti versi PDF — bukan 1 tabel flat, tapi block berulang per invoice (identik struktur file contoh: baris meta 6-kolom → baris header 11-kolom → N baris pairing dengan formula `Jasa Subtotal = Harga*Qty*(1-Diskon/100)`, `Sparepart Subtotal = Harga*Qty*(1-Diskon/100)`, `Subtotal Line = SparepartSubtotal+JasaSubtotal` → baris `Total` dengan formula `SUM()` masing-masing kolom Subtotal + `Subtotal Line Total = Sparepart Total+Jasa Total`). Karena struktur block variable-height per invoice, export ini juga memakai pola `FromArray` (`[]`) + seluruh sheet ditulis manual di `AfterSheet`, iterasi baris invoice yang sama dengan yang dipakai controller (`->limit(1001)->get()`, di-cap `capRows(1000)` — pola sama seperti PDF, karena penulisan manual per-baris tidak kompatibel dengan `WithChunkReading` milik Maatwebsite yang mengasumsikan 1 baris = 1 record Eloquent linear).

**Formula tepat per baris pairing** (baris relatif `$r`, kolom sesuai urutan `A:K` seperti file contoh):
- `E{$r} = B{$r}*C{$r}*(1-D{$r}/100)` (Subtotal Jasa)
- `J{$r} = G{$r}*H{$r}*(1-I{$r}/100)` (Subtotal Sparepart)
- `K{$r} = J{$r}+E{$r}` (Subtotal Line)

**Baris Total per blok** (baris `$t`, range pairing `$first`–`$last`):
- `E{$t} = SUM(E{$first}:E{$last})`
- `J{$t} = SUM(J{$first}:J{$last})`
- `K{$t} = J{$t}+E{$t}`

## 4. Catatan Desain Penting

1. **Excel "Tampilan Mekanik" bukan formula murni end-to-end.** Kolom Total Qty/Discount/Subtotal Jasa & Sparepart ditulis sebagai **nilai hasil agregasi PHP** (bukan formula Excel), karena tidak ada kolom "harga kotor sebelum diskon" yang nyata untuk dijadikan sumber formula (angka `600000`/`980000` pada file contoh user tampak sekadar data contoh acak, bukan turunan kolom lain yang tersedia). Hanya **Grand Total** yang jadi formula asli (`=Subtotal Jasa + Subtotal Sparepart`), karena itu satu-satunya relasi antar-kolom yang benar-benar valid dan sesuai pola file contoh (`I3='=H3+E3'`).
2. **Excel "Tampilan Invoice Detail" pakai formula penuh**, karena semua komponennya (`Harga`, `Qty`, `Diskon %`) memang kolom nyata di baris yang sama — formula `Subtotal = Harga*Qty*(1-Diskon/100)` valid dan bisa direplikasi persis seperti file contoh.
3. **Nomor baris absolut** untuk formula Excel di kedua export dihitung manual saat iterasi (`$currentRow` counter yang di-increment tiap `setCellValue`), bukan lewat `WithMapping`.
4. **`mechanic_name`/`mechanic_nip` hasil query builder mentah** (bukan Eloquent model `Mechanic`) — label gabungan `"{nip} - {name}"` tidak bisa memakai `Mechanic::getDisplayLabelAttribute()` langsung (karena baris hasil `select()` bukan instance `Mechanic`). Logic yang identik (`$nip ? "{$nip} - {$name}" : $name`) ditulis inline (ternary satu baris) di 3 titik pemakaian — index Blade (`@php`), PDF Blade (`@php`), dan Excel `AfterSheet` writer — tanpa helper baru, karena masing-masing titik sudah punya akses langsung ke `mechanic_nip`/`mechanic_name` dari row/objek yang sama dan logic-nya cukup pendek untuk tidak butuh abstraksi (pola yang sama seperti duplikasi `optional(...)->display_label ?? '-'` yang sudah ada di beberapa tempat pada `InvoiceReportController`/views terkait).

## 5. Edge Cases

1. **Mekanik tanpa invoice sama sekali dalam rentang filter:** tidak muncul di Tampilan Mekanik (konsekuensi alami `INNER JOIN` — bukan bug, sesuai pola "hanya tampilkan yang punya aktivitas").
2. **Invoice Direct Sales:** dikecualikan otomatis dari Tampilan Mekanik (tidak ada `work_order_id` untuk di-`JOIN`), tapi tetap tampil di Tampilan Invoice Detail dengan Mekanik `-` (lihat §3.6).
3. **Invoice tanpa baris Jasa maupun Sparepart sama sekali** (draft kosong yang lolos filter): Tampilan Invoice Detail menampilkan 1 baris placeholder `-`/0, baris Total tetap muncul dengan nilai 0.
4. **Jumlah Jasa ≠ jumlah Sparepart dalam 1 invoice:** sisi yang lebih pendek diisi `-` (deskripsi) dan `0` (harga/qty/diskon/subtotal) — persis seperti file contoh (invoice pertama: 1 Jasa vs 2 Sparepart → baris ke-2 kolom Jasa jadi `-`/0/0/0).
5. **Mekanik tanpa `nip`:** label tampil nama saja (fallback sama seperti `Mechanic::display_label`).
6. **PDF Tampilan Invoice Detail dengan banyak invoice:** truncation 1000 baris (`capRows`) mengikuti pola existing, pesan "Data melebihi 1.000 baris" sama seperti laporan lain.

## 6. Testing Strategy

- **`WorkshopPerformanceLinePairerTest.php`** (baru): unit test helper pairing — jumlah Jasa = Sparepart, Jasa > Sparepart, Sparepart > Jasa, invoice tanpa line item sama sekali.
- **`WorkshopPerformanceReportControllerTest.php`** (baru): pola sama seperti `InvoiceReportControllerTest`/`PkbReportControllerTest` — `grantBranchPermission()` lokal, scenario builder mirip `makeInvoice()`. Test: akses ditolak tanpa permission (`report.workshop_performance.view`), Tampilan Mekanik menampilkan agregat benar (Total Customer distinct, Total Qty, Total Discount, Subtotal, Grand Total) untuk 2 mekanik berbeda dengan beberapa invoice, filter branch/status/tanggal bekerja, mekanik tanpa invoice tidak muncul, Direct Sales invoice tidak mempengaruhi agregat Mekanik. Tampilan Invoice Detail: pairing Jasa/Sparepart benar termasuk sisi yang lebih pendek, Direct Sales invoice tampil dengan Mekanik `-`.
- **`WorkshopPerformanceReportExportTest.php`** (baru): pola sama seperti `InvoiceReportExportTest` — PDF preview/download disposition header, PDF preview kedua tampilan menampilkan data yang benar via `extractPdfText()` (dengan `preg_replace('/\s+/', ' ', ...)` proaktif karena tabel banyak kolom), export Excel `content-type` check, dan minimal 1 test yang membaca sel Excel hasil export via `PhpOffice\PhpSpreadsheet\IOFactory::load()` untuk memverifikasi formula tertulis benar (bukan cuma isi/value) di kedua export — mis. assert `getCell('I3')->getValue()` mengandung string formula `=E3+H3` pada export Mekanik, dan `=B5*C5*(1-D5/100)` pada export Invoice Detail.
- Full regression: `php artisan test` di akhir milestone.

## 7. Out of Scope

- Tidak ada perubahan pada laporan existing (PKB, Invoice, Piutang, PKB vs Invoice, Sparepart Stock) — murni laporan baru.
- Tidak ada perubahan skema database.
- Tidak ada drill-down/link dari baris agregat Mekanik ke daftar invoice terkait (baru sebatas angka agregat, sesuai kolom yang diminta).
- Tidak ada grafik/chart — murni tabel, konsisten dengan seluruh laporan lain di aplikasi ini.
