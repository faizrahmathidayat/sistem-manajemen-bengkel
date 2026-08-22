# Laporan Laba Rugi (Laba Kotor via Weighted Average Cost) — Design Spec

**Date:** 2026-08-22
**Status:** Draft

## 1. Background

Kebutuhan: laporan yang menunjukkan margin penjualan (Pendapatan − HPP). User secara eksplisit menyepakati (lihat diskusi sebelum spec ini) beberapa keputusan skenario yang membentuk scope:

- **Istilah di UI/menu: "Laporan Laba Rugi".** Tapi perhitungan aktualnya adalah **Laba Kotor** (Pendapatan − HPP), **TANPA** biaya operasional (gaji, listrik, sewa, dll) — sistem ini tidak punya modul biaya operasional sama sekali. Ini didokumentasikan eksplisit di sini supaya developer lain tidak salah paham label ini sudah termasuk biaya operasional.
- **Metode HPP: Weighted Average Cost (WAC).** Sparepart tidak dilacak per-batch (bukan FIFO) — satu angka rata-rata tertimbang berjalan per sparepart per cabang, direkalkulasi tiap ada barang masuk berharga.
- **Saldo awal HPP** (untuk stok yang sudah ada sebelum fitur ini aktif): **direkonstruksi lewat replay historis** dari seluruh `inventory_movements` + `goods_receipt_lines` sejak transaksi pertama — bukan sekadar ambil harga beli terakhir.
- **Stock Adjustment positif** (`adjustment_in`) **tidak mengubah** `average_cost` (tidak ada harga sumber yang valid untuk direkalkulasi). Berlaku juga untuk `transfer_in` dengan alasan yang sama (`stock_transfer_lines` tidak punya kolom harga).
- **Invoice yang dihitung sebagai pendapatan:** status Diposting, Dibayar Sebagian, Lunas. Draft & Dibatalkan dikecualikan.
- **Jasa ikut dihitung**, HPP jasa = 0 (representasi wajar untuk bengkel — jasa tidak beli barang). Breakdown laporan tetap memisahkan Margin Jasa vs Margin Sparepart.
- **Level detail:** toggle Ringkasan (per periode/cabang) & Detail per Invoice — pola sama persis `WorkshopPerformanceReportController`.

## 2. Codebase Audit (ringkasan)

- **Skema stok saat ini** (`sparepart_branch_stocks`): PK `sparepart_branch_id` (bukan auto-increment), kolom `on_hand_qty`, `reserved_qty`. **Tidak ada kolom cost.** Model `SparepartBranchStock` (`app/Models/SparepartBranchStock.php`) — `$fillable = ['sparepart_branch_id', 'on_hand_qty', 'reserved_qty']`, cast `decimal:3`.
- **Tidak ada cost tracking di mana pun.** `spareparts`/`sparepart_branches` hanya punya `selling_price` (harga jual, bukan HPP). `goods_receipt_lines.purchase_price` cuma riwayat transaksi, bukan running cost.
- **3 titik "barang masuk" yang perlu hook WAC** (semua sudah pakai pola locking `SparepartBranchStock::...->lockForUpdate()` dalam `DB::transaction`, jadi aman untuk ditambah mutasi `average_cost` di baris yang sama):
  1. `GoodsReceiptController::post()` (`app/Http/Controllers/GoodsReceiptController.php:174-193`) — **satu-satunya titik yang punya harga** (`$line->qty`, `$line->purchase_price` dari `GoodsReceiptLine`).
  2. `StockAdjustmentController::post()` (`app/Http/Controllers/StockAdjustmentController.php:294-306`) — `adjustment_in` saat `$delta > 0`, **tidak ada harga**.
  3. `StockTransferController::receive()` (`app/Http/Controllers/StockTransferController.php:380-391`) — `transfer_in`, **tidak ada harga** (`stock_transfer_lines` tidak punya kolom price, hanya `qty`).
