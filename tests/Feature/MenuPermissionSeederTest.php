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

    public function test_seeder_creates_gross_profit_report_menu_and_permission(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $this->assertDatabaseHas('menus', ['code' => 'reporting.gross_profit', 'is_branch_scoped' => true]);
        $this->assertDatabaseHas('permissions', ['code' => 'report.gross_profit.view']);
    }

    public function test_seeder_creates_goods_receipt_report_menu_and_permission(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $this->assertDatabaseHas('menus', ['code' => 'reporting.goods_receipt', 'is_branch_scoped' => true]);
        $this->assertDatabaseHas('permissions', ['code' => 'report.goods_receipt.view']);
    }

    public function test_seeder_creates_procurement_menus_and_permissions(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $menus = [
            'pembelian.permintaan' => true,
            'pembelian.penentuan_supplier' => false,
            'pembelian.pembelian' => false,
            'master.supplier' => false,
            'administrasi.approval_setting' => false,
            'reporting.purchase_request' => false,
            'reporting.purchase_order' => false,
        ];
        foreach ($menus as $code => $branchScoped) {
            $this->assertDatabaseHas('menus', ['code' => $code, 'is_branch_scoped' => $branchScoped]);
        }

        $codes = [
            'purchase_request.view', 'purchase_request.create',
            'purchase_request.view_all', 'purchase_request.assign_supplier',
            'purchase_order.view', 'purchase_order.issue', 'purchase_order.cancel',
            'supplier.view', 'supplier.create', 'supplier.edit',
            'approval_setting.view', 'approval_setting.manage',
            'report.purchase_request.view', 'report.purchase_order.view',
        ];
        foreach ($codes as $code) {
            $this->assertDatabaseHas('permissions', ['code' => $code]);
        }

        $this->assertDatabaseMissing('permissions', ['code' => 'purchase_request.approve_manager']);
        $this->assertSame(
            'pembelian.permintaan',
            Permission::where('code', 'purchase_request.create')->first()->menu->code
        );
    }

    public function test_procurement_seed_is_idempotent(): void
    {
        $this->seed(MenuPermissionSeeder::class);
        $this->seed(MenuPermissionSeeder::class);

        $this->assertSame(1, Permission::where('code', 'purchase_order.issue')->count());
    }

    public function test_seeds_invoice_share_whatsapp_permission(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $this->assertDatabaseHas('permissions', ['code' => 'invoice.share_whatsapp']);
    }
}
