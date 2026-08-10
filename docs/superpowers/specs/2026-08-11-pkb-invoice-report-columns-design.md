# Penambahan Kolom Laporan PKB & Laporan Invoice — Design Spec

**Date:** 2026-08-11
**Status:** Approved

## 1. Background

User meminta penambahan kolom pada dua laporan yang sudah ada, di seluruh mode tampilannya (index rekap, index detail, PDF preview/download, Excel export):

- **Laporan PKB** (Rekap & Detail): kolom Cabang, Mekanik (`{kode mekanik} - {nama mekanik}`), Tahun Motor, Kilometer.
- **Laporan Invoice** (Rekap & Detail): kolom Cabang, Mekanik (`{kode mekanik} - {nama mekanik}`), dan Discount khusus di tampilan Detail.

Ini murni penambahan kolom pada laporan yang sudah ada — **tidak ada perubahan skema database**. Seluruh data sumber sudah tersedia:

- "Kode mekanik" = kolom `mechanics.nip`, ditambahkan pada milestone sebelumnya (`2026-08-11-mechanic-improvements-design.md`, sudah live/committed).
- "Tahun motor" = `vehicles.year`.
- "Kilometer" = `work_orders.odometer_km` (dicatat per-PKB saat pembuatan work order, bukan field master di tabel `vehicles`).
- "Discount" (Detail) = `invoice_details.discount_amount`, field diskon per baris dari milestone Invoice Improvements sebelumnya.

## 2. Codebase Audit (ringkasan)

- `PkbReportController` (`app/Http/Controllers/PkbReportController.php`) — `index()`, `exportExcel()`, `renderPdf()` semuanya sudah eager-load `['branch', 'customer', 'vehicle', 'mechanic', ...]`. **Tidak perlu perubahan query** untuk PKB — data mekanik/kendaraan/cabang sudah ter-load, tinggal ditampilkan.
- `InvoiceReportController` (`app/Http/Controllers/InvoiceReportController.php`) — `index()`/`exportExcel()`/`renderPdf()` saat ini hanya eager-load `['branch', 'customer']` (+`details` untuk mode detail). **Perlu ditambah** `workOrder.mechanic` ke eager-load di ketiga method, karena mekanik invoice hanya bisa diakses lewat `$invoice->workOrder->mechanic` (invoice tidak punya `mechanic_id` langsung).
- `Invoice::getIsDirectSaleAttribute()` sudah ada — invoice Direct Sales (`work_order_id IS NULL`) tidak punya `workOrder`, sehingga `$invoice->workOrder` bernilai `null`. Kolom Mekanik untuk baris ini akan tampil `-`.
- `Mechanic` model (`app/Models/Mechanic.php`) — fillable sudah punya `nip`, tapi belum ada accessor label gabungan `"{nip} - {name}"`. Perlu ditambahkan sebagai accessor baru, dipakai di seluruh 4 kombinasi laporan (PKB rekap/detail, Invoice rekap/detail) plus PDF & Excel masing-masing — total 8 titik pemakaian. Accessor menghindari duplikasi logic.
- View index: `resources/views/reports/pkb/index.blade.php` (2 tabel: rekap & detail, dipilih via `@if ($mode === 'detail')`), `resources/views/reports/invoices/index.blade.php` (pola sama).
- View PDF: `resources/views/reports/pkb/pdf.blade.php`, `resources/views/reports/invoices/pdf.blade.php` — extends `layouts.print`, tabel penuh lebar 100% dengan font 9px; menambah beberapa kolom tidak butuh perubahan layout (sudah terbukti aman saat kolom Diskon ditambahkan ke `invoices/print-pdf.blade.php` pada milestone sebelumnya).
- Excel export: `app/Exports/PkbReportExport.php`, `app/Exports/InvoiceReportExport.php` — pola `headings()` (array kolom) + `map($model)` (array nilai per kolom, harus sinkron urutan dengan `headings()`).
- Test suite existing: `PkbReportControllerTest.php`, `PkbReportExportTest.php`, `InvoiceReportControllerTest.php`, `InvoiceReportExportTest.php` — semua pakai `RefreshDatabase` + helper lokal `grantBranchPermission()` yang di-copy-paste per file (pola yang sudah mapan, akan diikuti).
- Kolom `colspan` pada baris empty-state di kedua index view perlu disesuaikan mengikuti jumlah kolom baru (lihat §4.5).

## 3. Design

### 3.1 Accessor label mekanik — `app/Models/Mechanic.php`