- **1 titik "barang keluar berbayar"**: `InvoiceService::postInvoice()` (`app/Services/InvoiceService.php:348-447`) — loop kedua (baris 416-444) yang membuat `InventoryMovement` tipe `USAGE_OUT` per `InvoiceDetail` bertipe `sparepart`. **Ini titik yang tepat untuk snapshot HPP** ke `invoice_details.hpp_snapshot`, karena di sinilah stok benar-benar dikurangi dan `average_cost` saat itu final untuk baris tersebut. Detail bertipe `service` tidak melalui loop ini sama sekali (jasa tidak menyentuh stok) — HPP jasa otomatis 0 lewat default kolom (lihat §3.1), tidak perlu langkah eksplisit tambahan.
- **`invoice_details.line_total` sudah NET setelah diskon, dan TIDAK termasuk PPN** (PPN dihitung di level invoice, `invoices.tax_amount`, bukan per baris) — dikonfirmasi dari spec `2026-08-11-workshop-performance-report-design.md` §Codebase Audit. Ini penting: **Pendapatan Usaha untuk Laba Kotor = `SUM(invoice_details.line_total)`, TIDAK termasuk PPN** (PPN adalah pungutan pajak untuk negara, bukan pendapatan usaha).
- **`inventory_movements`** (`app/Models/InventoryMovement.php`) — setiap baris punya `movement_type` (`App\Support\InventoryMovementType`: `RECEIPT`, `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`, `TRANSFER_OUT`, `TRANSFER_IN`, `USAGE_OUT`), `qty_in`/`qty_out`, **`balance_after`** (qty on-hand setelah movement ini — sudah tersimpan per baris, tidak perlu dihitung ulang), `reference_type`/`reference_id` (polymorphic, `reference_type='goods_receipt_line'` → `GoodsReceiptLine::find($reference_id)->purchase_price` untuk movement `RECEIPT`).
- **Tidak ada precedent `app/Console/Commands`** di proyek ini (direktori belum ada) — backfill saldo awal HPP akan ditulis sebagai **data migration** (logic langsung di `up()` migration baru), bukan artisan command baru, supaya konsisten (tidak memperkenalkan pola baru yang belum ada).
- **Pola laporan existing** (`WorkshopPerformanceReportController`, `InvoiceReportController`, dll) — `index()` (toggle via query param), `exportExcel()`, `previewPdf()`/`downloadPdf()` lewat trait `HandlesReportExport` (`app/Http/Controllers/Concerns/HandlesReportExport.php`: `authorizeExport()`, `capRows()`, `streamPdf()`). Filter `branch_ids[]`, `date_from`/`date_to`, `status`. **Laporan ini mengikuti pola yang identik.**
- **Migrasi**: konvensi nama `YYYY_MM_DD_NNNNNN_description.php` (lihat `database/migrations/2026_08_12_000001_add_pin_and_hash_id_to_invoices_table.php` sebagai contoh terbaru).
- **Permission/menu**: `MenuPermissionSeeder` (`database/seeders/MenuPermissionSeeder.php:240-290`) — pola `report.<slug>.view`. Sidebar (`resources/views/partials/sidebar.blade.php`) — link dibungkus `@if ($user->branchesWithPermission('report.<slug>.view')->isNotEmpty())`.

## 3. Design

### 3.1 Skema Database & Model

**Migrasi baru #1** — `average_cost` di `sparepart_branch_stocks`:
```php
Schema::table('sparepart_branch_stocks', function (Blueprint $table) {
    $table->decimal('average_cost', 18, 2)->default(0)->after('reserved_qty');
});
```
Update `SparepartBranchStock::$fillable` tambah `'average_cost'`, cast `'average_cost' => 'decimal:2'`.

**Migrasi baru #2** — `hpp_snapshot` di `invoice_details`:
```php
Schema::table('invoice_details', function (Blueprint $table) {
    $table->decimal('hpp_snapshot', 18, 2)->default(0)->after('line_total');
});
```
Default `0` (bukan nullable) — baris `service` otomatis HPP 0 tanpa langkah tambahan; baris `sparepart` di-update eksplisit saat posting (§3.3). Update `InvoiceDetail::$fillable` tambah `'hpp_snapshot'`, cast `'hpp_snapshot' => 'decimal:2'`.

### 3.2 `InventoryCostService` — WAC Engine

Service baru `App\Services\InventoryCostService`, satu method murni (pure function, mudah di-unit-test) dipakai di semua titik rekalkulasi:

