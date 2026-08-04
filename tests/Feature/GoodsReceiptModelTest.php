<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Support\GoodsReceiptStatus;
use App\Support\InventoryMovementType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodsReceiptModelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSparepartBranch(Branch $branch): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);

        return SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
    }

    public function test_goods_receipt_can_be_created_with_fillable_fields_and_defaults_to_draft(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $goodsReceipt = GoodsReceipt::create([
            'number' => 'PB/JKT/202608/00001',
            'branch_id' => $branch->id,
            'receipt_date' => now()->format('Y-m-d'),
        ]);

        $this->assertSame(GoodsReceiptStatus::DRAFT, $goodsReceipt->status);
        $this->assertSame($user->id, $goodsReceipt->created_by);
    }

    public function test_goods_receipt_number_is_unique(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $attrs = ['number' => 'PB/JKT/202608/00001', 'branch_id' => $branch->id, 'receipt_date' => now()->format('Y-m-d')];
        GoodsReceipt::create($attrs);

        $this->expectException(QueryException::class);
        GoodsReceipt::create($attrs);
    }

    public function test_goods_receipt_line_belongs_to_receipt_and_sparepart_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $goodsReceipt = GoodsReceipt::create(['number' => 'PB/JKT/202608/00001', 'branch_id' => $branch->id, 'receipt_date' => now()->format('Y-m-d')]);
        $sparepartBranch = $this->makeSparepartBranch($branch);

        $line = GoodsReceiptLine::create([
            'goods_receipt_id' => $goodsReceipt->id,
            'sparepart_branch_id' => $sparepartBranch->id,
            'qty' => 10,
            'purchase_price' => 40000,
            'line_total' => 400000,
        ]);

        $this->assertSame($goodsReceipt->id, $line->goodsReceipt->id);
        $this->assertSame($sparepartBranch->id, $line->sparepartBranch->id);
        $this->assertCount(1, $goodsReceipt->lines);
    }

    public function test_deleting_goods_receipt_cascades_to_its_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $goodsReceipt = GoodsReceipt::create(['number' => 'PB/JKT/202608/00001', 'branch_id' => $branch->id, 'receipt_date' => now()->format('Y-m-d')]);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $line = GoodsReceiptLine::create([
            'goods_receipt_id' => $goodsReceipt->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'qty' => 10, 'purchase_price' => 40000, 'line_total' => 400000,
        ]);

        $goodsReceipt->delete();

        $this->assertDatabaseMissing('goods_receipt_lines', ['id' => $line->id]);
    }

    public function test_inventory_movement_can_be_created_with_fillable_fields(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);

        $movement = InventoryMovement::create([
            'movement_at' => now(),
            'branch_id' => $branch->id,
            'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT,
            'qty_in' => 10,
            'qty_out' => 0,
            'balance_after' => 10,
            'reference_type' => 'goods_receipt_line',
            'reference_id' => 1,
        ]);

        $this->assertSame(InventoryMovementType::RECEIPT, $movement->movement_type);
        $this->assertSame(10.0, (float) $movement->qty_in);
    }

    public function test_inventory_movement_rejects_qty_in_and_qty_out_both_positive(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);

        $this->expectException(QueryException::class);
        InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 5, 'qty_out' => 5,
            'balance_after' => 10, 'reference_type' => 'goods_receipt_line', 'reference_id' => 1,
        ]);
    }

    public function test_inventory_movement_rejects_both_qty_in_and_qty_out_zero(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);

        $this->expectException(QueryException::class);
        InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 0, 'qty_out' => 0,
            'balance_after' => 10, 'reference_type' => 'goods_receipt_line', 'reference_id' => 1,
        ]);
    }
}
