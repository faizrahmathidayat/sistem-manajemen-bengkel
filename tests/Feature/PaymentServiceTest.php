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
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
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

    // Mirrors InvoiceControllerTest::makeWorkOrder()/makeInvoice() — duplicated rather than
    // shared, matching this codebase's existing convention of each test file keeping its own
    // local scenario builder.
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

    public function test_create_payment_receipt_allocates_across_two_invoices_and_updates_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoiceA = $this->makePostedInvoice($branch, $customer, 100000);
        $invoiceB = $this->makePostedInvoice($branch, $customer, 200000);

        $receipt = (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 250000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoiceA->id, 'allocated_amount' => 100000],
                ['invoice_id' => $invoiceB->id, 'allocated_amount' => 150000],
            ],
        ]);

        $this->assertNotNull($receipt->number);
        $this->assertCount(2, $receipt->allocations);

        $invoiceA->refresh();
        $invoiceB->refresh();
        $this->assertSame(100000.0, (float) $invoiceA->paid_amount);
        $this->assertSame(InvoiceStatus::PAID, $invoiceA->status);
        $this->assertSame(150000.0, (float) $invoiceB->paid_amount);
        $this->assertSame(InvoiceStatus::PARTIALLY_PAID, $invoiceB->status);
        $this->assertSame(50000.0, (float) $invoiceB->outstanding_amount);
    }

    public function test_create_rejects_allocation_exceeding_outstanding_balance(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);

        $this->expectException(DomainException::class);

        (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 150000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 150000],
            ],
        ]);
    }

    public function test_create_rejects_invoice_belonging_to_a_different_customer(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $invoice = $this->makePostedInvoice($branch, $customerA, 100000);

        $this->expectException(DomainException::class);

        (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customerB->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 50000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 50000],
            ],
        ]);
    }

    public function test_create_rejects_invoice_that_is_not_posted_or_partially_paid(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $invoice->update(['status' => InvoiceStatus::PAID, 'paid_amount' => 100000]);

        $this->expectException(DomainException::class);

        (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 10000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 10000],
            ],
        ]);
    }
}
