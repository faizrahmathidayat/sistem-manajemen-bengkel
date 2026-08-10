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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class WorkshopPerformanceReportExportTest extends TestCase
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

    protected function makeInvoiceWithLines(Branch $branch, Customer $customer, Mechanic $mechanic, array $serviceAmounts, array $sparepartAmounts, string $invoiceDate): Invoice
    {
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        MechanicBranch::firstOrCreate(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::firstOrCreate(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id,
            'plate_number' => 'B ' . random_int(1000, 9999) . ' ' . $branch->code,
        ]);

        $services = [];
        foreach ($serviceAmounts as $index => $amount) {
            $catalog = ServiceCatalog::create(['code' => 'SVC-' . random_int(10000, 99999), 'name' => "Jasa {$index}", 'default_price' => $amount]);
            $services[] = ['service_catalog_id' => $catalog->id, 'description' => "Jasa {$index}", 'qty' => 1, 'unit_price' => $amount];
        }

        $spareparts = [];
        foreach ($sparepartAmounts as $index => $amount) {
            $sparepart = Sparepart::create(['code' => 'SPR-' . random_int(10000, 99999), 'name' => "Sparepart {$index}"]);
            $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => $amount]);
            DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);
            $spareparts[] = ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 1, 'unit_price' => $amount];
        }

        $user = User::factory()->create();
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete', 'invoice.create', 'invoice.post'] as $code) {
            $this->grantBranchPermission($user, $branch, $code);
        }

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => $services,
            'spareparts' => $spareparts,
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);
        $invoice = Invoice::where('work_order_id', $workOrder->id)->firstOrFail();
        $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");
        $invoice->update(['invoice_date' => $invoiceDate]);

        return $invoice->fresh(['details', 'workOrder.mechanic', 'branch', 'customer']);
    }

    public function test_pdf_actions_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/workshop-performance/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/workshop-performance/pdf-download')->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance/pdf-preview');

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance/pdf-download');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_pdf_preview_mechanic_view_shows_aggregates(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [90000], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance/pdf-preview');

        $response->assertOk();
        $text = preg_replace('/\s+/', ' ', $this->extractPdfText($response->getContent()));
        $this->assertStringContainsString('MEK-001 - Agus Setiawan', $text);
        $this->assertStringContainsString('100.000', $text);
        $this->assertStringContainsString('90.000', $text);
        $this->assertStringContainsString('190.000', $text);
    }

    public function test_pdf_preview_invoice_detail_view_shows_paired_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [90000, 40000], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance/pdf-preview?view_type=invoice_detail');

        $response->assertOk();
        $text = preg_replace('/\s+/', ' ', $this->extractPdfText($response->getContent()));
        $this->assertStringContainsString('MEK-001 - Agus Setiawan', $text);
        $this->assertStringContainsString('Jasa 0', $text);
        $this->assertStringContainsString('Sparepart 0', $text);
        $this->assertStringContainsString('Sparepart 1', $text);
        $this->assertStringContainsString('Cabang Jakarta', $text);
    }

    protected function loadExportedSheet($response): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        // Excel::download() returns a BinaryFileResponse (file written to disk), unlike
        // DomPDF's stream()/download() which set response content in-memory — so we must
        // read the underlying file path, not $response->getContent() (which is empty here).
        $spreadsheet = IOFactory::load($response->getFile()->getPathname());

        return $spreadsheet->getActiveSheet();
    }

    public function test_export_excel_is_forbidden_without_permission(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/reports/workshop-performance/export-excel');

        $response->assertForbidden();
    }

    public function test_export_excel_mechanic_view_returns_xlsx_with_grand_total_formula(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [90000], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance/export-excel');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sheet = $this->loadExportedSheet($response);
        $this->assertSame('MEK-001 - Agus Setiawan', $sheet->getCell('A3')->getValue());
        // assertEquals (bukan assertSame) untuk sel numerik: PhpSpreadsheet boleh membaca ulang
        // angka bulat sebagai int atau float tergantung reader — yang penting nilainya, bukan tipenya.
        $this->assertEquals(100000, $sheet->getCell('E3')->getValue());
        $this->assertEquals(90000, $sheet->getCell('H3')->getValue());
        $this->assertSame('=E3+H3', $sheet->getCell('I3')->getValue());
    }

    public function test_export_excel_invoice_detail_view_writes_subtotal_and_total_formulas(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [90000, 40000], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance/export-excel?view_type=invoice_detail');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sheet = $this->loadExportedSheet($response);
        // Baris 2: meta header; baris 3: nilai meta; baris 4: header kolom Jasa/Sparepart; baris 5-6: pairing; baris 7: Total.
        $this->assertSame('=B5*C5*(1-D5/100)', $sheet->getCell('E5')->getValue());
        $this->assertSame('=G5*H5*(1-I5/100)', $sheet->getCell('J5')->getValue());
        $this->assertSame('=J5+E5', $sheet->getCell('K5')->getValue());
        $this->assertSame('Total', $sheet->getCell('A7')->getValue());
        $this->assertSame('=SUM(E5:E6)', $sheet->getCell('E7')->getValue());
        $this->assertSame('=SUM(J5:J6)', $sheet->getCell('J7')->getValue());
        $this->assertSame('=J7+E7', $sheet->getCell('K7')->getValue());
    }
}
