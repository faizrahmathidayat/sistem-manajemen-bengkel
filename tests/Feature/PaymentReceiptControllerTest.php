<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\PaymentReceipt;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentReceiptControllerTest extends TestCase
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

    protected function makePostedInvoice(Branch $branch, Customer $customer, float $grandTotal): Invoice
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

        return (new InvoiceService())->postInvoice($invoice);
    }

    public function test_store_creates_payment_receipt_and_updates_invoice_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'payment.create');

        $response = $this->actingAs($user)->post('/payment-receipts', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->format('Y-m-d'),
            'payment_method' => 'cash',
            'amount' => 100000,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 100000],
            ],
        ]);

        $receipt = PaymentReceipt::latest('id')->first();
        $response->assertRedirect("/payment-receipts/{$receipt->id}");
        $invoice->refresh();
        $this->assertSame(\App\Support\InvoiceStatus::PAID, $invoice->status);
    }

    public function test_store_requires_payment_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/payment-receipts', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->format('Y-m-d'),
            'payment_method' => 'cash',
            'amount' => 100000,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 100000],
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_store_rejects_when_allocation_sum_does_not_match_amount(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'payment.create');

        $response = $this->actingAs($user)->post('/payment-receipts', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->format('Y-m-d'),
            'payment_method' => 'cash',
            'amount' => 50000,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 100000],
            ],
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_void_reverses_the_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $receipt = (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 100000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 100000],
            ],
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'payment.void');

        $response = $this->actingAs($user)->patch("/payment-receipts/{$receipt->id}/void", [
            'reason' => 'Salah nominal',
        ]);

        $response->assertRedirect("/payment-receipts/{$receipt->id}");
        $receipt->refresh();
        $this->assertSame(\App\Support\PaymentReceiptStatus::VOID, $receipt->status);
        $invoice->refresh();
        $this->assertSame(\App\Support\InvoiceStatus::POSTED, $invoice->status);
    }

    public function test_void_requires_payment_void_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $receipt = (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 100000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 100000],
            ],
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch("/payment-receipts/{$receipt->id}/void", [
            'reason' => 'Salah nominal',
        ]);

        $response->assertForbidden();
    }

    public function test_lookup_outstanding_invoices_returns_only_this_customer_and_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'payment.create');

        $response = $this->actingAs($user)->getJson("/payment-receipts/lookup/outstanding-invoices/{$customer->id}?branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonFragment(['number' => $invoice->number]);
    }
}
