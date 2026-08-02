<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SparepartAuthorizationTest extends TestCase
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

    public function test_branches_with_permission_returns_only_branches_where_the_code_is_granted(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        (new UserBranchService())->assign($user, $branchB);

        $reloaded = User::find($user->id);
        $result = $reloaded->branchesWithPermission('sparepart.view');

        $this->assertCount(1, $result);
        $this->assertSame($branchA->id, $result->first()->id);
    }

    public function test_branches_with_permission_returns_empty_collection_without_any_grant(): void
    {
        $user = User::factory()->create();
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $this->assertTrue($user->branchesWithPermission('sparepart.view')->isEmpty());
    }

    public function test_policy_grants_view_and_update_for_the_correct_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.edit');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $sparepartBranch));
        $this->assertTrue($reloaded->can('update', $sparepartBranch));
    }

    public function test_policy_denies_access_for_a_user_with_permission_in_a_different_branch(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $sparepartBranchInB = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchB->id, 'selling_price' => 100000]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('view', $sparepartBranchInB));
    }

    public function test_policy_delete_requires_the_delete_code_not_just_edit(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.edit');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('update', $sparepartBranch));
        $this->assertFalse($reloaded->can('delete', $sparepartBranch));
    }
}
