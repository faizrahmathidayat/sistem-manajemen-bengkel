<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserBranchPermissionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_with_valid_relations(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);

        $grant = UserBranchPermission::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'permission_id' => $permission->id,
            'granted_by' => $user->id,
        ]);

        $this->assertSame($user->id, $grant->user->id);
        $this->assertSame($branch->id, $grant->branch->id);
        $this->assertSame($permission->id, $grant->permission->id);
        $this->assertSame($user->id, $grant->granter->id);
    }

    public function test_same_permission_can_be_granted_in_two_different_branches(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);

        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branchA->id, 'permission_id' => $permission->id]);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branchB->id, 'permission_id' => $permission->id]);

        $this->assertSame(2, UserBranchPermission::where('user_id', $user->id)->count());
    }

    public function test_duplicate_grant_for_same_user_branch_permission_is_rejected(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);

        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $this->expectException(QueryException::class);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }
}
