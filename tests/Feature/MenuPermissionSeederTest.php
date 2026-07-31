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
}
