<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteGlobalGrantsForBranchScopedPermissionCodesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_deletes_global_grants_for_permissions_on_branch_scoped_menus(): void
    {
        $user = User::factory()->create();

        $branchScopedMenu = Menu::create(['code' => 'operasional.pkb', 'name' => 'Perintah Kerja Bengkel', 'sort_order' => 1, 'is_branch_scoped' => true]);
        $branchScopedPermission = Permission::create(['menu_id' => $branchScopedMenu->id, 'code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);

        $globalMenu = Menu::create(['code' => 'administrasi.users', 'name' => 'Users', 'sort_order' => 2, 'is_branch_scoped' => false]);
        $globalPermission = Permission::create(['menu_id' => $globalMenu->id, 'code' => 'user.view', 'resource' => 'user', 'action' => 'view', 'description' => 'Melihat user']);

        $staleGlobalGrant = UserPermission::create(['user_id' => $user->id, 'permission_id' => $branchScopedPermission->id]);
        $legitimateGlobalGrant = UserPermission::create(['user_id' => $user->id, 'permission_id' => $globalPermission->id]);

        (new \DeleteGlobalGrantsForBranchScopedPermissionCodes())->up();

        $this->assertDatabaseMissing('user_permissions', ['id' => $staleGlobalGrant->id]);
        $this->assertDatabaseHas('user_permissions', ['id' => $legitimateGlobalGrant->id]);
    }
}
