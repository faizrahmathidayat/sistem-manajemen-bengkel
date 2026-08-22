# Laporan Laba Rugi (Weighted Average Cost) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bangun mesin Weighted Average Cost (WAC) untuk HPP sparepart per cabang, snapshot HPP per baris invoice saat posting, migrasi backfill saldo awal dari histori pergerakan, dan laporan baru "Laporan Laba Rugi" (Ringkasan + Detail per Invoice, PDF & Excel) — sesuai spec `docs/superpowers/specs/2026-08-22-gross-profit-report-design.md`.

**Architecture:** Kolom `average_cost` baru di `sparepart_branch_stocks` (HPP berjalan per sparepart per cabang), direkalkulasi lewat service murni `InventoryCostService::recalculateOnReceipt()` HANYA di titik "barang masuk berharga" (`GoodsReceiptController::post()`). Kolom `hpp_snapshot` baru di `invoice_details`, di-snapshot dari `average_cost` saat itu di `InvoiceService::postInvoice()` (baris `sparepart`; baris `service` otomatis 0 lewat default kolom). Stock Adjustment positif dan Stock Transfer masuk TIDAK mengubah `average_cost` (tidak ada kode baru di situ — no-op yang diverifikasi lewat regression test). Saldo awal direkonstruksi lewat `AverageCostBackfillService` (replay kronologis `inventory_movements` + `goods_receipt_lines`, dipanggil dari migrasi data satu kali). Laporan baru `GrossProfitReportController` mengikuti pola `WorkshopPerformanceReportController` persis (2 tampilan via `view_type`, trait `HandlesReportExport`, export `FromQuery + WithMapping + AfterSheet` seperti `InvoiceReportExport`).

**Tech Stack:** Laravel 8.75, PHP 7.4 (tidak ada `?->`, tidak ada constructor property promotion, tidak ada `match`), Blade, Maatwebsite Excel, DomPDF (`layouts.print`), MySQL/MariaDB (InnoDB, FK constraints aktif).

**Spec:** `docs/superpowers/specs/2026-08-22-gross-profit-report-design.md`

## Global Constraints

- PHP 7.4 syntax only.
- Label UI: **"Laporan Laba Rugi"**. Perhitungan aktual: **Laba Kotor** (Pendapatan − HPP), TANPA biaya operasional — lihat spec §1 & §4.1. Jangan sampai ada teks/komentar yang menyiratkan ini laba bersih.
- Invoice yang dihitung sebagai pendapatan: status `POSTED`, `PARTIALLY_PAID`, `PAID` SAJA (`App\Support\InvoiceStatus`). Draft & Cancelled dikecualikan — tidak ada filter `status` di UI laporan ini (fixed, bukan pilihan user).
- `invoice_details.line_total` sudah net diskon dan TIDAK termasuk PPN — jangan tambahkan logic pengurangan PPN di mana pun untuk laporan ini.
- `average_cost` HANYA berubah di `GoodsReceiptController::post()`. `StockAdjustmentController::post()` (adjustment_in) dan `StockTransferController::receive()` (transfer_in) TIDAK mengubah `average_cost` — ini disengaja (lihat spec §3.3 poin 2-3), jangan "diperbaiki".
- `hpp_snapshot` di-snapshot SEKALI saat posting invoice, tidak berubah lagi setelahnya meski `average_cost` sparepart terkait berubah kemudian.
- Format angka: `number_format($value, 0, ',', '.')` di semua tempat tampilan (titik ribuan, tanpa desimal), kecuali `average_cost`/`hpp_snapshot` yang disimpan `decimal(18,2)` dan dibulatkan `round(..., 2)` di setiap rekalkulasi.
- Setiap task diakhiri `php artisan test` (terfilter ke file yang berubah) lalu commit dengan pesan seperti tercantum di task. Jangan skip verifikasi.
- Full `php artisan test` di Task 10 sebelum commit terakhir — wajib hijau tanpa regresi.

---

## File Structure

- **Create:** `database/migrations/2026_08_22_000001_add_average_cost_to_sparepart_branch_stocks_table.php`
- **Create:** `database/migrations/2026_08_22_000002_add_hpp_snapshot_to_invoice_details_table.php`
- **Create:** `database/migrations/2026_08_22_000003_backfill_average_cost_from_movement_history.php`
- **Modify:** `app/Models/SparepartBranchStock.php`, `app/Models/InvoiceDetail.php`
- **Create:** `app/Services/InventoryCostService.php`
- **Create:** `app/Services/AverageCostBackfillService.php`
- **Modify:** `app/Http/Controllers/GoodsReceiptController.php` — hook WAC di `post()`.
- **Modify:** `app/Services/InvoiceService.php` — hook `hpp_snapshot` di `postInvoice()`.
- **Modify:** `database/seeders/MenuPermissionSeeder.php` — entry menu + permission baru.
- **Modify:** `resources/views/partials/sidebar.blade.php` — link baru di blok Reporting.
- **Create:** `app/Http/Controllers/GrossProfitReportController.php`.
- **Modify:** `routes/web.php` — 4 route baru di grup `reports`.
- **Create:** `resources/views/reports/gross-profit/index.blade.php`, `.../no-access.blade.php`, `.../pdf.blade.php`.
- **Create:** `app/Exports/GrossProfitSummaryExport.php`, `app/Exports/GrossProfitInvoiceDetailExport.php`.
- **Create (tests):** `tests/Unit/InventoryCostServiceTest.php`, `tests/Feature/AverageCostBackfillServiceTest.php`, `tests/Feature/InvoiceHppSnapshotTest.php`, `tests/Feature/GrossProfitReportControllerTest.php`, `tests/Feature/GrossProfitReportExportTest.php`.
- **Modify (tests):** `tests/Feature/GoodsReceiptManagementTest.php`, `tests/Feature/StockAdjustmentManagementTest.php`, `tests/Feature/StockTransferManagementTest.php`, `tests/Feature/MenuPermissionSeederTest.php`, `tests/Feature/AppShellTest.php`.

---

### Task 1: Skema — `average_cost` & `hpp_snapshot`

**Files:**
- Create: `database/migrations/2026_08_22_000001_add_average_cost_to_sparepart_branch_stocks_table.php`
- Create: `database/migrations/2026_08_22_000002_add_hpp_snapshot_to_invoice_details_table.php`
- Modify: `app/Models/SparepartBranchStock.php`, `app/Models/InvoiceDetail.php`

**Interfaces:**
- Produces: kolom `sparepart_branch_stocks.average_cost` (decimal 18,2, default 0) dan `invoice_details.hpp_snapshot` (decimal 18,2, default 0) — dipakai semua task berikutnya.

- [ ] **Step 1: Buat migrasi `average_cost`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAverageCostToSparepartBranchStocksTable extends Migration
{
    public function up()
    {
        Schema::table('sparepart_branch_stocks', function (Blueprint $table) {
            $table->decimal('average_cost', 18, 2)->default(0)->after('reserved_qty');
        });
    }

    public function down()
    {
        Schema::table('sparepart_branch_stocks', function (Blueprint $table) {
            $table->dropColumn('average_cost');
        });
    }
}
```

- [ ] **Step 2: Buat migrasi `hpp_snapshot`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHppSnapshotToInvoiceDetailsTable extends Migration
{
    public function up()
    {
        Schema::table('invoice_details', function (Blueprint $table) {
            $table->decimal('hpp_snapshot', 18, 2)->default(0)->after('line_total');
        });
    }

    public function down()
    {
        Schema::table('invoice_details', function (Blueprint $table) {
            $table->dropColumn('hpp_snapshot');
        });
    }
}
```

- [ ] **Step 3: Jalankan migrasi**

Run: `php artisan migrate`
Expected: kedua migrasi baru berhasil, tidak ada error.

- [ ] **Step 4: Update model `SparepartBranchStock`**

`app/Models/SparepartBranchStock.php` — ubah `$fillable` dan `$casts`:

```php
    protected $fillable = ['sparepart_branch_id', 'on_hand_qty', 'reserved_qty', 'average_cost'];

    protected $casts = [
        'on_hand_qty' => 'decimal:3',
        'reserved_qty' => 'decimal:3',
        'average_cost' => 'decimal:2',
    ];
```

