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
use Tests\TestCase;

class WorkOrderManagementTest extends TestCase
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

    protected function makeScenario(Branch $branch): array
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        // Reference/catalog data (categories, brands, types, service catalog codes, sparepart
        // codes, vehicle plate numbers) is globally unique in this schema, so it is namespaced
        // by branch code here to let makeScenario() be called more than once per test (e.g. for
        // a multi-branch index test) without a duplicate-key violation.
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

        return compact('customer', 'vehicle', 'mechanic', 'catalog', 'sparepartBranch');
    }

    protected function baseStorePayload(Branch $branch, array $scenario): array
    {
        return [
            'branch_id' => $branch->id,
            'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->format('Y-m-d'),
            'odometer_km' => 15000,
            'notes' => 'Servis rutin',
            'services' => [
                ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 50000],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $scenario['sparepartBranch']->id, 'qty' => 2, 'unit_price' => 60000],
            ],
        ];
    }

    public function test_store_creates_work_order_with_header_and_both_line_types(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));

        $workOrder = WorkOrder::first();
        $response->assertRedirect(route('work-orders.show', $workOrder));
        $this->assertNotNull($workOrder);
        $this->assertSame(WorkOrderStatus::DRAFT, $workOrder->status);
        $this->assertStringStartsWith('PKB/JKT/', $workOrder->number);
        $this->assertCount(1, $workOrder->serviceLines);
        $this->assertCount(1, $workOrder->sparepartLines);
        $this->assertSame(50000.0, (float) $workOrder->serviceLines->first()->line_total);
        $this->assertSame(120000.0, (float) $workOrder->sparepartLines->first()->line_total);
    }

    public function test_store_recomputes_line_total_server_side_ignoring_client_value(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['services'][0]['line_total'] = 999999;

        $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $workOrder = WorkOrder::first();
        $this->assertSame(50000.0, (float) $workOrder->serviceLines->first()->line_total);
    }

    public function test_store_is_forbidden_without_pkb_create_in_the_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/work-orders', $this->baseStorePayload($branch, $scenario));

        $response->assertForbidden();
    }

    public function test_store_rejects_customer_not_servable_in_the_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $otherCustomer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['customer_id'] = $otherCustomer->id;

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors(['customer_id']);
    }

    public function test_store_rejects_vehicle_not_belonging_to_the_customer(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $otherCustomer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        CustomerBranch::create(['customer_id' => $otherCustomer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => 'Motor']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);
        $otherVehicle = Vehicle::create(['customer_id' => $otherCustomer->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 5555 AAA']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['vehicle_id'] = $otherVehicle->id;

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors(['vehicle_id']);
    }

    public function test_store_rejects_mechanic_not_assigned_to_the_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $otherMechanic = Mechanic::create(['name' => 'Mekanik Lain']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['mechanic_id'] = $otherMechanic->id;

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors(['mechanic_id']);
    }

    public function test_store_rejects_sparepart_from_a_different_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $otherBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $otherSparepart = Sparepart::create(['code' => 'FIL-01', 'name' => 'Filter Udara']);
        $otherSparepartBranch = SparepartBranch::create(['sparepart_id' => $otherSparepart->id, 'branch_id' => $otherBranch->id, 'selling_price' => 40000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['spareparts'][0]['sparepart_branch_id'] = $otherSparepartBranch->id;

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors();
    }

    public function test_store_rejects_a_work_order_with_no_lines_at_all(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['services'] = [];
        $payload['spareparts'] = [];

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors(['services']);
    }

    public function test_store_rejects_all_blank_line_rows_instead_of_crashing(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['services'] = [
            ['service_catalog_id' => null, 'description' => '', 'qty' => '', 'unit_price' => ''],
        ];
        $payload['spareparts'] = [
            ['sparepart_branch_id' => '', 'qty' => '', 'unit_price' => ''],
        ];

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors(['services']);
        $this->assertSame(0, WorkOrder::count());
    }

    public function test_store_silently_drops_a_blank_row_mixed_with_a_real_line(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['services'] = [
            ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 50000],
            ['service_catalog_id' => null, 'description' => '', 'qty' => '', 'unit_price' => ''],
        ];
        $payload['spareparts'] = [];

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $workOrder = WorkOrder::first();
        $response->assertRedirect(route('work-orders.show', $workOrder));
        $this->assertNotNull($workOrder);
        $this->assertCount(1, $workOrder->serviceLines);
        $this->assertSame('Ganti Oli', $workOrder->serviceLines->first()->description);
    }

    public function test_edit_form_still_renders_and_shows_a_now_inactive_customer(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();
        $scenario['customer']->update(['is_active' => false]);

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}/edit");

        $response->assertOk();
        $response->assertSee($scenario['customer']->name);
    }

    public function test_update_does_not_silently_reassign_a_now_inactive_customer(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();
        $originalCustomerId = $workOrder->customer_id;
        $scenario['customer']->update(['is_active' => false]);

        $response = $this->actingAs(User::find($user->id))->put("/work-orders/{$workOrder->id}", [
            'customer_id' => $originalCustomerId,
            'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->format('Y-m-d'),
            'notes' => 'Catatan diperbarui',
            'services' => [
                ['service_catalog_id' => null, 'description' => 'Servis tambahan', 'qty' => 2, 'unit_price' => 25000],
            ],
            'spareparts' => [],
        ]);

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $workOrder->refresh();
        $this->assertSame($originalCustomerId, $workOrder->customer_id);
        $this->assertSame('Catatan diperbarui', $workOrder->notes);
    }

    public function test_index_lists_work_orders_for_authorized_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $scenarioA = $this->makeScenario($branchA);
        $scenarioB = $this->makeScenario($branchB);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'pkb.view');
        $this->grantBranchPermission($user, $branchA, 'pkb.create');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branchA, $scenarioA));
        $this->grantBranchPermission($user, $branchB, 'pkb.create');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branchB, $scenarioB));

        $response = $this->actingAs(User::find($user->id))->get('/work-orders');

        $response->assertOk();
        $workOrderA = WorkOrder::where('branch_id', $branchA->id)->first();
        $workOrderB = WorkOrder::where('branch_id', $branchB->id)->first();
        $response->assertSee($workOrderA->number);
        $response->assertDontSee($workOrderB->number);
    }

    public function test_index_shows_no_access_page_without_any_pkb_view_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/work-orders');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_show_is_forbidden_for_a_work_order_in_an_unauthorized_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $creator = User::factory()->create();
        $this->grantBranchPermission($creator, $branch, 'pkb.create');
        $this->actingAs(User::find($creator->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->get("/work-orders/{$workOrder->id}");

        $response->assertForbidden();
    }

    public function test_create_form_renders_for_a_user_with_pkb_create_in_some_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders/create');

        $response->assertOk();
    }

    public function test_edit_form_renders_for_a_user_with_pkb_edit_on_a_draft_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}/edit");

        $response->assertOk();
    }

    public function test_update_replaces_lines_and_recomputes_totals(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();
        $updatePayload = [
            'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => null, 'description' => 'Servis tambahan', 'qty' => 2, 'unit_price' => 25000],
            ],
            'spareparts' => [],
        ];

        $response = $this->actingAs(User::find($user->id))->put("/work-orders/{$workOrder->id}", $updatePayload);

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $workOrder->refresh();
        $this->assertCount(1, $workOrder->serviceLines);
        $this->assertCount(0, $workOrder->sparepartLines);
        $this->assertSame(50000.0, (float) $workOrder->serviceLines->first()->line_total);
    }

    public function test_update_is_forbidden_for_a_cancelled_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();
        $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");
        $workOrder->refresh();

        $response = $this->actingAs(User::find($user->id))->put("/work-orders/{$workOrder->id}", [
            'customer_id' => $scenario['customer']->id, 'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => [['service_catalog_id' => null, 'description' => 'X', 'qty' => 1, 'unit_price' => 1000]],
        ]);

        $response->assertForbidden();
    }

    public function test_cancel_marks_work_order_cancelled(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $workOrder->refresh();
        $this->assertSame(WorkOrderStatus::CANCELLED, $workOrder->status);
    }

    public function test_cancel_is_forbidden_without_pkb_cancel_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");

        $response->assertForbidden();
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders?q[]=PKB');

        $response->assertOk();
    }

    public function test_index_shows_empty_state_when_no_work_orders_match(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders');

        $response->assertOk();
        $response->assertSee('Belum ada PKB');
    }

    public function test_empty_state_cta_shown_with_create_permission_in_any_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders');

        $response->assertOk();
        $response->assertSee('Buat PKB Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders');

        $response->assertOk();
        $response->assertDontSee('Buat PKB Pertama');
    }

    protected function confirmWorkOrder(Branch $branch, array $scenario, array $overrides = []): WorkOrder
    {
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $this->actingAs(User::find($user->id))->post('/work-orders', array_merge($this->baseStorePayload($branch, $scenario), $overrides));
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/confirm");

        return $workOrder->fresh();
    }

    public function test_confirm_reserves_full_qty_and_sets_open_when_stock_is_sufficient(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);

        $workOrder = $this->confirmWorkOrder($branch, $scenario);

        $this->assertSame(WorkOrderStatus::OPEN, $workOrder->status);
        $line = $workOrder->sparepartLines->first();
        $this->assertCount(1, $line->reservations);
        $this->assertSame(2.0, (float) $line->reservations->first()->qty);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $this->assertSame(2.0, (float) $stock->reserved_qty);
    }

    public function test_confirm_partially_reserves_and_sets_shortage_when_stock_is_insufficient(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);

        $workOrder = $this->confirmWorkOrder($branch, $scenario);

        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->status);
        $line = $workOrder->sparepartLines->first();
        $this->assertCount(1, $line->reservations);
        $this->assertSame(1.0, (float) $line->reservations->first()->qty);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $this->assertSame(1.0, (float) $stock->reserved_qty);
    }

    public function test_confirm_creates_no_reservation_when_stock_is_zero(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);

        $workOrder = $this->confirmWorkOrder($branch, $scenario);

        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->status);
        $this->assertCount(0, $workOrder->sparepartLines->first()->reservations);
    }

    public function test_confirm_sets_open_immediately_for_a_jasa_only_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['spareparts'] = [];

        $workOrder = $this->confirmWorkOrder($branch, $scenario, $payload);

        $this->assertSame(WorkOrderStatus::OPEN, $workOrder->status);
    }

    public function test_confirm_is_forbidden_without_pkb_confirm_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::latest('id')->first();

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/confirm");

        $response->assertForbidden();
    }

    public function test_confirm_is_forbidden_for_a_non_draft_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/confirm");

        $response->assertForbidden();
    }

    public function test_override_shortage_records_reason_without_changing_status_or_reservations(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/override-shortage", [
            'reason' => 'Sparepart dipesan dari cabang lain, tetap lanjutkan servis.',
        ]);

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $workOrder->refresh();
        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->status);
        $this->assertNotNull($workOrder->shortage_overridden_at);
        $this->assertSame($user->id, $workOrder->shortage_overridden_by);
        $stockAfter = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $this->assertSame(1.0, (float) $stockAfter->reserved_qty);
    }

    public function test_override_shortage_requires_a_reason(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => '']);

        $response->assertSessionHasErrors(['reason']);
    }

    public function test_override_shortage_is_forbidden_when_status_is_not_shortage(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => 'x']);

        $response->assertForbidden();
    }

    public function test_override_shortage_is_forbidden_when_already_overridden(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => 'first']);

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => 'second']);

        $response->assertForbidden();
    }

    public function test_cancel_from_open_releases_active_reservations(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $workOrder->refresh();
        $this->assertSame(WorkOrderStatus::CANCELLED, $workOrder->status);
        $line = $workOrder->sparepartLines->first();
        $this->assertSame('released', $line->reservations->first()->status);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $this->assertSame(0.0, (float) $stock->reserved_qty);
    }

    public function test_cancel_from_shortage_releases_partial_reservation(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');

        $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $this->assertSame(0.0, (float) $stock->reserved_qty);
    }

    public function test_cancel_from_draft_still_works_without_touching_reservations(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::latest('id')->first();

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $this->assertSame(WorkOrderStatus::CANCELLED, $workOrder->fresh()->status);
    }

    public function test_update_is_still_forbidden_for_open_and_shortage_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.edit');

        $response = $this->actingAs(User::find($user->id))->put("/work-orders/{$workOrder->id}", [
            'customer_id' => $scenario['customer']->id, 'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => [['service_catalog_id' => null, 'description' => 'X', 'qty' => 1, 'unit_price' => 1000]],
        ]);

        $response->assertForbidden();
    }
}
