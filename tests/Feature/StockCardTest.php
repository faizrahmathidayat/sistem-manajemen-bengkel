<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\GoodsReceiptStatus;
use App\Support\InventoryMovementType;
use App\Support\StockAdjustmentStatus;
use App\Support\TransferStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCardTest extends TestCase
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

    protected function makeSparepartBranch(Branch $branch): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);

        return SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
    }

    public function test_index_shows_no_access_page_without_any_sparepart_view_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/stock-card');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_index_renders_with_branch_and_sparepart_selected(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $response->assertSee('OLI-01');
    }

    public function test_index_shows_empty_state_when_selected_sparepart_has_no_movements(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $response->assertSee('Belum ada riwayat mutasi');
    }

    public function test_index_lists_movements_chronologically_ascending(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        InventoryMovement::create([
            'movement_at' => now()->subDays(2), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 9481, 'qty_out' => 0,
            'balance_after' => 9481, 'reference_type' => 'goods_receipt_line', 'reference_id' => 1,
        ]);
        InventoryMovement::create([
            'movement_at' => now()->subDay(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::ADJUSTMENT_OUT, 'qty_in' => 0, 'qty_out' => 3627,
            'balance_after' => 5854, 'reference_type' => 'stock_adjustment_line', 'reference_id' => 1,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $content = $response->getContent();
        // The RECEIPT row's balance (9.481, formatted "9.481") must render
        // before the ADJUSTMENT_OUT row's balance (5.854) in the raw HTML,
        // proving ascending chronological order rather than the app's usual
        // descending one. Values are deliberately large/distinctive so they
        // can't accidentally match unrelated digits elsewhere on the page.
        $this->assertLessThan(strpos($content, '5.854'), strpos($content, '9.481'));
    }

    public function test_index_resolves_reference_to_goods_receipt_number_and_link(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $goodsReceipt = GoodsReceipt::create([
            'number' => 'PB/JKT/202608/00001', 'branch_id' => $branch->id, 'receipt_date' => now()->format('Y-m-d'),
            'status' => GoodsReceiptStatus::POSTED,
        ]);
        $line = GoodsReceiptLine::create([
            'goods_receipt_id' => $goodsReceipt->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'qty' => 10, 'purchase_price' => 40000, 'line_total' => 400000,
        ]);
        InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 10, 'qty_out' => 0,
            'balance_after' => 10, 'reference_type' => 'goods_receipt_line', 'reference_id' => $line->id,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $response->assertSee('PB/JKT/202608/00001');
        $response->assertSee(route('goods-receipts.show', $goodsReceipt), false);
    }

    public function test_index_resolves_reference_to_stock_adjustment_number_and_link(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::POSTED,
        ]);
        $line = StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 8, 'adjustment_qty' => -2, 'reason' => 'Rusak',
        ]);
        InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::ADJUSTMENT_OUT, 'qty_in' => 0, 'qty_out' => 2,
            'balance_after' => 8, 'reference_type' => 'stock_adjustment_line', 'reference_id' => $line->id,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $response->assertSee('SA/JKT/202608/00001');
        $response->assertSee(route('stock-adjustments.show', $stockAdjustment), false);
    }

    public function test_index_resolves_reference_to_stock_transfer_number_and_link(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $otherBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $sparepart = $sparepartBranch->sparepart;
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $otherBranch->id, 'selling_price' => 60000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/BDG/202608/00001', 'from_branch_id' => $otherBranch->id, 'to_branch_id' => $branch->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::RECEIVED,
        ]);
        $line = StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 5]);
        InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::TRANSFER_IN, 'qty_in' => 5, 'qty_out' => 0,
            'balance_after' => 5, 'reference_type' => 'stock_transfer_line', 'reference_id' => $line->id,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $response->assertSee('ST/BDG/202608/00001');
        $response->assertSee(route('stock-transfers.show', $stockTransfer), false);
    }

    public function test_index_paginates_at_twenty_rows(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        for ($i = 0; $i < 25; $i++) {
            InventoryMovement::create([
                'movement_at' => now()->subMinutes(25 - $i), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
                'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 1, 'qty_out' => 0,
                'balance_after' => $i + 1, 'reference_type' => 'goods_receipt_line', 'reference_id' => $i + 1,
            ]);
        }

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        // simplePaginate(20) with 25 rows must produce a second-page link —
        // checking for the query string rather than a specific button label,
        // since the label text depends on Laravel's (unlocalized) pagination
        // translation strings, not app-authored copy.
        $response->assertSee('page=2', false);
    }
}
