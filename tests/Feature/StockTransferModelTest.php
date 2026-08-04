<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Sparepart;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Support\InventoryMovementType;
use App\Support\TransferStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferModelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSparepart(string $codeSuffix = ''): Sparepart
    {
        return Sparepart::create(['code' => "OLI-01{$codeSuffix}", 'name' => 'Oli Mesin']);
    }

    public function test_stock_transfer_can_be_created_with_fillable_fields_and_defaults_to_draft(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        $fromBranch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $toBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);

        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001',
            'from_branch_id' => $fromBranch->id,
            'to_branch_id' => $toBranch->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $this->assertSame(TransferStatus::DRAFT, $stockTransfer->status);
        $this->assertSame($user->id, $stockTransfer->created_by);
        $this->assertNull($stockTransfer->approved_by);
        $this->assertNull($stockTransfer->dispatched_by);
        $this->assertNull($stockTransfer->received_by);
    }

    public function test_stock_transfer_number_is_unique(): void
    {
        $fromBranch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $toBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $attrs = ['number' => 'ST/JKT/202608/00001', 'from_branch_id' => $fromBranch->id, 'to_branch_id' => $toBranch->id, 'transfer_date' => now()->format('Y-m-d')];
        StockTransfer::create($attrs);

        $this->expectException(QueryException::class);
        StockTransfer::create($attrs);
    }

    public function test_stock_transfer_rejects_same_from_and_to_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $this->expectException(QueryException::class);
        StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $branch->id, 'to_branch_id' => $branch->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);
    }

    public function test_stock_transfer_line_belongs_to_transfer_and_sparepart(): void
    {
        $fromBranch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $toBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $fromBranch->id, 'to_branch_id' => $toBranch->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);
        $sparepart = $this->makeSparepart();

        $line = StockTransferLine::create([
            'stock_transfer_id' => $stockTransfer->id,
            'sparepart_id' => $sparepart->id,
            'qty' => 5,
        ]);

        $this->assertSame($stockTransfer->id, $line->stockTransfer->id);
        $this->assertSame($sparepart->id, $line->sparepart->id);
        $this->assertCount(1, $stockTransfer->lines);
    }

    public function test_deleting_stock_transfer_cascades_to_its_lines(): void
    {
        $fromBranch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $toBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $fromBranch->id, 'to_branch_id' => $toBranch->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);
        $sparepart = $this->makeSparepart();
        $line = StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 5]);

        $stockTransfer->delete();

        $this->assertDatabaseMissing('stock_transfer_lines', ['id' => $line->id]);
    }

    public function test_stock_transfer_line_rejects_duplicate_sparepart_in_same_transfer(): void
    {
        $fromBranch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $toBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $fromBranch->id, 'to_branch_id' => $toBranch->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);
        $sparepart = $this->makeSparepart();
        StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 5]);

        $this->expectException(QueryException::class);
        StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 3]);
    }

    public function test_stock_transfer_line_rejects_nonpositive_qty(): void
    {
        $fromBranch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $toBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $fromBranch->id, 'to_branch_id' => $toBranch->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);
        $sparepart = $this->makeSparepart();

        $this->expectException(QueryException::class);
        StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 0]);
    }

    public function test_inventory_movement_can_be_created_with_transfer_out_and_transfer_in_types(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = $this->makeSparepart();
        $sparepartBranch = \App\Models\SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        $movementOut = InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::TRANSFER_OUT, 'qty_in' => 0, 'qty_out' => 5,
            'balance_after' => 5, 'reference_type' => 'stock_transfer_line', 'reference_id' => 1,
        ]);
        $movementIn = InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::TRANSFER_IN, 'qty_in' => 5, 'qty_out' => 0,
            'balance_after' => 10, 'reference_type' => 'stock_transfer_line', 'reference_id' => 2,
        ]);

        $this->assertSame(InventoryMovementType::TRANSFER_OUT, $movementOut->movement_type);
        $this->assertSame(InventoryMovementType::TRANSFER_IN, $movementIn->movement_type);
    }
}