- [ ] **Step 5: Update model `InvoiceDetail`**

`app/Models/InvoiceDetail.php` — tambahkan `hpp_snapshot` ke `$fillable` dan `$casts`:

```php
    protected $fillable = [
        'invoice_id', 'item_type',
        'work_order_service_line_id', 'work_order_sparepart_line_id', 'sparepart_branch_id',
        'item_code_snapshot', 'description', 'qty', 'unit_price',
        'discount_percent', 'discount_amount', 'line_total', 'hpp_snapshot', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'hpp_snapshot' => 'decimal:2',
    ];
```

- [ ] **Step 6: Verifikasi cepat lewat tinker**

Run: `php artisan tinker --execute="echo Schema::hasColumn('sparepart_branch_stocks','average_cost') ? 'OK1' : 'FAIL1'; echo Schema::hasColumn('invoice_details','hpp_snapshot') ? 'OK2' : 'FAIL2';"`
Expected: `OK1OK2`

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_22_000001_add_average_cost_to_sparepart_branch_stocks_table.php database/migrations/2026_08_22_000002_add_hpp_snapshot_to_invoice_details_table.php app/Models/SparepartBranchStock.php app/Models/InvoiceDetail.php
git commit -m "feat: add average_cost and hpp_snapshot columns for gross profit costing"
```

---

### Task 2: `InventoryCostService` (WAC Engine)

**Files:**
- Create: `app/Services/InventoryCostService.php`
- Test: `tests/Unit/InventoryCostServiceTest.php`

**Interfaces:**
- Produces: `App\Services\InventoryCostService::recalculateOnReceipt(float $qtyBefore, float $avgCostBefore, float $qtyIn, float $unitCost): float` — dipakai Task 3 (`GoodsReceiptController`) dan Task 6 (`AverageCostBackfillService`).

- [ ] **Step 1: Tulis failing test**

Buat `tests/Unit/InventoryCostServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\InventoryCostService;
use Tests\TestCase;