```php
namespace App\Services;

class InventoryCostService
{
    /**
     * Rumus weighted average cost standar. $qtyBefore/$avgCostBefore adalah
     * kondisi SEBELUM barang masuk ini; $qtyIn/$unitCost adalah barang yang masuk.
     * Dipanggil oleh caller yang sudah lockForUpdate() baris stock-nya sendiri —
     * service ini tidak melakukan query/lock apa pun.
     */
    public function recalculateOnReceipt(float $qtyBefore, float $avgCostBefore, float $qtyIn, float $unitCost): float
    {
        $qtyAfter = $qtyBefore + $qtyIn;

        if ($qtyAfter <= 0.0) {
            return $unitCost;
        }

        return (($qtyBefore * $avgCostBefore) + ($qtyIn * $unitCost)) / $qtyAfter;
    }
}
```

Tidak ada method untuk kasus "tidak berubah" (`adjustment_in`/`transfer_in`/semua movement keluar) — caller cukup TIDAK memanggil service ini sama sekali di titik itu (qty berubah, `average_cost` dibiarkan seperti sebelumnya).

### 3.3 Hook Points — Integrasi ke Controller Existing

**1. `GoodsReceiptController::post()`** (`app/Http/Controllers/GoodsReceiptController.php:174-193`) — tambahkan pemanggilan service SEBELUM `$stock->save()`:
```php
$qtyBefore = (float) $stock->on_hand_qty;      // sebelum ditambah $line->qty
$avgBefore = (float) $stock->average_cost;
$stock->average_cost = app(InventoryCostService::class)->recalculateOnReceipt(
    $qtyBefore, $avgBefore, (float) $line->qty, (float) $line->purchase_price
);
$stock->on_hand_qty += $line->qty;
$stock->save();
```
(Urutan baca `on_hand_qty` SEBELUM increment penting — service butuh qty SEBELUM barang masuk ini.)

**2. `StockAdjustmentController::post()`** (baris 294-306) — **tidak ada perubahan kode di sini** untuk `average_cost` (keputusan: `adjustment_in` tidak mengubah rata-rata). Hanya dicatat sebagai no-op eksplisit di komentar kode saat implementasi, supaya jelas ini disengaja bukan terlewat.

**3. `StockTransferController::receive()`** (baris 380-391) — **tidak ada perubahan** untuk `average_cost` juga, alasan sama (tidak ada kolom harga di `stock_transfer_lines`).

**4. `InvoiceService::postInvoice()`** (baris 416-444, loop "Pass 2") — tambahkan SEBELUM `$stock->on_hand_qty -= $detail->qty;`:
```php
$detail->hpp_snapshot = (float) $stock->average_cost;
$detail->save();

$stock->on_hand_qty -= $detail->qty;
$stock->save();
```
(`average_cost` TIDAK diubah di sini — barang keluar tidak mengubah rata-rata, sesuai definisi WAC. `$stock` sudah di-lock di Pass 1, jadi nilai `average_cost` yang dibaca di sini konsisten.)

### 3.4 Migrasi Backfill Saldo Awal HPP

**Migrasi baru #3** — data migration murni, dijalankan SEKALI setelah migrasi #1 (`average_cost` kolom sudah ada). Algoritma per `sparepart_branch_id` yang punya minimal 1 baris `inventory_movements`:

1. Ambil semua movement untuk `sparepart_branch_id` ini, `orderBy('movement_at')->orderBy('id')` (urutan kronologis, `id` sebagai tie-breaker untuk movement dengan timestamp identik).
2. `$avgCost = 0.0`.
3. Untuk tiap movement:
   - Jika `movement_type === RECEIPT`: ambil `purchase_price` dari `GoodsReceiptLine::find($movement->reference_id)` (batch-load semua goods_receipt_line yang relevan di awal per sparepart_branch_id, hindari N+1 query per baris). `$qtyBefore = $movement->balance_after - $movement->qty_in` (memanfaatkan `balance_after` yang sudah tersimpan per movement — tidak perlu akumulasi qty manual). `$avgCost = InventoryCostService::recalculateOnReceipt($qtyBefore, $avgCost, $movement->qty_in, $purchasePrice)`.
   - Jika movement type lain (`ADJUSTMENT_IN`, `ADJUSTMENT_OUT`, `TRANSFER_OUT`, `TRANSFER_IN`, `USAGE_OUT`): `$avgCost` tidak berubah.
4. Setelah loop selesai: `SparepartBranchStock::where('sparepart_branch_id', $id)->update(['average_cost' => round($avgCost, 2)])`.
5. `sparepart_branch_id` yang TIDAK punya movement sama sekali (stok baru dikonfigurasi, belum pernah ada Penerimaan Barang): tetap `average_cost = 0` (default kolom), tidak perlu langkah tambahan.

