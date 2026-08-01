<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPermissionTabTest extends TestCase
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

    public function test_granting_a_permission_creates_user_permission_with_granter(): void
    {
        $target = User::factory()->create();
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        $admin = $this->userWithPermissions(['user_permission.manage']);

        $response = $this->actingAs($admin)->postJson("/users/{$target->id}/permissions/{$permission->id}");

        $response->assertOk();
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $target->id,
            'permission_id' => $permission->id,
            'granted_by' => $admin->id,
        ]);
    }

    public function test_revoking_a_permission_removes_it(): void
    {
        $target = User::factory()->create();
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        UserPermission::create(['user_id' => $target->id, 'permission_id' => $permission->id]);
        $admin = $this->userWithPermissions(['user_permission.manage']);

        $response = $this->actingAs($admin)->deleteJson("/users/{$target->id}/permissions/{$permission->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $target->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_permission_endpoints_are_forbidden_without_permission(): void
    {
        $target = User::factory()->create();
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/users/{$target->id}/permissions/{$permission->id}")->assertForbidden();
    }

    public function test_cannot_revoke_own_last_user_permission_manage(): void
    {
        $managePermission = Permission::create([
            'code' => 'user_permission.manage',
            'resource' => 'user_permission',
            'action' => 'manage',
            'description' => 'Mengelola permission user',
        ]);
        $admin = User::factory()->create();
        UserPermission::create(['user_id' => $admin->id, 'permission_id' => $managePermission->id]);
        $admin = User::find($admin->id);

        $response = $this->actingAs($admin)->deleteJson("/users/{$admin->id}/permissions/{$managePermission->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $admin->id,
            'permission_id' => $managePermission->id,
        ]);
    }

    public function test_can_revoke_own_user_permission_manage_when_another_active_holder_exists(): void
    {
        $managePermission = Permission::create([
            'code' => 'user_permission.manage',
            'resource' => 'user_permission',
            'action' => 'manage',
            'description' => 'Mengelola permission user',
        ]);
        $otherAdmin = User::factory()->create(['is_active' => true]);
        UserPermission::create(['user_id' => $otherAdmin->id, 'permission_id' => $managePermission->id]);

        $admin = User::factory()->create();
        UserPermission::create(['user_id' => $admin->id, 'permission_id' => $managePermission->id]);
        $admin = User::find($admin->id);

        $response = $this->actingAs($admin)->deleteJson("/users/{$admin->id}/permissions/{$managePermission->id}");

        $response->assertOk();
    }

    public function test_can_revoke_user_permission_manage_from_a_different_user(): void
    {
        $managePermission = Permission::create([
            'code' => 'user_permission.manage',
            'resource' => 'user_permission',
            'action' => 'manage',
            'description' => 'Mengelola permission user',
        ]);
        $target = User::factory()->create();
        UserPermission::create(['user_id' => $target->id, 'permission_id' => $managePermission->id]);

        $admin = $this->userWithPermissions(['user_permission.manage']);

        $response = $this->actingAs($admin)->deleteJson("/users/{$target->id}/permissions/{$managePermission->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $target->id,
            'permission_id' => $managePermission->id,
        ]);
    }

    public function test_show_page_renders_permission_tab_grouped_by_menu(): void
    {
        $menu = Menu::create(['code' => 'test.menu', 'name' => 'Menu Uji', 'sort_order' => 1]);
        Permission::create(['menu_id' => $menu->id, 'code' => 'test.view', 'resource' => 'test', 'action' => 'view', 'description' => 'Lihat Uji']);
        $target = User::factory()->create();
        $admin = $this->userWithPermissions(['user.view', 'user_permission.manage']);

        $response = $this->actingAs($admin)->get("/users/{$target->id}");

        $response->assertOk();
        $response->assertSee('Menu Uji');
        $response->assertSee('Lihat Uji');
    }
}
