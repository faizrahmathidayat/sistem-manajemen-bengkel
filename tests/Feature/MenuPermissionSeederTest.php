<?php

namespace Tests\Feature;

use App\Models\Permission;
use Database\Seeders\MenuPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_expected_menus_and_permissions(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $this->assertDatabaseHas('permissions', ['code' => 'pkb.create']);
        $this->assertDatabaseHas('permissions', ['code' => 'invoice.post']);

        $permission = Permission::where('code', 'invoice.post')->first();
        $this->assertSame('operasional.invoice', $permission->menu->code);
    }

    public function test_seeder_is_idempotent_when_run_twice(): void
    {
        $this->seed(MenuPermissionSeeder::class);
        $this->seed(MenuPermissionSeeder::class);

        $this->assertSame(1, Permission::where('code', 'pkb.create')->count());
    }

    public function test_seeder_marks_operational_menus_as_branch_scoped_and_others_as_global(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $this->assertDatabaseHas('menus', ['code' => 'operasional.pkb', 'is_branch_scoped' => true]);
        $this->assertDatabaseHas('menus', ['code' => 'persediaan.sparepart', 'is_branch_scoped' => true]);
        $this->assertDatabaseHas('menus', ['code' => 'reporting.pkb', 'is_branch_scoped' => true]);
        $this->assertDatabaseHas('menus', ['code' => 'master.branch', 'is_branch_scoped' => false]);
        $this->assertDatabaseHas('menus', ['code' => 'administrasi.users', 'is_branch_scoped' => false]);
    }

    public function test_seeder_creates_vehicle_reference_menu_and_permissions(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $this->assertDatabaseHas('menus', ['code' => 'master.vehicle_reference', 'is_branch_scoped' => false]);
        $this->assertDatabaseHas('permissions', ['code' => 'vehicle_reference.view']);
        $this->assertDatabaseHas('permissions', ['code' => 'vehicle_reference.manage']);
    }

    public function test_seeder_creates_workshop_performance_report_menu_and_permission(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $this->assertDatabaseHas('menus', ['code' => 'reporting.workshop_performance', 'is_branch_scoped' => true]);
        $this->assertDatabaseHas('permissions', ['code' => 'report.workshop_performance.view']);
    }
}