Dijalankan dalam `DB::transaction()` per batch (chunk per 100 `sparepart_branch_id` misalnya) supaya tidak menahan 1 transaksi raksasa terlalu lama di database production.

### 3.5 Routing, Permission, Menu

- **Controller:** `App\Http\Controllers\GrossProfitReportController` (nama class bahasa Inggris, label UI bahasa Indonesia — konsisten dengan `WorkshopPerformanceReportController` yang menampilkan "Laporan Performance Bengkel").
- **Routes** (grup `reports` existing):
  ```php
  Route::get('/gross-profit', [GrossProfitReportController::class, 'index'])->name('gross-profit.index');
  Route::get('/gross-profit/export-excel', [GrossProfitReportController::class, 'exportExcel'])->name('gross-profit.export-excel');
  Route::get('/gross-profit/pdf-preview', [GrossProfitReportController::class, 'previewPdf'])->name('gross-profit.pdf-preview');
  Route::get('/gross-profit/pdf-download', [GrossProfitReportController::class, 'downloadPdf'])->name('gross-profit.pdf-download');
  ```
- **Permission:** `report.gross_profit.view`, entry baru di `MenuPermissionSeeder` (ditempatkan setelah entry `reporting.sparepart`):
  ```php
  [
      'code' => 'reporting.gross_profit',
      'name' => 'Laporan Laba Rugi',
      'is_branch_scoped' => true,
      'permissions' => [
          ['code' => 'report.gross_profit.view', 'resource' => 'report', 'action' => 'gross_profit.view', 'description' => 'Melihat laporan laba rugi'],
      ],
  ],
  ```
- **Sidebar:** link baru di blok "Reporting" (`resources/views/partials/sidebar.blade.php`), pola identik link laporan lain, kondisi gabungan pembuka blok juga ditambah.

### 3.6 Filter Bar

Identik `WorkshopPerformanceReportController`:

| Param | UI | Keterangan |
|---|---|---|
| `branch_ids[]` | Multi-select cabang | Cabang yang diizinkan user (`branchesWithPermission('report.gross_profit.view')`) |
| `date_from` / `date_to` | Input tanggal | Filter ke `invoices.invoice_date` |
| `view_type` | Dropdown "Tampilan": `summary` (default) / `invoice_detail` | — |

**Tidak ada filter `status`** — berbeda dari laporan lain, karena status invoice yang dihitung sudah FIXED (Posted, Dibayar Sebagian, Lunas) sesuai keputusan §1, bukan pilihan user (supaya angka laba tidak bisa "digeser" secara tidak sengaja dengan memasukkan Draft).

### 3.7 Query Design

**Base filter** — selalu di `invoices`: `whereIn('status', [POSTED, PARTIALLY_PAID, PAID])`, plus branch/tanggal.

**Tampilan Ringkasan** (`view_type=summary`) — agregat per bulan per cabang:
```php
Invoice::query()
    ->join('invoice_details', 'invoice_details.invoice_id', '=', 'invoices.id')
    ->whereIn('invoices.branch_id', $permittedBranches->pluck('id'))
    ->whereIn('invoices.status', [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID])
    ->when(...) // branch_ids, date_from, date_to — pola sama seperti laporan lain
    ->groupBy('invoices.branch_id', DB::raw("DATE_FORMAT(invoices.invoice_date, '%Y-%m')"))
    ->select([
        'invoices.branch_id',
        DB::raw("DATE_FORMAT(invoices.invoice_date, '%Y-%m') as period"),
        DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'service' THEN invoice_details.line_total ELSE 0 END), 0) as pendapatan_jasa"),
        DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'sparepart' THEN invoice_details.line_total ELSE 0 END), 0) as pendapatan_sparepart"),
        DB::raw("COALESCE(SUM(invoice_details.qty * invoice_details.hpp_snapshot), 0) as total_hpp"),
    ])
    ->orderBy('period')->orderBy('invoices.branch_id');
```
`total_hpp` dihitung uniform tanpa `CASE WHEN item_type` — karena `hpp_snapshot` jasa SELALU 0 (§3.1), `qty * hpp_snapshot` otomatis 0 untuk baris jasa. Laba Kotor & Margin % dihitung di PHP: `laba_kotor = pendapatan_jasa + pendapatan_sparepart - total_hpp`, `margin_persen = pendapatan > 0 ? laba_kotor / pendapatan * 100 : 0`.

