<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LookupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermission(string $code): User
    {
        $user = User::factory()->create();
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        return User::find($user->id);
    }

    protected function userWithBranchPermission(string $code, Branch $branch): User
    {
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        return User::find($user->id);
    }

    // --- customers() ---

    public function test_customers_returns_matches_for_a_three_character_query(): void
    {
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        $user = $this->userWithPermission('customer.view');

        $response = $this->actingAs($user)->getJson('/lookup/customers?q=Bud');

        $response->assertOk();
        $response->assertJsonFragment(['text' => 'Budi Santoso']);
        $response->assertJsonMissing(['text' => 'Siti Aminah']);
    }

    public function test_customers_returns_empty_for_less_than_three_characters(): void
    {
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $user = $this->userWithPermission('customer.view');

        $response = $this->actingAs($user)->getJson('/lookup/customers?q=Bu');

        $response->assertOk();
        $response->assertExactJson([]);
    }

    public function test_customers_is_forbidden_without_customer_view_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/lookup/customers?q=Bud')->assertForbidden();
    }

    public function test_customers_filters_by_branch_id_when_given(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $inBranchA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $inBranchB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Wijaya', 'stnk_name' => 'Budi Wijaya']);
        CustomerBranch::create(['customer_id' => $inBranchA->id, 'branch_id' => $branchA->id]);
        CustomerBranch::create(['customer_id' => $inBranchB->id, 'branch_id' => $branchB->id]);
        $user = $this->userWithPermission('customer.view');

        $response = $this->actingAs($user)->getJson("/lookup/customers?q=Bud&branch_id={$branchA->id}");

        $response->assertOk();
        $response->assertJsonFragment(['text' => 'Budi Santoso']);
        $response->assertJsonMissing(['text' => 'Budi Wijaya']);
    }

    public function test_customers_ignores_branch_id_when_absent(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $inBranchA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $unassigned = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Wijaya', 'stnk_name' => 'Budi Wijaya']);
        CustomerBranch::create(['customer_id' => $inBranchA->id, 'branch_id' => $branchA->id]);
        $user = $this->userWithPermission('customer.view');

        $response = $this->actingAs($user)->getJson('/lookup/customers?q=Bud');

        $response->assertOk();
        $response->assertJsonFragment(['text' => 'Budi Santoso']);
        $response->assertJsonFragment(['text' => 'Budi Wijaya']);
    }

    public function test_customers_ids_mode_resolves_specific_records_regardless_of_length(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Al', 'stnk_name' => 'Al']);
        $user = $this->userWithPermission('customer.view');

        $response = $this->actingAs($user)->getJson("/lookup/customers?ids[]={$customer->id}");

        $response->assertOk();
        $response->assertJsonFragment(['id' => $customer->id, 'text' => 'Al']);
    }

    public function test_customers_ids_mode_resolves_an_inactive_customer(): void
    {
        // Regression coverage: the PKB edit page's Select2 preselect relies on ids[] mode to
        // resolve the currently-assigned customer even after that customer has since been
        // deactivated (WorkOrderController::edit() no longer server-side backfills a
        // full customer list — see Task 2's work). If ids[] mode filtered out inactive
        // records, the edit page's Customer picker would render blank and the (still
        // required) field would block saving any further edit to that Work Order.
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso', 'is_active' => false]);
        $user = $this->userWithPermission('customer.view');

        $response = $this->actingAs($user)->getJson("/lookup/customers?ids[]={$customer->id}");

        $response->assertOk();
        $response->assertJsonFragment(['id' => $customer->id, 'text' => 'Budi Santoso']);
    }

    public function test_customers_q_search_excludes_inactive_customers(): void
    {
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Nonaktif', 'stnk_name' => 'Budi Nonaktif', 'is_active' => false]);
        $user = $this->userWithPermission('customer.view');

        $response = $this->actingAs($user)->getJson('/lookup/customers?q=Bud');

        $response->assertOk();
        $response->assertJsonMissing(['text' => 'Budi Nonaktif']);
    }

    // --- mechanics() ---

    public function test_mechanics_returns_matches_and_is_forbidden_without_permission(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermission('mechanic.view');

        $this->actingAs($user)->getJson('/lookup/mechanics?q=Agu')->assertOk()
            ->assertJsonFragment(['text' => 'Agus Setiawan']);

        $noPermUser = User::factory()->create();
        $this->actingAs($noPermUser)->getJson('/lookup/mechanics?q=Agu')->assertForbidden();
    }

    public function test_mechanics_filters_by_branch_id_when_given(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $inBranchA = Mechanic::create(['name' => 'Agus Setiawan']);
        $inBranchB = Mechanic::create(['name' => 'Agus Wibowo']);
        MechanicBranch::create(['mechanic_id' => $inBranchA->id, 'branch_id' => $branchA->id]);
        MechanicBranch::create(['mechanic_id' => $inBranchB->id, 'branch_id' => $branchB->id]);
        $user = $this->userWithPermission('mechanic.view');

        $response = $this->actingAs($user)->getJson("/lookup/mechanics?q=Agu&branch_id={$branchA->id}");

        $response->assertOk();
        $response->assertJsonFragment(['text' => 'Agus Setiawan']);
        $response->assertJsonMissing(['text' => 'Agus Wibowo']);
    }

    public function test_mechanics_ids_mode_resolves_an_inactive_mechanic(): void
    {
        // Same regression coverage as test_customers_ids_mode_resolves_an_inactive_customer,
        // for the Mekanik picker on the PKB edit page.
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'is_active' => false]);
        $user = $this->userWithPermission('mechanic.view');

        $response = $this->actingAs($user)->getJson("/lookup/mechanics?ids[]={$mechanic->id}");

        $response->assertOk();
        $response->assertJsonFragment(['id' => $mechanic->id, 'text' => 'Agus Setiawan']);
    }

    public function test_mechanics_q_search_excludes_inactive_mechanics(): void
    {
        Mechanic::create(['name' => 'Agus Nonaktif', 'is_active' => false]);
        $user = $this->userWithPermission('mechanic.view');

        $response = $this->actingAs($user)->getJson('/lookup/mechanics?q=Agu');

        $response->assertOk();
        $response->assertJsonMissing(['text' => 'Agus Nonaktif']);
    }

    // --- spareparts() ---

    public function test_spareparts_requires_branch_id(): void
    {
        $user = $this->userWithPermission('mechanic.view');

        $this->actingAs($user)->getJson('/lookup/spareparts?q=Oli')->assertStatus(400);
    }

    public function test_spareparts_returns_matches_scoped_to_branch_with_full_shape(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'SP-OLI-001', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create([
            'sparepart_id' => $sparepart->id, 'branch_id' => $branch->id,
            'selling_price' => 45000, 'minimum_stock' => 10,
        ]);
        $user = $this->userWithBranchPermission('sparepart.view', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/spareparts?q=Oli&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => $sparepartBranch->id,
            'sparepart_id' => $sparepart->id,
            'text' => 'SP-OLI-001 — Oli Mesin',
            'code' => 'SP-OLI-001',
            'selling_price' => 45000.0,
            'available_qty' => 0.0,
            'on_hand_qty' => 0.0,
        ]);
    }

    public function test_spareparts_is_forbidden_without_sparepart_view_in_that_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/lookup/spareparts?q=Oli&branch_id={$branch->id}")->assertForbidden();
    }

    public function test_spareparts_ids_mode_resolves_multiple_records(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartA = Sparepart::create(['code' => 'SP-A', 'name' => 'Sparepart A']);
        $sparepartB = Sparepart::create(['code' => 'SP-B', 'name' => 'Sparepart B']);
        $sbA = SparepartBranch::create(['sparepart_id' => $sparepartA->id, 'branch_id' => $branch->id, 'selling_price' => 10000]);
        $sbB = SparepartBranch::create(['sparepart_id' => $sparepartB->id, 'branch_id' => $branch->id, 'selling_price' => 20000]);
        $user = $this->userWithBranchPermission('sparepart.view', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/spareparts?ids[]={$sbA->id}&ids[]={$sbB->id}&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['id' => $sbA->id, 'code' => 'SP-A']);
        $response->assertJsonFragment(['id' => $sbB->id, 'code' => 'SP-B']);
    }

    public function test_spareparts_ids_mode_silently_excludes_ids_outside_branch_scope(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = Sparepart::create(['code' => 'SP-A', 'name' => 'Sparepart A']);
        $sbInBranchB = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchB->id, 'selling_price' => 10000]);
        $user = $this->userWithBranchPermission('sparepart.view', $branchA);

        $response = $this->actingAs($user)->getJson("/lookup/spareparts?ids[]={$sbInBranchB->id}&branch_id={$branchA->id}");

        $response->assertOk();
        $response->assertExactJson([]);
    }

    public function test_spareparts_ids_mode_resolves_an_inactive_sparepart_branch_config(): void
    {
        // Same regression coverage as test_customers_ids_mode_resolves_an_inactive_customer,
        // for the per-row Sparepart picker on the PKB edit page.
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'SP-OLI-002', 'name' => 'Oli Gardan']);
        $sparepartBranch = SparepartBranch::create([
            'sparepart_id' => $sparepart->id, 'branch_id' => $branch->id,
            'selling_price' => 45000, 'is_active' => false,
        ]);
        $user = $this->userWithBranchPermission('sparepart.view', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/spareparts?ids[]={$sparepartBranch->id}&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonFragment(['id' => $sparepartBranch->id, 'code' => 'SP-OLI-002']);
    }

    public function test_spareparts_ids_mode_matches_by_bare_sparepart_id_when_id_field_param_requests_it(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        // spareparts.id and sparepart_branches.id are independent auto-increment
        // counters, but both tend to start at 1 in an isolated test, which would let
        // whereIn('id', $ids) alone satisfy this assertion by coincidence. Create two
        // throwaway spareparts (no branch config) first so the target sparepart's id
        // is guaranteed to differ from the target sparepartBranch's id below, proving
        // the match happens via the sparepart_id column, not the id column.
        Sparepart::create(['code' => 'SP-DECOY-1', 'name' => 'Decoy 1']);
        Sparepart::create(['code' => 'SP-DECOY-2', 'name' => 'Decoy 2']);

        $sparepart = Sparepart::create(['code' => 'SP-A', 'name' => 'Sparepart A']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 10000]);
        $this->assertNotSame($sparepart->id, $sparepartBranch->id, 'test setup invariant: ids must differ to validate the fix, not the id column alone');
        $user = $this->userWithBranchPermission('sparepart.view', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/spareparts?ids[]={$sparepart->id}&branch_id={$branch->id}&id_field=sparepart_id");

        $response->assertOk();
        $response->assertJsonFragment(['id' => $sparepartBranch->id, 'sparepart_id' => $sparepart->id]);
    }

    public function test_spareparts_ids_mode_does_not_match_by_sparepart_id_without_id_field_param(): void
    {
        // Regression guard: a caller resolving by sparepart_branch_id (PKB, Goods Receipt,
        // Stock Adjustment) must NOT also match an unrelated sparepart whose global id
        // happens to numerically equal the requested sparepart_branch_id — this exact
        // collision was caught live during Task 8 verification (a Stock Adjustment line's
        // sparepart_branch_id coincided with an unrelated sparepart's id, and the edit page
        // preselected the wrong sparepart because preselectAjaxOption took items[0] from a
        // response containing both the correct and the spurious match). Requesting by a bare
        // sparepart_id without id_field=sparepart_id must return nothing, proving the default
        // mode only matches the id column.
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'SP-A', 'name' => 'Sparepart A']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 10000]);
        $this->assertNotSame($sparepart->id, $sparepartBranch->id, 'test setup invariant: ids must differ for this assertion to be meaningful');
        $user = $this->userWithBranchPermission('sparepart.view', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/spareparts?ids[]={$sparepart->id}&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertExactJson([]);
    }

    public function test_spareparts_q_search_excludes_inactive_sparepart_branch_configs(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'SP-OLI-003', 'name' => 'Oli Nonaktif']);
        SparepartBranch::create([
            'sparepart_id' => $sparepart->id, 'branch_id' => $branch->id,
            'selling_price' => 45000, 'is_active' => false,
        ]);
        $user = $this->userWithBranchPermission('sparepart.view', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/spareparts?q=Oli&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonMissing(['code' => 'SP-OLI-003']);
    }

    // --- vehicles() ---

    protected function makeVehicleScenario(Branch $branch, array $vehicleOverrides = [], array $customerOverrides = []): array
    {
        $customer = Customer::create(array_merge([
            'customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso',
        ], $customerOverrides));
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => 'Motor ' . $branch->code]);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);
        $vehicle = Vehicle::create(array_merge([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ', 'year' => 2020,
        ], $vehicleOverrides));

        return compact('customer', 'vehicle');
    }

    public function test_vehicles_requires_branch_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/lookup/vehicles?q=234')->assertStatus(400);
    }

    public function test_vehicles_is_forbidden_without_pkb_create_or_edit_in_that_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/lookup/vehicles?q=234&branch_id={$branch->id}")->assertForbidden();
    }

    public function test_vehicles_allows_a_user_with_only_pkb_edit(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeVehicleScenario($branch);
        $user = $this->userWithBranchPermission('pkb.edit', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/vehicles?q=234&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonFragment(['plate_number' => 'B 1234 XYZ']);
    }

    public function test_vehicles_returns_matches_scoped_to_branch_with_full_shape(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeVehicleScenario($branch);
        $user = $this->userWithBranchPermission('pkb.create', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/vehicles?q=234&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => $scenario['vehicle']->id,
            'text' => 'Honda Beat 2020 - B 1234 XYZ (Budi Santoso)',
            'plate_number' => 'B 1234 XYZ',
            'brand_name' => 'Honda',
            'type_name' => 'Beat',
            'year' => 2020,
            'customer_id' => $scenario['customer']->id,
            'customer_name' => 'Budi Santoso',
        ]);
    }

    public function test_vehicles_returns_empty_for_less_than_three_characters(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeVehicleScenario($branch);
        $user = $this->userWithBranchPermission('pkb.create', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/vehicles?q=23&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertExactJson([]);
    }

    public function test_vehicles_excludes_vehicles_outside_branch_scope(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->makeVehicleScenario($branchA, ['plate_number' => 'B 1111 AAA']);
        $this->makeVehicleScenario($branchB, ['plate_number' => 'B 1111 BBB'], ['name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        $user = $this->userWithBranchPermission('pkb.create', $branchA);

        $response = $this->actingAs($user)->getJson("/lookup/vehicles?q=111&branch_id={$branchA->id}");

        $response->assertOk();
        $response->assertJsonFragment(['plate_number' => 'B 1111 AAA']);
        $response->assertJsonMissing(['plate_number' => 'B 1111 BBB']);
    }

    public function test_vehicles_excludes_inactive_vehicles(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeVehicleScenario($branch, ['plate_number' => 'B 2222 NON', 'is_active' => false]);
        $user = $this->userWithBranchPermission('pkb.create', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/vehicles?q=222&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertExactJson([]);
    }

    public function test_vehicles_ids_mode_resolves_a_specific_vehicle_regardless_of_active_customer_status(): void
    {
        // Regression coverage: the PKB edit page's vehicle picker preselects via ids[] mode, and
        // must keep resolving the linked vehicle/customer even after the customer has since been
        // deactivated — mirrors LookupControllerTest::test_customers_ids_mode_resolves_an_inactive_customer.
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeVehicleScenario($branch, ['plate_number' => 'B 3333 XYZ'], ['is_active' => false]);
        $user = $this->userWithBranchPermission('pkb.edit', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/vehicles?ids[]={$scenario['vehicle']->id}&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonFragment(['id' => $scenario['vehicle']->id, 'plate_number' => 'B 3333 XYZ', 'customer_name' => 'Budi Santoso']);
    }
}