```php
public function getDisplayLabelAttribute(): string
{
    return $this->nip ? "{$this->nip} - {$this->name}" : $this->name;
}
```

Fallback ke nama saja jika `nip` kosong (field nullable di level DB — lihat spec mechanic-improvements §3 poin 4), supaya tidak menampilkan `" - Nama"` dengan strip menggantung.

### 3.2 Laporan PKB

**Kolom baru & posisi** (disisipkan di antara kolom existing, urutan final):

- **Rekap:** No. PKB, **Cabang**, Tanggal, Customer & Kendaraan, Mekanik, **Tahun Motor**, **Kilometer**, Subtotal Jasa, Subtotal Sparepart, Grand Total, Status
- **Detail:** No. PKB, **Cabang**, Tanggal, Customer & Kendaraan, **Mekanik**, **Tahun Motor**, **Kilometer**, Tipe Item, Nama Item/Jasa, Qty, Harga Satuan, Subtotal Line, Status
  (Mekanik saat ini sama sekali tidak tampil di mode Detail — akan ditambahkan bersamaan)

**Sumber nilai per baris** (`$workOrder`):
- Cabang: `$workOrder->branch->name`
- Mekanik: `$workOrder->mechanic->display_label`
- Tahun Motor: `$workOrder->vehicle->year ?? '-'`
- Kilometer: `$workOrder->odometer_km ?? '-'` (mengikuti format apa-adanya yang sudah dipakai di `work-orders/show.blade.php:50` dan `work-orders/print-pdf.blade.php:61` — tanpa `number_format`, karena kolomnya decimal(12,1) dan nilai umumnya bulat)

**File yang diubah:**
- `resources/views/reports/pkb/index.blade.php` — kedua tabel (rekap & detail): tambah `<th>` + `<td>` sesuai urutan di atas.
- `resources/views/reports/pkb/pdf.blade.php` — kedua blok tabel (rekap & detail): sama.
- `app/Exports/PkbReportExport.php` — `headings()` dan `map()` kedua mode: sisipkan 4 kolom baru (rekap: Cabang/Tahun Motor/Kilometer di sekitar Mekanik yang sudah ada; detail: Cabang/Mekanik/Tahun Motor/Kilometer).
- `PkbReportController`: **tidak berubah** (eager-load sudah cukup).

### 3.3 Laporan Invoice

**Kolom baru & posisi:**

- **Rekap:** No. Invoice, **Cabang**, Tanggal, Customer, **Mekanik**, Subtotal Jasa, Subtotal Sparepart, Discount, Grand Total, Terbayar, Sisa Piutang, Status
  (kolom Discount di rekap **sudah ada** — level header invoice, `discount_amount` invoice — tidak disentuh)
- **Detail:** No. Invoice, **Cabang**, Tanggal, Customer, **Mekanik**, Tipe Item, Nama Item, Qty, Harga Satuan, **Diskon**, Subtotal Line, Status
  (kolom **Diskon** di sini baru — per baris `invoice_details.discount_amount`, ditempatkan sebelum Subtotal Line, mengikuti pola yang sudah dipakai di `invoices/show.blade.php` dan `invoices/print-pdf.blade.php` pada milestone Invoice Improvements)

**Sumber nilai per baris** (`$invoice`):
- Cabang: `$invoice->branch->name`
- Mekanik: `optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-'` (invoice Direct Sales tidak punya `workOrder` → tampil `-`)
- Diskon (Detail, per `$detail` dalam `$invoice->details`): `$detail->discount_amount > 0 ? number_format($detail->discount_amount, 0, ',', '.') : '-'`

**File yang diubah:**
- `app/Http/Controllers/InvoiceReportController.php` — tambah `workOrder.mechanic` ke eager-load di `index()` (baris `$invoices = $query->with(['branch', 'customer']);` → `->with(['branch', 'customer', 'workOrder.mechanic'])`), `exportExcel()`, dan `renderPdf()` (masing-masing `->with(['branch', 'customer', 'details'])` → tambah `'workOrder.mechanic'`).
- `resources/views/reports/invoices/index.blade.php` — kedua tabel: tambah kolom sesuai urutan di atas.
- `resources/views/reports/invoices/pdf.blade.php` — kedua blok tabel: sama.
- `app/Exports/InvoiceReportExport.php` — `headings()` dan `map()` kedua mode: tambah Cabang & Mekanik (rekap dan detail), tambah Diskon (detail saja, termasuk di baris placeholder untuk invoice tanpa detail).

### 3.4 Update `colspan` empty-state

