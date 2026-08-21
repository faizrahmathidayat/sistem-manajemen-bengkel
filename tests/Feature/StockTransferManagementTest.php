<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\TransferStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferManagementTest extends TestCase
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

    protected function makeSparepartAtBranches(Branch $from, Branch $to, string $codeSuffix = ''): Sparepart
    {
        $sparepart = Sparepart::create(['code' => "OLI-01{$codeSuffix}", 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $from->id, 'selling_price' => 60000]);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $to->id, 'selling_price' => 60000]);

        return $sparepart;
    }

    protected function baseStorePayload(Branch $from, Branch $to, Sparepart $sparepart, float $qty = 5): array
    {
        return [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
            'lines' => [
                ['sparepart_id' => $sparepart->id, 'qty' => $qty],
            ],
        ];
    }

    public function test_store_creates_stock_transfer_with_lines(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));

        $stockTransfer = StockTransfer::first();
        $response->assertRedirect(route('stock-transfers.show', $stockTransfer));
        $this->assertSame(TransferStatus::DRAFT, $stockTransfer->status);
        $this->assertStringStartsWith('ST/JKT/', $stockTransfer->number);
        $this->assertCount(1, $stockTransfer->lines);
        $this->assertSame(5.0, (float) $stockTransfer->lines->first()->qty);
    }

    public function test_store_rejects_decimal_qty(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart, 2.5));

        $response->assertSessionHasErrors(['lines.0.qty']);
        $this->assertSame(0, StockTransfer::count());
    }

    public function test_store_is_forbidden_without_stock_transfer_create_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));

        $response->assertForbidden();
    }

    public function test_store_rejects_a_transfer_with_no_lines(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'transfer_date' => now()->format('Y-m-d'), 'lines' => [],
        ]);

        $response->assertSessionHasErrors(['lines']);
    }

    public function test_store_rejects_duplicate_sparepart_in_same_document(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'transfer_date' => now()->format('Y-m-d'),
            'lines' => [
                ['sparepart_id' => $sparepart->id, 'qty' => 3],
                ['sparepart_id' => $sparepart->id, 'qty' => 2],
            ],
        ]);

        $response->assertSessionHasErrors(['lines.0.sparepart_id', 'lines.1.sparepart_id']);
    }

    public function test_store_rejects_same_from_and_to_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', [
            'from_branch_id' => $branch->id, 'to_branch_id' => $branch->id, 'transfer_date' => now()->format('Y-m-d'),
            'lines' => [['sparepart_id' => $sparepart->id, 'qty' => 3]],
        ]);

        $response->assertSessionHasErrors(['to_branch_id']);
    }

    public function test_store_rejects_sparepart_not_configured_at_origin_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $to->id, 'selling_price' => 60000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));

        $response->assertSessionHasErrors(['lines.0.sparepart_id']);
    }

    public function test_store_rejects_sparepart_not_configured_at_destination_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $from->id, 'selling_price' => 60000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));

        $response->assertSessionHasErrors(['lines.0.sparepart_id']);
    }

    public function test_index_lists_transfers_visible_from_either_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $other = Branch::create(['code' => 'SBY', 'name' => 'Cabang Surabaya']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.view');
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));
        $visibleTransfer = StockTransfer::first();

        $response = $this->actingAs(User::find($user->id))->get('/stock-transfers');

        $response->assertOk();
        $response->assertSee($visibleTransfer->number);
    }

    public function test_index_filters_by_status(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $draft = StockTransfer::create([
            'number' => 'ST-DRAFT-TEST', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::DRAFT,
        ]);
        $approved = StockTransfer::create([
            'number' => 'ST-APPROVED-TEST', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');

        $response = $this->actingAs($user)->get('/stock-transfers?status=' . TransferStatus::APPROVED);

        $response->assertOk();
        $response->assertSee($approved->number);
        $response->assertDontSee($draft->number);
    }

    public function test_index_shows_no_access_page_without_any_stock_transfer_view_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/stock-transfers');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_index_shows_empty_state_when_no_transfers_match(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_transfer.view');

        $response = $this->actingAs(User::find($user->id))->get('/stock-transfers');

        $response->assertOk();
        $response->assertSee('Belum ada transfer stock');
    }

    public function test_index_renders_all_five_status_badges_correctly(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $statuses = [
            TransferStatus::DRAFT => 'Draft',
            TransferStatus::APPROVED => 'Disetujui',
            TransferStatus::DISPATCHED => 'Dikirim',
            TransferStatus::RECEIVED => 'Diterima',
            TransferStatus::CANCELLED => 'Dibatalkan',
        ];
        foreach (array_keys($statuses) as $index => $status) {
            StockTransfer::create([
                'number' => "ST/JKT/202608/0000{$index}9",
                'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
                'transfer_date' => now()->format('Y-m-d'), 'status' => $status,
            ]);
        }

        $response = $this->actingAs(User::find($user->id))->get('/stock-transfers');

        $response->assertOk();
        foreach ($statuses as $label) {
            $response->assertSee($label);
        }
    }

    public function test_create_form_renders_for_a_user_with_stock_transfer_create_in_some_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->get('/stock-transfers/create');

        $response->assertOk();
    }

    public function test_create_page_loads_select2_for_sparepart_picker(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->get('/stock-transfers/create');

        $response->assertOk();
        $response->assertSee('select2-ajax-picker.js', false);
    }

    public function test_create_form_replays_old_lines_after_a_validation_error(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $payload = $this->baseStorePayload($from, $to, $sparepart, 7);
        unset($payload['transfer_date']);

        $this->from(route('stock-transfers.create'))->actingAs(User::find($user->id))->post('/stock-transfers', $payload);
        $response = $this->actingAs(User::find($user->id))->get(route('stock-transfers.create'));

        $response->assertOk();
        $response->assertSee('oldLines', false);
        $response->assertSee('"qty":7', false);
    }

    public function test_edit_form_renders_for_a_user_with_stock_transfer_create_on_a_draft_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));
        $stockTransfer = StockTransfer::first();

        $response = $this->actingAs(User::find($user->id))->get("/stock-transfers/{$stockTransfer->id}/edit");

        $response->assertOk();
    }

    public function test_update_successfully_replaces_lines(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $newSparepart = $this->makeSparepartAtBranches($from, $to, '-updated');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart, 5));
        $stockTransfer = StockTransfer::first();

        $response = $this->actingAs(User::find($user->id))->put("/stock-transfers/{$stockTransfer->id}", [
            'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
            'lines' => [['sparepart_id' => $newSparepart->id, 'qty' => 9]],
        ]);

        $response->assertRedirect(route('stock-transfers.show', $stockTransfer));
        $stockTransfer->refresh();
        $this->assertCount(1, $stockTransfer->lines);
        $line = $stockTransfer->lines->first();
        $this->assertSame($newSparepart->id, $line->sparepart_id);
        $this->assertSame(9.0, (float) $line->qty);
    }

    public function test_update_can_change_destination_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $newTo = Branch::create(['code' => 'SBY', 'name' => 'Cabang Surabaya']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $newTo->id, 'selling_price' => 60000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));
        $stockTransfer = StockTransfer::first();

        $this->actingAs(User::find($user->id))->put("/stock-transfers/{$stockTransfer->id}", [
            'to_branch_id' => $newTo->id,
            'transfer_date' => now()->format('Y-m-d'),
            'lines' => [['sparepart_id' => $sparepart->id, 'qty' => 5]],
        ]);

        $stockTransfer->refresh();
        $this->assertSame($newTo->id, $stockTransfer->to_branch_id);
    }

    public function test_update_is_forbidden_for_a_non_draft_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->put("/stock-transfers/{$stockTransfer->id}", [
            'to_branch_id' => $to->id, 'transfer_date' => now()->format('Y-m-d'),
            'lines' => [['sparepart_id' => $sparepart->id, 'qty' => 1]],
        ]);

        $response->assertForbidden();
    }

    public function test_show_renders_both_branches_and_status_badge(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-transfers/{$stockTransfer->id}");

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
        $response->assertSee('Cabang Bandung');
        $response->assertSee('<span class="status-dot status-active">Draft</span>', false);
    }

    public function test_approve_moves_draft_to_approved(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $approver = User::factory()->create();
        $this->grantBranchPermission($approver, $from, 'stock_transfer.approve');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs(User::find($approver->id))->patch("/stock-transfers/{$stockTransfer->id}/approve");

        $response->assertRedirect(route('stock-transfers.show', $stockTransfer));
        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::APPROVED, $stockTransfer->status);
        $this->assertSame($approver->id, $stockTransfer->approved_by);
        $this->assertNotNull($stockTransfer->approved_at);
    }

    public function test_approve_is_forbidden_without_stock_transfer_approve_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/approve");

        $response->assertForbidden();
    }

    public function test_approve_is_forbidden_for_a_non_draft_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/approve");

        $response->assertForbidden();
    }

    public function test_dispatch_decreases_origin_stock_and_writes_transfer_out_movement(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $fromSparepartBranch = SparepartBranch::where('sparepart_id', $sparepart->id)->where('branch_id', $from->id)->first();
        $fromSparepartBranch->stock()->update(['on_hand_qty' => 20]);
        $dispatcher = User::factory()->create();
        $this->grantBranchPermission($dispatcher, $from, 'stock_transfer.dispatch');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);
        \App\Models\StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 8]);

        $response = $this->actingAs(User::find($dispatcher->id))->patch("/stock-transfers/{$stockTransfer->id}/dispatch");

        $response->assertRedirect(route('stock-transfers.show', $stockTransfer));
        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::DISPATCHED, $stockTransfer->status);
        $this->assertNotNull($stockTransfer->dispatched_by);
        $fromStock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $fromSparepartBranch->id)->first();
        $this->assertSame(12.0, (float) $fromStock->on_hand_qty);
        $movement = \DB::table('inventory_movements')->where('sparepart_branch_id', $fromSparepartBranch->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame('transfer_out', $movement->movement_type);
        $this->assertSame(8.0, (float) $movement->qty_out);
        $this->assertSame(12.0, (float) $movement->balance_after);
    }

    public function test_dispatch_rejects_the_whole_batch_when_any_line_violates_reserved_qty_at_origin(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $okSparepart = $this->makeSparepartAtBranches($from, $to, '-ok');
        $badSparepart = $this->makeSparepartAtBranches($from, $to, '-bad');
        $okFromStock = SparepartBranch::where('sparepart_id', $okSparepart->id)->where('branch_id', $from->id)->first();
        $okFromStock->stock()->update(['on_hand_qty' => 20]);
        $badFromStock = SparepartBranch::where('sparepart_id', $badSparepart->id)->where('branch_id', $from->id)->first();
        $badFromStock->stock()->update(['on_hand_qty' => 10, 'reserved_qty' => 8]);
        $dispatcher = User::factory()->create();
        $this->grantBranchPermission($dispatcher, $from, 'stock_transfer.dispatch');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);
        \App\Models\StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $okSparepart->id, 'qty' => 5, 'sort_order' => 0]);
        \App\Models\StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $badSparepart->id, 'qty' => 5, 'sort_order' => 1]);

        $response = $this->actingAs(User::find($dispatcher->id))->patch("/stock-transfers/{$stockTransfer->id}/dispatch");

        $response->assertSessionHas('error', function ($message) {
            return str_contains($message, 'OLI-01-bad');
        });
        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::APPROVED, $stockTransfer->status, 'A rejected dispatch must leave the document APPROVED.');
        $okStock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $okFromStock->id)->first();
        $this->assertSame(20.0, (float) $okStock->on_hand_qty, 'The valid line must not be dispatched either — all-or-nothing.');
        $this->assertSame(0, \DB::table('inventory_movements')->count());
    }

    public function test_dispatch_is_forbidden_without_stock_transfer_dispatch_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/dispatch");

        $response->assertForbidden();
    }

    public function test_dispatch_is_forbidden_for_a_draft_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.dispatch');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/dispatch");

        $response->assertForbidden();
    }

    public function test_receive_increases_destination_stock_and_writes_transfer_in_movement(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $toSparepartBranch = SparepartBranch::where('sparepart_id', $sparepart->id)->where('branch_id', $to->id)->first();
        $toSparepartBranch->stock()->update(['on_hand_qty' => 3]);
        $receiver = User::factory()->create();
        $this->grantBranchPermission($receiver, $to, 'stock_transfer.receive');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::DISPATCHED,
        ]);
        \App\Models\StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 8]);

        $response = $this->actingAs(User::find($receiver->id))->patch("/stock-transfers/{$stockTransfer->id}/receive");

        $response->assertRedirect(route('stock-transfers.show', $stockTransfer));
        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::RECEIVED, $stockTransfer->status);
        $this->assertNotNull($stockTransfer->received_by);
        $toStock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $toSparepartBranch->id)->first();
        $this->assertSame(11.0, (float) $toStock->on_hand_qty);
        $movement = \DB::table('inventory_movements')->where('sparepart_branch_id', $toSparepartBranch->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame('transfer_in', $movement->movement_type);
        $this->assertSame(8.0, (float) $movement->qty_in);
        $this->assertSame(11.0, (float) $movement->balance_after);
    }

    public function test_receive_rejects_when_destination_sparepart_branch_was_deactivated_after_dispatch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $toSparepartBranch = SparepartBranch::where('sparepart_id', $sparepart->id)->where('branch_id', $to->id)->first();
        $toSparepartBranch->update(['is_active' => false]);
        $receiver = User::factory()->create();
        $this->grantBranchPermission($receiver, $to, 'stock_transfer.receive');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::DISPATCHED,
        ]);
        \App\Models\StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 8]);

        $response = $this->actingAs(User::find($receiver->id))->patch("/stock-transfers/{$stockTransfer->id}/receive");

        $response->assertSessionHas('error');
        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::DISPATCHED, $stockTransfer->status, 'A rejected receive must leave the document DISPATCHED.');
        $this->assertSame(0, \DB::table('inventory_movements')->count());
    }

    public function test_receive_is_forbidden_without_stock_transfer_receive_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.view');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::DISPATCHED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/receive");

        $response->assertForbidden();
    }

    public function test_receive_is_forbidden_for_an_approved_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.receive');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/receive");

        $response->assertForbidden();
    }

    public function test_cancel_from_draft_sets_cancelled_with_no_stock_impact(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.cancel');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/cancel");

        $response->assertRedirect(route('stock-transfers.show', $stockTransfer));
        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::CANCELLED, $stockTransfer->status);
    }

    public function test_cancel_from_approved_sets_cancelled_with_no_stock_impact(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.cancel');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/cancel");

        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::CANCELLED, $stockTransfer->status);
    }

    public function test_cancel_is_forbidden_for_a_dispatched_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.cancel');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::DISPATCHED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/cancel");

        $response->assertForbidden();
    }

    public function test_show_renders_approve_button_for_a_draft_transfer_with_approve_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-transfers/{$stockTransfer->id}");

        $response->assertOk();
        $response->assertSee(route('stock-transfers.approve', $stockTransfer), false);
    }

    public function test_show_renders_dispatch_button_for_an_approved_transfer_with_dispatch_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $this->grantBranchPermission($user, $from, 'stock_transfer.dispatch');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-transfers/{$stockTransfer->id}");

        $response->assertOk();
        $response->assertSee(route('stock-transfers.dispatch', $stockTransfer), false);
    }

    public function test_show_renders_receive_button_for_a_dispatched_transfer_with_receive_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.view');
        $this->grantBranchPermission($user, $to, 'stock_transfer.receive');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::DISPATCHED,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-transfers/{$stockTransfer->id}");

        $response->assertOk();
        $response->assertSee(route('stock-transfers.receive', $stockTransfer), false);
    }

    public function test_show_hides_all_action_buttons_for_a_received_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $this->grantBranchPermission($user, $from, 'stock_transfer.dispatch');
        $this->grantBranchPermission($user, $from, 'stock_transfer.cancel');
        $this->grantBranchPermission($user, $to, 'stock_transfer.receive');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::RECEIVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-transfers/{$stockTransfer->id}");

        $response->assertOk();
        $response->assertDontSee(route('stock-transfers.approve', $stockTransfer), false);
        $response->assertDontSee(route('stock-transfers.dispatch', $stockTransfer), false);
        $response->assertDontSee(route('stock-transfers.receive', $stockTransfer), false);
        $response->assertDontSee(route('stock-transfers.cancel', $stockTransfer), false);
    }
}
