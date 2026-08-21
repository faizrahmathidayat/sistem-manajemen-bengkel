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
use App\Services\InvoiceService;
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

    public function test_store_rejects_decimal_qty_on_service_line(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['services'][0]['qty'] = 1.5;

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors(['services.0.qty']);
        $this->assertSame(0, WorkOrder::count());
    }

    public function test_store_rejects_decimal_qty_on_sparepart_line(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['spareparts'][0]['qty'] = 2.5;

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors(['spareparts.0.qty']);
        $this->assertSame(0, WorkOrder::count());
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

    public function test_store_forces_service_unit_price_from_catalog_ignoring_client_value(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        // catalog default_price is 50000 (set in makeScenario); submit a tampered price.
        $payload['services'][0]['unit_price'] = 1;

        $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $workOrder = WorkOrder::first();
        $this->assertSame(50000.0, (float) $workOrder->serviceLines->first()->unit_price);
    }

    public function test_store_forces_sparepart_unit_price_from_branch_selling_price_ignoring_client_value(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        // sparepartBranch selling_price is 60000 (set in makeScenario); submit a tampered price.
        $payload['spareparts'][0]['unit_price'] = 1;

        $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $workOrder = WorkOrder::first();
        $this->assertSame(60000.0, (float) $workOrder->sparepartLines->first()->unit_price);
    }

    public function test_store_rejects_a_service_line_without_a_catalog(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['services'] = [
            ['service_catalog_id' => null, 'description' => 'Servis manual', 'qty' => 1, 'unit_price' => 50000],
        ];

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors(['services.0.service_catalog_id']);
        $this->assertSame(0, WorkOrder::count());
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

        // As of the Kendaraan-first cascade, Customer is a readonly display derived from the
        // selected vehicle rather than its own Select2 picker. The edit page seeds that readonly
        // field server-side from $workOrder->customer->name (surviving deactivation, since it's
        // a plain eager-loaded relation, not a lookup query), and the vehicle picker itself is
        // preselected client-side via preselectAjaxOption(), which fetches
        // /lookup/vehicles?ids[]=<id> and injects a matching <option>. A non-JS-executing HTTP
        // test cannot observe that injected <option>. What it CAN and must still prove is that
        // the edit page renders successfully (doesn't error), that the readonly Customer field
        // shows the (now inactive) customer's name, AND that the data the client-side vehicle
        // preselect depends on — the linked vehicle's id, echoed verbatim into the inline
        // bootstrap <script> as `preselectAjaxOption(vehicleSelect, { ..., id: <id>, ... })` —
        // is present and correct in the raw response. Combined with
        // LookupControllerTest::test_vehicles_ids_mode_resolves_a_specific_vehicle_regardless_of_active_customer_status
        // (which proves that id, once fetched, resolves the vehicle and its owner's name even
        // though the customer is now inactive), this is the strongest assertion a Feature test
        // can honestly make about this flow.
        $response->assertOk();
        $response->assertSee('value="' . $scenario['customer']->name . '"', false);
        $response->assertSee('preselectAjaxOption(vehicleSelect', false);
        $response->assertSee('id: ' . $scenario['vehicle']->id . ',', false);
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
                ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Servis tambahan', 'qty' => 2, 'unit_price' => 25000],
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

    public function test_index_filters_by_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $draft = WorkOrder::create([
            'number' => 'PKB-DRAFT-TEST', 'branch_id' => $branch->id, 'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id, 'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->toDateString(), 'status' => WorkOrderStatus::DRAFT,
        ]);
        $open = WorkOrder::create([
            'number' => 'PKB-OPEN-TEST', 'branch_id' => $branch->id, 'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id, 'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->toDateString(), 'status' => WorkOrderStatus::OPEN,
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->get('/work-orders?status=' . WorkOrderStatus::OPEN);

        $response->assertOk();
        $response->assertSee($open->number);
        $response->assertDontSee($draft->number);
    }

    public function test_index_shows_dikonfirmasi_label_for_an_open_work_order_not_dibatalkan(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $this->assertSame(WorkOrderStatus::OPEN, $workOrder->status);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders');

        $response->assertOk();
        $response->assertSee('Dikonfirmasi');
        // Not a bare assertDontSee('Dibatalkan'): the list-filter-bar's own status
        // dropdown (added for the status filter feature) always renders a "Dibatalkan"
        // option regardless of what data exists. Assert against the status badge's
        // unique markup instead.
        $response->assertDontSee('status-danger">Dibatalkan', false);
    }

    public function test_index_shows_kurang_stok_label_for_a_shortage_work_order_not_dibatalkan(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->status);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders');

        $response->assertOk();
        $response->assertSee('Kurang Stok');
        // Not a bare assertDontSee('Dibatalkan'): the list-filter-bar's own status
        // dropdown (added for the status filter feature) always renders a "Dibatalkan"
        // option regardless of what data exists. Assert against the status badge's
        // unique markup instead.
        $response->assertDontSee('status-danger">Dibatalkan', false);
    }

    public function test_index_shows_selesai_label_for_a_completed_work_order_not_dibatalkan(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $response = $this->actingAs($user)->get('/work-orders');

        $response->assertOk();
        $response->assertSee('Selesai');
        // Not a bare assertDontSee('Dibatalkan'): the list-filter-bar's own status
        // dropdown (added for the status filter feature) always renders a "Dibatalkan"
        // option regardless of what data exists. Assert against the status badge's
        // unique markup instead.
        $response->assertDontSee('status-danger">Dibatalkan', false);
    }

    public function test_index_shows_belum_diinvoice_label_for_a_completed_work_order_without_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $response = $this->actingAs($user)->get('/work-orders');

        $response->assertOk();
        $response->assertSee('Belum Diinvoice');
        $response->assertDontSee('Sudah Diinvoice');
    }

    public function test_index_shows_sudah_diinvoice_label_for_a_completed_work_order_with_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        (new InvoiceService())->createFromWorkOrder($workOrder->fresh());

        $response = $this->actingAs($user)->get('/work-orders');

        $response->assertOk();
        $response->assertSee('Sudah Diinvoice');
        $response->assertDontSee('Belum Diinvoice');
    }

    public function test_index_does_not_show_invoice_label_for_a_non_completed_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->get('/work-orders');

        $response->assertOk();
        $response->assertDontSee('Sudah Diinvoice');
        $response->assertDontSee('Belum Diinvoice');
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

    public function test_create_page_loads_select2_and_lookup_js(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders/create');

        $response->assertOk();
        $response->assertSee('select2', false);
        $response->assertSee('select2-ajax-picker.js', false);
        $response->assertSee('id="vehicleSelect"', false);
        $response->assertSee('id="mechanicSelect"', false);
        $response->assertSee('id="customerDisplay"', false);
        $response->assertSee('id="customerIdInput"', false);
    }

    public function test_create_form_mechanic_and_vehicle_replay_both_trigger_select2_change(): void
    {
        // Regression guard: after a failed validation round-trip, old('mechanic_id')/old('vehicle_id')
        // repopulate the underlying <select> values via preselectAjaxOption(), but Select2 only
        // re-renders its visible widget in response to a jQuery 'change' event — without an explicit
        // $(...).trigger('change') the picker would silently keep showing its placeholder even
        // though the correct value was set underneath. Since Select2's actual re-render is
        // client-side JS with no PHP-testable surface, the strongest thing a Feature test can
        // honestly assert is that the compiled page source contains the trigger call immediately
        // tied to each preselect.
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders/create');

        $response->assertOk();
        $response->assertSee("const item = await preselectAjaxOption(vehicleSelect, { endpoint: '" . route('lookup.vehicles') . "', id: oldVehicleId, extraParams: function () { return { branch_id: currentBranchId }; } });", false);
        $response->assertSee("preselectAjaxOption(mechanicSelect, { endpoint: '" . route('lookup.mechanics') . "', id: oldMechanicId, extraParams: function () { return { branch_id: currentBranchId }; } });", false);
        $response->assertSee("\$(vehicleSelect).trigger('change');", false);
        $response->assertSee("\$(mechanicSelect).trigger('change');", false);
    }

    public function test_edit_page_loads_select2_and_lookup_js(): void
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
        $response->assertSee('select2', false);
        $response->assertSee('select2-ajax-picker.js', false);
    }

    public function test_edit_page_preselects_the_work_orders_vehicle(): void
    {
        // The vehicle option label (including brand/type/year) is now built server-side by
        // LookupController::vehicles() and delivered via AJAX rather than rendered inline in
        // this page — see LookupControllerTest::test_vehicles_returns_matches_scoped_to_branch_with_full_shape
        // for that assertion. What this page can honestly assert is that it echoes the correct
        // vehicle id into the client-side preselect call.
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $scenario['vehicle']->update(['year' => 2020]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}/edit");

        $response->assertOk();
        $response->assertSee('preselectAjaxOption(vehicleSelect', false);
        $response->assertSee('id: ' . $scenario['vehicle']->id . ',', false);
    }

    public function test_show_page_displays_vehicle_year(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $scenario['vehicle']->update(['year' => 2021]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee('Tahun Kendaraan');
        $response->assertSee('2021');
    }

    public function test_create_page_shows_keluhan_label_not_catatan(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs($user)->get('/work-orders/create');

        $response->assertOk();
        $response->assertSee('Keluhan');
        $response->assertDontSee('>Catatan<', false);
    }

    public function test_edit_page_shows_keluhan_label_not_catatan(): void
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
        $response->assertSee('Keluhan');
        $response->assertDontSee('>Catatan<', false);
    }

    public function test_show_page_shows_keluhan_label_not_catatan(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee('Keluhan');
        $response->assertDontSee('>Catatan<', false);
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
                ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Servis tambahan', 'qty' => 1, 'unit_price' => 25000],
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

    public function test_confirm_reserves_correctly_for_two_sparepart_lines_submitted_in_reverse_id_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $sparepartTwo = Sparepart::create(['code' => 'OLI-02-JKT', 'name' => 'Oli Gardan']);
        $sparepartBranchTwo = SparepartBranch::create([
            'sparepart_id' => $sparepartTwo->id, 'branch_id' => $branch->id, 'selling_price' => 70000,
        ]);
        // sparepartBranchTwo necessarily has a higher id than scenario['sparepartBranch'] since it
        // was created afterwards — submitting it FIRST below deliberately reverses ascending
        // sparepart_branch_id order relative to submission/sort_order, which is what would trigger
        // an AB-BA lock-ordering deadlock between two work orders if orderBy('sparepart_branch_id')
        // were silently ignored (Finding 1: it was being appended after the relation's own
        // orderBy('sort_order'), so it never actually reordered anything).
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchTwo->id)->update(['on_hand_qty' => 10]);

        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['spareparts'] = [
            ['sparepart_branch_id' => $sparepartBranchTwo->id, 'qty' => 3, 'unit_price' => 70000],
            ['sparepart_branch_id' => $scenario['sparepartBranch']->id, 'qty' => 2, 'unit_price' => 60000],
        ];

        $workOrder = $this->confirmWorkOrder($branch, $scenario, $payload);

        $this->assertSame(WorkOrderStatus::OPEN, $workOrder->status);

        $orderedLines = $workOrder->sparepartLines()->reorder()->orderBy('sparepart_branch_id')->get();
        $this->assertCount(2, $orderedLines);
        $this->assertSame($scenario['sparepartBranch']->id, $orderedLines->first()->sparepart_branch_id);
        $this->assertSame($sparepartBranchTwo->id, $orderedLines->last()->sparepart_branch_id);

        $lineOne = $workOrder->sparepartLines->firstWhere('sparepart_branch_id', $scenario['sparepartBranch']->id);
        $lineTwo = $workOrder->sparepartLines->firstWhere('sparepart_branch_id', $sparepartBranchTwo->id);
        $this->assertCount(1, $lineOne->reservations);
        $this->assertCount(1, $lineTwo->reservations);
        $this->assertSame(2.0, (float) $lineOne->reservations->first()->qty);
        $this->assertSame(3.0, (float) $lineTwo->reservations->first()->qty);

        $stockOne = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $stockTwo = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchTwo->id)->first();
        $this->assertSame(2.0, (float) $stockOne->reserved_qty);
        $this->assertSame(3.0, (float) $stockTwo->reserved_qty);
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

    public function test_cancel_called_twice_in_sequence_is_rejected_on_the_second_call(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');

        $firstResponse = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");
        $secondResponse = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");

        $firstResponse->assertRedirect(route('work-orders.show', $workOrder));
        // Once cancelled, the WorkOrderPolicy itself rejects a further cancel attempt since the
        // work order is no longer in a cancellable status — proving the second call cannot
        // reach the release logic again and cannot further decrement reserved_qty.
        $secondResponse->assertForbidden();

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $this->assertSame(0.0, (float) $stock->reserved_qty);
        $this->assertSame(WorkOrderStatus::CANCELLED, $workOrder->fresh()->status);
    }

    public function test_cancel_second_call_with_a_stale_in_memory_status_is_a_safe_no_op(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $this->actingAs(User::find($user->id));

        // Simulate two requests that both loaded the WorkOrder (e.g. via route-model binding)
        // while it was still OPEN, before either had actually processed the cancel — this is
        // exactly the race Finding 2 covers: the controller must re-check status from a locked,
        // freshly-read row inside the transaction rather than trusting the in-memory object's
        // (possibly stale) status, or a second in-flight request could double-release the
        // reservation and drive reserved_qty negative.
        $staleOne = WorkOrder::find($workOrder->id);
        $staleTwo = WorkOrder::find($workOrder->id);

        $controller = app(\App\Http\Controllers\WorkOrderController::class);
        $controller->cancel($staleOne);
        $controller->cancel($staleTwo);

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $this->assertSame(0.0, (float) $stock->reserved_qty);
        $this->assertSame(WorkOrderStatus::CANCELLED, $workOrder->fresh()->status);
        $reservation = $workOrder->sparepartLines->first()->reservations()->first();
        $this->assertSame('released', $reservation->status);
    }

    public function test_cancel_releases_reservations_and_decrements_stock_for_two_sparepart_lines(): void
    {
        // Regression test for the cancel() lock-ordering fix: cancel() must lock each line's
        // stock row BEFORE locking/reading that line's reservations (matching confirm()'s
        // stock-then-reservations order) rather than locking all of a line's reservations first
        // via the unindexed reference_type/reference_id query. This test can't observe lock
        // ORDER directly (no real concurrent threads in PHPUnit), but it proves the restructured
        // per-line loop in cancel() still produces correct functional results — every line's
        // reservation released and its own stock row decremented by the right amount — for a
        // work order with 2+ sparepart lines against different SparepartBranch records.
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $sparepartTwo = Sparepart::create(['code' => 'OLI-02-JKT', 'name' => 'Oli Gardan']);
        $sparepartBranchTwo = SparepartBranch::create([
            'sparepart_id' => $sparepartTwo->id, 'branch_id' => $branch->id, 'selling_price' => 70000,
        ]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchTwo->id)->update(['on_hand_qty' => 10]);

        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['spareparts'] = [
            ['sparepart_branch_id' => $sparepartBranchTwo->id, 'qty' => 3, 'unit_price' => 70000],
            ['sparepart_branch_id' => $scenario['sparepartBranch']->id, 'qty' => 2, 'unit_price' => 60000],
        ];

        $workOrder = $this->confirmWorkOrder($branch, $scenario, $payload);
        $this->assertSame(WorkOrderStatus::OPEN, $workOrder->status);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $workOrder->refresh();
        $this->assertSame(WorkOrderStatus::CANCELLED, $workOrder->status);

        $lineOne = $workOrder->sparepartLines->firstWhere('sparepart_branch_id', $scenario['sparepartBranch']->id);
        $lineTwo = $workOrder->sparepartLines->firstWhere('sparepart_branch_id', $sparepartBranchTwo->id);
        $this->assertSame('released', $lineOne->reservations->first()->status);
        $this->assertSame('released', $lineTwo->reservations->first()->status);

        $stockOne = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $stockTwo = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchTwo->id)->first();
        $this->assertSame(0.0, (float) $stockOne->reserved_qty);
        $this->assertSame(0.0, (float) $stockTwo->reserved_qty);
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

    public function test_show_renders_confirm_button_for_a_draft_work_order_with_confirm_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::latest('id')->first();

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee(route('work-orders.confirm', $workOrder), false);
    }

    public function test_show_renders_open_status_and_reserved_qty_after_confirm(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee('Dikonfirmasi');
        $response->assertDontSee(route('work-orders.confirm', $workOrder), false);
        $response->assertDontSee(route('work-orders.edit', $workOrder), false);
    }

    public function test_show_renders_shortage_status_and_override_form_when_not_yet_overridden(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee('Kurang Stok');
        $response->assertSee(route('work-orders.overrideShortage', $workOrder), false);
    }

    public function test_show_hides_override_form_after_shortage_is_overridden(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => 'Ditunda menunggu barang datang.']);

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertDontSee(route('work-orders.overrideShortage', $workOrder), false);
        $response->assertSee('Ditunda menunggu barang datang.');
    }

    public function test_complete_transitions_open_work_order_to_completed(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $this->assertSame(WorkOrderStatus::OPEN, $workOrder->status);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.complete');

        $response = $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $response->assertRedirect("/work-orders/{$workOrder->id}");
        $this->assertSame(WorkOrderStatus::COMPLETED, $workOrder->fresh()->status);
    }

    public function test_complete_transitions_overridden_shortage_work_order_to_completed(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        // on_hand_qty stays at its default 0, so confirm() below produces SHORTAGE.
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->status);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => 'Customer setuju tunggu part.']);

        $response = $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $response->assertRedirect("/work-orders/{$workOrder->id}");
        $this->assertSame(WorkOrderStatus::COMPLETED, $workOrder->fresh()->status);
    }

    public function test_complete_rejected_when_shortage_not_yet_overridden(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->status);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.complete');

        $response = $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $response->assertForbidden();
        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->fresh()->status);
    }

    public function test_complete_requires_pkb_complete_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $response->assertForbidden();
    }

    public function test_show_offers_create_invoice_button_when_completed_without_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        // Grant every permission this user will need before the first authenticated request:
        // hasPermissionToInBranch() memoizes its result per branch on the $user instance, and
        // actingAs() reuses that same instance across every call in this test, so a grant made
        // between two requests would silently miss the cache populated by the earlier one.
        $this->grantBranchPermission($user, $branch, 'pkb.complete');
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'invoice.create');
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee('Buat Invoice');
    }

    public function test_show_links_to_existing_invoice_instead_of_create_button(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        // See the comment in test_show_offers_create_invoice_button_when_completed_without_invoice
        // above: grant both permissions before the first request that uses this $user.
        $this->grantBranchPermission($user, $branch, 'pkb.complete');
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        $invoice = (new InvoiceService())->createFromWorkOrder($workOrder->fresh());

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee($invoice->number);
        $response->assertDontSee('Buat Invoice');
    }

    public function test_show_still_displays_shortage_override_info_after_work_order_is_completed(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        // on_hand_qty stays at its default 0, so confirm() below produces SHORTAGE.
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        // Grant every permission before the first request with this $user (see the caching
        // comment on the two "show offers/links" tests above).
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => 'Customer setuju tunggu part.']);
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee('Customer setuju tunggu part.');
        $response->assertSee($user->name);
    }
}
