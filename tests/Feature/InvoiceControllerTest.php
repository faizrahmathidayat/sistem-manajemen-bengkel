<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
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

class InvoiceControllerTest extends TestCase
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

    // Mirrors WorkOrderManagementTest::makeScenario()+baseStorePayload()+confirmWorkOrder(), extended
    // with an optional final "complete" step since this file's tests need PKBs all the way through
    // to COMPLETED (to create invoices from), not just OPEN/SHORTAGE.
    protected function makeWorkOrder(Branch $branch, bool $complete = true): WorkOrder
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

        if ($complete) {
            $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        }

        return $workOrder->fresh();
    }

    protected function makeInvoice(Branch $branch): Invoice
    {
        return (new InvoiceService())->createFromWorkOrder($this->makeWorkOrder($branch));
    }

    public function test_show_displays_invoice_header_and_snapshot_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee($invoice->number);
        $response->assertSee('Ganti Oli');
        $response->assertSee('Oli Mesin');
    }

    public function test_show_displays_payment_history_and_outstanding_balance(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        (new InvoiceService())->postInvoice($invoice);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'payment.create');

        (new \App\Services\PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $invoice->customer_id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 50000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 50000],
            ],
        ]);

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee('Riwayat Pembayaran');
        $invoice->refresh();
        $response->assertSee(number_format($invoice->outstanding_amount, 0, ',', '.'));
    }

    public function test_show_is_forbidden_without_invoice_view_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertForbidden();
    }

    public function test_index_lists_invoices_for_permitted_branch_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $invoiceA = $this->makeInvoice($branchA);
        $invoiceB = $this->makeInvoice($branchB);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'invoice.view');

        $response = $this->actingAs($user)->get('/invoices');

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_invoices_by_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $draftInvoice = $this->makeInvoice($branch);
        $sourceWorkOrder = $draftInvoice->workOrder;
        $secondWorkOrder = WorkOrder::create([
            'number' => 'PKB-SECOND-TEST', 'branch_id' => $branch->id, 'customer_id' => $sourceWorkOrder->customer_id,
            'vehicle_id' => $sourceWorkOrder->vehicle_id, 'mechanic_id' => $sourceWorkOrder->mechanic_id,
            'work_order_date' => now()->toDateString(), 'status' => \App\Support\WorkOrderStatus::COMPLETED,
        ]);
        $postedInvoice = Invoice::create([
            'number' => 'INV-POSTED-TEST', 'work_order_id' => $secondWorkOrder->id, 'branch_id' => $branch->id,
            'customer_id' => $sourceWorkOrder->customer_id, 'invoice_date' => now()->toDateString(),
            'status' => \App\Support\InvoiceStatus::POSTED,
            'subtotal_service' => 100000, 'subtotal_sparepart' => 0, 'discount_percent' => 0, 'discount_amount' => 0,
            'tax_percent' => 0, 'tax_amount' => 0, 'grand_total' => 100000, 'paid_amount' => 0,
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->get('/invoices?status=' . \App\Support\InvoiceStatus::POSTED);

        $response->assertOk();
        $response->assertSee($postedInvoice->number);
        $response->assertDontSee($draftInvoice->number);
    }

    public function test_index_shows_no_access_view_without_any_invoice_view_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/invoices');

        $response->assertOk();
        $response->assertSee('Anda belum memiliki akses invoice');
    }

    public function test_store_creates_draft_invoice_from_completed_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);

        $invoice = Invoice::latest('id')->first();
        $response->assertRedirect("/invoices/{$invoice->id}");
        $this->assertSame(\App\Support\InvoiceStatus::DRAFT, $invoice->status);
        $this->assertSame($workOrder->id, $invoice->work_order_id);
        $this->assertCount(2, $invoice->details);
    }

    public function test_store_rejects_work_order_that_is_not_completed(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch, false);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);

        $response->assertForbidden();
        $this->assertSame(0, Invoice::count());
    }

    public function test_store_rejects_when_work_order_already_has_an_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        (new InvoiceService())->createFromWorkOrder($workOrder);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $response->assertSessionHas('error');
        $this->assertSame(1, Invoice::count());
    }

    public function test_store_requires_invoice_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);

        $response->assertForbidden();
    }

    public function test_update_recalculates_discount_tax_and_grand_total(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        // subtotal_service=50000 (1 x 50000), subtotal_sparepart=120000 (2 x 60000) -> subtotal=170000
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 10,
            'tax_percent' => 11,
            'notes' => 'Diskon member',
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => $serviceDetail->description,
                'qty' => (float) $serviceDetail->qty,
                'unit_price' => (float) $serviceDetail->unit_price,
            ]],
            'spareparts' => [[
                'work_order_sparepart_line_id' => $sparepartDetail->work_order_sparepart_line_id,
                'sparepart_branch_id' => $sparepartDetail->sparepart_branch_id,
                'qty' => (float) $sparepartDetail->qty,
                'unit_price' => (float) $sparepartDetail->unit_price,
            ]],
        ]);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();
        $this->assertSame(10.0, (float) $invoice->discount_percent);
        $this->assertSame(17000.0, (float) $invoice->discount_amount);
        $this->assertSame(11.0, (float) $invoice->tax_percent);
        $this->assertSame(16830.0, (float) $invoice->tax_amount);
        $this->assertSame(169830.0, (float) $invoice->grand_total);
        $this->assertSame('Diskon member', $invoice->notes);
    }

    public function test_update_applies_per_line_discount_and_computes_net_line_total(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => 'Ganti Oli',
                'qty' => 1,
                'unit_price' => 100000,
                'discount_percent' => 10,
            ]],
            'spareparts' => [[
                'work_order_sparepart_line_id' => $sparepartDetail->work_order_sparepart_line_id,
                'sparepart_branch_id' => $sparepartDetail->sparepart_branch_id,
                'qty' => 2,
                'unit_price' => 50000,
                'discount_percent' => 20,
            ]],
        ]);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();

        $newServiceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $this->assertSame(10.0, (float) $newServiceDetail->discount_percent);
        $this->assertSame(10000.0, (float) $newServiceDetail->discount_amount);
        $this->assertSame(90000.0, (float) $newServiceDetail->line_total);

        $newSparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $this->assertSame(20.0, (float) $newSparepartDetail->discount_percent);
        $this->assertSame(20000.0, (float) $newSparepartDetail->discount_amount);
        $this->assertSame(80000.0, (float) $newSparepartDetail->line_total);

        $this->assertSame(90000.0, (float) $invoice->subtotal_service);
        $this->assertSame(80000.0, (float) $invoice->subtotal_sparepart);
        $this->assertSame(170000.0, (float) $invoice->grand_total);
    }

    public function test_update_rejects_decimal_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => 'Ganti Oli',
                'qty' => 1.5,
                'unit_price' => 100000,
            ]],
            'spareparts' => [[
                'work_order_sparepart_line_id' => $sparepartDetail->work_order_sparepart_line_id,
                'sparepart_branch_id' => $sparepartDetail->sparepart_branch_id,
                'qty' => 2,
                'unit_price' => 50000,
            ]],
        ]);

        $response->assertSessionHasErrors(['services.0.qty']);
    }

    public function test_update_defaults_discount_percent_to_zero_when_omitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => 'Ganti Oli',
                'qty' => 1,
                'unit_price' => 50000,
            ]],
            'spareparts' => [],
        ]);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $newServiceDetail = $invoice->fresh()->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $this->assertSame(0.0, (float) $newServiceDetail->discount_percent);
        $this->assertSame(0.0, (float) $newServiceDetail->discount_amount);
        $this->assertSame(50000.0, (float) $newServiceDetail->line_total);
    }

    public function test_update_saves_due_date(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');
        $dueDate = $invoice->invoice_date->copy()->addDays(14)->toDateString();

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'due_date' => $dueDate,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => $serviceDetail->description,
                'qty' => (float) $serviceDetail->qty,
                'unit_price' => (float) $serviceDetail->unit_price,
            ]],
            'spareparts' => [[
                'work_order_sparepart_line_id' => $sparepartDetail->work_order_sparepart_line_id,
                'sparepart_branch_id' => $sparepartDetail->sparepart_branch_id,
                'qty' => (float) $sparepartDetail->qty,
                'unit_price' => (float) $sparepartDetail->unit_price,
            ]],
        ]);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();
        $this->assertSame($dueDate, $invoice->due_date->toDateString());
    }

    public function test_update_rejects_due_date_before_invoice_date(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'due_date' => $invoice->invoice_date->copy()->subDay()->toDateString(),
        ]);

        $response->assertSessionHasErrors('due_date');
    }

    public function test_update_rejects_discount_percent_over_100(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 150,
            'tax_percent' => 0,
        ]);

        $response->assertSessionHasErrors('discount_percent');
    }

    public function test_update_is_forbidden_once_invoice_is_posted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        (new InvoiceService())->postInvoice($invoice);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 5,
            'tax_percent' => 11,
        ]);

        $response->assertForbidden();
    }

    public function test_post_transitions_invoice_to_posted_and_deducts_stock(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $invoice = (new InvoiceService())->createFromWorkOrder($workOrder);
        $sparepartBranchId = $workOrder->sparepartLines->first()->sparepart_branch_id;
        $stockBefore = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchId)->first();

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.post');

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");

        $response->assertRedirect("/invoices/{$invoice->id}");
        $this->assertSame(\App\Support\InvoiceStatus::POSTED, $invoice->fresh()->status);
        $stockAfter = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchId)->first();
        $this->assertSame((float) $stockBefore->on_hand_qty - 2.0, (float) $stockAfter->on_hand_qty);
        $this->assertSame(0.0, (float) $stockAfter->reserved_qty);
        $this->assertDatabaseHas('inventory_movements', [
            'sparepart_branch_id' => $sparepartBranchId,
            'movement_type' => 'usage_out',
            'reference_type' => 'invoice_detail',
        ]);
    }

    public function test_post_is_forbidden_when_invoice_already_posted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        (new InvoiceService())->postInvoice($invoice);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.post');

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");

        $response->assertForbidden();
    }

    public function test_post_requires_invoice_post_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");

        $response->assertForbidden();
    }

    public function test_update_removing_sparepart_line_releases_pkb_reservation(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $sparepartBranchId = $sparepartDetail->sparepart_branch_id;
        $stockBefore = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchId)->first();
        $this->assertSame(2.0, (float) $stockBefore->reserved_qty);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => $serviceDetail->description,
                'qty' => (float) $serviceDetail->qty,
                'unit_price' => (float) $serviceDetail->unit_price,
            ]],
            'spareparts' => [],
        ]);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();
        $this->assertCount(1, $invoice->details);
        $this->assertSame(0.0, (float) $invoice->subtotal_sparepart);
        $stockAfter = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchId)->first();
        $this->assertSame(0.0, (float) $stockAfter->reserved_qty);
        $this->assertDatabaseHas('inventory_reservations', [
            'sparepart_branch_id' => $sparepartBranchId,
            'reference_type' => 'work_order_sparepart_line',
            'status' => 'released',
        ]);
    }

    public function test_update_adds_free_form_sparepart_line_not_traced_to_pkb(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);

        $extraSparepart = Sparepart::create(['code' => 'FLT-01', 'name' => 'Filter Udara']);
        $extraSparepartBranch = SparepartBranch::create(['sparepart_id' => $extraSparepart->id, 'branch_id' => $branch->id, 'selling_price' => 45000]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => $serviceDetail->description,
                'qty' => (float) $serviceDetail->qty,
                'unit_price' => (float) $serviceDetail->unit_price,
            ]],
            'spareparts' => [
                [
                    'work_order_sparepart_line_id' => $sparepartDetail->work_order_sparepart_line_id,
                    'sparepart_branch_id' => $sparepartDetail->sparepart_branch_id,
                    'qty' => (float) $sparepartDetail->qty,
                    'unit_price' => (float) $sparepartDetail->unit_price,
                ],
                [
                    'work_order_sparepart_line_id' => null,
                    'sparepart_branch_id' => $extraSparepartBranch->id,
                    'qty' => 1,
                    'unit_price' => 45000,
                ],
            ],
        ]);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();
        $this->assertCount(3, $invoice->details);
        $this->assertSame(165000.0, (float) $invoice->subtotal_sparepart);
        $freeFormDetail = $invoice->details->firstWhere('sparepart_branch_id', $extraSparepartBranch->id);
        $this->assertNull($freeFormDetail->work_order_sparepart_line_id);
    }

    public function test_update_rejects_sparepart_from_a_different_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $invoice = $this->makeInvoice($branchA);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);

        $otherSparepart = Sparepart::create(['code' => 'FLT-02', 'name' => 'Filter Oli']);
        $otherBranchSparepart = SparepartBranch::create(['sparepart_id' => $otherSparepart->id, 'branch_id' => $branchB->id, 'selling_price' => 30000]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => $serviceDetail->description,
                'qty' => (float) $serviceDetail->qty,
                'unit_price' => (float) $serviceDetail->unit_price,
            ]],
            'spareparts' => [[
                'work_order_sparepart_line_id' => null,
                'sparepart_branch_id' => $otherBranchSparepart->id,
                'qty' => 1,
                'unit_price' => 30000,
            ]],
        ]);

        $response->assertSessionHasErrors('spareparts.0.sparepart_branch_id');
    }

    public function test_edit_page_renders_locked_pkb_lines_with_line_editor_markup(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/edit");

        $response->assertOk();
        $response->assertSee('select2', false);
        $response->assertSee('select2-ajax-picker.js', false);
        $response->assertSee('sparepart-item-locked', false);
        $response->assertSee('sparepart-item-free', false);
        $response->assertSee('"work_order_service_line_id":' . $serviceDetail->work_order_service_line_id, false);
        $response->assertSee('"work_order_sparepart_line_id":' . $sparepartDetail->work_order_sparepart_line_id, false);
    }

    public function test_edit_page_offers_service_catalog_master_data_for_baris_jasa(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/edit");

        $response->assertOk();
        $response->assertSee('service-catalog-select', false);
        $response->assertSee('Ganti Oli');
    }

    public function test_edit_page_includes_free_form_line_data_without_a_pkb_trace(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $extraSparepart = Sparepart::create(['code' => 'FLT-01', 'name' => 'Filter Udara']);
        $extraSparepartBranch = SparepartBranch::create(['sparepart_id' => $extraSparepart->id, 'branch_id' => $branch->id, 'selling_price' => 45000]);
        InvoiceDetail::create([
            'invoice_id' => $invoice->id,
            'item_type' => \App\Support\InvoiceDetailItemType::SPAREPART,
            'work_order_service_line_id' => null,
            'work_order_sparepart_line_id' => null,
            'sparepart_branch_id' => $extraSparepartBranch->id,
            'description' => 'Filter Udara',
            'qty' => 1,
            'unit_price' => 45000,
            'line_total' => 45000,
            'sort_order' => 99,
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/edit");

        $response->assertOk();
        $response->assertSee('"work_order_sparepart_line_id":null,"sparepart_branch_id":' . $extraSparepartBranch->id, false);
    }

    public function test_cancel_marks_draft_invoice_as_cancelled_and_releases_reservations(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $sparepartBranchId = $sparepartDetail->sparepart_branch_id;
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.void');

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/cancel", ['reason' => 'Customer batal servis.']);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();
        $this->assertSame(\App\Support\InvoiceStatus::CANCELLED, $invoice->status);
        $this->assertSame('Customer batal servis.', $invoice->cancel_reason);
        $this->assertSame($user->id, $invoice->cancelled_by);
        $this->assertNotNull($invoice->cancelled_at);
        $stockAfter = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchId)->first();
        $this->assertSame(0.0, (float) $stockAfter->reserved_qty);
    }

    public function test_cancel_is_forbidden_once_invoice_is_posted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        (new InvoiceService())->postInvoice($invoice);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.void');

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/cancel", ['reason' => 'Coba batalkan setelah posting.']);

        $response->assertForbidden();
    }

    public function test_cancel_requires_invoice_void_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/cancel", ['reason' => 'Tanpa izin.']);

        $response->assertForbidden();
    }

    public function test_cancel_requires_a_reason(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.void');

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/cancel", []);

        $response->assertSessionHasErrors('reason');
    }

    public function test_show_offers_cancel_form_for_draft_invoice_with_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'invoice.void');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee(route('invoices.cancel', $invoice), false);
        $response->assertSee('Batalkan Invoice');
    }

    public function test_show_displays_cancellation_info_after_invoice_is_cancelled(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'invoice.void');
        $this->actingAs($user)->patch("/invoices/{$invoice->id}/cancel", ['reason' => 'Customer batal servis.']);

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee('Invoice dibatalkan');
        $response->assertSee('Customer batal servis.');
        $response->assertDontSee('Batalkan Invoice');
    }
}
