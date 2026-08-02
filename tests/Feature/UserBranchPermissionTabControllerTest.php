<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserBranchPermissionTabControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermissions(array $codes): User
    {
        $user = User::factory()->create();

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $code]
            );
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        return User::find($user->id);
    }

    public function test_granting_a_branch_scoped_permission_creates_the_row(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $menu = Menu::create(['code' => 'operasional.pkb', 'name' => 'Perintah Kerja Bengkel', 'sort_order' => 1, 'is_branch_scoped' => true]);
        $permission = Permission::create(['menu_id' => $menu->id, 'code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        $admin = $this->userWithPermissions(['user_permission.manage']);
        (new UserBranchService())->assign($target, $branch);

        $response = $this->actingAs($admin)->postJson("/users/{$target->id}/branches/{$branch->id}/permissions/{$permission->id}");

        $response->assertOk();
        $this->assertDatabaseHas('user_branch_permissions', [
            'user_id' => $target->id,
            'branch_id' => $branch->id,
            'permission_id' => $permission->id,
            'granted_by' => $admin->id,
        ]);
    }

    public function test_revoking_a_branch_scoped_permission_removes_only_that_branchs_row(): void
    {
        $target = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        UserBranchPermission::create(['user_id' => $target->id, 'branch_id' => $branchA->id, 'permission_id' => $permission->id]);
        UserBranchPermission::create(['user_id' => $target->id, 'branch_id' => $branchB->id, 'permission_id' => $permission->id]);
        $admin = $this->userWithPermissions(['user_permission.manage']);

        $response = $this->actingAs($admin)->deleteJson("/users/{$target->id}/branches/{$branchA->id}/permissions/{$permission->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_branch_permissions', ['user_id' => $target->id, 'branch_id' => $branchA->id, 'permission_id' => $permission->id]);
        $this->assertDatabaseHas('user_branch_permissions', ['user_id' => $target->id, 'branch_id' => $branchB->id, 'permission_id' => $permission->id]);
    }

    public function test_endpoints_are_forbidden_without_permission(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/users/{$target->id}/branches/{$branch->id}/permissions/{$permission->id}")->assertForbidden();
    }

    public function test_destroy_is_forbidden_without_permission(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        UserBranchPermission::create(['user_id' => $target->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
        $user = User::factory()->create();

        $this->actingAs($user)->deleteJson("/users/{$target->id}/branches/{$branch->id}/permissions/{$permission->id}")->assertForbidden();

        $this->assertDatabaseHas('user_branch_permissions', [
            'user_id' => $target->id,
            'branch_id' => $branch->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_granting_a_global_menu_permission_is_rejected(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $menu = Menu::create(['code' => 'administrasi.users', 'name' => 'Users', 'sort_order' => 1, 'is_branch_scoped' => false]);
        $permission = Permission::create(['menu_id' => $menu->id, 'code' => 'user.view', 'resource' => 'user', 'action' => 'view', 'description' => 'Melihat user']);
        $admin = $this->userWithPermissions(['user_permission.manage']);
        (new UserBranchService())->assign($target, $branch);

        $response = $this->actingAs($admin)->postJson("/users/{$target->id}/branches/{$branch->id}/permissions/{$permission->id}");

        $response->assertStatus(422);
        $this->assertDatabaseMissing('user_branch_permissions', [
            'user_id' => $target->id,
            'branch_id' => $branch->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_granting_a_permission_for_a_branch_the_user_is_not_assigned_to_is_rejected(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $menu = Menu::create(['code' => 'operasional.pkb', 'name' => 'Perintah Kerja Bengkel', 'sort_order' => 1, 'is_branch_scoped' => true]);
        $permission = Permission::create(['menu_id' => $menu->id, 'code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        $admin = $this->userWithPermissions(['user_permission.manage']);

        $response = $this->actingAs($admin)->postJson("/users/{$target->id}/branches/{$branch->id}/permissions/{$permission->id}");

        $response->assertStatus(422);
        $this->assertDatabaseMissing('user_branch_permissions', [
            'user_id' => $target->id,
            'branch_id' => $branch->id,
            'permission_id' => $permission->id,
        ]);
    }
}
