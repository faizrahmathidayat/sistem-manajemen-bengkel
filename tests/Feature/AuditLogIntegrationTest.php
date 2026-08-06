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
use App\Support\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogIntegrationTest extends TestCase
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

    protected function makeInvoice(Branch $branch, Customer $customer, float $grandTotal): Invoice
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

        return (new InvoiceService())->createFromWorkOrder($workOrder->fresh());
    }

    public function test_posting_an_invoice_logs_invoice_posted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000);

        (new InvoiceService())->postInvoice($invoice);

        $log = AuditLog::where('event', AuditEvent::INVOICE_POSTED)->first();
        $this->assertNotNull($log);
        $this->assertSame(Invoice::class, $log->auditable_type);
        $this->assertSame($invoice->id, $log->auditable_id);
        $this->assertSame($branch->id, $log->branch_id);
    }

    public function test_cancelling_an_invoice_logs_invoice_cancelled_with_the_reason(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000);

        (new InvoiceService())->cancelInvoice($invoice, 'Alasan uji audit log');

        $log = AuditLog::where('event', AuditEvent::INVOICE_CANCELLED)->first();
        $this->assertNotNull($log);
        $this->assertSame($invoice->id, $log->auditable_id);
        $this->assertSame('Alasan uji audit log', $log->new_values['reason']);
    }

    public function test_creating_a_payment_receipt_logs_payment_receipt_created(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000);
        $invoice = (new InvoiceService())->postInvoice($invoice);

        $receipt = (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
            'reference_number' => null, 'amount' => 100000, 'notes' => null,
            'allocations' => [['invoice_id' => $invoice->id, 'allocated_amount' => 100000]],
        ]);

        $log = AuditLog::where('event', AuditEvent::PAYMENT_RECEIPT_CREATED)->first();
        $this->assertNotNull($log);
        $this->assertSame(\App\Models\PaymentReceipt::class, $log->auditable_type);
        $this->assertSame($receipt->id, $log->auditable_id);
    }

    public function test_voiding_a_payment_receipt_logs_payment_receipt_voided(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000);
        $invoice = (new InvoiceService())->postInvoice($invoice);
        $receipt = (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
            'reference_number' => null, 'amount' => 100000, 'notes' => null,
            'allocations' => [['invoice_id' => $invoice->id, 'allocated_amount' => 100000]],
        ]);

        (new PaymentService())->voidPaymentReceipt($receipt, 'Salah nominal');

        $log = AuditLog::where('event', AuditEvent::PAYMENT_RECEIPT_VOIDED)->first();
        $this->assertNotNull($log);
        $this->assertSame($receipt->id, $log->auditable_id);
        $this->assertSame('Salah nominal', $log->new_values['reason']);
    }
}
