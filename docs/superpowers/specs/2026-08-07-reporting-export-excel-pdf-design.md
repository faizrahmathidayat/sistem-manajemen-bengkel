# Export Excel & PDF untuk Seluruh Modul Reporting — Design

## 1. Tujuan

Menambahkan kemampuan Export Excel (.xlsx) dan PDF (Preview di tab baru + Download langsung) ke kelima laporan yang sudah aktif di proyek ini — Piutang, PKB, Invoice, Gap Invoice vs PKB, Sparepart/Stok — dengan hasil export yang **selalu mencerminkan filter yang sedang aktif** di halaman, tanpa mengubah query/otorisasi laporan yang sudah ada.

## 2. Dependency Baru (Keputusan Bersama Pengguna)

Proyek ini sebelumnya sengaja minim dependency (Bootstrap via CDN, tanpa build step) — ini kali pertama menambah package Composer baru sejak awal. Dikonfirmasi bersama pengguna:

- **`maatwebsite/excel` (^3.1)** — menghasilkan `.xlsx` asli (bukan CSV yang diganti nama), mendukung `FromQuery` + `WithChunkReading` untuk dataset besar tanpa membebani memori. Menarik `phpoffice/phpspreadsheet` sebagai dependency transitif.
- **`barryvdh/laravel-dompdf` (^2.0)** — dipilih di atas alternatif seperti `laravel-snappy` karena **tidak butuh binary eksternal** (`wkhtmltopdf`), yang tidak tersedia di lingkungan dev Windows/Laragon proyek ini. Murni PHP, langsung jalan setelah `composer require`.

Keduanya kompatibel dengan constraint proyek (`php: ^7.3|^8.0`, PHP runtime aktual 7.4.33, Laravel 8.75).

## 3. Koreksi Terhadap Asumsi di Instruksi Awal (ditemukan saat eksplorasi)

Parameter filter **tidak seragam** di kelima laporan — instruksi awal menyebut `mode, branch_ids, search, status, date_from, date_to, gap_status, stock_status` sebagai satu set umum, tapi kenyataannya:

| Laporan | Parameter filter sesungguhnya |
|---|---|
| Piutang | `branch_ids[]`, `customer` (bukan `search`), `status` (unpaid/paid/all), `date_from`, `date_to` — **tidak ada `mode`** (laporan ini single-view, bukan dual Rekap/Detail) |
| PKB | `branch_ids[]`, `mechanic` (search hanya nama mekanik), `status`, `date_from`, `date_to`, `mode` |
| Invoice | `branch_ids[]`, `search`, `status`, `date_from`, `date_to`, `mode` |
| Gap Invoice vs PKB | `branch_ids[]`, `search`, `gap_status`, `date_from`, `date_to`, `mode` |
| Sparepart/Stok | `branch_ids[]`, `search`, `stock_status`, `mode` — **tidak ada `date_from`/`date_to`** |

**Implikasi desain**: mekanisme "bawa filter aktif ke tombol export" TIDAK boleh menyusun ulang parameter satu-per-satu (rawan salah nama/hilang parameter, apalagi dengan variasi di atas) — sebagai gantinya, setiap tombol export cukup meneruskan `request()->query()` **apa adanya** ke route export via `route('reports.xxx.export-excel', request()->query())`. Ini otomatis benar untuk laporan mana pun tanpa peduli nama/jumlah parameternya, dan tidak pernah kadaluarsa jika suatu laporan menambah filter baru di masa depan.

## 4. Arsitektur

### 4.1 Refactor wajib: ekstraksi query-builder dari tiap controller

Setiap dari 5 controller laporan saat ini membangun query filter langsung di dalam `index()`. Agar 3 aksi export (Excel/Preview PDF/Download PDF) tidak menduplikasi logika filter yang sama 3x lagi per laporan (=15 blok duplikat), setiap controller diekstrak method protected baru, contoh untuk `InvoiceReportController`:

```php
protected function buildFilteredQuery(): Builder
{
    // isi persis logika filter yang sekarang ada di index(), tidak berubah sama sekali
}
```

`index()`, `exportExcel()`, `previewPdf()`, `downloadPdf()` semuanya memanggil `buildFilteredQuery()` yang sama — satu sumber kebenaran untuk apa yang termasuk dalam hasil, baik di halaman web maupun di file export. **Tidak ada perubahan pada hasil/perilaku `index()` yang sudah ada** — murni pemindahan kode, bukan penulisan ulang logika.

Untuk Laporan Gap yang punya algoritma perbandingan baris (`buildComparisonLines()`/`compareLine()`, saat ini method protected di `InvoicePkbGapReportController`) — diekstrak lebih jauh ke class stateless baru `app/Support/InvoicePkbGapComparator.php` supaya bisa dipakai baik oleh controller (untuk tampilan web) maupun `InvoicePkbGapExport` (untuk Excel) tanpa Export class harus bergantung ke instance Controller (pola yang tidak lazim di Laravel).

