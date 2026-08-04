<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Support\InventoryMovementType;
use App\Support\StockAdjustmentStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentModelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSparepartBranch(Branch $branch, string $codeSuffix = ''): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => "OLI-01{$codeSuffix}", 'name' => 'Oli Mesin']);

        return SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
    }

    public function test_stock_adjustment_can_be_created_with_fillable_fields_and_defaults_to_draft(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001',
            'branch_id' => $branch->id,
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Stock opname bulanan',
        ]);

        $this->assertSame(StockAdjustmentStatus::DRAFT, $stockAdjustment->status);
        $this->assertSame($user->id, $stockAdjustment->created_by);
        $this->assertNull($stockAdjustment->approved_by);
        $this->assertNull($stockAdjustment->approved_at);
    }

    public function test_stock_adjustment_number_is_unique(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $attrs = ['number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname'];
        StockAdjustment::create($attrs);

        $this->expectException(QueryException::class);
        StockAdjustment::create($attrs);
    }

    public function test_stock_adjustment_line_belongs_to_adjustment_and_sparepart_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $stockAdjustment = StockAdjustment::create(['number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname']);
        $sparepartBranch = $this->makeSparepartBranch($branch);

        $line = StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id,
            'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10,
            'physical_qty' => 8,
            'adjustment_qty' => -2,
            'reason' => 'Rusak',
        ]);

        $this->assertSame($stockAdjustment->id, $line->stockAdjustment->id);
        $this->assertSame($sparepartBranch->id, $line->sparepartBranch->id);
        $this->assertCount(1, $stockAdjustment->lines);
    }

    public function test_deleting_stock_adjustment_cascades_to_its_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $stockAdjustment = StockAdjustment::create(['number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $line = StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 8, 'adjustment_qty' => -2, 'reason' => 'Rusak',
        ]);

        $stockAdjustment->delete();

        $this->assertDatabaseMissing('stock_adjustment_lines', ['id' => $line->id]);
    }

    public function test_stock_adjustment_line_rejects_duplicate_sparepart_branch_in_same_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $stockAdjustment = StockAdjustment::create(['number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 8, 'adjustment_qty' => -2, 'reason' => 'Rusak',
        ]);

        $this->expectException(QueryException::class);
        StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 9, 'adjustment_qty' => -1, 'reason' => 'Hilang',
        ]);
    }

    public function test_inventory_movement_can_be_created_with_adjustment_in_and_adjustment_out_types(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);

        $movementIn = InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::ADJUSTMENT_IN, 'qty_in' => 5, 'qty_out' => 0,
            'balance_after' => 15, 'reference_type' => 'stock_adjustment_line', 'reference_id' => 1,
        ]);
        $movementOut = InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::ADJUSTMENT_OUT, 'qty_in' => 0, 'qty_out' => 3,
            'balance_after' => 12, 'reference_type' => 'stock_adjustment_line', 'reference_id' => 2,
        ]);

        $this->assertSame(InventoryMovementType::ADJUSTMENT_IN, $movementIn->movement_type);
        $this->assertSame(InventoryMovementType::ADJUSTMENT_OUT, $movementOut->movement_type);
    }
}
