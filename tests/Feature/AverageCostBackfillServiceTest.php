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
