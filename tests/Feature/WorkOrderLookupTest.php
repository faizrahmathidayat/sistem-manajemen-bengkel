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
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderLookupTest extends TestCase
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

    public function test_customers_by_branch_returns_only_customers_servable_in_that_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customerA->id, 'branch_id' => $branchA->id]);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        CustomerBranch::create(['customer_id' => $customerB->id, 'branch_id' => $branchB->id]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->getJson("/work-orders/lookup/customers/{$branchA->id}");

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Budi Santoso']);
        $response->assertJsonMissing(['name' => 'Siti Aminah']);
    }

    public function test_customers_by_branch_is_forbidden_without_pkb_create_in_that_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson("/work-orders/lookup/customers/{$branch->id}");

        $response->assertForbidden();
    }

    public function test_vehicles_by_customer_returns_only_that_customers_active_vehicles(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $otherCustomer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        Vehicle::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ']);
        Vehicle::create(['customer_id' => $otherCustomer->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 9999 ZZZ']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->getJson("/work-orders/lookup/vehicles/{$customer->id}");

        $response->assertOk();
        $response->assertJsonFragment(['plate_number' => 'B 1234 XYZ']);
        $response->assertJsonMissing(['plate_number' => 'B 9999 ZZZ']);
    }

    public function test_vehicles_by_customer_allows_a_user_with_only_pkb_edit_in_a_shared_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        Vehicle::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.edit');

        $response = $this->actingAs(User::find($user->id))->getJson("/work-orders/lookup/vehicles/{$customer->id}");

        $response->assertOk();
        $response->assertJsonFragment(['plate_number' => 'B 1234 XYZ']);
    }

    public function test_vehicles_by_customer_is_forbidden_when_user_has_no_pkb_create_in_any_shared_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson("/work-orders/lookup/vehicles/{$customer->id}");

        $response->assertForbidden();
    }

    public function test_mechanics_by_branch_returns_only_active_mechanics_assigned_to_that_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $mechanicA = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanicA->id, 'branch_id' => $branchA->id]);
        $mechanicB = Mechanic::create(['name' => 'Budi Hartono']);
        MechanicBranch::create(['mechanic_id' => $mechanicB->id, 'branch_id' => $branchB->id]);
        $inactiveMechanic = Mechanic::create(['name' => 'Non Aktif', 'is_active' => false]);
        MechanicBranch::create(['mechanic_id' => $inactiveMechanic->id, 'branch_id' => $branchA->id]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->getJson("/work-orders/lookup/mechanics/{$branchA->id}");

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Agus Setiawan']);
        $response->assertJsonMissing(['name' => 'Budi Hartono']);
        $response->assertJsonMissing(['name' => 'Non Aktif']);
    }

    public function test_spareparts_by_branch_returns_only_active_configs_for_that_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $configA = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchA->id, 'selling_price' => 60000]);
        $inactiveSparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        SparepartBranch::create(['sparepart_id' => $inactiveSparepart->id, 'branch_id' => $branchA->id, 'selling_price' => 100000, 'is_active' => false]);
        $sparepartInB = Sparepart::create(['code' => 'FIL-01', 'name' => 'Filter Udara']);
        SparepartBranch::create(['sparepart_id' => $sparepartInB->id, 'branch_id' => $branchB->id, 'selling_price' => 40000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->getJson("/work-orders/lookup/spareparts/{$branchA->id}");

        $response->assertOk();
        $response->assertJsonFragment(['code' => 'OLI-01', 'id' => $configA->id]);
        $response->assertJsonMissing(['code' => 'BAN-01']);
        $response->assertJsonMissing(['code' => 'FIL-01']);
    }
}
