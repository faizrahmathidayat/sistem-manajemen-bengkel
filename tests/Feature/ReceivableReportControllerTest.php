<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\UserBranchService;
use App\Support\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceivableReportControllerTest extends TestCase
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

    protected function makeInvoice(Branch $branch, Customer $customer, float $grandTotal, string $invoiceDate, bool $post = true): Invoice
    {
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::firstOrCreate(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id,
            'plate_number' => 'B ' . random_int(1000, 9999) . ' ' . $branch->code,
        ]);
        $mechanic = Mechanic::firstOrCreate(['name' => "Mekanik {$branch->code}"]);
        MechanicBranch::firstOrCreate(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create([
            'code' => 'SVC-' . random_int(1000, 9999), 'name' => 'Jasa', 'default_price' => $grandTotal,
        ]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => $catalog->id, 'description' => 'Jasa', 'qty' => 1, 'unit_price' => $grandTotal],
            ],
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $invoice = (new InvoiceService())->createFromWorkOrder($workOrder->fresh());
        if ($post) {
            $invoice = (new InvoiceService())->postInvoice($invoice);
        }
        $invoice->update(['invoice_date' => $invoiceDate]);

        return $invoice->fresh();
    }

    public function test_index_defaults_to_unpaid_only_and_computes_summary_totals(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $unpaid = $this->makeInvoice($branch, $customer, 100000, now()->toDateString());
        $paid = $this->makeInvoice($branch, $customer, 50000, now()->toDateString());
        (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
            'reference_number' => null, 'amount' => 50000, 'notes' => null,
            'allocations' => [['invoice_id' => $paid->id, 'allocated_amount' => 50000]],
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables');

        $response->assertOk();
        $response->assertSee($unpaid->number);
        $response->assertDontSee($paid->number);
        $response->assertSee('100.000');
    }

    public function test_index_status_paid_shows_only_fully_paid_invoices(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $unpaid = $this->makeInvoice($branch, $customer, 100000, now()->toDateString());
        $paid = $this->makeInvoice($branch, $customer, 50000, now()->toDateString());
        (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
            'reference_number' => null, 'amount' => 50000, 'notes' => null,
            'allocations' => [['invoice_id' => $paid->id, 'allocated_amount' => 50000]],
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables?status=paid');

        $response->assertOk();
        $response->assertSee($paid->number);
        $response->assertDontSee($unpaid->number);
    }

    public function test_index_status_all_shows_both(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $unpaid = $this->makeInvoice($branch, $customer, 100000, now()->toDateString());
        $paid = $this->makeInvoice($branch, $customer, 50000, now()->toDateString());
        (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
            'reference_number' => null, 'amount' => 50000, 'notes' => null,
            'allocations' => [['invoice_id' => $paid->id, 'allocated_amount' => 50000]],
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables?status=all');

        $response->assertOk();
        $response->assertSee($unpaid->number);
        $response->assertSee($paid->number);
    }

    public function test_index_excludes_draft_invoices_even_with_status_all(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $posted = $this->makeInvoice($branch, $customer, 100000, now()->toDateString());
        // A distinctive, never-posted second invoice (createFromWorkOrder() alone yields DRAFT,
        // deliberately not calling postInvoice() here) with a grand_total large/distinctive
        // enough that it couldn't coincidentally appear elsewhere on the page if it leaked in.
        $draftInvoice = $this->makeInvoice($branch, $customer, 987654, now()->toDateString(), false);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables?status=all');

        $response->assertOk();
        $response->assertSee($posted->number);
        $response->assertDontSee($draftInvoice->number);
        $response->assertDontSee('987.654');
    }

    public function test_index_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $invoiceA = $this->makeInvoice($branchA, $customerA, 100000, now()->toDateString());
        $invoiceB = $this->makeInvoice($branchB, $customerB, 100000, now()->toDateString());
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'report.receivable.view');
        $this->grantBranchPermission($user, $branchB, 'report.receivable.view');

        $response = $this->actingAs($user)->get("/reports/receivables?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_by_customer_search(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $invoiceA = $this->makeInvoice($branch, $customerA, 100000, now()->toDateString());
        $invoiceB = $this->makeInvoice($branch, $customerB, 100000, now()->toDateString());
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables?customer=Budi');

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_by_invoice_date_range(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $old = $this->makeInvoice($branch, $customer, 100000, '2025-01-01');
        $recent = $this->makeInvoice($branch, $customer, 100000, now()->toDateString());
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables?date_from=' . now()->subDay()->toDateString());

        $response->assertOk();
        $response->assertSee($recent->number);
        $response->assertDontSee($old->number);
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/receivables');

        $response->assertOk();
        $response->assertSee('belum memiliki akses', false);
    }

    public function test_index_is_scoped_to_permitted_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoiceA = $this->makeInvoice($branchA, $customer, 100000, now()->toDateString());
        $invoiceB = $this->makeInvoice($branchB, $customer, 100000, now()->toDateString());
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables?status=all');

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }
}
