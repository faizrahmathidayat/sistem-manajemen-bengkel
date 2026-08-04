<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\StockAdjustmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentManagementTest extends TestCase
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

    protected function makeSparepartBranch(Branch $branch, string $codeSuffix = '', float $onHandQty = 0): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => "OLI-01{$codeSuffix}", 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        if ($onHandQty > 0) {
            $sparepartBranch->stock()->update(['on_hand_qty' => $onHandQty]);
        }

        return $sparepartBranch;
    }

    protected function baseStorePayload(Branch $branch, SparepartBranch $sparepartBranch, float $physicalQty = 8): array
    {
        return [
            'branch_id' => $branch->id,
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Stock opname bulanan',
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'physical_qty' => $physicalQty, 'reason' => 'Selisih hitung fisik'],
            ],
        ];
    }

    public function test_store_creates_stock_adjustment_with_lines_and_captures_system_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch, 8));

        $stockAdjustment = StockAdjustment::first();
        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $this->assertSame(StockAdjustmentStatus::DRAFT, $stockAdjustment->status);
        $this->assertStringStartsWith('SA/JKT/', $stockAdjustment->number);
        $this->assertCount(1, $stockAdjustment->lines);
        $line = $stockAdjustment->lines->first();
        $this->assertSame(10.0, (float) $line->system_qty);
        $this->assertSame(8.0, (float) $line->physical_qty);
        $this->assertSame(-2.0, (float) $line->adjustment_qty);

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(10.0, (float) $stock->on_hand_qty, 'Creating a DRAFT adjustment must not touch stock.');
    }

    public function test_store_ignores_client_supplied_system_qty_and_adjustment_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $payload = $this->baseStorePayload($branch, $sparepartBranch, 8);
        $payload['lines'][0]['system_qty'] = 999;
        $payload['lines'][0]['adjustment_qty'] = 999;

        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $payload);

        $line = StockAdjustment::first()->lines->first();
        $this->assertSame(10.0, (float) $line->system_qty);
        $this->assertSame(-2.0, (float) $line->adjustment_qty);
    }

    public function test_store_is_forbidden_without_stock_adjustment_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch));

        $response->assertForbidden();
    }

    public function test_store_rejects_an_adjustment_with_no_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-adjustments', [
            'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname', 'lines' => [],
        ]);

        $response->assertSessionHasErrors(['lines']);
    }

    public function test_store_rejects_duplicate_sparepart_in_same_document(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-adjustments', [
            'branch_id' => $branch->id,
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname',
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'physical_qty' => 8, 'reason' => 'Rusak'],
                ['sparepart_branch_id' => $sparepartBranch->id, 'physical_qty' => 9, 'reason' => 'Hilang'],
            ],
        ]);

        $response->assertSessionHasErrors(['lines.0.sparepart_branch_id', 'lines.1.sparepart_branch_id']);
    }

    public function test_store_rejects_sparepart_from_a_different_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $otherBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $otherSparepartBranch = $this->makeSparepartBranch($otherBranch, '-other');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $otherSparepartBranch));

        $response->assertSessionHasErrors(['lines.0.sparepart_branch_id']);
    }

    public function test_index_lists_adjustments_for_authorized_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepartBranchA = $this->makeSparepartBranch($branchA, '-a');
        $sparepartBranchB = $this->makeSparepartBranch($branchB, '-b');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'stock_adjustment.view');
        $this->grantBranchPermission($user, $branchA, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branchA, $sparepartBranchA));
        $this->grantBranchPermission($user, $branchB, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branchB, $sparepartBranchB));

        $response = $this->actingAs(User::find($user->id))->get('/stock-adjustments');

        $response->assertOk();
        $adjustmentA = StockAdjustment::where('branch_id', $branchA->id)->first();
        $adjustmentB = StockAdjustment::where('branch_id', $branchB->id)->first();
        $response->assertSee($adjustmentA->number);
        $response->assertDontSee($adjustmentB->number);
    }

    public function test_index_shows_no_access_page_without_any_stock_adjustment_view_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/stock-adjustments');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_index_shows_empty_state_when_no_adjustments_match(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');

        $response = $this->actingAs(User::find($user->id))->get('/stock-adjustments');

        $response->assertOk();
        $response->assertSee('Belum ada stock adjustment');
    }

    public function test_index_renders_all_five_status_badges_correctly(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $statuses = [
            StockAdjustmentStatus::DRAFT => 'Draft',
            StockAdjustmentStatus::PENDING_APPROVAL => 'Diajukan',
            StockAdjustmentStatus::APPROVED => 'Disetujui',
            StockAdjustmentStatus::POSTED => 'Diposting',
            StockAdjustmentStatus::CANCELLED => 'Dibatalkan',
        ];
        foreach (array_keys($statuses) as $index => $status) {
            StockAdjustment::create([
                'number' => "SA/JKT/202608/0000{$index}9",
                'branch_id' => $branch->id,
                'adjustment_date' => now()->format('Y-m-d'),
                'reason' => 'Opname',
                'status' => $status,
            ]);
        }

        $response = $this->actingAs(User::find($user->id))->get('/stock-adjustments');

        $response->assertOk();
        foreach ($statuses as $label) {
            $response->assertSee($label);
        }
    }

    public function test_create_form_renders_for_a_user_with_stock_adjustment_create_in_some_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');

        $response = $this->actingAs(User::find($user->id))->get('/stock-adjustments/create');

        $response->assertOk();
    }

    public function test_create_form_replays_old_lines_after_a_validation_error(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $payload = $this->baseStorePayload($branch, $sparepartBranch, 7);
        unset($payload['adjustment_date']);

        $this->from(route('stock-adjustments.create'))->actingAs(User::find($user->id))->post('/stock-adjustments', $payload);
        $response = $this->actingAs(User::find($user->id))->get(route('stock-adjustments.create'));

        $response->assertOk();
        $response->assertSee('oldLines', false);
        $response->assertSee('"physical_qty":7', false);
    }

    public function test_edit_form_renders_for_a_user_with_stock_adjustment_create_on_a_draft_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch));
        $stockAdjustment = StockAdjustment::first();

        $response = $this->actingAs(User::find($user->id))->get("/stock-adjustments/{$stockAdjustment->id}/edit");

        $response->assertOk();
    }

    public function test_update_successfully_replaces_lines_and_recomputes_system_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $newSparepartBranch = $this->makeSparepartBranch($branch, '-updated', 20);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch, 8));
        $stockAdjustment = StockAdjustment::first();

        $response = $this->actingAs(User::find($user->id))->put("/stock-adjustments/{$stockAdjustment->id}", [
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname revisi',
            'lines' => [
                ['sparepart_branch_id' => $newSparepartBranch->id, 'physical_qty' => 18, 'reason' => 'Selisih baru'],
            ],
        ]);

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertCount(1, $stockAdjustment->lines);
        $line = $stockAdjustment->lines->first();
        $this->assertSame($newSparepartBranch->id, $line->sparepart_branch_id);
        $this->assertSame(20.0, (float) $line->system_qty);
        $this->assertSame(-2.0, (float) $line->adjustment_qty);
    }

    public function test_update_can_change_header_fields(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch));
        $stockAdjustment = StockAdjustment::first();

        $this->actingAs(User::find($user->id))->put("/stock-adjustments/{$stockAdjustment->id}", [
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Alasan yang diperbarui',
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'physical_qty' => 8, 'reason' => 'Selisih hitung fisik'],
            ],
        ]);

        $stockAdjustment->refresh();
        $this->assertSame('Alasan yang diperbarui', $stockAdjustment->reason);
    }

    public function test_update_is_forbidden_for_a_non_draft_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($user->id))->put("/stock-adjustments/{$stockAdjustment->id}", [
            'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname',
            'lines' => [['sparepart_branch_id' => $sparepartBranch->id, 'physical_qty' => 1, 'reason' => 'x']],
        ]);

        $response->assertForbidden();
    }

    public function test_show_renders_status_badge_and_approval_info_when_approved(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $approver = User::factory()->create(['name' => 'Budi Approver']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
            'approved_by' => $approver->id, 'approved_at' => now(),
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-adjustments/{$stockAdjustment->id}");

        $response->assertOk();
        $response->assertSee('Disetujui');
        $response->assertSee('Budi Approver');
    }

    public function test_submit_moves_draft_to_pending_approval(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch));
        $stockAdjustment = StockAdjustment::first();

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/submit");

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::PENDING_APPROVAL, $stockAdjustment->status);
    }

    public function test_submit_is_forbidden_without_stock_adjustment_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname',
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/submit");

        $response->assertForbidden();
    }

    public function test_submit_is_forbidden_for_a_non_draft_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/submit");

        $response->assertForbidden();
    }

    public function test_approve_moves_pending_approval_to_approved_and_records_approver(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $approver = User::factory()->create();
        $this->grantBranchPermission($approver, $branch, 'stock_adjustment.approve');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($approver->id))->patch("/stock-adjustments/{$stockAdjustment->id}/approve");

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::APPROVED, $stockAdjustment->status);
        $this->assertSame($approver->id, $stockAdjustment->approved_by);
        $this->assertNotNull($stockAdjustment->approved_at);
    }

    public function test_approve_is_forbidden_without_stock_adjustment_approve_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/approve");

        $response->assertForbidden();
    }

    public function test_approve_is_forbidden_for_a_draft_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname',
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/approve");

        $response->assertForbidden();
    }

    public function test_post_increases_stock_when_physical_qty_is_higher_and_writes_adjustment_in_movement(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 15, 'adjustment_qty' => 5, 'reason' => 'Ditemukan lebih',
        ]);

        $response = $this->actingAs(User::find($poster->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::POSTED, $stockAdjustment->status);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(15.0, (float) $stock->on_hand_qty);
        $movement = \DB::table('inventory_movements')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame('adjustment_in', $movement->movement_type);
        $this->assertSame(5.0, (float) $movement->qty_in);
        $this->assertSame(0.0, (float) $movement->qty_out);
        $this->assertSame(15.0, (float) $movement->balance_after);
        $this->assertNull($movement->notes);
    }

    public function test_post_decreases_stock_when_physical_qty_is_lower_and_writes_adjustment_out_movement(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 6, 'adjustment_qty' => -4, 'reason' => 'Rusak',
        ]);

        $this->actingAs(User::find($poster->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(6.0, (float) $stock->on_hand_qty);
        $movement = \DB::table('inventory_movements')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame('adjustment_out', $movement->movement_type);
        $this->assertSame(0.0, (float) $movement->qty_in);
        $this->assertSame(4.0, (float) $movement->qty_out);
        $this->assertSame(6.0, (float) $movement->balance_after);
    }

    public function test_post_skips_ledger_entry_when_recomputed_delta_is_zero_but_still_marks_posted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 10, 'adjustment_qty' => 0, 'reason' => 'Sesuai',
        ]);

        $response = $this->actingAs(User::find($poster->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::POSTED, $stockAdjustment->status);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(10.0, (float) $stock->on_hand_qty);
        $this->assertSame(0, \DB::table('inventory_movements')->where('sparepart_branch_id', $sparepartBranch->id)->count());
    }

    public function test_post_recomputes_against_current_stock_and_notes_the_drift_when_stock_changed_since_submission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 20);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        // At creation/approval time, system_qty was 20 and physical count was 15 (adjustment_qty = -5).
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 20, 'physical_qty' => 15, 'adjustment_qty' => -5, 'reason' => 'Rusak',
        ]);
        // Simulate another movement (e.g. a Goods Receipt) landing between approval and posting.
        $sparepartBranch->stock()->update(['on_hand_qty' => 22]);

        $this->actingAs(User::find($poster->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(15.0, (float) $stock->on_hand_qty, 'Final on_hand_qty must exactly equal physical_qty regardless of drift.');
        $movement = \DB::table('inventory_movements')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame('adjustment_out', $movement->movement_type);
        $this->assertSame(7.0, (float) $movement->qty_out, 'Recomputed delta must be 15 - 22 = -7, not the stale -5.');
        $this->assertSame(15.0, (float) $movement->balance_after);
        $this->assertNotNull($movement->notes);
        $this->assertStringContainsString('bergeser', $movement->notes);
    }

    public function test_post_is_forbidden_without_stock_adjustment_post_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $response->assertForbidden();
    }

    public function test_post_is_forbidden_for_a_pending_approval_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $response->assertForbidden();
    }

    public function test_post_rejects_the_whole_batch_when_any_line_physical_qty_is_below_reserved_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $sparepartBranch->stock()->update(['reserved_qty' => 8]);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 5, 'adjustment_qty' => -5, 'reason' => 'Rusak',
        ]);

        $response = $this->actingAs(User::find($poster->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $response->assertSessionHas('status', function ($message) {
            return str_contains($message, 'OLI-01') && str_contains($message, 'PKB terkait');
        });
        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::APPROVED, $stockAdjustment->status, 'A rejected post must leave the document APPROVED, not POSTED.');
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(10.0, (float) $stock->on_hand_qty, 'Rejected posting must not mutate on_hand_qty.');
        $this->assertSame(0, \DB::table('inventory_movements')->where('sparepart_branch_id', $sparepartBranch->id)->count());
    }

    public function test_post_rejects_the_whole_batch_even_when_only_one_of_multiple_lines_violates_reserved_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $okSparepartBranch = $this->makeSparepartBranch($branch, '-ok', 10);
        $violatingSparepartBranch = $this->makeSparepartBranch($branch, '-bad', 10);
        $violatingSparepartBranch->stock()->update(['reserved_qty' => 8]);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $okSparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 15, 'adjustment_qty' => 5, 'reason' => 'Ditemukan lebih', 'sort_order' => 0,
        ]);
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $violatingSparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 5, 'adjustment_qty' => -5, 'reason' => 'Rusak', 'sort_order' => 1,
        ]);

        $this->actingAs(User::find($poster->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::APPROVED, $stockAdjustment->status, 'Nothing should be posted when any line violates reserved_qty.');
        $okStock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $okSparepartBranch->id)->first();
        $this->assertSame(10.0, (float) $okStock->on_hand_qty, 'The valid line must not be posted either — all-or-nothing.');
        $this->assertSame(
            0,
            \DB::table('inventory_movements')->whereIn('sparepart_branch_id', [$okSparepartBranch->id, $violatingSparepartBranch->id])->count()
        );
    }

    public function test_post_appends_a_note_to_the_adjustment_when_recomputed_delta_lands_on_zero_after_stock_already_drifted_to_match(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 20);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 20, 'physical_qty' => 15, 'adjustment_qty' => -5, 'reason' => 'Rusak',
        ]);
        // Simulate the discrepancy having already been resolved by another movement before posting.
        $sparepartBranch->stock()->update(['on_hand_qty' => 15]);

        $response = $this->actingAs(User::find($poster->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::POSTED, $stockAdjustment->status);
        $this->assertSame(0, \DB::table('inventory_movements')->where('sparepart_branch_id', $sparepartBranch->id)->count());
        $this->assertNotNull($stockAdjustment->notes);
        $this->assertStringContainsString('OLI-01', $stockAdjustment->notes);
        $this->assertStringContainsString('sudah sesuai', $stockAdjustment->notes);
    }

    public function test_submit_second_call_with_a_stale_in_memory_status_flashes_an_accurate_message(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname',
        ]);
        $this->actingAs(User::find($user->id));
        // Simulate two requests that both loaded the StockAdjustment (e.g. via route-model
        // binding) while it was still DRAFT, before either had actually processed the submit —
        // the controller must re-check status from a locked, freshly-read row inside the
        // transaction rather than trusting the in-memory object's (possibly stale) status, and
        // the second call's response must say so instead of falsely claiming success.
        $staleOne = StockAdjustment::find($stockAdjustment->id);
        $staleTwo = StockAdjustment::find($stockAdjustment->id);

        $controller = app(\App\Http\Controllers\StockAdjustmentController::class);
        $controller->submit($staleOne);
        $controller->submit($staleTwo);

        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::PENDING_APPROVAL, $stockAdjustment->status);
        $this->assertStringContainsString('sudah tidak dalam status draft', session('status'));
    }

    public function test_cancel_from_draft_sets_cancelled_with_no_stock_impact(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.cancel');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname',
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/cancel");

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::CANCELLED, $stockAdjustment->status);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(10.0, (float) $stock->on_hand_qty);
    }

    public function test_cancel_from_pending_approval_sets_cancelled_with_no_stock_impact(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.cancel');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/cancel");

        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::CANCELLED, $stockAdjustment->status);
    }

    public function test_cancel_from_approved_sets_cancelled_with_no_stock_impact(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $approver = User::factory()->create();
        $this->grantBranchPermission($approver, $branch, 'stock_adjustment.cancel');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
            'approved_by' => $approver->id, 'approved_at' => now(),
        ]);

        $response = $this->actingAs(User::find($approver->id))->patch("/stock-adjustments/{$stockAdjustment->id}/cancel");

        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::CANCELLED, $stockAdjustment->status);
        $this->assertNotNull($stockAdjustment->approved_by, 'approved_by must remain as historical trace after cancellation.');
    }

    public function test_cancel_is_forbidden_for_a_posted_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.cancel');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::POSTED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/cancel");

        $response->assertForbidden();
    }

    public function test_show_renders_submit_button_for_a_draft_adjustment_with_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname',
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-adjustments/{$stockAdjustment->id}");

        $response->assertOk();
        $response->assertSee(route('stock-adjustments.submit', $stockAdjustment), false);
    }

    public function test_show_renders_approve_button_for_a_pending_approval_adjustment_with_approve_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-adjustments/{$stockAdjustment->id}");

        $response->assertOk();
        $response->assertSee(route('stock-adjustments.approve', $stockAdjustment), false);
    }

    public function test_show_renders_post_button_for_an_approved_adjustment_with_post_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-adjustments/{$stockAdjustment->id}");

        $response->assertOk();
        $response->assertSee(route('stock-adjustments.post', $stockAdjustment), false);
    }

    public function test_show_hides_all_action_buttons_for_a_posted_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.post');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.cancel');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::POSTED,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-adjustments/{$stockAdjustment->id}");

        $response->assertOk();
        $response->assertDontSee(route('stock-adjustments.submit', $stockAdjustment), false);
        $response->assertDontSee(route('stock-adjustments.approve', $stockAdjustment), false);
        $response->assertDontSee(route('stock-adjustments.post', $stockAdjustment), false);
        $response->assertDontSee(route('stock-adjustments.cancel', $stockAdjustment), false);
    }
}
