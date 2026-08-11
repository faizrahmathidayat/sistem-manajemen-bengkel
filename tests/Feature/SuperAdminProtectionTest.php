<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminProtectionTest extends TestCase
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

    protected function makeSuperAdmin(array $codes = []): User
    {
        $superAdmin = User::factory()->create(['username' => config('app.superadmin_username'), 'name' => 'Super Admin']);

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $code]
            );
            UserPermission::create(['user_id' => $superAdmin->id, 'permission_id' => $permission->id]);
        }

        return User::find($superAdmin->id);
    }

    public function test_index_hides_superadmin_from_non_superadmin_viewer(): void
    {
        $this->makeSuperAdmin();
        $viewer = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($viewer)->get('/users');

        $response->assertOk();
        $response->assertDontSee('Super Admin');
    }

    public function test_index_shows_superadmin_to_superadmin_viewer(): void
    {
        $superAdmin = $this->makeSuperAdmin(['user.view']);

        $response = $this->actingAs($superAdmin)->get('/users');

        $response->assertOk();
        $response->assertSee('Super Admin');
    }

    public function test_show_is_forbidden_for_non_superadmin_viewing_superadmin(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $viewer = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($viewer)->get("/users/{$superAdmin->id}");

        $response->assertForbidden();
    }

    public function test_show_is_allowed_for_superadmin_viewing_self(): void
    {
        $superAdmin = $this->makeSuperAdmin(['user.view']);

        $response = $this->actingAs($superAdmin)->get("/users/{$superAdmin->id}");

        $response->assertOk();
    }

    public function test_show_still_allowed_for_non_superadmin_viewing_a_regular_user(): void
    {
        $target = User::factory()->create(['name' => 'Budi Santoso']);
        $viewer = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($viewer)->get("/users/{$target->id}");

        $response->assertOk();
    }

    public function test_update_is_forbidden_for_non_superadmin_updating_superadmin(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $actor = $this->userWithPermissions(['user.edit']);

        $response = $this->actingAs($actor)->put("/users/{$superAdmin->id}", [
            'name' => 'Hacked Name',
            'username' => $superAdmin->username,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'name' => 'Super Admin']);
    }
}