### 4.2 Struktur Excel Export (`app/Exports/`)

5 class baru, satu per laporan, masing-masing men-implementasi `FromQuery`, `WithHeadings`, `WithMapping`, `WithChunkReading` (chunk 500 baris), `ShouldAutoSize` dari `maatwebsite/excel`:

- `app/Exports/ReceivableReportExport.php`
- `app/Exports/PkbReportExport.php`
- `app/Exports/InvoiceReportExport.php`
- `app/Exports/InvoicePkbGapReportExport.php`
- `app/Exports/SparepartStockReportExport.php`

Setiap class menerima query hasil `buildFilteredQuery()` + parameter `mode` (untuk yang dual-mode) via constructor. `WithMapping` di tiap class mencerminkan **persis** kolom yang tampil di tabel Rekap/Detail masing-masing laporan (lihat bagian 6) — bukan re-desain kolom baru.

`FromQuery` (bukan `FromCollection`) dipakai secara sengaja — maatwebsite/excel akan menjalankan query dengan chunking otomatis di level database, bukan memuat seluruh hasil ke memori PHP sekaligus sebelum diekspor. Ini memenuhi syarat performa/memori dari instruksi awal tanpa kode chunking manual.

### 4.3 Struktur PDF Export

- **Layout cetak bersama**: `resources/views/layouts/print.blade.php` — CSS khusus cetak (ukuran A4, margin, tipografi), header berisi nama aplikasi ("Sistem Manajemen Bengkel" — string tetap, **bukan** `config('app.name')` karena `.env` masih default "Laravel" dan belum pernah diubah), ringkasan filter aktif (cabang/status/tanggal — dirender sebagai teks, bukan dropdown), area tabel (`@yield('table')`), dan footer "Dicetak oleh {{ auth()->user()->name }} pada {{ now()->translatedFormat(...) }}".
- **Orientasi**: **landscape untuk seluruh 5 laporan**, tanpa kecuali — dikonfirmasi dari jumlah kolom tabel riil (7–11 kolom di semua laporan, termasuk Piutang yang punya 10 kolom bahkan di mode satu-satunya) membuat portrait terlalu sempit di semua kasus.
- **5 template PDF** (`resources/views/reports/<nama>/pdf.blade.php`), masing-masing `@extends('layouts.print')`, isi tabel mencerminkan persis kolom Rekap/Detail aktif — sama seperti Excel, tidak ada desain kolom baru.
- **Batas baris PDF: 1.000 baris** (dikonfirmasi bersama pengguna) — jika hasil filter melebihi batas ini, PDF tetap dibuat berisi 1.000 baris pertama (urutan sama seperti tabel web) ditambah catatan "Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap." di atas tabel. Excel **tidak** dibatasi (selalu lengkap, berkat chunking).
- **Preview vs Download** hanya beda satu argumen: `Pdf::loadView(...)->stream($filename)` (inline, `Content-Disposition: inline`, buka tab baru) vs `->download($filename)` (`Content-Disposition: attachment`).

### 4.4 Tombol Export (UI bersama)

Partial baru `resources/views/partials/report-export-buttons.blade.php`, menerima 3 nama route (`excelRoute`, `pdfPreviewRoute`, `pdfDownloadRoute`) via parameter — dirender sebagai button group Bootstrap "Export ▾" di pojok kanan atas card filter tiap laporan:

```blade
<div class="btn-group">
    <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
        <i class="bi bi-download me-1"></i>Export
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="{{ route($excelRoute, request()->query()) }}">Export Excel</a></li>
        <li><a class="dropdown-item" href="{{ route($pdfPreviewRoute, request()->query()) }}" target="_blank">Preview PDF</a></li>
        <li><a class="dropdown-item" href="{{ route($pdfDownloadRoute, request()->query()) }}">Download PDF</a></li>
    </ul>
</div>
```

`request()->query()` diteruskan apa adanya (lihat bagian 3) — tombol ini selalu benar tanpa perlu tahu nama parameter spesifik laporan yang memanggilnya. Preview PDF dibuka `target="_blank"` (tab baru) sesuai instruksi.

## 5. Otorisasi

**Tidak ada kode permission baru.** Setiap dari 3 aksi export baru memanggil branch-scoped check yang **identik** dengan `index()` laporan yang sama:

```php
$permittedBranches = $user->branchesWithPermission('report.xxx.view');
if ($permittedBranches->isEmpty()) {
    abort(403);
}
```

