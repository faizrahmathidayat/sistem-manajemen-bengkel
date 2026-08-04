<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\StockAdjustmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentAuthorizationTest extends TestCase
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

    protected function makeStockAdjustment(Branch $branch, array $overrides = []): StockAdjustment
    {
        return StockAdjustment::create(array_merge([
            'number' => 'SA/JKT/202608/00001',
            'branch_id' => $branch->id,
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Stock opname',
        ], $overrides));
    }

    public function test_policy_grants_view_and_update_for_the_correct_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = $this->makeStockAdjustment($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $stockAdjustment));
        $this->assertTrue($reloaded->can('update', $stockAdjustment));
    }

    public function test_policy_denies_access_for_a_user_with_permission_in_a_different_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'stock_adjustment.view');
        $stockAdjustment = $this->makeStockAdjustment($branchB);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('view', $stockAdjustment));
    }

    public function test_policy_update_requires_create_code_not_just_view(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $stockAdjustment = $this->makeStockAdjustment($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $stockAdjustment));
        $this->assertFalse($reloaded->can('update', $stockAdjustment));
    }

    public function test_policy_grants_submit_for_a_draft_adjustment_with_create_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = $this->makeStockAdjustment($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('submit', $stockAdjustment));
    }

    public function test_policy_denies_submit_for_a_non_draft_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = $this->makeStockAdjustment($branch, ['status' => StockAdjustmentStatus::PENDING_APPROVAL]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('submit', $stockAdjustment));
    }

    public function test_policy_grants_approve_for_a_pending_approval_adjustment_with_approve_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $stockAdjustment = $this->makeStockAdjustment($branch, ['status' => StockAdjustmentStatus::PENDING_APPROVAL]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('approve', $stockAdjustment));
    }

    public function test_policy_denies_approve_for_a_draft_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $stockAdjustment = $this->makeStockAdjustment($branch);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('approve', $stockAdjustment));
    }

    public function test_policy_grants_post_for_an_approved_adjustment_with_post_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.post');
        $stockAdjustment = $this->makeStockAdjustment($branch, ['status' => StockAdjustmentStatus::APPROVED]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('post', $stockAdjustment));
    }

    public function test_policy_denies_post_for_a_pending_approval_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.post');
        $stockAdjustment = $this->makeStockAdjustment($branch, ['status' => StockAdjustmentStatus::PENDING_APPROVAL]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('post', $stockAdjustment));
    }

    public function test_policy_grants_cancel_for_draft_pending_approval_and_approved_statuses(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.cancel');

        foreach ([StockAdjustmentStatus::DRAFT, StockAdjustmentStatus::PENDING_APPROVAL, StockAdjustmentStatus::APPROVED] as $index => $status) {
            $stockAdjustment = $this->makeStockAdjustment($branch, ['number' => "SA/JKT/202608/0000{$index}9", 'status' => $status]);
            $reloaded = User::find($user->id);
            $this->assertTrue($reloaded->can('cancel', $stockAdjustment), "Expected cancel to be allowed from status {$status}");
        }
    }

    public function test_policy_denies_cancel_for_a_posted_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.cancel');
        $stockAdjustment = $this->makeStockAdjustment($branch, ['status' => StockAdjustmentStatus::POSTED]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('cancel', $stockAdjustment));
    }

    public function test_policy_denies_update_and_submit_for_a_posted_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = $this->makeStockAdjustment($branch, ['status' => StockAdjustmentStatus::POSTED]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('update', $stockAdjustment));
        $this->assertFalse($reloaded->can('submit', $stockAdjustment));
    }
}
