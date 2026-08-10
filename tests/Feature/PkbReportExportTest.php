<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
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
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class PkbReportExportTest extends TestCase
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

    protected function makeCompletedWorkOrder(Branch $branch, Customer $customer, float $serviceAmount, string $workOrderDate): WorkOrder
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

        $user = User::factory()->create();
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete'] as $code) {
            $this->grantBranchPermission($user, $branch, $code);
        }

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => $workOrderDate,
            'services' => [['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $serviceAmount]],
            'spareparts' => [],
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        return $workOrder->fresh();
    }

    public function test_export_excel_returns_xlsx_with_correct_headers(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/export-excel');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_excel_is_forbidden_without_permission(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/reports/pkb/export-excel');

        $response->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/pdf-preview');

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/pdf-download');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_pdf_actions_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/pkb/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/pkb/pdf-download')->assertForbidden();
    }

    public function test_pdf_preview_respects_mechanic_and_date_filters(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $old = $this->makeCompletedWorkOrder($branch, $customer, 100000, '2025-01-01');
        $recent = $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/pdf-preview?date_from=' . now()->subDay()->toDateString());

        $response->assertOk();
        $text = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString($recent->number, $text);
        $this->assertStringNotContainsString($old->number, $text);
    }

    public function test_pdf_preview_detail_mode_shows_line_items(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/pdf-preview?mode=detail');

        $response->assertOk();
        $text = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('Ganti Oli', $text);
    }

    public function test_pdf_preview_rekap_mode_shows_branch_mechanic_year_and_odometer(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $workOrder = $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $workOrder->mechanic->update(['nip' => 'MEK-001']);
        $workOrder->vehicle->update(['year' => 2022]);
        $workOrder->update(['odometer_km' => 15000.5]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/pdf-preview');

        $response->assertOk();
        $text = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('Cabang Jakarta', $text);
        $this->assertStringContainsString('MEK-001 - Mekanik JKT', $text);
        $this->assertStringContainsString('2022', $text);
        $this->assertStringContainsString('15000.5', $text);
    }

    public function test_pdf_preview_detail_mode_shows_branch_mechanic_year_and_odometer(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $workOrder = $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $workOrder->mechanic->update(['nip' => 'MEK-001']);
        $workOrder->vehicle->update(['year' => 2022]);
        $workOrder->update(['odometer_km' => 15000.5]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/pdf-preview?mode=detail');

        $response->assertOk();
        $text = preg_replace('/\s+/', ' ', $this->extractPdfText($response->getContent()));
        $this->assertStringContainsString('Cabang Jakarta', $text);
        $this->assertStringContainsString('MEK-001 - Mekanik JKT', $text);
        $this->assertStringContainsString('2022', $text);
        $this->assertStringContainsString('15000.5', $text);
    }

    public function test_export_buttons_render_on_the_report_page_with_filters_forwarded(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?mode=detail');

        $response->assertOk();
        $response->assertSee('/reports/pkb/export-excel?mode=detail', false);
        $response->assertSee('/reports/pkb/pdf-preview?mode=detail', false);
        $response->assertSee('/reports/pkb/pdf-download?mode=detail', false);
    }
}
