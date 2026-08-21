<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\InvoiceService;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePaymentModalTest extends TestCase
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

    protected function makeWorkOrder(Branch $branch): WorkOrder
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
        $catalog = ServiceCatalog::create(['code' => "SVC-01-{$branch->code}", 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => "OLI-01-{$branch->code}", 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);

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
                ['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 50000],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000],
            ],
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        return $workOrder->fresh();
    }

    protected function makePostedInvoice(Branch $branch)
    {
        $invoice = (new InvoiceService())->createFromWorkOrder($this->makeWorkOrder($branch));
        (new InvoiceService())->postInvoice($invoice);

        return $invoice->fresh();
    }

    public function test_show_page_displays_pay_button_for_posted_invoice_with_outstanding_balance(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'payment.create');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee('id="payInvoiceModal"', false);
        $response->assertSee('data-bs-target="#payInvoiceModal"', false);
    }

    public function test_show_page_hides_pay_button_without_payment_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertDontSee('id="payInvoiceModal"', false);
    }

    public function test_show_page_hides_pay_button_for_draft_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = (new InvoiceService())->createFromWorkOrder($this->makeWorkOrder($branch));
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'payment.create');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertDontSee('id="payInvoiceModal"', false);
    }

    public function test_show_page_hides_pay_button_when_invoice_is_fully_paid(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'payment.create');

        $this->actingAs($user)->postJson('/payment-receipts', [
            'branch_id' => $branch->id,
            'customer_id' => $invoice->customer_id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => $invoice->outstanding_amount,
            'allocations' => [['invoice_id' => $invoice->id, 'allocated_amount' => $invoice->outstanding_amount]],
        ]);

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertDontSee('id="payInvoiceModal"', false);
    }

    public function test_ajax_store_creates_payment_and_returns_json_success(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'payment.create');

        $response = $this->actingAs($user)->postJson('/payment-receipts', [
            'branch_id' => $branch->id,
            'customer_id' => $invoice->customer_id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => $invoice->outstanding_amount,
            'allocations' => [['invoice_id' => $invoice->id, 'allocated_amount' => $invoice->outstanding_amount]],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['message', 'redirect']);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'paid_amount' => $invoice->outstanding_amount]);
        $this->assertSame(0.0, (float) $invoice->fresh()->outstanding_amount);
    }

    public function test_ajax_store_returns_422_with_field_errors_for_missing_payment_date(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'payment.create');

        $response = $this->actingAs($user)->postJson('/payment-receipts', [
            'branch_id' => $branch->id,
            'customer_id' => $invoice->customer_id,
            'payment_method' => 'cash',
            'amount' => $invoice->outstanding_amount,
            'allocations' => [['invoice_id' => $invoice->id, 'allocated_amount' => $invoice->outstanding_amount]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_date']);
    }

    public function test_ajax_store_returns_422_with_domain_error_message_when_amount_exceeds_outstanding(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'payment.create');
        $overAmount = $invoice->outstanding_amount + 1000;

        $response = $this->actingAs($user)->postJson('/payment-receipts', [
            'branch_id' => $branch->id,
            'customer_id' => $invoice->customer_id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => $overAmount,
            'allocations' => [['invoice_id' => $invoice->id, 'allocated_amount' => $overAmount]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);
        $response->assertJsonMissingValidationErrors(['payment_date']);
    }
}