(Beda dari `index()` yang redirect ke halaman no-access — aksi export mengembalikan `403` polos karena tidak ada "halaman" untuk ditampilkan.) Ini otomatis konsisten dengan bagian 4.1: karena `buildFilteredQuery()` sudah men-scope ke cabang yang diizinkan di awal, seorang user tidak akan pernah bisa mengekspor data cabang yang tidak ia punya akses — sama seperti halaman web-nya.

## 6. Pemetaan Kolom per Laporan (Export mencerminkan tabel web, tidak ada kolom baru)

| Laporan | Kolom Rekap | Kolom Detail |
|---|---|---|
| Piutang | No. Invoice, Tanggal, Customer, Cabang, Grand Total, Sudah Dibayar, Sisa Piutang, Jatuh Tempo, Umur Piutang, Status | *(tidak ada mode Detail)* |
| PKB | No. PKB, Tanggal, Customer & Kendaraan, Mekanik, Subtotal Jasa, Subtotal Sparepart, Grand Total, Status | No. PKB, Tanggal, Customer & Kendaraan, Tipe Item, Nama Item/Jasa, Qty, Harga Satuan, Subtotal Line, Status |
| Invoice | No. Invoice, Tanggal, Customer, Subtotal Jasa, Subtotal Sparepart, Discount, Grand Total, Terbayar, Sisa Piutang, Status | No. Invoice, Tanggal, Customer, Status, Tipe Item, Nama Item, Qty, Harga Satuan, Subtotal Line |
| Gap Invoice vs PKB | No. PKB, No. Invoice, Tanggal, Customer, Total PKB, Total Invoice, Selisih (Rp), Selisih (%), Status Gap | No. PKB, No. Invoice, Tanggal, Customer, Tipe Item, Nama Item, Qty PKB, Harga PKB, Qty Invoice, Harga Invoice, Kategori |
| Sparepart/Stok | Kode, Nama Sparepart, Cabang, Stok Min, Stok On-Hand, Nilai Inventaris, Status | Kode, Nama Sparepart, Cabang, Stok Min, On-Hand, Reserved, Available, Harga Satuan, Nilai Total, Status |

Baris kategori/status (badge berwarna di web) diekspor sebagai **teks polos** ("Sesuai"/"Kritis"/dst) di Excel dan PDF — tidak ada badge berwarna di file export, styling cukup bold/border tipis pada header tabel.

## 7. Ringkasan Filter di Header PDF & Sheet Excel

Setiap file export menampilkan ringkasan filter yang sedang aktif (bukan hanya data mentah), supaya file yang di-download tetap bermakna tanpa harus membuka aplikasi:
- **PDF**: baris teks di bawah header ("Cabang: Jakarta, Bandung · Status: Semua · Tanggal: 01/08/2026 – 07/08/2026 · Tampilan: Rekap")
- **Excel**: baris pertama sheet (sebelum header kolom) berisi ringkasan yang sama, di-merge-cell sepanjang lebar tabel.

## 8. Routes Baru (15 total, 3 per laporan)

Menggunakan prefix nama route yang sudah ada per laporan (tidak seragam — `receivables`, `pkb`, `invoices`, `invoice-pkb-gap`, `sparepart-stock`), pola akhiran sama untuk semua:

```
GET /reports/receivables/export-excel      → reports.receivables.export-excel
GET /reports/receivables/pdf-preview       → reports.receivables.pdf-preview
GET /reports/receivables/pdf-download      → reports.receivables.pdf-download
(pola yang sama untuk pkb, invoices, invoice-pkb-gap, sparepart-stock)
```

## 9. Ruang Lingkup yang Sengaja Dikecualikan

- **Tidak ada export terjadwal/email** — murni aksi manual dari tombol di halaman laporan.
- **Tidak ada logo gambar di PDF** — tidak ada asset logo di proyek ini saat ini; header PDF berupa teks nama aplikasi saja. Menambahkan logo adalah pekerjaan terpisah (butuh asset dari pengguna) di luar scope milestone ini.
- **Tidak ada export untuk halaman selain 5 laporan ini** (mis. daftar Invoice/PKB biasa, Kartu Stok) — scope tetap ketat sesuai 5 laporan yang diminta.
- **Tidak ada opsi format lain** (mis. .xls lama, .ods) — hanya .xlsx dan .pdf.

## 10. Implikasi untuk Implementation Plan

Mengingat besarnya scope (5 controller di-refactor + 5 Excel export class + 5 template PDF + 1 layout cetak bersama + 1 partial tombol + 15 route + otorisasi × 5), implementation plan yang akan disusun setelah ini **direkomendasikan dipecah per-laporan** (1 task infrastruktur bersama + 5 task per-laporan, atau setiap laporan sebagai satu task berisi refactor+Excel+PDF+test sekaligus) — bukan dikerjakan sebagai satu task raksasa. Keputusan pemecahan tepatnya akan ditentukan saat penyusunan plan, bukan di dokumen desain ini.
