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

class DashboardTabsTest extends TestCase
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

    protected function makeCustomerVehicleMechanic(Branch $branch, string $customerName = 'Budi Santoso', string $plateSuffix = '1'): array
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => $customerName, 'stnk_name' => $customerName]);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => "Mobil {$branch->code}{$plateSuffix}"]);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => "B {$plateSuffix}234 {$branch->code}",
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);

        return [$customer, $vehicle, $mechanic];
    }

    protected function makeWorkOrderRow(Branch $branch, Customer $customer, Vehicle $vehicle, Mechanic $mechanic, string $status, string $workOrderDate, string $numberSuffix): WorkOrder
    {
        return WorkOrder::create([
            'number' => "PKB-TEST-{$numberSuffix}",
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => $workOrderDate,
            'status' => $status,
        ]);
    }

    protected function makeInvoiceRow(Branch $branch, Customer $customer, WorkOrder $workOrder, string $status, float $grandTotal, float $paidAmount): Invoice
    {
        return Invoice::create([
            'number' => 'INV-TEST-' . $workOrder->id,
            'work_order_id' => $workOrder->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'status' => $status,
            'subtotal_service' => $grandTotal,
            'subtotal_sparepart' => 0,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_percent' => 0,
            'tax_amount' => 0,
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
        ]);
    }

    public function test_pkb_invoice_search_matches_number_customer_or_plate_across_both_types(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customerA, $vehicleA, $mechanic] = $this->makeCustomerVehicleMechanic($branch, 'Andi Wijaya', '9');
        [$customerB, $vehicleB] = $this->makeCustomerVehicleMechanic($branch, 'Siti Aminah', '8');
        $woA = $this->makeWorkOrderRow($branch, $customerA, $vehicleA, $mechanic, WorkOrderStatus::OPEN, now()->toDateString(), 'a');
        $woB = $this->makeWorkOrderRow($branch, $customerB, $vehicleB, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'b');
        $this->makeInvoiceRow($branch, $customerB, $woB, InvoiceStatus::POSTED, 100000, 0);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id . '&pkb_invoice_q=Andi');

        $response->assertOk();
        $numbers = collect($response->json('pkbInvoiceRows'))->pluck('number');
        $this->assertTrue($numbers->contains($woA->number));
        $this->assertFalse($numbers->contains($woB->number));
    }

    public function test_pkb_invoice_status_filter_pkb_prefix_only_returns_pkb_rows(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);
        $wo = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::SHORTAGE, now()->toDateString(), 'a');
        $woForInvoice = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'b');
        $this->makeInvoiceRow($branch, $customer, $woForInvoice, InvoiceStatus::POSTED, 100000, 0);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id . '&pkb_invoice_status=pkb:shortage');

        $response->assertOk();
        $rows = collect($response->json('pkbInvoiceRows'));
        $this->assertTrue($rows->every(fn ($row) => $row['type'] === 'pkb'));
        $this->assertSame([$wo->number], $rows->pluck('number')->all());
    }

    public function test_pkb_invoice_date_range_filters_each_type_by_its_own_date_column(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);
        $woInRange = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::OPEN, '2026-08-05', 'in');
        $woOutOfRange = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::OPEN, '2026-07-01', 'out');

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->getJson(
            '/dashboard?branch_ids[]=' . $branch->id . '&pkb_invoice_date_from=2026-08-01&pkb_invoice_date_to=2026-08-10'
        );

        $response->assertOk();
        $numbers = collect($response->json('pkbInvoiceRows'))->pluck('number');
        $this->assertTrue($numbers->contains($woInRange->number));
        $this->assertFalse($numbers->contains($woOutOfRange->number));
    }

    public function test_pkb_invoice_rows_are_merged_sorted_desc_and_limited_to_15(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);
        for ($i = 0; $i < 20; $i++) {
            $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::OPEN, now()->subDays($i)->toDateString(), "n{$i}");
        }

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $rows = $response->json('pkbInvoiceRows');
        $this->assertCount(15, $rows);
        $this->assertSame('PKB-TEST-n0', $rows[0]['number']);
    }

    public function test_audit_log_tab_hidden_and_empty_without_audit_log_view_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        AuditLog::create(['branch_id' => $branch->id, 'event' => AuditEvent::INVOICE_POSTED]);

        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $response->assertJson(['canViewAuditLog' => false, 'auditLogRows' => []]);
    }

    public function test_audit_log_rows_include_severity_mapped_from_event_and_filtered_by_selected_branches(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        AuditLog::create(['branch_id' => $branchA->id, 'event' => AuditEvent::USER_BRANCH_PERMISSION_GRANTED]);
        AuditLog::create(['branch_id' => $branchB->id, 'event' => AuditEvent::INVOICE_POSTED]);

        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branchA);
        (new UserBranchService())->assign($user, $branchB);
        $permission = Permission::firstOrCreate(
            ['code' => 'audit_log.view'],
            ['resource' => 'audit_log', 'action' => 'view', 'description' => 'audit_log.view']
        );
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branchA->id);

        $response->assertOk();
        $rows = $response->json('auditLogRows');
        $this->assertCount(1, $rows);
        $this->assertSame('HIGH', $rows[0]['severity']);
    }
}