**Tampilan Detail per Invoice** (`view_type=invoice_detail`) — 1 baris per invoice: `Invoice::query()` dengan base filter yang sama (TANPA join, pakai `->withSum` atau eager-load `details` lalu hitung di PHP — pola sama seperti `InvoiceReportController`), kolom: No. Invoice, Tanggal, Cabang, Customer, Pendapatan Jasa, Pendapatan Sparepart, Total HPP, Laba Kotor, Margin %.

### 3.8 PDF & Excel Export

Pola identik `WorkshopPerformanceReportExport`/`InvoiceReportExport` — `App\Exports\GrossProfitSummaryExport` dan `App\Exports\GrossProfitInvoiceDetailExport`, keduanya `implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents` (nilai kolom statis hasil hitung PHP, bukan formula Excel — tidak ada kebutuhan formula lintas-sel di sini karena semua kolom sudah agregat hasil query, beda dari kasus `WorkshopPerformanceMechanicExport` yang butuh formula `Grand Total`). PDF lewat `layouts.print` (landscape) + trait `HandlesReportExport` (`capRows`, `streamPdf`) — pola baku.

## 4. Catatan Desain Penting

1. **Ini BUKAN Laba Rugi akuntansi standar.** Label UI "Laporan Laba Rugi" murni permintaan eksplisit user untuk kesederhanaan istilah — perhitungan aktual adalah Laba Kotor (Pendapatan − HPP). Tidak ada biaya operasional. Dicatat di §1 dan di komentar controller supaya tidak disalahpahami developer lain di kemudian hari.
2. **`hpp_snapshot` di-snapshot SEKALI saat posting, tidak berubah lagi setelahnya** — meskipun `average_cost` sparepart terkait berubah di kemudian hari (misal ada Penerimaan Barang baru), laporan untuk invoice yang SUDAH diposting tetap menunjukkan HPP pada saat transaksi itu terjadi (histori tidak retroaktif berubah). Ini konsisten dengan prinsip "invoice yang sudah final tidak boleh berubah angkanya".
3. **PPN dikecualikan dari Pendapatan** — `invoice_details.line_total` sudah tidak termasuk PPN (PPN dihitung di level invoice), jadi tidak perlu langkah eksplisit untuk "mengeluarkan" PPN dari perhitungan — secara alami sudah tidak ikut.
4. **`transfer_in` diperlakukan sama seperti `adjustment_in`** (tidak mengubah `average_cost`) meski user hanya eksplisit ditanya soal `adjustment_in` — perluasan ini konsisten karena akar masalahnya sama (`stock_transfer_lines` tidak punya kolom harga) dan sudah disampaikan ke user saat diskusi tanpa keberatan.
5. **Invoice Direct Sales** (`work_order_id IS NULL`) **tetap ikut dihitung** — `invoice_details` tidak bergantung pada `work_order_id`, jadi tidak perlu perlakuan khusus (konsisten dengan laporan Invoice/Performance existing yang juga mengikutkan Direct Sales).
6. **Backfill migration idempotent tidak diperlukan** — ini migrasi satu kali yang dijalankan sekali saat deploy fitur ini (bagian dari `php artisan migrate` normal), tidak didesain untuk dijalankan ulang. Kalau perlu dijalankan ulang (misal data korup), harus manual re-run lewat query langsung, bukan re-migrate.

## 5. Edge Cases

