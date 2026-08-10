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
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class InvoiceReportExportTest extends TestCase
{
    use RefreshDatabase, ExtractsPdfText;

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

    protected function makeInvoice(Branch $branch, Customer $customer, float $serviceAmount, float $sparepartAmount, string $invoiceDate, bool $post = true): \App\Models\Invoice
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
        $catalog = ServiceCatalog::create(['code' => 'SVC-' . random_int(1000, 9999), 'name' => 'Ganti Oli', 'default_price' => $serviceAmount]);

        $spareparts = [];
        if ($sparepartAmount > 0) {
            $sparepart = Sparepart::create(['code' => 'OLI-' . random_int(1000, 9999), 'name' => 'Oli Mesin']);
            $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => $sparepartAmount]);
            DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);
            $spareparts = [['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 1, 'unit_price' => $sparepartAmount]];
        }

        $user = User::factory()->create();
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete', 'invoice.create', 'invoice.post'] as $code) {
            $this->grantBranchPermission($user, $branch, $code);
        }

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => [['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $serviceAmount]],
            'spareparts' => $spareparts,
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);
        $invoice = \App\Models\Invoice::where('work_order_id', $workOrder->id)->firstOrFail();
        if ($post) {
            $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");
            $invoice = $invoice->fresh();
        }
        $invoice->update(['invoice_date' => $invoiceDate]);

        return $invoice->fresh('details');
    }

    public function test_export_excel_returns_xlsx_with_correct_headers(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/export-excel');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_excel_is_forbidden_without_permission(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/reports/invoices/export-excel');

        $response->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/pdf-preview');

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/pdf-download');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_pdf_actions_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/invoices/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/invoices/pdf-download')->assertForbidden();
    }

    public function test_pdf_preview_respects_status_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $draft = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString(), false);
        $posted = $this->makeInvoice($branch, $customer, 50000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/pdf-preview?status=posted');

        $response->assertOk();
        $text = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString($posted->number, $text);
        $this->assertStringNotContainsString($draft->number, $text);
    }

    public function test_pdf_preview_detail_mode_shows_line_items(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/pdf-preview?mode=detail');

        $response->assertOk();
        $text = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('Ganti Oli', $text);
        $this->assertStringContainsString('Oli Mesin', $text);
    }

    public function test_pdf_preview_rekap_mode_shows_branch_and_mechanic(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $invoice->workOrder->mechanic->update(['nip' => 'MEK-001']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/pdf-preview');

        $response->assertOk();
        $text = preg_replace('/\s+/', ' ', $this->extractPdfText($response->getContent()));
        $this->assertStringContainsString('Cabang Jakarta', $text);
        $this->assertStringContainsString('MEK-001 - Mekanik JKT', $text);
    }

    public function test_pdf_preview_detail_mode_shows_branch_mechanic_and_discount(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $invoice->workOrder->mechanic->update(['nip' => 'MEK-001']);
        $invoice->details()->first()->update(['discount_percent' => 10, 'discount_amount' => 10000, 'line_total' => 90000]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/pdf-preview?mode=detail');

        $response->assertOk();
        $text = preg_replace('/\s+/', ' ', $this->extractPdfText($response->getContent()));
        $this->assertStringContainsString('Cabang Jakarta', $text);
        $this->assertStringContainsString('MEK-001 - Mekanik JKT', $text);
        $this->assertStringContainsString('10.000', $text);
    }

    public function test_export_buttons_render_on_the_report_page_with_filters_forwarded(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?mode=detail');

        $response->assertOk();
        $response->assertSee('/reports/invoices/export-excel?mode=detail', false);
        $response->assertSee('/reports/invoices/pdf-preview?mode=detail', false);
        $response->assertSee('/reports/invoices/pdf-download?mode=detail', false);
    }
}
