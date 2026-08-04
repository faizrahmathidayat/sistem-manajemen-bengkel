<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\GoodsReceiptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodsReceiptAuthorizationTest extends TestCase
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

    protected function makeGoodsReceipt(Branch $branch, array $overrides = []): GoodsReceipt
    {
        return GoodsReceipt::create(array_merge([
            'number' => 'PB/JKT/202608/00001',
            'branch_id' => $branch->id,
            'receipt_date' => now()->format('Y-m-d'),
        ], $overrides));
    }

    public function test_policy_grants_view_and_update_for_the_correct_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.view');
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $goodsReceipt = $this->makeGoodsReceipt($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $goodsReceipt));
        $this->assertTrue($reloaded->can('update', $goodsReceipt));
    }

    public function test_policy_denies_access_for_a_user_with_permission_in_a_different_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'receipt.view');
        $goodsReceipt = $this->makeGoodsReceipt($branchB);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('view', $goodsReceipt));
    }

    public function test_policy_update_requires_create_code_not_just_view(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.view');
        $goodsReceipt = $this->makeGoodsReceipt($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $goodsReceipt));
        $this->assertFalse($reloaded->can('update', $goodsReceipt));
    }

    public function test_policy_denies_update_post_and_cancel_for_a_posted_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->grantBranchPermission($user, $branch, 'receipt.cancel');
        $goodsReceipt = $this->makeGoodsReceipt($branch, ['status' => GoodsReceiptStatus::POSTED]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('update', $goodsReceipt));
        $this->assertFalse($reloaded->can('post', $goodsReceipt));
        $this->assertFalse($reloaded->can('cancel', $goodsReceipt));
    }

    public function test_policy_grants_post_for_a_draft_receipt_with_post_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $goodsReceipt = $this->makeGoodsReceipt($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('post', $goodsReceipt));
    }

    public function test_policy_grants_cancel_for_a_draft_receipt_with_cancel_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.cancel');
        $goodsReceipt = $this->makeGoodsReceipt($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('cancel', $goodsReceipt));
    }
}
