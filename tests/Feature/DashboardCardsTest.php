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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardCardsTest extends TestCase
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

    protected function makeInvoiceRow(Branch $branch, Customer $customer, WorkOrder $workOrder, string $status, float $grandTotal, float $paidAmount, ?string $dueDate = null, ?string $invoiceDate = null): Invoice
    {
        return Invoice::create([
            'number' => 'INV-TEST-' . $workOrder->id,
            'work_order_id' => $workOrder->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => $invoiceDate ?? now()->toDateString(),
            'due_date' => $dueDate,
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

    public function test_pkb_status_today_breaks_down_by_status_excluding_cancelled_and_other_days(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::DRAFT, $today, '1');
        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::OPEN, $today, '2');
        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::SHORTAGE, $today, '3');
        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, $today, '4');
        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::CANCELLED, $today, '5');
        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::OPEN, $yesterday, '6');

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $response->assertJson(['pkbStatus' => ['draft' => 1, 'open' => 1, 'shortage' => 1, 'completed' => 1]]);
    }

    public function test_receivables_summary_computes_revenue_and_unpaid_per_definition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);

        $woDraft = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'd');
        $this->makeInvoiceRow($branch, $customer, $woDraft, InvoiceStatus::DRAFT, 100000, 0);

        $woPosted = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'p');
        $this->makeInvoiceRow($branch, $customer, $woPosted, InvoiceStatus::POSTED, 200000, 0);

        $woPartial = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'pp');
        $this->makeInvoiceRow($branch, $customer, $woPartial, InvoiceStatus::PARTIALLY_PAID, 300000, 100000);

        $woPaid = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'pd');
        $this->makeInvoiceRow($branch, $customer, $woPaid, InvoiceStatus::PAID, 400000, 400000);

        $woCancelled = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'c');
        $this->makeInvoiceRow($branch, $customer, $woCancelled, InvoiceStatus::CANCELLED, 500000, 0);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        // revenue = 200000 (posted) + 300000 (partial) + 400000 (paid) = 900000
        // unpaid = (200000-0) + (300000-100000) = 400000
        $response->assertJson(['receivables' => ['revenue' => 900000, 'unpaid' => 400000]]);
    }

    public function test_receivables_aging_buckets_unpaid_invoices_by_days_overdue(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);

        $woNotDue = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'nd');
        $this->makeInvoiceRow($branch, $customer, $woNotDue, InvoiceStatus::POSTED, 100000, 0, now()->addDays(5)->toDateString());

        $wo1to30 = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 't1');
        $this->makeInvoiceRow($branch, $customer, $wo1to30, InvoiceStatus::POSTED, 200000, 0, now()->subDays(10)->toDateString());

        $wo31to60 = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 't2');
        $this->makeInvoiceRow($branch, $customer, $wo31to60, InvoiceStatus::POSTED, 300000, 0, now()->subDays(45)->toDateString());

        $wo60plus = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 't3');
        $this->makeInvoiceRow($branch, $customer, $wo60plus, InvoiceStatus::POSTED, 400000, 0, now()->subDays(90)->toDateString());

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $response->assertJson(['chartReceivables' => [
            'labels' => ['Belum Jatuh Tempo', '1-30 Hari', '31-60 Hari', '>60 Hari'],
            'values' => [100000, 200000, 300000, 400000],
        ]]);
    }

    public function test_weekly_trend_counts_work_orders_created_and_invoices_posted_via_audit_log(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);

        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::DRAFT, now()->toDateString(), 'w0');
        $threeWeeksAgoWo = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::DRAFT, now()->toDateString(), 'w3');
        DB::table('work_orders')->where('id', $threeWeeksAgoWo->id)->update(['created_at' => now()->subWeeks(3)]);

        AuditLog::create(['branch_id' => $branch->id, 'event' => AuditEvent::INVOICE_POSTED]);
        $oldLog = AuditLog::create(['branch_id' => $branch->id, 'event' => AuditEvent::INVOICE_POSTED]);
        DB::table('audit_logs')->where('id', $oldLog->id)->update(['created_at' => now()->subWeeks(5)]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $data = $response->json();
        $this->assertCount(8, $data['chartTrend']['labels']);
        $this->assertSame(1, $data['chartTrend']['pkb'][7]); // pekan ini
        $this->assertSame(1, $data['chartTrend']['pkb'][4]); // 3 pekan lalu
        $this->assertSame(1, $data['chartTrend']['invoice'][7]);
        $this->assertSame(1, $data['chartTrend']['invoice'][2]); // 5 pekan lalu
    }

    public function test_each_widget_scopes_branches_by_its_own_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);
        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::OPEN, now()->toDateString(), 'x');

        $user = User::factory()->create();
        // Hanya sparepart.view, TIDAK pkb.view — Card 3 (PKB) harus tetap nol karena widget itu
        // butuh pkb.view sendiri, terlepas dari sparepart.view yang dimiliki.
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $response->assertJson(['pkbStatus' => ['draft' => 0, 'open' => 0, 'shortage' => 0, 'completed' => 0]]);
    }
}
