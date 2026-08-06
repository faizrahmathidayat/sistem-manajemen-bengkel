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
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
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
use App\Support\StockAdjustmentStatus;
use App\Support\TransferStatus;
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

    public function test_posting_a_stock_adjustment_logs_stock_adjustment_posted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        $sparepartBranch->stock()->update(['on_hand_qty' => 10]);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 15, 'adjustment_qty' => 5, 'reason' => 'Ditemukan lebih',
        ]);

        $this->actingAs($poster)->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $log = AuditLog::where('event', AuditEvent::STOCK_ADJUSTMENT_POSTED)->first();
        $this->assertNotNull($log);
        $this->assertSame(StockAdjustment::class, $log->auditable_type);
        $this->assertSame($stockAdjustment->id, $log->auditable_id);
        $this->assertSame($branch->id, $log->branch_id);
    }

    public function test_dispatching_a_stock_transfer_logs_stock_transfer_dispatched(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $from->id, 'selling_price' => 60000])
            ->stock()->update(['on_hand_qty' => 20]);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $to->id, 'selling_price' => 60000]);
        $dispatcher = User::factory()->create();
        $this->grantBranchPermission($dispatcher, $from, 'stock_transfer.dispatch');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);
        StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 8]);

        $this->actingAs($dispatcher)->patch("/stock-transfers/{$stockTransfer->id}/dispatch");

        $log = AuditLog::where('event', AuditEvent::STOCK_TRANSFER_DISPATCHED)->first();
        $this->assertNotNull($log);
        $this->assertSame(StockTransfer::class, $log->auditable_type);
        $this->assertSame($stockTransfer->id, $log->auditable_id);
        $this->assertSame($from->id, $log->branch_id);
    }

    public function test_receiving_a_stock_transfer_logs_stock_transfer_received(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $from->id, 'selling_price' => 60000]);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $to->id, 'selling_price' => 60000])
            ->stock()->update(['on_hand_qty' => 3]);
        $receiver = User::factory()->create();
        $this->grantBranchPermission($receiver, $to, 'stock_transfer.receive');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::DISPATCHED,
        ]);
        StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 8]);

        $this->actingAs($receiver)->patch("/stock-transfers/{$stockTransfer->id}/receive");

        $log = AuditLog::where('event', AuditEvent::STOCK_TRANSFER_RECEIVED)->first();
        $this->assertNotNull($log);
        $this->assertSame($stockTransfer->id, $log->auditable_id);
        $this->assertSame($to->id, $log->branch_id);
    }

    public function test_cancelling_a_stock_transfer_logs_stock_transfer_voided(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $canceller = User::factory()->create();
        $this->grantBranchPermission($canceller, $from, 'stock_transfer.cancel');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $this->actingAs($canceller)->patch("/stock-transfers/{$stockTransfer->id}/cancel");

        $log = AuditLog::where('event', AuditEvent::STOCK_TRANSFER_VOIDED)->first();
        $this->assertNotNull($log);
        $this->assertSame($stockTransfer->id, $log->auditable_id);
        $this->assertSame($from->id, $log->branch_id);
    }

    public function test_granting_a_branch_permission_logs_user_branch_permission_granted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $menu = \App\Models\Menu::create(['code' => 'operasional.invoice', 'name' => 'Invoice', 'sort_order' => 1, 'is_branch_scoped' => true]);
        $permission = Permission::create(['menu_id' => $menu->id, 'code' => 'invoice.view', 'resource' => 'invoice', 'action' => 'view', 'description' => 'Melihat invoice']);
        $actor = User::factory()->create();
        $managePermission = Permission::firstOrCreate(['code' => 'user_permission.manage'], ['resource' => 'user_permission', 'action' => 'manage', 'description' => 'Mengelola permission milik user']);
        \App\Models\UserPermission::create(['user_id' => $actor->id, 'permission_id' => $managePermission->id]);
        $targetUser = User::factory()->create();
        (new UserBranchService())->assign($targetUser, $branch);

        $this->actingAs($actor)->post("/users/{$targetUser->id}/branches/{$branch->id}/permissions/{$permission->id}");

        $log = AuditLog::where('event', AuditEvent::USER_BRANCH_PERMISSION_GRANTED)->first();
        $this->assertNotNull($log);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($targetUser->id, $log->auditable_id);
        $this->assertSame($branch->id, $log->branch_id);
        $this->assertSame('invoice.view', $log->new_values['permission']);
    }

    public function test_revoking_a_branch_permission_logs_user_branch_permission_revoked(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'invoice.view', 'resource' => 'invoice', 'action' => 'view', 'description' => 'Melihat invoice']);
        $actor = User::factory()->create();
        $managePermission = Permission::firstOrCreate(['code' => 'user_permission.manage'], ['resource' => 'user_permission', 'action' => 'manage', 'description' => 'Mengelola permission milik user']);
        \App\Models\UserPermission::create(['user_id' => $actor->id, 'permission_id' => $managePermission->id]);
        $targetUser = User::factory()->create();
        (new UserBranchService())->assign($targetUser, $branch);
        UserBranchPermission::create(['user_id' => $targetUser->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $this->actingAs($actor)->delete("/users/{$targetUser->id}/branches/{$branch->id}/permissions/{$permission->id}");

        $log = AuditLog::where('event', AuditEvent::USER_BRANCH_PERMISSION_REVOKED)->first();
        $this->assertNotNull($log);
        $this->assertSame($targetUser->id, $log->auditable_id);
        $this->assertSame('invoice.view', $log->old_values['permission']);
    }
}
