<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerVehicleTabTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermissions(array $codes): User
    {
        $user = User::factory()->create();

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $code]
            );
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        return User::find($user->id);
    }

    public function test_show_page_renders_kendaraan_tab_with_customers_vehicles(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $category = VehicleCategory::create(['name' => 'Motor']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);
        Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get("/customers/{$customer->id}");

        $response->assertOk();
        $response->assertSee('B 1234 XYZ');
    }

    public function test_tambah_kendaraan_link_shown_when_authorized(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $user = $this->userWithPermissions(['customer.view', 'vehicle.create']);

        $response = $this->actingAs($user)->get("/customers/{$customer->id}");

        $response->assertOk();
        $response->assertSee(route('vehicles.create', ['customer_id' => $customer->id]), false);
    }

    public function test_tambah_kendaraan_link_hidden_without_vehicle_create_permission(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get("/customers/{$customer->id}");

        $response->assertOk();
        $response->assertDontSee(route('vehicles.create', ['customer_id' => $customer->id]), false);
    }
}