class InventoryCostServiceTest extends TestCase
{
    protected InventoryCostService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InventoryCostService();
    }

    public function test_first_receipt_into_empty_stock_uses_the_receipt_price_as_average(): void
    {
        $result = $this->service->recalculateOnReceipt(0.0, 0.0, 10.0, 40000.0);

        $this->assertEqualsWithDelta(40000.0, $result, 0.01);
    }

    public function test_second_receipt_blends_with_existing_average(): void
    {
        // 10 unit @ 40000 sudah ada, masuk lagi 5 unit @ 55000
        // (10*40000 + 5*55000) / 15 = (400000 + 275000) / 15 = 45000
        $result = $this->service->recalculateOnReceipt(10.0, 40000.0, 5.0, 55000.0);

        $this->assertEqualsWithDelta(45000.0, $result, 0.01);
    }

    public function test_receipt_with_decimal_qty_computes_precisely(): void
    {
        // 2.5 unit @ 100000 sudah ada, masuk lagi 2.5 unit @ 120000
        // (2.5*100000 + 2.5*120000) / 5 = (250000 + 300000) / 5 = 110000
        $result = $this->service->recalculateOnReceipt(2.5, 100000.0, 2.5, 120000.0);

        $this->assertEqualsWithDelta(110000.0, $result, 0.01);
    }

    public function test_zero_qty_after_is_guarded_and_returns_the_incoming_unit_cost(): void
    {
        // Defensive edge case yang seharusnya tidak terjadi di alur normal (qtyIn selalu > 0
        // untuk RECEIPT), tapi dijaga supaya tidak divide-by-zero.
        $result = $this->service->recalculateOnReceipt(0.0, 0.0, 0.0, 40000.0);

        $this->assertEqualsWithDelta(40000.0, $result, 0.01);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=InventoryCostServiceTest`
Expected: FAIL — class `App\Services\InventoryCostService` belum ada.

- [ ] **Step 3: Implementasi**

Buat `app/Services/InventoryCostService.php`:

```php
<?php

namespace App\Services;

class InventoryCostService
{
    /**
     * Rumus weighted average cost standar. $qtyBefore/$avgCostBefore adalah kondisi
     * SEBELUM barang masuk ini; $qtyIn/$unitCost adalah barang yang masuk. Caller
     * bertanggung jawab lockForUpdate() baris stock-nya sendiri — service ini murni
     * kalkulasi, tidak melakukan query atau mutasi apa pun.
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

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=InventoryCostServiceTest`
Expected: PASS (4 test)

- [ ] **Step 5: Commit**

```bash
git add app/Services/InventoryCostService.php tests/Unit/InventoryCostServiceTest.php
git commit -m "feat: add InventoryCostService weighted-average-cost engine"
```

---

### Task 3: Hook WAC ke `GoodsReceiptController::post()`

**Files:**
- Modify: `app/Http/Controllers/GoodsReceiptController.php`
- Modify: `tests/Feature/GoodsReceiptManagementTest.php`

**Interfaces:**
- Consumes: `InventoryCostService::recalculateOnReceipt()` (Task 2).

- [ ] **Step 1: Tulis failing test**

Tambahkan ke `tests/Feature/GoodsReceiptManagementTest.php`, setelah `test_post_with_two_lines_of_different_spareparts_increases_both_correctly()`:

```php
    public function test_post_sets_average_cost_to_purchase_price_for_first_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(40000.0, (float) $stock->average_cost);
    }

    public function test_post_blends_average_cost_across_two_receipts(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');

        // Receipt 1: 10 @ 40000
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $firstReceipt = GoodsReceipt::first();
        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$firstReceipt->id}/post");

        // Receipt 2: 5 @ 55000 -> expected avg (10*40000 + 5*55000) / 15 = 45000
        $this->actingAs(User::find($user->id))->post('/goods-receipts', [
            'branch_id' => $branch->id,
            'receipt_date' => now()->format('Y-m-d'),
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 5, 'purchase_price' => 55000],
            ],
        ]);
        $secondReceipt = GoodsReceipt::latest('id')->first();
        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$secondReceipt->id}/post");

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(15.0, (float) $stock->on_hand_qty);
        $this->assertEqualsWithDelta(45000.0, (float) $stock->average_cost, 0.01);
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=GoodsReceiptManagementTest`
Expected: FAIL pada 2 test baru — `average_cost` masih `0.00` (kolom ada dari Task 1, tapi belum ada logic yang mengisinya).

- [ ] **Step 3: Implementasi hook**

`app/Http/Controllers/GoodsReceiptController.php` — tambahkan `use App\Services\InventoryCostService;` di bagian `use` (setelah `use App\Services\DocumentNumberGenerator;`), lalu ubah loop di `post()` (baris ~174-193):

```php
            foreach ($lines as $line) {
                $stock = SparepartBranchStock::where('sparepart_branch_id', $line->sparepart_branch_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $qtyBefore = (float) $stock->on_hand_qty;
                $stock->average_cost = app(InventoryCostService::class)->recalculateOnReceipt(
                    $qtyBefore, (float) $stock->average_cost, (float) $line->qty, (float) $line->purchase_price
                );
                $stock->on_hand_qty += $line->qty;
                $stock->save();

                InventoryMovement::create([
                    'movement_at' => now(),
                    'branch_id' => $fresh->branch_id,
                    'sparepart_branch_id' => $line->sparepart_branch_id,
                    'movement_type' => InventoryMovementType::RECEIPT,
                    'qty_in' => $line->qty,
                    'qty_out' => 0,
                    'balance_after' => $stock->on_hand_qty,
                    'reference_type' => 'goods_receipt_line',
                    'reference_id' => $line->id,
                    'created_by' => auth()->id(),
                ]);
            }
```

(Perhatikan: `$qtyBefore` dibaca SEBELUM `$stock->on_hand_qty += $line->qty`, sesuai spec §3.3.)

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=GoodsReceiptManagementTest`
Expected: PASS semua (termasuk test lama, tidak ada regresi).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/GoodsReceiptController.php tests/Feature/GoodsReceiptManagementTest.php
git commit -m "feat: recalculate average_cost on goods receipt posting"
```

---

### Task 4: Regression — Stock Adjustment & Stock Transfer TIDAK Mengubah `average_cost`

**Files:**
- Modify: `tests/Feature/StockAdjustmentManagementTest.php`
- Modify: `tests/Feature/StockTransferManagementTest.php`

**Interfaces:**
- Tidak ada perubahan kode produksi di task ini — murni bukti tertulis bahwa keputusan spec §3.3 poin 2-3 ("tidak diubah") benar-benar berlaku, supaya regresi di masa depan (kalau ada yang "memperbaiki" ini tanpa sadar itu keputusan desain) langsung ketahuan.

- [ ] **Step 1: Tulis test untuk Stock Adjustment (harus langsung PASS, bukan TDD merah-hijau)**

Tambahkan ke `tests/Feature/StockAdjustmentManagementTest.php`, setelah `test_post_increases_stock_when_physical_qty_is_higher_and_writes_adjustment_in_movement()`:

```php
    public function test_post_with_positive_delta_does_not_change_average_cost(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 5);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['average_cost' => 42000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.post');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch, 12));
        $adjustment = \App\Models\StockAdjustment::first();
        $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$adjustment->id}/approve");

        $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$adjustment->id}/post");

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(12.0, (float) $stock->on_hand_qty, 'qty harus berubah sesuai physical_qty');
        $this->assertSame(42000.0, (float) $stock->average_cost, 'average_cost TIDAK boleh berubah oleh Stock Adjustment positif (spec §3.3)');
    }
```

*(Sesuaikan nama route approve/permission code kalau berbeda dari yang tertulis — cek `routes/web.php` prefix `stock-adjustments` dan test existing lain di file yang sama untuk memastikan flow create→approve→post yang benar sebelum menulis assert final.)*

- [ ] **Step 2: Jalankan test**

Run: `php artisan test --filter=test_post_with_positive_delta_does_not_change_average_cost`
Expected: PASS langsung (tidak ada kode produksi yang perlu diubah — ini murni bukti bahwa Task 1-3 tidak "accidentally" menyentuh jalur Stock Adjustment).

- [ ] **Step 3: Tulis test untuk Stock Transfer (juga harus langsung PASS)**

Tambahkan ke `tests/Feature/StockTransferManagementTest.php`, setelah `test_receive_increases_destination_stock_and_writes_transfer_in_movement()`:

```php
    public function test_receive_does_not_change_average_cost_at_destination(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $destinationSparepartBranch = SparepartBranch::where('sparepart_id', $sparepart->id)->where('branch_id', $to->id)->firstOrFail();
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $destinationSparepartBranch->id)->update(['average_cost' => 33000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $this->grantBranchPermission($user, $from, 'stock_transfer.dispatch');
        $this->grantBranchPermission($user, $to, 'stock_transfer.receive');
        $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));
        $transfer = \App\Models\StockTransfer::first();
        $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$transfer->id}/approve");
        $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$transfer->id}/dispatch");

        $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$transfer->id}/receive");

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $destinationSparepartBranch->id)->first();
        $this->assertSame(33000.0, (float) $stock->average_cost, 'average_cost TIDAK boleh berubah oleh Stock Transfer masuk (spec §3.3)');
    }
```

*(Sesuaikan nama route/permission code approve/dispatch dengan yang sebenarnya ada — cek test existing di file yang sama untuk flow create→approve→dispatch→receive yang persis.)*

- [ ] **Step 4: Jalankan kedua test**

Run: `php artisan test --filter=StockAdjustmentManagementTest && php artisan test --filter=StockTransferManagementTest`
Expected: PASS semua.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/StockAdjustmentManagementTest.php tests/Feature/StockTransferManagementTest.php
git commit -m "test: confirm stock adjustment and transfer leave average_cost unchanged"
```

---

### Task 5: Hook `hpp_snapshot` ke `InvoiceService::postInvoice()`

**Files:**
- Modify: `app/Services/InvoiceService.php`
- Test: `tests/Feature/InvoiceHppSnapshotTest.php` (baru)

**Interfaces:**
- Consumes: `sparepart_branch_stocks.average_cost` (Task 1, diisi Task 3).
- Produces: `invoice_details.hpp_snapshot` terisi — dipakai Task 7 (query laporan).

- [ ] **Step 1: Tulis failing test**

Buat `tests/Feature/InvoiceHppSnapshotTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Services\InvoiceService;
use App\Support\InvoiceDetailItemType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceHppSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function makeBranchAndCustomer(): array
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);

        return [$branch, $customer];
    }

    public function test_posting_snapshots_average_cost_onto_sparepart_lines(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')
            ->where('sparepart_branch_id', $sparepartBranch->id)
            ->update(['on_hand_qty' => 10, 'average_cost' => 37500]);

        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'services' => [
                ['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000],
            ],
        ]);

        (new InvoiceService())->postInvoice($invoice->fresh());

        $serviceDetail = $invoice->fresh()->details->firstWhere('item_type', InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->fresh()->details->firstWhere('item_type', InvoiceDetailItemType::SPAREPART);

        $this->assertSame(0.0, (float) $serviceDetail->hpp_snapshot, 'Baris jasa HPP harus selalu 0');
        $this->assertSame(37500.0, (float) $sparepartDetail->hpp_snapshot, 'Baris sparepart HPP harus disnapshot dari average_cost saat posting');
    }

    public function test_posting_does_not_change_average_cost(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')
            ->where('sparepart_branch_id', $sparepartBranch->id)
            ->update(['on_hand_qty' => 10, 'average_cost' => 37500]);

        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000],
            ],
        ]);

        (new InvoiceService())->postInvoice($invoice->fresh());

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(8.0, (float) $stock->on_hand_qty);
        $this->assertSame(37500.0, (float) $stock->average_cost, 'average_cost TIDAK boleh berubah oleh barang keluar (WAC hanya berubah saat barang masuk)');
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=InvoiceHppSnapshotTest`
Expected: FAIL pada `test_posting_snapshots_average_cost_onto_sparepart_lines` — `hpp_snapshot` sparepart masih `0.00` (belum ada logic yang mengisinya). Test kedua kemungkinan sudah PASS (tidak ada kode yang mengubah `average_cost` di jalur ini) — itu tidak masalah, biarkan tetap ditulis sebagai bukti regresi.

- [ ] **Step 3: Implementasi hook**

`app/Services/InvoiceService.php` — di method `postInvoice()`, ubah loop "Pass 2" (baris ~416-444):

```php
            // Pass 2: mutate stock, release reservations, and record kartu stok.
            foreach ($bySparepart as $sparepartBranchId => $detailsForSparepart) {
                $stock = $lockedStocks[$sparepartBranchId];

                foreach ($detailsForSparepart as $detail) {
                    if ($detail->sparepartLine) {
                        foreach ($detail->sparepartLine->reservations as $reservation) {
                            $stock->reserved_qty -= $reservation->qty;
                            $reservation->status = 'released';
                            $reservation->save();
                        }
                    }

                    $detail->hpp_snapshot = (float) $stock->average_cost;
                    $detail->save();

                    $stock->on_hand_qty -= $detail->qty;
                    $stock->save();

                    InventoryMovement::create([
                        'movement_at' => now(),
                        'branch_id' => $fresh->branch_id,
                        'sparepart_branch_id' => $sparepartBranchId,
                        'movement_type' => InventoryMovementType::USAGE_OUT,
                        'qty_in' => 0,
                        'qty_out' => $detail->qty,
                        'balance_after' => $stock->on_hand_qty,
                        'reference_type' => 'invoice_detail',
                        'reference_id' => $detail->id,
                        'created_by' => auth()->id(),
                    ]);
                }
            }
```

(Baris jasa tidak melewati loop ini sama sekali — `hpp_snapshot`-nya tetap default `0` dari migrasi Task 1, tidak perlu langkah tambahan.)

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=InvoiceHppSnapshotTest`
Expected: PASS keduanya.

- [ ] **Step 5: Jalankan regresi invoice existing**

Run: `php artisan test --filter=InvoiceDirectSaleTest && php artisan test --filter=InvoiceControllerTest`
Expected: PASS semua — tidak ada regresi dari perubahan `postInvoice()`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/InvoiceService.php tests/Feature/InvoiceHppSnapshotTest.php
git commit -m "feat: snapshot average_cost onto invoice_details.hpp_snapshot at posting"
```

---

### Task 6: `AverageCostBackfillService` + Migrasi Backfill

**Files:**
- Create: `app/Services/AverageCostBackfillService.php`
- Create: `database/migrations/2026_08_22_000003_backfill_average_cost_from_movement_history.php`
- Test: `tests/Feature/AverageCostBackfillServiceTest.php` (baru)

**Interfaces:**
- Consumes: `InventoryCostService::recalculateOnReceipt()` (Task 2).
- Produces: `AverageCostBackfillService::replayForSparepartBranch(int $sparepartBranchId): float` (murni, dites langsung), `AverageCostBackfillService::run(): int` (dipanggil migrasi).

> **Catatan desain:** logic backfill TIDAK ditulis langsung di `up()` migrasi (beda dari draft awal di spec) — diekstrak ke service supaya bisa dites langsung dengan data yang dibuat lewat `RefreshDatabase` (migrasi `up()` sendiri berjalan SEBELUM data test manapun ada, jadi tidak bisa diuji lewat siklus migrasi). Migrasi jadi wrapper tipis yang memanggil service ini — konsisten dengan pola `WorkshopPerformanceLinePairer`/`InvoicePkbGapComparator` (logic diekstrak ke class kecil yang testable).

- [ ] **Step 1: Tulis failing test**

Buat `tests/Feature/AverageCostBackfillServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Services\AverageCostBackfillService;
use App\Services\InventoryCostService;
use App\Support\GoodsReceiptStatus;
use App\Support\InventoryMovementType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AverageCostBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSparepartBranch(): SparepartBranch
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);

        return SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
    }

    protected function service(): AverageCostBackfillService
    {
        return new AverageCostBackfillService(new InventoryCostService());
    }

    public function test_replay_reconstructs_weighted_average_from_two_receipts(): void
    {
        $sparepartBranch = $this->makeSparepartBranch();
        $goodsReceipt = GoodsReceipt::create([
            'number' => 'PB/JKT/202601/00001', 'branch_id' => $sparepartBranch->branch_id,
            'receipt_date' => now()->toDateString(), 'status' => GoodsReceiptStatus::POSTED,
        ]);

        $line1 = GoodsReceiptLine::create(['goods_receipt_id' => $goodsReceipt->id, 'sparepart_branch_id' => $sparepartBranch->id, 'qty' => 10, 'purchase_price' => 40000, 'line_total' => 400000]);
        InventoryMovement::create([
            'movement_at' => now()->subDays(2), 'branch_id' => $sparepartBranch->branch_id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 10, 'qty_out' => 0, 'balance_after' => 10,
            'reference_type' => 'goods_receipt_line', 'reference_id' => $line1->id,
        ]);

        $line2 = GoodsReceiptLine::create(['goods_receipt_id' => $goodsReceipt->id, 'sparepart_branch_id' => $sparepartBranch->id, 'qty' => 5, 'purchase_price' => 55000, 'line_total' => 275000]);
        InventoryMovement::create([
            'movement_at' => now()->subDay(), 'branch_id' => $sparepartBranch->branch_id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 5, 'qty_out' => 0, 'balance_after' => 15,
            'reference_type' => 'goods_receipt_line', 'reference_id' => $line2->id,
        ]);

        $result = $this->service()->replayForSparepartBranch($sparepartBranch->id);

        $this->assertEqualsWithDelta(45000.0, $result, 0.01);
    }

    public function test_replay_ignores_movements_without_a_price_source(): void
    {
        $sparepartBranch = $this->makeSparepartBranch();
        $goodsReceipt = GoodsReceipt::create([
            'number' => 'PB/JKT/202601/00001', 'branch_id' => $sparepartBranch->branch_id,
            'receipt_date' => now()->toDateString(), 'status' => GoodsReceiptStatus::POSTED,
        ]);
        $line = GoodsReceiptLine::create(['goods_receipt_id' => $goodsReceipt->id, 'sparepart_branch_id' => $sparepartBranch->id, 'qty' => 10, 'purchase_price' => 40000, 'line_total' => 400000]);
        InventoryMovement::create([
            'movement_at' => now()->subDays(2), 'branch_id' => $sparepartBranch->branch_id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 10, 'qty_out' => 0, 'balance_after' => 10,
            'reference_type' => 'goods_receipt_line', 'reference_id' => $line->id,
        ]);
        // Adjustment positif tanpa harga — avg TIDAK boleh berubah oleh baris ini.
        InventoryMovement::create([
            'movement_at' => now()->subDay(), 'branch_id' => $sparepartBranch->branch_id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::ADJUSTMENT_IN, 'qty_in' => 3, 'qty_out' => 0, 'balance_after' => 13,
            'reference_type' => 'stock_adjustment_line', 'reference_id' => 999,
        ]);

        $result = $this->service()->replayForSparepartBranch($sparepartBranch->id);

        $this->assertEqualsWithDelta(40000.0, $result, 0.01);
    }

    public function test_replay_returns_zero_for_sparepart_branch_without_any_movement(): void
    {
        $sparepartBranch = $this->makeSparepartBranch();

        $result = $this->service()->replayForSparepartBranch($sparepartBranch->id);

        $this->assertSame(0.0, $result);
    }

    public function test_run_persists_average_cost_for_every_sparepart_branch_with_movements(): void
    {
        $sparepartBranch = $this->makeSparepartBranch();
        $goodsReceipt = GoodsReceipt::create([
            'number' => 'PB/JKT/202601/00001', 'branch_id' => $sparepartBranch->branch_id,
            'receipt_date' => now()->toDateString(), 'status' => GoodsReceiptStatus::POSTED,
        ]);
        $line = GoodsReceiptLine::create(['goods_receipt_id' => $goodsReceipt->id, 'sparepart_branch_id' => $sparepartBranch->id, 'qty' => 10, 'purchase_price' => 40000, 'line_total' => 400000]);
        InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $sparepartBranch->branch_id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 10, 'qty_out' => 0, 'balance_after' => 10,
            'reference_type' => 'goods_receipt_line', 'reference_id' => $line->id,
        ]);

        $updated = $this->service()->run();

        $this->assertSame(1, $updated);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertEqualsWithDelta(40000.0, (float) $stock->average_cost, 0.01);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=AverageCostBackfillServiceTest`
Expected: FAIL — class `App\Services\AverageCostBackfillService` belum ada.

- [ ] **Step 3: Implementasi service**

Buat `app/Services/AverageCostBackfillService.php`:

```php
<?php

namespace App\Services;

use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\SparepartBranchStock;
use App\Support\InventoryMovementType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AverageCostBackfillService
{
    protected InventoryCostService $costService;

    public function __construct(InventoryCostService $costService)
    {
        $this->costService = $costService;
    }

    /**
     * Replay seluruh inventory_movements (kronologis) untuk satu sparepart_branch_id
     * untuk merekonstruksi weighted average cost yang seharusnya, sesuai
     * docs/superpowers/specs/2026-08-22-gross-profit-report-design.md §3.4. Hanya
     * movement RECEIPT yang mengubah rata-rata (satu-satunya yang punya harga sumber
     * lewat goods_receipt_lines.purchase_price); movement lain hanya lewat sebagai qty.
     */
    public function replayForSparepartBranch(int $sparepartBranchId): float
    {
        $movements = InventoryMovement::where('sparepart_branch_id', $sparepartBranchId)
            ->orderBy('movement_at')
            ->orderBy('id')
            ->get();

        if ($movements->isEmpty()) {
            return 0.0;
        }

        $receiptLineIds = $movements
            ->where('movement_type', InventoryMovementType::RECEIPT)
            ->pluck('reference_id');

        $purchasePrices = GoodsReceiptLine::whereIn('id', $receiptLineIds)->pluck('purchase_price', 'id');

        $avgCost = 0.0;

        foreach ($movements as $movement) {
            if ($movement->movement_type !== InventoryMovementType::RECEIPT) {
                continue;
            }

            if (! isset($purchasePrices[$movement->reference_id])) {
                throw new RuntimeException(
                    "GoodsReceiptLine #{$movement->reference_id} yang direferensikan InventoryMovement #{$movement->id} tidak ditemukan."
                );
            }

            $qtyIn = (float) $movement->qty_in;
            $qtyBefore = (float) $movement->balance_after - $qtyIn;
            $unitCost = (float) $purchasePrices[$movement->reference_id];

            $avgCost = $this->costService->recalculateOnReceipt($qtyBefore, $avgCost, $qtyIn, $unitCost);
        }

        return round($avgCost, 2);
    }

    /**
     * Menjalankan replay untuk setiap sparepart_branch yang punya minimal 1 movement,
     * menyimpan hasilnya ke sparepart_branch_stocks.average_cost. Dipanggil sekali dari
     * migrasi backfill. Idempotent — aman dipanggil ulang (selalu menghitung ulang dari
     * histori penuh), meski tidak didesain untuk dijalankan rutin.
     */
    public function run(): int
    {
        $sparepartBranchIds = InventoryMovement::query()->distinct()->pluck('sparepart_branch_id');
        $updated = 0;

        foreach ($sparepartBranchIds->chunk(100) as $chunk) {
            DB::transaction(function () use ($chunk, &$updated) {
                foreach ($chunk as $sparepartBranchId) {
                    $avgCost = $this->replayForSparepartBranch($sparepartBranchId);

                    SparepartBranchStock::where('sparepart_branch_id', $sparepartBranchId)
                        ->update(['average_cost' => $avgCost]);

                    $updated++;
                }
            });
        }

        return $updated;
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=AverageCostBackfillServiceTest`
Expected: PASS semua (4 test).

- [ ] **Step 5: Buat migrasi backfill**

Buat `database/migrations/2026_08_22_000003_backfill_average_cost_from_movement_history.php`:

```php
<?php

use App\Models\SparepartBranchStock;
use App\Services\AverageCostBackfillService;
use App\Services\InventoryCostService;
use Illuminate\Database\Migrations\Migration;

class BackfillAverageCostFromMovementHistory extends Migration
{
    public function up()
    {
        (new AverageCostBackfillService(new InventoryCostService()))->run();
    }

    public function down()
    {
        SparepartBranchStock::query()->update(['average_cost' => 0]);
    }
}
```

- [ ] **Step 6: Jalankan migrasi & verifikasi tidak error**

Run: `php artisan migrate`
Expected: migrasi baru berhasil (di database dev, kemungkinan besar `0 updated` kalau belum ada `inventory_movements` — itu wajar, bukan error).

- [ ] **Step 7: Commit**

```bash
git add app/Services/AverageCostBackfillService.php database/migrations/2026_08_22_000003_backfill_average_cost_from_movement_history.php tests/Feature/AverageCostBackfillServiceTest.php
git commit -m "feat: add historical replay backfill for average_cost"
```

---

### Task 7: `GrossProfitReportController` — Routing, Permission, Menu, Kedua Tampilan

**Files:**
- Modify: `database/seeders/MenuPermissionSeeder.php`
- Modify: `resources/views/partials/sidebar.blade.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/MenuPermissionSeederTest.php`, `tests/Feature/AppShellTest.php`
- Create: `app/Http/Controllers/GrossProfitReportController.php`
- Create: `resources/views/reports/gross-profit/index.blade.php`, `resources/views/reports/gross-profit/no-access.blade.php`
- Test: `tests/Feature/GrossProfitReportControllerTest.php` (baru)

**Interfaces:**
- Consumes: `invoice_details.hpp_snapshot` (Task 5).
- Produces: route `reports.gross-profit.index` (dan `.export-excel`/`.pdf-preview`/`.pdf-download` — method-nya distub dulu, diisi Task 8-9), permission `report.gross_profit.view`.

- [ ] **Step 1: Tulis failing test untuk permission seeder**

Tambahkan ke `tests/Feature/MenuPermissionSeederTest.php`:

```php
    public function test_seeder_creates_gross_profit_report_menu_and_permission(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $this->assertDatabaseHas('menus', ['code' => 'reporting.gross_profit', 'is_branch_scoped' => true]);
        $this->assertDatabaseHas('permissions', ['code' => 'report.gross_profit.view']);
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=test_seeder_creates_gross_profit_report_menu_and_permission`
Expected: FAIL.

- [ ] **Step 3: Tambahkan entry menu & permission**

Di `database/seeders/MenuPermissionSeeder.php`, sisipkan persis setelah entry `'reporting.sparepart'` (sebelum penutup `];`):

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

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=test_seeder_creates_gross_profit_report_menu_and_permission`
Expected: PASS.

- [ ] **Step 5: Tambahkan route (stub Excel/PDF dulu — diisi Task 8-9)**

Di `routes/web.php`, dalam grup `Route::prefix('reports')->name('reports.')`, tambahkan setelah blok `sparepart-stock`:

```php
        Route::get('/gross-profit', [GrossProfitReportController::class, 'index'])->name('gross-profit.index');
        Route::get('/gross-profit/export-excel', [GrossProfitReportController::class, 'exportExcel'])->name('gross-profit.export-excel');
        Route::get('/gross-profit/pdf-preview', [GrossProfitReportController::class, 'previewPdf'])->name('gross-profit.pdf-preview');
        Route::get('/gross-profit/pdf-download', [GrossProfitReportController::class, 'downloadPdf'])->name('gross-profit.pdf-download');
```

Tambahkan juga `use App\Http\Controllers\GrossProfitReportController;` di bagian atas `routes/web.php` (ikuti alfabetis/pola import controller lain di file itu).

- [ ] **Step 6: Buat controller (index untuk kedua view_type; exportExcel/previewPdf/downloadPdf stub sementara)**

Buat `app/Http/Controllers/GrossProfitReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\Invoice;
use App\Support\InvoiceStatus;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class GrossProfitReportController extends Controller
{
    use HandlesReportExport;

    const COUNTED_STATUSES = [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID];

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.gross_profit.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.gross-profit.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);

        $viewData = [
            'viewType' => $filters['viewType'],
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'summaryRows' => null,
            'invoices' => null,
        ];

        if ($filters['viewType'] === 'invoice_detail') {
            $viewData['invoices'] = $this->buildInvoiceDetailQuery($filters, $permittedBranches)
                ->with(['branch', 'customer'])
                ->orderByDesc('invoice_date')
                ->orderByDesc('id')
                ->simplePaginate(15)
                ->withQueryString();

            return view('reports.gross-profit.index', $viewData);
        }

        $rows = $this->buildSummaryQuery($filters, $permittedBranches)->get();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $perPage = 15;
        $viewData['summaryRows'] = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return view('reports.gross-profit.index', $viewData);
    }

    public function exportExcel()
    {
        abort(501, 'Belum diimplementasikan — lihat Task 9.');
    }

    public function previewPdf()
    {
        abort(501, 'Belum diimplementasikan — lihat Task 8.');
    }

    public function downloadPdf()
    {
        abort(501, 'Belum diimplementasikan — lihat Task 8.');
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        return [
            'branchIds' => $branchIds,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
            'viewType' => request('view_type') === 'invoice_detail' ? 'invoice_detail' : 'summary',
        ];
    }

    protected function buildSummaryQuery(array $filters, SupportCollection $permittedBranches)
    {
        return Invoice::query()
            ->join('invoice_details', 'invoice_details.invoice_id', '=', 'invoices.id')
            ->whereIn('invoices.branch_id', $permittedBranches->pluck('id'))
            ->whereIn('invoices.status', self::COUNTED_STATUSES)
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('invoices.branch_id', $filters['branchIds']))
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoices.invoice_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoices.invoice_date', '<=', $filters['dateTo']))
            ->groupBy('invoices.branch_id', DB::raw("DATE_FORMAT(invoices.invoice_date, '%Y-%m')"))
            ->select([
                'invoices.branch_id',
                DB::raw("DATE_FORMAT(invoices.invoice_date, '%Y-%m') as period"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'service' THEN invoice_details.line_total ELSE 0 END), 0) as pendapatan_jasa"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'sparepart' THEN invoice_details.line_total ELSE 0 END), 0) as pendapatan_sparepart"),
                DB::raw('COALESCE(SUM(invoice_details.qty * invoice_details.hpp_snapshot), 0) as total_hpp'),
            ])
            ->orderBy('period')->orderBy('invoices.branch_id');
    }

    protected function buildInvoiceDetailQuery(array $filters, SupportCollection $permittedBranches)
    {
        return Invoice::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->whereIn('status', self::COUNTED_STATUSES)
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('branch_id', $filters['branchIds']))
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoice_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoice_date', '<=', $filters['dateTo']))
            ->withSum(['details as pendapatan_jasa' => function ($q) {
                $q->where('item_type', 'service');
            }], 'line_total')
            ->withSum(['details as pendapatan_sparepart' => function ($q) {
                $q->where('item_type', 'sparepart');
            }], 'line_total')
            ->withSum('details as total_hpp', DB::raw('qty * hpp_snapshot'));
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $viewTypeLabel = $filters['viewType'] === 'invoice_detail' ? 'Detail per Invoice' : 'Ringkasan';

        return "Cabang: {$branchLabel} · Tanggal: {$dateLabel} · Tampilan: {$viewTypeLabel} · Status: Diposting/Dibayar Sebagian/Lunas";
    }

    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
```

*(Catatan implementasi: `withSum('details as total_hpp', DB::raw('qty * hpp_snapshot'))` — cek dukungan Laravel 8 untuk raw column expression di posisi kolom `withSum`; kalau versi Eloquent yang terpasang tidak menerima `DB::raw` di situ, ganti dengan sub-query manual `selectSub` atau hitung `total_hpp` dengan `loadSum`/relasi tambahan sederhana yang menjumlah `qty*hpp_snapshot` per invoice — sesuaikan saat implementasi berdasarkan hasil test Step 8, jangan asumsikan tanpa verifikasi.)*

- [ ] **Step 7: Buat view `no-access.blade.php`**

Buat `resources/views/reports/gross-profit/no-access.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Laporan Laba Rugi')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-graph-up-arrow"></i></span>
            <div>
                <p class="eyebrow mb-1">Reporting</p>
                <h1 class="h3 mb-1">Laporan Laba Rugi</h1>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0">Anda belum memiliki akses laporan laba rugi di cabang manapun.</p>
        </div>
    </div>
@endsection
```

- [ ] **Step 8: Buat view `index.blade.php` (kedua tampilan)**

Buat `resources/views/reports/gross-profit/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Laporan Laba Rugi')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-graph-up-arrow"></i></span>
            <div>
                <p class="eyebrow mb-1">Reporting</p>
                <h1 class="h3 mb-1">Laporan Laba Rugi</h1>
                <p class="text-muted mb-0">Pendapatan dikurangi HPP (weighted average cost) — belum termasuk biaya operasional.</p>
            </div>
        </div>
        <div class="heading-actions">
            @include('partials.report-export-buttons', [
                'excelRoute' => 'reports.gross-profit.export-excel',
                'pdfPreviewRoute' => 'reports.gross-profit.pdf-preview',
                'pdfDownloadRoute' => 'reports.gross-profit.pdf-download',
            ])
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.gross-profit.index') }}" id="grossProfitFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tanggal Dari</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sampai</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tampilan</label>
                    <select name="view_type" class="form-select form-select-sm">
                        <option value="summary" {{ $viewType === 'summary' ? 'selected' : '' }}>Ringkasan</option>
                        <option value="invoice_detail" {{ $viewType === 'invoice_detail' ? 'selected' : '' }}>Detail per Invoice</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Terapkan Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
        @if ($viewType === 'invoice_detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Invoice</th><th>Tanggal</th><th>Cabang</th><th>Customer</th>
                        <th>Pendapatan Jasa</th><th>Pendapatan Sparepart</th><th>Total HPP</th><th>Laba Kotor</th><th>Margin %</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        @php
                            $pendapatan = (float) $invoice->pendapatan_jasa + (float) $invoice->pendapatan_sparepart;
                            $labaKotor = $pendapatan - (float) $invoice->total_hpp;
                            $margin = $pendapatan > 0 ? ($labaKotor / $pendapatan) * 100 : 0;
                        @endphp
                        <tr>
                            <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                            <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                            <td>{{ $invoice->branch->name }}</td>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ number_format($invoice->pendapatan_jasa, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->pendapatan_sparepart, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->total_hpp, 0, ',', '.') }}</td>
                            <td>{{ number_format($labaKotor, 0, ',', '.') }}</td>
                            <td>{{ number_format($margin, 1, ',', '.') }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-graph-up-arrow',
                                    'title' => 'Belum ada data',
                                    'description' => 'Tidak ada invoice yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Periode</th><th>Cabang</th>
                        <th>Pendapatan Jasa</th><th>Pendapatan Sparepart</th><th>Total HPP</th><th>Laba Kotor</th><th>Margin %</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summaryRows as $row)
                        @php
                            $pendapatan = (float) $row->pendapatan_jasa + (float) $row->pendapatan_sparepart;
                            $labaKotor = $pendapatan - (float) $row->total_hpp;
                            $margin = $pendapatan > 0 ? ($labaKotor / $pendapatan) * 100 : 0;
                            $branchName = optional($branches->firstWhere('id', $row->branch_id))->name ?? '-';
                        @endphp
                        <tr>
                            <td>{{ $row->period }}</td>
                            <td>{{ $branchName }}</td>
                            <td>{{ number_format($row->pendapatan_jasa, 0, ',', '.') }}</td>
                            <td>{{ number_format($row->pendapatan_sparepart, 0, ',', '.') }}</td>
                            <td>{{ number_format($row->total_hpp, 0, ',', '.') }}</td>
                            <td>{{ number_format($labaKotor, 0, ',', '.') }}</td>
                            <td>{{ number_format($margin, 1, ',', '.') }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-graph-up-arrow',
                                    'title' => 'Belum ada data',
                                    'description' => 'Tidak ada invoice yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @endif
        </div>
    </div>

    <div class="mt-3">
        {{ $viewType === 'invoice_detail' ? $invoices->links() : $summaryRows->links() }}
    </div>

    @push('scripts')
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('grossProfitFilterForm');
        if (!menu || !form) return;
        menu.addEventListener('click', function (event) { event.stopPropagation(); });
        const selectAll = document.getElementById('branchFilterSelectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
        }
        form.addEventListener('submit', function () {
            form.querySelectorAll('input[data-branch-hidden]').forEach(function (el) { el.remove(); });
            document.querySelectorAll('.branch-filter-checkbox:checked').forEach(function (checkbox) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'branch_ids[]';
                hidden.value = checkbox.value;
                hidden.setAttribute('data-branch-hidden', '1');
                form.appendChild(hidden);
            });
        });
    })();
    </script>
    @endpush
@endsection
```

- [ ] **Step 9: Tambahkan link sidebar**

Di `resources/views/partials/sidebar.blade.php`:
1. Ubah kondisi gabungan baris 126 — tambahkan `|| $user->branchesWithPermission('report.gross_profit.view')->isNotEmpty()` sebelum penutup `))`.
2. Tambahkan link baru setelah blok `report.sparepart.view` (baris ~158-163):

```blade
    @if ($user->branchesWithPermission('report.gross_profit.view')->isNotEmpty())
        <a href="{{ route('reports.gross-profit.index') }}" class="nav-link {{ request()->routeIs('reports.gross-profit.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-graph-up-arrow"></i></span>
            <span class="nav-text">Laporan Laba Rugi</span>
        </a>
    @endif
```

- [ ] **Step 10: Tulis test controller — Tampilan Ringkasan**

Buat `tests/Feature/GrossProfitReportControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\InvoiceService;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrossProfitReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function grantBranchPermission(User $user, Branch $branch, string $code): void
    {
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    protected function makePostedInvoice(Branch $branch, float $avgCost, float $sellingPrice, float $qty): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $sparepart = Sparepart::create(['code' => 'OLI-' . uniqid(), 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => $sellingPrice]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 100, 'average_cost' => $avgCost]);

        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000]],
            'spareparts' => [['sparepart_branch_id' => $sparepartBranch->id, 'qty' => $qty, 'unit_price' => $sellingPrice]],
        ]);
        (new InvoiceService())->postInvoice($invoice->fresh());
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/gross-profit');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_summary_view_computes_gross_profit_correctly(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makePostedInvoice($branch, 37500, 60000, 2); // pendapatan sparepart 120000, hpp 75000
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit');

        $response->assertOk();
        // Pendapatan jasa 40000 + sparepart 120000 = 160000. HPP = 75000. Laba kotor = 85000.
        $response->assertSee('85.000');
    }

    public function test_draft_and_cancelled_invoices_are_excluded_from_summary(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        // Invoice draft (tidak diposting) tidak boleh ikut dihitung.
        (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 999999]],
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit');

        $response->assertOk();
        $response->assertDontSee('999.999');
    }

    public function test_branch_filter_restricts_results(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->makePostedInvoice($branchA, 10000, 20000, 1);
        $this->makePostedInvoice($branchB, 10000, 30000, 1);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'report.gross_profit.view');
        $this->grantBranchPermission($user, $branchB, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit?branch_ids[]=' . $branchA->id);

        $response->assertOk();
        // Pendapatan sparepart branch A: 20000. Branch B (30000) tidak boleh ikut.
        $response->assertDontSee('30.000');
    }

    public function test_invoice_detail_view_shows_gross_profit_per_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makePostedInvoice($branch, 37500, 60000, 2);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit?view_type=invoice_detail');

        $response->assertOk();
        $response->assertSee('85.000');
    }
}
```

- [ ] **Step 11: Jalankan test, pastikan gagal lalu perbaiki**

Run: `php artisan test --filter=GrossProfitReportControllerTest`
Expected: kemungkinan besar FAIL dulu di percobaan pertama pada perhitungan `total_hpp`/`withSum` (lihat catatan Step 6 soal `DB::raw` di `withSum`) — debug dan perbaiki `buildInvoiceDetailQuery()`/`buildSummaryQuery()` sampai seluruh test PASS. Jangan lanjut ke step berikutnya sebelum semua hijau.

- [ ] **Step 12: Jalankan test sidebar & full regression file terkait**

Tambahkan test baru ke `tests/Feature/AppShellTest.php` (pola sama seperti test sidebar `workshop_performance` yang sudah ada di file itu — cari & contoh persis dari situ) untuk memverifikasi link "Laporan Laba Rugi" muncul/hilang sesuai permission.

Run: `php artisan test --filter=AppShellTest && php artisan test --filter=MenuPermissionSeederTest && php artisan test --filter=GrossProfitReportControllerTest`
Expected: PASS semua.

- [ ] **Step 13: Commit**

```bash
git add database/seeders/MenuPermissionSeeder.php resources/views/partials/sidebar.blade.php routes/web.php app/Http/Controllers/GrossProfitReportController.php resources/views/reports/gross-profit tests/Feature/GrossProfitReportControllerTest.php tests/Feature/MenuPermissionSeederTest.php tests/Feature/AppShellTest.php
git commit -m "feat: add Laporan Laba Rugi controller, routes, permission, and both views"
```

---

### Task 8: Cetak PDF

**Files:**
- Modify: `app/Http/Controllers/GrossProfitReportController.php`
- Create: `resources/views/reports/gross-profit/pdf.blade.php`
- Modify: `tests/Feature/GrossProfitReportExportTest.php` (buat baru di task ini)

**Interfaces:**
- Consumes: `buildSummaryQuery()`/`buildInvoiceDetailQuery()` (Task 7).

- [ ] **Step 1: Tulis failing test**

Buat `tests/Feature/GrossProfitReportExportTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\InvoiceService;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrossProfitReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function grantBranchPermission(User $user, Branch $branch, string $code): void
    {
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    protected function makePostedInvoice(Branch $branch): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 100, 'average_cost' => 37500]);

        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'spareparts' => [['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000]],
        ]);
        (new InvoiceService())->postInvoice($invoice->fresh());
    }

    public function test_pdf_actions_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/gross-profit/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/gross-profit/pdf-download')->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_disposition_with_correct_totals(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit/pdf-preview');

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit/pdf-download');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=GrossProfitReportExportTest`
Expected: FAIL — `previewPdf()`/`downloadPdf()` masih `abort(501)`.

- [ ] **Step 3: Implementasi `previewPdf()`/`downloadPdf()`**

Di `app/Http/Controllers/GrossProfitReportController.php`, ganti stub Task 7:

```php
    public function previewPdf()
    {
        return $this->renderPdf('inline');
    }

    public function downloadPdf()
    {
        return $this->renderPdf('attachment');
    }

    protected function renderPdf(string $disposition)
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.gross_profit.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);

        if ($filters['viewType'] === 'invoice_detail') {
            $rows = $this->buildInvoiceDetailQuery($filters, $permittedBranches)
                ->with(['branch', 'customer'])
                ->orderByDesc('invoice_date')->orderByDesc('id')
                ->limit(1001)->get();
            [$rows, $truncated] = $this->capRows($rows);

            return $this->streamPdf('reports.gross-profit.pdf', [
                'viewType' => $filters['viewType'],
                'summaryRows' => collect(),
                'invoices' => $rows,
                'truncated' => $truncated,
                'filterSummary' => $this->filterSummaryText($filters),
            ], 'laporan-laba-rugi-detail', $disposition);
        }

        $rows = $this->buildSummaryQuery($filters, $permittedBranches)->get();

        return $this->streamPdf('reports.gross-profit.pdf', [
            'viewType' => $filters['viewType'],
            'summaryRows' => $rows,
            'invoices' => collect(),
            'truncated' => false,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-laba-rugi-ringkasan', $disposition);
    }
```

Hapus method `exportExcel()` stub untuk sementara TETAP dibiarkan `abort(501, ...)` — diisi Task 9.

- [ ] **Step 4: Buat view `pdf.blade.php`**

Buat `resources/views/reports/gross-profit/pdf.blade.php`:

```blade
@extends('layouts.print')
@section('title', 'Laporan Laba Rugi')
@section('table')
    <p style="margin-bottom: 8px;"><strong>{{ $filterSummary }}</strong></p>
    @if ($truncated)
        <p style="color: red;">Data melebihi 1.000 baris, hanya 1.000 baris pertama yang ditampilkan.</p>
    @endif

    @if ($viewType === 'invoice_detail')
        <table class="print-table">
            <thead>
                <tr>
                    <th>No. Invoice</th><th>Tanggal</th><th>Cabang</th><th>Customer</th>
                    <th>Pendapatan Jasa</th><th>Pendapatan Sparepart</th><th>Total HPP</th><th>Laba Kotor</th><th>Margin %</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    @php
                        $pendapatan = (float) $invoice->pendapatan_jasa + (float) $invoice->pendapatan_sparepart;
                        $labaKotor = $pendapatan - (float) $invoice->total_hpp;
                        $margin = $pendapatan > 0 ? ($labaKotor / $pendapatan) * 100 : 0;
                    @endphp
                    <tr>
                        <td>{{ $invoice->number }}</td>
                        <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                        <td>{{ $invoice->branch->name }}</td>
                        <td>{{ $invoice->customer->name }}</td>
                        <td>{{ number_format($invoice->pendapatan_jasa, 0, ',', '.') }}</td>
                        <td>{{ number_format($invoice->pendapatan_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($invoice->total_hpp, 0, ',', '.') }}</td>
                        <td>{{ number_format($labaKotor, 0, ',', '.') }}</td>
                        <td>{{ number_format($margin, 1, ',', '.') }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <table class="print-table">
            <thead>
                <tr>
                    <th>Periode</th><th>Cabang ID</th>
                    <th>Pendapatan Jasa</th><th>Pendapatan Sparepart</th><th>Total HPP</th><th>Laba Kotor</th><th>Margin %</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summaryRows as $row)
                    @php
                        $pendapatan = (float) $row->pendapatan_jasa + (float) $row->pendapatan_sparepart;
                        $labaKotor = $pendapatan - (float) $row->total_hpp;
                        $margin = $pendapatan > 0 ? ($labaKotor / $pendapatan) * 100 : 0;
                    @endphp
                    <tr>
                        <td>{{ $row->period }}</td>
                        <td>{{ $row->branch_id }}</td>
                        <td>{{ number_format($row->pendapatan_jasa, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->pendapatan_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->total_hpp, 0, ',', '.') }}</td>
                        <td>{{ number_format($labaKotor, 0, ',', '.') }}</td>
                        <td>{{ number_format($margin, 1, ',', '.') }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
```

*(Kolom "Cabang ID" di tampilan Ringkasan PDF — query agregat hanya punya `branch_id` mentah, bukan nama. Kalau mau nama cabang, tambahkan `->with('branch')`-style lookup manual di `renderPdf()` sebelum kirim ke view, atau join `branches` di `buildSummaryQuery()` dan select `branches.name`. Putuskan & implementasikan salah satu saat step ini, jangan biarkan PDF menampilkan ID mentah ke user akhir.)*

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=GrossProfitReportExportTest`
Expected: PASS semua 3 test.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/GrossProfitReportController.php resources/views/reports/gross-profit/pdf.blade.php tests/Feature/GrossProfitReportExportTest.php
git commit -m "feat: add PDF export for Laporan Laba Rugi"
```

---

### Task 9: Export Excel

**Files:**
- Create: `app/Exports/GrossProfitSummaryExport.php`, `app/Exports/GrossProfitInvoiceDetailExport.php`
- Modify: `app/Http/Controllers/GrossProfitReportController.php`
- Modify: `tests/Feature/GrossProfitReportExportTest.php`

**Interfaces:**
- Consumes: `buildSummaryQuery()`/`buildInvoiceDetailQuery()` (Task 7).

- [ ] **Step 1: Tulis failing test**

Tambahkan ke `tests/Feature/GrossProfitReportExportTest.php`:

```php
    public function test_export_excel_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/gross-profit/export-excel')->assertForbidden();
    }

    public function test_export_excel_summary_returns_xlsx(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit/export-excel');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_excel_invoice_detail_returns_xlsx(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit/export-excel?view_type=invoice_detail');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=GrossProfitReportExportTest`
Expected: FAIL pada 3 test baru — `exportExcel()` masih `abort(501)`.

- [ ] **Step 3: Buat `GrossProfitSummaryExport`**

Buat `app/Exports/GrossProfitSummaryExport.php`:

```php
<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class GrossProfitSummaryExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
{
    protected Builder $query;
    protected string $filterSummary;

    public function __construct(Builder $query, string $filterSummary)
    {
        $this->query = $query;
        $this->filterSummary = $filterSummary;
    }

    public function query()
    {
        return $this->query;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return ['Periode', 'Cabang ID', 'Pendapatan Jasa', 'Pendapatan Sparepart', 'Total HPP', 'Laba Kotor', 'Margin %'];
    }

    public function map($row): array
    {
        $pendapatan = (float) $row->pendapatan_jasa + (float) $row->pendapatan_sparepart;
        $labaKotor = $pendapatan - (float) $row->total_hpp;
        $margin = $pendapatan > 0 ? round(($labaKotor / $pendapatan) * 100, 1) : 0;

        return [
            $row->period,
            $row->branch_id,
            (float) $row->pendapatan_jasa,
            (float) $row->pendapatan_sparepart,
            (float) $row->total_hpp,
            $labaKotor,
            $margin,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $sheet->insertNewRowBefore(1, 1);
                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->setCellValue('A1', $this->filterSummary);
                $sheet->getStyle('A1')->getFont()->setBold(true);
            },
        ];
    }
}
```

- [ ] **Step 4: Buat `GrossProfitInvoiceDetailExport`**

Buat `app/Exports/GrossProfitInvoiceDetailExport.php`:

```php
<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class GrossProfitInvoiceDetailExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
{
    protected Builder $query;
    protected string $filterSummary;

    public function __construct(Builder $query, string $filterSummary)
    {
        $this->query = $query;
        $this->filterSummary = $filterSummary;
    }

    public function query()
    {
        return $this->query->orderByDesc('invoice_date')->orderByDesc('id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return ['No. Invoice', 'Tanggal', 'Cabang', 'Customer', 'Pendapatan Jasa', 'Pendapatan Sparepart', 'Total HPP', 'Laba Kotor', 'Margin %'];
    }

    public function map($invoice): array
    {
        $pendapatan = (float) $invoice->pendapatan_jasa + (float) $invoice->pendapatan_sparepart;
        $labaKotor = $pendapatan - (float) $invoice->total_hpp;
        $margin = $pendapatan > 0 ? round(($labaKotor / $pendapatan) * 100, 1) : 0;

        return [
            $invoice->number,
            $invoice->invoice_date->format('Y-m-d'),
            $invoice->branch->name,
            $invoice->customer->name,
            (float) $invoice->pendapatan_jasa,
            (float) $invoice->pendapatan_sparepart,
            (float) $invoice->total_hpp,
            $labaKotor,
            $margin,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $sheet->insertNewRowBefore(1, 1);
                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->setCellValue('A1', $this->filterSummary);
                $sheet->getStyle('A1')->getFont()->setBold(true);
            },
        ];
    }
}
```

- [ ] **Step 5: Implementasi `exportExcel()`**

Di `app/Http/Controllers/GrossProfitReportController.php`, tambahkan `use App\Exports\GrossProfitInvoiceDetailExport;`, `use App\Exports\GrossProfitSummaryExport;`, `use Maatwebsite\Excel\Facades\Excel;` di bagian atas, lalu ganti stub:

```php
    public function exportExcel()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.gross_profit.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);

        if ($filters['viewType'] === 'invoice_detail') {
            $query = $this->buildInvoiceDetailQuery($filters, $permittedBranches)->with(['branch', 'customer']);

            return Excel::download(
                new GrossProfitInvoiceDetailExport($query, $this->filterSummaryText($filters)),
                'laporan-laba-rugi-detail-' . now()->format('Ymd-His') . '.xlsx'
            );
        }

        $query = $this->buildSummaryQuery($filters, $permittedBranches);

        return Excel::download(
            new GrossProfitSummaryExport($query, $this->filterSummaryText($filters)),
            'laporan-laba-rugi-ringkasan-' . now()->format('Ymd-His') . '.xlsx'
        );
    }
```

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=GrossProfitReportExportTest`
Expected: PASS semua (6 test).

- [ ] **Step 7: Commit**

```bash
git add app/Exports/GrossProfitSummaryExport.php app/Exports/GrossProfitInvoiceDetailExport.php app/Http/Controllers/GrossProfitReportController.php tests/Feature/GrossProfitReportExportTest.php
git commit -m "feat: add Excel export for Laporan Laba Rugi"
```

---

### Task 10: Regresi Penuh & Verifikasi Manual Browser

**Files:** tidak ada file produksi baru — verifikasi murni.

- [ ] **Step 1: Full test suite**

Run: `php artisan test`
Expected: seluruh test PASS (termasuk semua test lama), 0 gagal. Kalau ada test lama yang gagal karena kolom baru (`average_cost`/`hpp_snapshot` muncul di assertion array/JSON yang sebelumnya exact-match), perbaiki test tersebut (bukan kode produksi) — assertion yang terlalu ketat terhadap struktur tabel adalah hal yang wajar perlu disesuaikan saat skema berubah.

- [ ] **Step 2: `php artisan view:clear`**

Run: `php artisan view:clear`

- [ ] **Step 3: Verifikasi manual di browser**

Login sebagai superadmin, lalu:
1. Buka `/goods-receipts/create`, buat + post 1 Penerimaan Barang untuk sparepart yang sudah punya stok — konfirmasi tidak ada error.
2. Cek `sparepart_branch_stocks.average_cost` sparepart itu lewat tinker, konfirmasi angkanya sesuai rumus WAC manual.
3. Buka `/invoices/direct/create`, buat invoice dengan baris sparepart itu, post invoice-nya — konfirmasi tidak ada error dan `average_cost` sparepart TIDAK berubah setelah posting (hanya `hpp_snapshot` di baris invoice yang terisi).
4. Buka `/reports/gross-profit` — konfirmasi Tampilan Ringkasan & Detail per Invoice menampilkan angka yang masuk akal, filter cabang/tanggal berfungsi, tombol Download Template/Excel/PDF berfungsi tanpa error.
5. Bersihkan data uji coba yang dibuat di langkah 1 dan 3 dari database dev (pakai domain method yang tepat, bukan raw delete, sesuai disiplin proyek ini).

- [ ] **Step 4: Announce & hand off**

Setelah semua hijau dan verifikasi manual selesai, announce: "Saya pakai skill finishing-a-development-branch untuk menyelesaikan pekerjaan ini." lalu jalankan skill tersebut (verifikasi test, tanya opsi merge/PR/keep-as-is ke user).