1. **Sparepart baru dikonfigurasi, belum pernah ada Penerimaan Barang, langsung dijual** (kalau stok 0 tapi entah bagaimana ada baris invoice — seharusnya tidak mungkin lolos validasi stok existing, tapi kalau `average_cost` masih 0 karena belum pernah ada `RECEIPT`): `hpp_snapshot = 0`, margin sparepart itu tampak 100% — bukan bug, representasi jujur dari data yang tersedia (tidak ada harga beli yang bisa dijadikan acuan).
2. **Invoice hanya berisi jasa (tanpa sparepart)**: `total_hpp = 0`, Laba Kotor = Pendapatan penuh, Margin 100% — bukan bug, memang jasa tidak punya HPP.
3. **Rentang tanggal filter memotong tengah bulan**: Tampilan Ringkasan tetap grouping per bulan kalender penuh (`DATE_FORMAT %Y-%m`) berdasarkan invoice yang LOLOS filter tanggal — bulan yang cuma sebagian ter-filter akan menampilkan angka parsial untuk bulan itu (sama seperti perilaku laporan lain yang grouping per periode).
4. **Backfill: movement dengan `reference_id` yang `GoodsReceiptLine`-nya sudah tidak ada** (seharusnya tidak mungkin, tidak ada hard-delete di `goods_receipt_lines`) — dijaga dengan `firstOrFail()` yang akan melempar exception dan menghentikan migrasi kalau data tidak konsisten (fail-fast, lebih baik ketahuan saat deploy daripada silent salah hitung).
5. **`average_cost` presisi pembulatan** — disimpan `decimal(18,2)` sama seperti `unit_price` lain, dibulatkan di setiap rekalkulasi (`round(..., 2)`) supaya tidak ada drift floating-point yang terakumulasi lintas ratusan transaksi.

## 6. Testing Strategy

- **`InventoryCostServiceTest.php`** (baru): unit test murni `recalculateOnReceipt()` — kasus stok kosong (avg = harga masuk), stok existing + masuk lagi (rumus WAC manual dihitung ulang di assertion), qty masuk desimal presisi 3 digit.
- **`GoodsReceiptManagementTest.php`** (tambah test): posting Penerimaan Barang memperbarui `average_cost` sesuai rumus WAC untuk sparepart yang sudah punya stok, dan untuk sparepart baru (stok 0 → avg = harga beli pertama).
- **`StockAdjustmentManagementTest.php`** (tambah test): posting adjustment positif TIDAK mengubah `average_cost` (hanya `on_hand_qty`).
- **`StockTransferManagementTest.php`** (tambah test): receive transfer TIDAK mengubah `average_cost` di cabang tujuan.
- **`InvoiceControllerTest.php`/`InvoicePostingTest.php`** (tambah test): posting invoice menyimpan `hpp_snapshot` sesuai `average_cost` saat itu untuk baris sparepart, dan `hpp_snapshot = 0` untuk baris jasa; `average_cost` sparepart TIDAK berubah setelah invoice diposting.
- **Migration backfill test** (baru, jalankan migrasi di test database `RefreshDatabase` lalu assert hasil): skenario multi-Penerimaan-Barang berurutan untuk 1 sparepart (avg hasil replay = WAC manual dihitung), skenario campuran RECEIPT + ADJUSTMENT_IN + USAGE_OUT (avg tidak berubah oleh 2 tipe terakhir), sparepart tanpa movement sama sekali (avg tetap 0).
- **`GrossProfitReportControllerTest.php`** (baru): akses ditolak tanpa permission, Tampilan Ringkasan menghitung benar (dengan invoice campuran jasa+sparepart, multi-status termasuk yang HARUS dikecualikan — Draft & Cancelled tidak ikut), Tampilan Detail per Invoice menampilkan Laba Kotor per invoice yang benar, filter branch/tanggal bekerja.
- **`GrossProfitReportExportTest.php`** (baru): PDF preview/download disposition header, PDF menampilkan angka benar, export Excel content-type & isi sel benar.
- Full regression: `php artisan test` di akhir milestone.

## 7. Out of Scope

- **Laba Rugi akuntansi penuh** (dengan biaya operasional: gaji, listrik, sewa, dll) — butuh modul biaya operasional terpisah, fase berikutnya kalau dibutuhkan (lihat §4.1).
- **Input harga manual untuk Stock Adjustment positif** — opsi ini sudah dipertimbangkan dan ditolak user demi kesederhanaan (tidak mengubah form/alur Stock Adjustment existing).
- **FIFO / batch costing** — tetap Weighted Average Cost, bukan pelacakan per-batch.
- **Laporan margin per sparepart/jasa individual** (produk mana yang paling untung) — sudah ditanyakan ke user dan tidak dipilih (user pilih opsi Ringkasan + Detail per Invoice, bukan breakdown per item).
- **Perubahan pada laporan existing** (PKB, Invoice, Piutang, Performance, Sparepart Stock) — murni fitur baru + kolom baru di skema, tidak ada laporan lain yang diubah.
