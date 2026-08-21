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
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class WorkOrderPrintTest extends TestCase
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

        return WorkOrder::latest('id')->first();
    }

    public function test_print_returns_pdf_for_a_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.print');

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/print");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_print_content_includes_pkb_number_customer_and_plate_number(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.print');

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/print");

        $content = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString($workOrder->number, $content);
        $this->assertStringContainsString('Budi Santoso', $content);
        $this->assertStringContainsString("B 1234 {$branch->code}", $content);
    }

    public function test_print_content_includes_vehicle_year(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $workOrder->vehicle->update(['year' => 2022]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.print');

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/print");

        $content = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('2022', $content);
    }

    public function test_print_content_shows_keluhan_label_not_catatan(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.print');

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/print");

        $content = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('Keluhan:', $content);
        $this->assertStringNotContainsString('Catatan:', $content);
    }

    public function test_print_works_regardless_of_work_order_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $this->assertSame(\App\Support\WorkOrderStatus::DRAFT, $workOrder->status);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.print');

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/print");

        $response->assertOk();
    }

    public function test_print_is_forbidden_without_pkb_print_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/print");

        $response->assertForbidden();
    }

    public function test_print_estimate_returns_pdf_with_ppn_for_draft_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $this->assertSame(\App\Support\WorkOrderStatus::DRAFT, $workOrder->status);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.print');

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/print-estimate");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $content = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('ESTIMASI', $content);
        // service total 50.000, sparepart total 120.000 -> subtotal 170.000, PPN 11% = 18.700
        $this->assertStringContainsString('18.700', $content);
        $this->assertStringContainsString('188.700', $content);
    }

    public function test_print_estimate_is_forbidden_when_work_order_is_not_draft(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.print');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/print-estimate");

        $response->assertForbidden();
    }

    public function test_print_estimate_is_forbidden_without_pkb_print_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/print-estimate");

        $response->assertForbidden();
    }

    public function test_show_page_displays_print_estimate_button_only_when_draft(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.print');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');

        $draftResponse = $this->actingAs($user)->get("/work-orders/{$workOrder->id}");
        $draftResponse->assertOk();
        $draftResponse->assertSee(route('work-orders.print-estimate', $workOrder), false);
        $draftResponse->assertSee('Cetak Estimasi');

        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");

        $confirmedResponse = $this->actingAs($user)->get("/work-orders/{$workOrder->id}");
        $confirmedResponse->assertOk();
        $confirmedResponse->assertDontSee('Cetak Estimasi');
    }

    public function test_regular_print_does_not_include_ppn(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.print');

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/print");

        $content = $this->extractPdfText($response->getContent());
        $this->assertStringNotContainsString('ESTIMASI', $content);
        $this->assertStringNotContainsString('PPN', $content);
    }

    public function test_show_page_displays_print_button_with_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.print');

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee(route('work-orders.print', $workOrder), false);
        $response->assertSee('Cetak PKB');
    }

    public function test_show_page_hides_print_button_without_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertDontSee('Cetak PKB');
    }
}
