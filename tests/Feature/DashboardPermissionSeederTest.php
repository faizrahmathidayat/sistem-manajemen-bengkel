<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Database\Seeders\DashboardPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_dashboard_menu_and_permission(): void
    {
        $this->seed(DashboardPermissionSeeder::class);

        $this->assertDatabaseHas('menus', ['code' => 'umum.dashboard', 'is_branch_scoped' => false]);
        $this->assertDatabaseHas('permissions', ['code' => 'dashboard.view']);
    }

    public function test_seeder_grants_dashboard_view_to_every_existing_user(): void
    {
        $userA = User::create(['username' => 'user_a', 'name' => 'User A', 'password' => Hash::make('secret'), 'is_active' => true]);
        $userB = User::create(['username' => 'user_b', 'name' => 'User B', 'password' => Hash::make('secret'), 'is_active' => true]);

        $this->seed(DashboardPermissionSeeder::class);

        $permission = Permission::where('code', 'dashboard.view')->first();
        $this->assertNotNull($permission);
        $this->assertDatabaseHas('user_permissions', ['user_id' => $userA->id, 'permission_id' => $permission->id]);
        $this->assertDatabaseHas('user_permissions', ['user_id' => $userB->id, 'permission_id' => $permission->id]);
    }

    public function test_seeder_does_not_duplicate_grants_when_run_twice(): void
    {
        $user = User::create(['username' => 'user_a', 'name' => 'User A', 'password' => Hash::make('secret'), 'is_active' => true]);

        $this->seed(DashboardPermissionSeeder::class);
        $this->seed(DashboardPermissionSeeder::class);

        $permission = Permission::where('code', 'dashboard.view')->first();
        $this->assertSame(1, UserPermission::where('user_id', $user->id)->where('permission_id', $permission->id)->count());
    }

    public function test_seeder_grants_dashboard_view_to_users_created_before_and_after_seeding_menu(): void
    {
        $existingUser = User::create(['username' => 'existing_user', 'name' => 'Existing User', 'password' => Hash::make('secret'), 'is_active' => true]);

        $this->seed(DashboardPermissionSeeder::class);

        $permission = Permission::where('code', 'dashboard.view')->first();
        $grantedUserIds = UserPermission::where('permission_id', $permission->id)->pluck('user_id')->all();

        $this->assertContains($existingUser->id, $grantedUserIds);
    }
}
