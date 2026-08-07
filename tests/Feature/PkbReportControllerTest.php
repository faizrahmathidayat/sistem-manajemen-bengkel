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
use App\Support\WorkOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PkbReportControllerTest extends TestCase
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

    protected function makeScenario(Branch $branch, string $mechanicName = 'Agus Setiawan'): array
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::firstOrCreate(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id,
            'plate_number' => 'B ' . random_int(1000, 9999) . ' ' . $branch->code,
        ]);
        $mechanic = Mechanic::firstOrCreate(['name' => $mechanicName]);
        MechanicBranch::firstOrCreate(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => 'SVC-' . random_int(1000, 9999), 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => 'OLI-' . random_int(1000, 9999), 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);

        return compact('customer', 'vehicle', 'mechanic', 'catalog', 'sparepartBranch');
    }

    protected function makeWorkOrder(Branch $branch, array $scenario, string $status, float $serviceAmount = 100000, float $sparepartAmount = 0): WorkOrder
    {
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');

        $spareparts = $sparepartAmount > 0
            ? [['sparepart_branch_id' => $scenario['sparepartBranch']->id, 'qty' => 1, 'unit_price' => $sparepartAmount]]
            : [];

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $serviceAmount],
            ],
            'spareparts' => $spareparts,
        ]);
        $workOrder = WorkOrder::latest('id')->first();

        if ($status === WorkOrderStatus::DRAFT) {
            return $workOrder->fresh();
        }

        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");

        if ($status === WorkOrderStatus::OPEN) {
            return $workOrder->fresh();
        }

        if ($status === WorkOrderStatus::CANCELLED) {
            $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/cancel");

            return $workOrder->fresh();
        }

        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        return $workOrder->fresh();
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('belum memiliki akses', false);
    }

    public function test_index_lists_work_orders_for_permitted_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $workOrder = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee($workOrder->number);
    }

    public function test_index_is_scoped_to_permitted_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $scenarioA = $this->makeScenario($branchA);
        $scenarioB = $this->makeScenario($branchB);
        $workOrderA = $this->makeWorkOrder($branchA, $scenarioA, WorkOrderStatus::COMPLETED);
        $workOrderB = $this->makeWorkOrder($branchB, $scenarioB, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee($workOrderA->number);
        $response->assertDontSee($workOrderB->number);
    }

    public function test_index_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $scenarioA = $this->makeScenario($branchA);
        $scenarioB = $this->makeScenario($branchB);
        $workOrderA = $this->makeWorkOrder($branchA, $scenarioA, WorkOrderStatus::COMPLETED);
        $workOrderB = $this->makeWorkOrder($branchB, $scenarioB, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.pkb.view');
        $this->grantBranchPermission($viewer, $branchB, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get("/reports/pkb?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee($workOrderA->number);
        $response->assertDontSee($workOrderB->number);
    }

    public function test_index_filters_by_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $draft = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::DRAFT);
        $completed = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?status=' . WorkOrderStatus::COMPLETED);

        $response->assertOk();
        $response->assertSee($completed->number);
        $response->assertDontSee($draft->number);
    }

    public function test_index_filters_by_mechanic_search(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenarioA = $this->makeScenario($branch, 'Agus Setiawan');
        $scenarioB = $this->makeScenario($branch, 'Budi Wijaya');
        $workOrderA = $this->makeWorkOrder($branch, $scenarioA, WorkOrderStatus::COMPLETED);
        $workOrderB = $this->makeWorkOrder($branch, $scenarioB, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?mechanic=Agus');

        $response->assertOk();
        $response->assertSee($workOrderA->number);
        $response->assertDontSee($workOrderB->number);
    }

    public function test_index_filters_by_work_order_date_range(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $old = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED);
        $old->update(['work_order_date' => '2025-01-01']);
        $recent = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?date_from=' . now()->subDay()->toDateString());

        $response->assertOk();
        $response->assertSee($recent->number);
        $response->assertDontSee($old->number);
    }

    public function test_index_computes_summary_cards_across_all_statuses_by_default(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::DRAFT, 50000, 0);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertViewHas('summary', function ($summary) {
            return (int) $summary->total_pkb === 2
                && (int) $summary->total_completed === 1
                && (float) $summary->total_value === 210000.0;
        });
    }

    public function test_index_shows_service_sparepart_and_grand_total_per_row(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('100.000');
        $response->assertSee('60.000');
        $response->assertSee('160.000');
    }

    public function test_index_renders_summary_cards_and_filter_form(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('Total PKB');
        $response->assertSee('Total Nilai PKB');
        $response->assertSee('Total PKB Selesai');
        $response->assertSee('name="mechanic"', false);
        $response->assertSee('name="date_from"', false);
        $response->assertSee('name="date_to"', false);
    }

    public function test_index_shows_customer_vehicle_and_mechanic_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch, 'Agus Setiawan');
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('Budi Santoso');
        $response->assertSee($scenario['vehicle']->plate_number);
        $response->assertSee('Agus Setiawan');
    }

    public function test_index_shows_status_badge(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('Selesai');
    }

    public function test_index_shows_empty_state_when_no_results_match_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('Tidak ada PKB yang cocok dengan filter saat ini.');
    }
}
