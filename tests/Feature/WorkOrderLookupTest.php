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
}
