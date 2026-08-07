<?php

namespace Tests\Feature;

use App\Mail\InvoicePostedMail;
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
use App\Services\PaymentService;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class InvoicePrintEmailTest extends TestCase
{
    use RefreshDatabase;
    use ExtractsPdfText;

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
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso', 'email' => 'budi@example.test']);
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

    protected function makeDraftInvoice(Branch $branch)
    {
        return (new InvoiceService())->createFromWorkOrder($this->makeWorkOrder($branch));
    }

    // --- print() ---

    public function test_print_returns_pdf_for_posted_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.print');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_print_content_includes_invoice_number_customer_and_plate_number(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.print');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $content = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString($invoice->number, $content);
        $this->assertStringContainsString('Budi Santoso', $content);
        $this->assertStringContainsString("B 1234 {$branch->code}", $content);
    }

    public function test_print_payment_history_only_includes_posted_receipts(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.print');

        $paymentService = new PaymentService();
        $postedReceipt = $paymentService->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $invoice->customer_id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 30000,
            'notes' => null,
            'allocations' => [['invoice_id' => $invoice->id, 'allocated_amount' => 30000]],
        ]);
        $voidedReceipt = $paymentService->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $invoice->customer_id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 20000,
            'notes' => null,
            'allocations' => [['invoice_id' => $invoice->id, 'allocated_amount' => 20000]],
        ]);
        $paymentService->voidPaymentReceipt($voidedReceipt, 'Kesalahan input.');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $content = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString($postedReceipt->number, $content);
        $this->assertStringNotContainsString($voidedReceipt->number, $content);
    }

    public function test_print_is_forbidden_for_draft_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeDraftInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.print');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $response->assertForbidden();
    }

    public function test_print_is_forbidden_for_cancelled_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeDraftInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.print');
        $this->grantBranchPermission($user, $branch, 'invoice.void');
        $this->actingAs($user)->patch("/invoices/{$invoice->id}/cancel", ['reason' => 'Batal.']);

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $response->assertForbidden();
    }

    public function test_print_is_forbidden_without_invoice_print_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $response->assertForbidden();
    }

    // --- sendEmail() ---

    public function test_send_email_queues_mail_and_flashes_success_when_customer_has_email(): void
    {
        Mail::fake();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.email');

        $response = $this->actingAs($user)->post("/invoices/{$invoice->id}/send-email");

        $response->assertRedirect("/invoices/{$invoice->id}");
        $response->assertSessionHas('status');
        Mail::assertQueued(InvoicePostedMail::class, function ($mail) use ($invoice) {
            return $mail->hasTo($invoice->customer->email) && $mail->invoice->is($invoice);
        });
    }

    public function test_send_email_rejects_when_customer_has_no_email(): void
    {
        Mail::fake();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $invoice->customer->update(['email' => null]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.email');

        $response = $this->actingAs($user)->post("/invoices/{$invoice->id}/send-email");

        $response->assertRedirect("/invoices/{$invoice->id}");
        $response->assertSessionHas('error');
        Mail::assertNothingQueued();
    }

    public function test_send_email_is_forbidden_for_draft_invoice(): void
    {
        Mail::fake();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeDraftInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.email');

        $response = $this->actingAs($user)->post("/invoices/{$invoice->id}/send-email");

        $response->assertForbidden();
        Mail::assertNothingQueued();
    }

    public function test_send_email_is_forbidden_without_invoice_email_permission(): void
    {
        Mail::fake();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post("/invoices/{$invoice->id}/send-email");

        $response->assertForbidden();
        Mail::assertNothingQueued();
    }

    // --- UI buttons ---

    public function test_show_page_displays_print_and_email_buttons_for_posted_invoice_with_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'invoice.print');
        $this->grantBranchPermission($user, $branch, 'invoice.email');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee(route('invoices.print', $invoice), false);
        $response->assertSee(route('invoices.send-email', $invoice), false);
        $response->assertSee('Cetak Invoice');
        $response->assertSee('Kirim Email');
    }

    public function test_show_page_hides_print_and_email_buttons_for_draft_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeDraftInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'invoice.print');
        $this->grantBranchPermission($user, $branch, 'invoice.email');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertDontSee('Cetak Invoice');
        $response->assertDontSee('Kirim Email');
    }
}
