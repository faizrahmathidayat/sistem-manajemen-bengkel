<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchScopedPermissionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_permission_only_in_the_branch_it_was_granted_for(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $permission = Permission::create(['code' => 'invoice.create', 'resource' => 'invoice', 'action' => 'create', 'description' => 'Membuat invoice']);
        (new UserBranchService())->assign($user, $branchA);

        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branchA->id, 'permission_id' => $permission->id]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->hasPermissionToInBranch('invoice.create', $branchA->id));
        $this->assertFalse($reloaded->hasPermissionToInBranch('invoice.create', $branchB->id));
    }

    public function test_user_without_any_grant_does_not_have_the_permission_in_any_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $this->assertFalse($user->hasPermissionToInBranch('invoice.create', $branch->id));
    }

    public function test_inactive_permission_is_not_granted_even_if_assigned_to_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create([
            'code' => 'invoice.void',
            'resource' => 'invoice',
            'action' => 'void',
            'description' => 'Void invoice',
            'is_active' => false,
        ]);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $this->assertFalse($user->hasPermissionToInBranch('invoice.void', $branch->id));
    }

    public function test_deactivated_user_does_not_have_branch_permission_even_if_granted(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'invoice.create', 'resource' => 'invoice', 'action' => 'create', 'description' => 'Membuat invoice']);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $this->assertFalse($user->hasPermissionToInBranch('invoice.create', $branch->id));
    }

    public function test_removing_the_branch_assignment_revokes_the_branch_permission_even_though_the_grant_row_still_exists(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'invoice.create', 'resource' => 'invoice', 'action' => 'create', 'description' => 'Membuat invoice']);
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $this->assertTrue($user->hasPermissionToInBranch('invoice.create', $branch->id));

        $user->userBranches()->where('branch_id', $branch->id)->update(['is_active' => false]);
        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->hasPermissionToInBranch('invoice.create', $branch->id));
        $this->assertDatabaseHas('user_branch_permissions', [
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'permission_id' => $permission->id,
        ]);
    }
}
