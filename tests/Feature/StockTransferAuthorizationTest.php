<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\TransferStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferAuthorizationTest extends TestCase
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

    protected function makeStockTransfer(Branch $from, Branch $to, array $overrides = []): StockTransfer
    {
        return StockTransfer::create(array_merge([
            'number' => 'ST/JKT/202608/00001',
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ], $overrides));
    }

    public function test_policy_grants_view_for_a_user_with_permission_in_the_from_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertTrue(User::find($user->id)->can('view', $stockTransfer));
    }

    public function test_policy_grants_view_for_a_user_with_permission_in_the_to_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.view');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertTrue(User::find($user->id)->can('view', $stockTransfer));
    }

    public function test_policy_denies_view_for_a_user_with_permission_in_neither_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $other = Branch::create(['code' => 'SBY', 'name' => 'Cabang Surabaya']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $other, 'stock_transfer.view');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertFalse(User::find($user->id)->can('view', $stockTransfer));
    }

    public function test_policy_grants_update_for_a_draft_transfer_with_create_code_in_from_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertTrue(User::find($user->id)->can('update', $stockTransfer));
    }

    public function test_policy_denies_update_for_a_user_with_create_code_only_in_to_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.create');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertFalse(User::find($user->id)->can('update', $stockTransfer));
    }

    public function test_policy_grants_approve_for_a_draft_transfer_with_approve_code_in_from_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertTrue(User::find($user->id)->can('approve', $stockTransfer));
    }

    public function test_policy_denies_approve_for_a_non_draft_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $stockTransfer = $this->makeStockTransfer($from, $to, ['status' => TransferStatus::APPROVED]);

        $this->assertFalse(User::find($user->id)->can('approve', $stockTransfer));
    }

    public function test_policy_grants_dispatch_for_an_approved_transfer_with_dispatch_code_in_from_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.dispatch');
        $stockTransfer = $this->makeStockTransfer($from, $to, ['status' => TransferStatus::APPROVED]);

        $this->assertTrue(User::find($user->id)->can('dispatch', $stockTransfer));
    }

    public function test_policy_denies_dispatch_for_a_draft_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.dispatch');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertFalse(User::find($user->id)->can('dispatch', $stockTransfer));
    }

    public function test_policy_grants_receive_for_a_dispatched_transfer_with_receive_code_in_to_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.receive');
        $stockTransfer = $this->makeStockTransfer($from, $to, ['status' => TransferStatus::DISPATCHED]);

        $this->assertTrue(User::find($user->id)->can('receive', $stockTransfer));
    }

    public function test_policy_denies_receive_for_a_user_with_receive_code_only_in_from_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.receive');
        $stockTransfer = $this->makeStockTransfer($from, $to, ['status' => TransferStatus::DISPATCHED]);

        $this->assertFalse(User::find($user->id)->can('receive', $stockTransfer));
    }

    public function test_policy_grants_cancel_for_draft_and_approved_statuses(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.cancel');

        foreach ([TransferStatus::DRAFT, TransferStatus::APPROVED] as $index => $status) {
            $stockTransfer = $this->makeStockTransfer($from, $to, ['number' => "ST/JKT/202608/0000{$index}9", 'status' => $status]);
            $this->assertTrue(User::find($user->id)->can('cancel', $stockTransfer), "Expected cancel to be allowed from status {$status}");
        }
    }

    public function test_policy_denies_cancel_for_a_dispatched_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.cancel');
        $stockTransfer = $this->makeStockTransfer($from, $to, ['status' => TransferStatus::DISPATCHED]);

        $this->assertFalse(User::find($user->id)->can('cancel', $stockTransfer));
    }
}
