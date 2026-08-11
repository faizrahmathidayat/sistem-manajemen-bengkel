<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_single_branch_cabangutama(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $this->assertDatabaseHas('branches', ['code' => 'CABANGUTAMA', 'name' => 'CABANGUTAMA', 'is_active' => true]);
        $this->assertSame(1, Branch::count());
    }

    public function test_seeds_superadmin_with_every_global_and_branch_scoped_permission(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $superAdmin = User::where('username', config('app.superadmin_username'))->firstOrFail();
        $branch = Branch::where('code', 'CABANGUTAMA')->firstOrFail();

        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertTrue($superAdmin->is_active);
        $this->assertTrue($superAdmin->branches->contains('id', $branch->id));

        $globalIds = Permission::whereHas('menu', fn ($q) => $q->where('is_branch_scoped', false))->pluck('id');
        $this->assertSame(
            $globalIds->count(),
            UserPermission::where('user_id', $superAdmin->id)->whereIn('permission_id', $globalIds)->count()
        );

        $branchScopedIds = Permission::whereHas('menu', fn ($q) => $q->where('is_branch_scoped', true))->pluck('id');
        $this->assertSame(
            $branchScopedIds->count(),
            UserBranchPermission::where('user_id', $superAdmin->id)->where('branch_id', $branch->id)
                ->whereIn('permission_id', $branchScopedIds)->count()
        );
    }

    public function test_superadmin_has_dashboard_view_without_running_dashboard_permission_seeder(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $superAdmin = User::where('username', config('app.superadmin_username'))->firstOrFail();

        $this->assertTrue($superAdmin->userPermissions()->whereHas('permission', fn ($q) => $q->where('code', 'dashboard.view'))->exists());
    }

    public function test_seeder_is_idempotent_when_run_twice(): void
    {
        $this->seed(DemoUsersSeeder::class);
        $this->seed(DemoUsersSeeder::class);

        $this->assertSame(1, User::where('username', config('app.superadmin_username'))->count());
        $this->assertSame(1, Branch::where('code', 'CABANGUTAMA')->count());
    }
}
