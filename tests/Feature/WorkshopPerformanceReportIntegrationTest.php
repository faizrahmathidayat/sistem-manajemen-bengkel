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

class WorkshopPerformanceReportIntegrationTest extends TestCase
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

    public function test_mechanic_view_is_consistent_across_index_pdf_and_excel(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [120000], [80000], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $indexResponse = $this->actingAs($viewer)->get('/reports/workshop-performance');
        $pdfResponse = $this->actingAs($viewer)->get('/reports/workshop-performance/pdf-preview');
        $excelResponse = $this->actingAs($viewer)->get('/reports/workshop-performance/export-excel');

        $indexResponse->assertOk();
        $indexResponse->assertSee('MEK-001 - Agus Setiawan');
        $indexResponse->assertSee('200.000');

        $pdfResponse->assertOk();
        $pdfText = preg_replace('/\s+/', ' ', $this->extractPdfText($pdfResponse->getContent()));
        $this->assertStringContainsString('MEK-001 - Agus Setiawan', $pdfText);
        $this->assertStringContainsString('200.000', $pdfText);

        $excelResponse->assertOk();
        $excelResponse->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_invoice_detail_view_is_consistent_across_index_and_pdf(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [90000, 40000], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $indexResponse = $this->actingAs($viewer)->get('/reports/workshop-performance?view_type=invoice_detail');
        $pdfResponse = $this->actingAs($viewer)->get('/reports/workshop-performance/pdf-preview?view_type=invoice_detail');

        $indexResponse->assertOk();
        $indexResponse->assertSee('Sparepart 1');

        $pdfResponse->assertOk();
        $pdfText = preg_replace('/\s+/', ' ', $this->extractPdfText($pdfResponse->getContent()));
        $this->assertStringContainsString('Sparepart 1', $pdfText);
        $this->assertStringContainsString('Cabang Jakarta', $pdfText);
    }

    public function test_full_permission_gate_denies_index_pdf_and_excel_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/workshop-performance')->assertOk();
        $this->actingAs($user)->get('/reports/workshop-performance')->assertSee('Anda belum memiliki akses laporan performance bengkel di cabang manapun.');
        $this->actingAs($user)->get('/reports/workshop-performance/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/workshop-performance/pdf-download')->assertForbidden();
        $this->actingAs($user)->get('/reports/workshop-performance/export-excel')->assertForbidden();
    }
}
