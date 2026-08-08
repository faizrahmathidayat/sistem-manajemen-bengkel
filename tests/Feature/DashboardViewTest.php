<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\UserBranchService;
use App\Support\WorkOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardViewTest extends TestCase
{
    use RefreshDatabase;

    protected function grantBranchPermission(User $user, Branch $branch, string $code): void
    {
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    public function test_pkb_invoice_filter_inputs_are_enabled(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $content = $response->getContent();
        preg_match('/<input[^>]*id="pkbInvoiceSearch"[^>]*>/', $content, $matches);
        $this->assertNotEmpty($matches, 'Input pencarian Tab 1 tidak ditemukan.');
        $this->assertStringNotContainsString('disabled', $matches[0]);
    }

    public function test_pkb_row_shows_type_badge_and_action_link(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $wo = WorkOrder::create([
            'number' => 'PKB-VIEW-1', 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id, 'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->toDateString(), 'status' => WorkOrderStatus::OPEN,
        ]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('PKB-VIEW-1');
        $response->assertSee(route('work-orders.show', $wo), false);
    }

    public function test_audit_log_tab_absent_from_html_without_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('tab-audit-log', false);
    }

    public function test_audit_log_tab_present_in_html_with_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        $permission = Permission::firstOrCreate(
            ['code' => 'audit_log.view'],
            ['resource' => 'audit_log', 'action' => 'view', 'description' => 'audit_log.view']
        );
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('tab-audit-log', false);
    }
}
