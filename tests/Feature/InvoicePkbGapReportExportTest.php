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

class InvoicePkbGapReportExportTest extends TestCase
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

    protected function makeGapPair(
        Branch $branch,
        Customer $customer,
        float $serviceAmount,
        float $sparepartAmount,
        string $invoiceDate,
        ?array $editPayload = null,
        bool $post = true
    ): array {
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

        $pkbUser = User::factory()->create();
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete', 'invoice.create', 'invoice.edit', 'invoice.post'] as $code) {
            $this->grantBranchPermission($pkbUser, $branch, $code);
        }

        $this->actingAs($pkbUser)->post('/work-orders', [
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => [['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $serviceAmount]],
            'spareparts' => $spareparts,
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($pkbUser)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($pkbUser)->patch("/work-orders/{$workOrder->id}/complete");

        $this->actingAs($pkbUser)->post('/invoices', ['work_order_id' => $workOrder->id]);
        $invoice = Invoice::where('work_order_id', $workOrder->id)->firstOrFail();

        if ($editPayload) {
            $this->actingAs($pkbUser)->put("/invoices/{$invoice->id}", $editPayload);
            $invoice = $invoice->fresh();
        }

        if ($post) {
            $this->actingAs($pkbUser)->patch("/invoices/{$invoice->id}/post");
            $invoice = $invoice->fresh();
        }

        $invoice->update(['invoice_date' => $invoiceDate]);

        return ['invoice' => $invoice->fresh('details'), 'workOrder' => $workOrder->fresh()];
    }

    public function test_export_excel_returns_xlsx_with_correct_headers(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap/export-excel?gap_status=semua');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_excel_is_forbidden_without_permission(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/reports/invoice-pkb-gap/export-excel');

        $response->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap/pdf-preview?gap_status=semua');

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap/pdf-download?gap_status=semua');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_pdf_actions_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/invoice-pkb-gap/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/invoice-pkb-gap/pdf-download')->assertForbidden();
    }

    public function test_pdf_preview_respects_gap_status_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $exact = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $gt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap/pdf-preview?gap_status=sesuai');

        $response->assertOk();
        $text = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString($exact['invoice']->number, $text);
        $this->assertStringNotContainsString($gt['invoice']->number, $text);
    }

    public function test_pdf_preview_detail_mode_shows_comparison_categories(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $pair = $this->makeGapPair($branch, $customer, 100000, 60000, now()->toDateString(), null, false);
        $serviceDetail = $pair['invoice']->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $editor = User::factory()->create();
        $this->grantBranchPermission($editor, $branch, 'invoice.edit');
        $this->actingAs($editor)->put("/invoices/{$pair['invoice']->id}", [
            'discount_percent' => 0, 'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 120000,
            ]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap/pdf-preview?mode=detail&gap_status=semua');

        $response->assertOk();
        $text = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('Berubah', $text);
        $this->assertStringContainsString('Dihapus', $text);
    }

    public function test_export_buttons_render_on_the_report_page_with_filters_forwarded(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=semua');

        $response->assertOk();
        $response->assertSee('/reports/invoice-pkb-gap/export-excel?gap_status=semua', false);
        $response->assertSee('/reports/invoice-pkb-gap/pdf-preview?gap_status=semua', false);
        $response->assertSee('/reports/invoice-pkb-gap/pdf-download?gap_status=semua', false);
    }
}