Kolom bertambah di setiap tabel, sehingga `colspan` pada baris `@empty` / placeholder-no-lines harus disesuaikan:

| View | Mode | Colspan lama | Kolom ditambah | Colspan baru |
|---|---|---|---|---|
| `reports/pkb/index.blade.php` | Rekap | 8 | +3 (Cabang, Tahun Motor, Kilometer) | 11 |
| `reports/pkb/index.blade.php` | Detail | 9 | +4 (Cabang, Mekanik, Tahun Motor, Kilometer) | 13 |
| `reports/invoices/index.blade.php` | Rekap | 10 | +2 (Cabang, Mekanik) | 12 |
| `reports/invoices/index.blade.php` | Detail | 9 | +3 (Cabang, Mekanik, Diskon) | 12 |

(Baris placeholder "tidak ada baris" milik masing-masing PKB/invoice pada mode Detail — bukan empty-state seluruh tabel — juga perlu kolom baru ditambahkan ke sel `&mdash;`-nya secara eksplisit, bukan lewat `colspan`, supaya jumlah kolom tetap konsisten dengan header.)

## 4. Edge Cases

1. **Invoice Direct Sales (tanpa PKB):** kolom Mekanik → `-`. Sudah konsisten dengan pola existing (`invoices/show.blade.php` menampilkan `Direct Sales` untuk field PKB saat `workOrder` null).
2. **Mekanik tanpa `nip` (data lama sebelum backfill, atau baris test yang tidak set `nip`):** accessor `display_label` fallback ke nama saja, tanpa strip menggantung.
3. **Kendaraan tanpa `year` terisi:** kolom Tahun Motor → `-` (field `year` nullable di skema `vehicles`).
4. **PKB tanpa `odometer_km` terisi:** kolom Kilometer → `-`.
5. **Baris PKB/Invoice tanpa line item di mode Detail** (placeholder `&mdash;` row yang sudah ada): kolom Cabang/Mekanik/Tahun Motor/Kilometer/Diskon baru tetap terisi nilai sebenarnya (bukan `&mdash;`) karena data itu di level header PKB/Invoice, bukan level baris item — hanya kolom Tipe Item/Nama Item/Qty/Harga/Subtotal Line yang `&mdash;`.

## 5. Testing Strategy

- **`MechanicServiceModelTest.php`** (atau file model test mekanik yang relevan): test baru untuk `display_label` accessor — dengan `nip` terisi, dan fallback saat `nip` null.
- **`PkbReportControllerTest.php`**: test baru memverifikasi kolom Cabang, Mekanik (format `nip - name`), Tahun Motor, Kilometer tampil di mode Rekap dan mode Detail (assert nilai-nilai tersebut muncul di response, mengikuti pola `assertSee` yang sudah dipakai di seluruh file ini).
- **`PkbReportExportTest.php`**: perluas assertion existing (jika ada pemeriksaan konten baris Excel) atau tambah test baru untuk memastikan tidak ada regresi pada `content-type`/`disposition` setelah kolom bertambah; tambah test PDF preview yang memverifikasi teks Tahun Motor/Kilometer/Cabang muncul di hasil `extractPdfText()`.
- **`InvoiceReportControllerTest.php`**: test baru untuk kolom Cabang & Mekanik di kedua mode, test khusus Direct Sales invoice menampilkan `-` di kolom Mekanik, test kolom Diskon tampil hanya di mode Detail dengan nilai per-baris yang benar.
- **`InvoiceReportExportTest.php`**: test PDF preview baru yang memverifikasi Cabang/Mekanik/Diskon muncul di `extractPdfText()`; pastikan test export-excel existing (`content-type` check) tetap hijau tanpa perubahan.
- Full regression: `php artisan test` di akhir — pastikan tidak ada test lama yang gagal akibat pergeseran kolom (khususnya test yang menghitung jumlah kolom via struktur tabel, walau audit awal tidak menemukan test yang fragile terhadap `colspan` count).

## 6. Out of Scope

- Tidak ada penambahan kolom baru di luar yang diminta (mis. tidak menambah kolom Mekanik/Cabang ke laporan lain seperti Sparepart Stock Report atau Receivables Report).
- Tidak ada perubahan pada filter form (tidak ada filter baru berdasarkan Mekanik/Cabang untuk Invoice Report — filter Cabang multi-select sudah ada di kedua laporan).
- Tidak ada perubahan pada `LookupController::mechanics()` atau dropdown pemilihan mekanik di form PKB — itu sudah ditangani di milestone mechanic-improvements sebelumnya.
