<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
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
use App\Support\AuditEvent;
use App\Support\InvoiceStatus;
use App\Support\WorkOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
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

    protected function makeCustomerVehicleMechanic(Branch $branch): array
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => "B 1234 {$branch->code}",
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);

        return [$customer, $vehicle, $mechanic];
    }

    public function test_html_and_json_response_return_the_same_payload_values(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);
        WorkOrder::create([
            'number' => 'PKB-E2E-1', 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id, 'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->toDateString(), 'status' => WorkOrderStatus::OPEN,
        ]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $htmlResponse = $this->actingAs($user)->get('/dashboard?branch_ids[]=' . $branch->id);
        $jsonResponse = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $htmlResponse->assertOk();
        $jsonResponse->assertOk();
        $jsonResponse->assertJson(['pkbStatus' => ['draft' => 0, 'open' => 1, 'shortage' => 0, 'completed' => 0]]);
        $htmlResponse->assertSee('PKB-E2E-1');
    }

    public function test_user_with_multiple_branches_only_sees_data_for_selected_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        [$customerA, $vehicleA, $mechanicA] = $this->makeCustomerVehicleMechanic($branchA);
        [$customerB, $vehicleB, $mechanicB] = $this->makeCustomerVehicleMechanic($branchB);
        WorkOrder::create([
            'number' => 'PKB-A', 'branch_id' => $branchA->id, 'customer_id' => $customerA->id,
            'vehicle_id' => $vehicleA->id, 'mechanic_id' => $mechanicA->id,
            'work_order_date' => now()->toDateString(), 'status' => WorkOrderStatus::OPEN,
        ]);
        WorkOrder::create([
            'number' => 'PKB-B', 'branch_id' => $branchB->id, 'customer_id' => $customerB->id,
            'vehicle_id' => $vehicleB->id, 'mechanic_id' => $mechanicB->id,
            'work_order_date' => now()->toDateString(), 'status' => WorkOrderStatus::OPEN,
        ]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'pkb.view');
        $this->grantBranchPermission($user, $branchB, 'pkb.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branchA->id);

        $response->assertOk();
        $numbers = collect($response->json('pkbInvoiceRows'))->pluck('number');
        $this->assertTrue($numbers->contains('PKB-A'));
        $this->assertFalse($numbers->contains('PKB-B'));
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_full_widget_permission_matrix_end_to_end(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);

        $wo = WorkOrder::create([
            'number' => 'PKB-MATRIX', 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id, 'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->toDateString(), 'status' => WorkOrderStatus::COMPLETED,
        ]);
        Invoice::create([
            'number' => 'INV-MATRIX', 'work_order_id' => $wo->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(), 'status' => InvoiceStatus::POSTED,
            'subtotal_service' => 150000, 'subtotal_sparepart' => 0, 'discount_percent' => 0, 'discount_amount' => 0,
            'tax_percent' => 0, 'tax_amount' => 0, 'grand_total' => 150000, 'paid_amount' => 0,
        ]);
        AuditLog::create(['branch_id' => $branch->id, 'event' => AuditEvent::INVOICE_POSTED]);

        // User dengan SEMUA permission relevan — semua widget harus terisi.
        $fullUser = User::factory()->create();
        $this->grantBranchPermission($fullUser, $branch, 'pkb.view');
        $this->grantBranchPermission($fullUser, $branch, 'invoice.view');
        $this->grantBranchPermission($fullUser, $branch, 'sparepart.view');
        $permission = Permission::firstOrCreate(
            ['code' => 'audit_log.view'],
            ['resource' => 'audit_log', 'action' => 'view', 'description' => 'audit_log.view']
        );
        UserPermission::create(['user_id' => $fullUser->id, 'permission_id' => $permission->id]);

        $fullResponse = $this->actingAs($fullUser)->getJson('/dashboard?branch_ids[]=' . $branch->id);
        $fullResponse->assertOk();
        $fullData = $fullResponse->json();
        $this->assertGreaterThan(0, count($fullData['pkbInvoiceRows']));
        $this->assertTrue($fullData['canViewAuditLog']);
        $this->assertCount(1, $fullData['auditLogRows']);
        // assertEquals (bukan assertSame): json_encode() PHP tidak mempertahankan sufiks
        // desimal untuk float bulat (150000.0 -> "150000"), sehingga json_decode()
        // mengembalikannya sebagai int, bukan float.
        $this->assertEquals(150000.0, $fullData['receivables']['revenue']);

        // User dengan HANYA sparepart.view — PKB/Invoice/Audit Log widget harus kosong.
        $limitedUser = User::factory()->create();
        $this->grantBranchPermission($limitedUser, $branch, 'sparepart.view');

        $limitedResponse = $this->actingAs($limitedUser)->getJson('/dashboard?branch_ids[]=' . $branch->id);
        $limitedResponse->assertOk();
        $limitedData = $limitedResponse->json();
        $this->assertSame([], $limitedData['pkbInvoiceRows']);
        $this->assertFalse($limitedData['canViewAuditLog']);
        $this->assertSame([], $limitedData['auditLogRows']);
        $this->assertEquals(0.0, $limitedData['receivables']['revenue']);
    }
}
