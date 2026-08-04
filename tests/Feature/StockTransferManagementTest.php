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
}
