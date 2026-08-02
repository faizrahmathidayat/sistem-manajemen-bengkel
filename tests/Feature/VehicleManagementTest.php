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

class VehicleManagementTest extends TestCase
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

    protected function makeHierarchy(): array
    {
        $category = VehicleCategory::firstOrCreate(['name' => 'Motor']);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Honda']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Beat']);

        return compact('category', 'brand', 'type');
    }

    public function test_index_lists_vehicles_for_authorized_user(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->get('/vehicles');

        $response->assertOk();
        $response->assertSee('B 1234 XYZ');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/vehicles');

        $response->assertForbidden();
    }

    public function test_store_creates_vehicle(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        $user = $this->userWithPermissions(['vehicle.create']);

        $response = $this->actingAs($user)->post('/vehicles', [
            'customer_id' => $customer->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'type_id' => $type->id,
            'plate_number' => 'B 1234 XYZ',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/vehicles');
        $this->assertDatabaseHas('vehicles', ['plate_number' => 'B 1234 XYZ', 'customer_id' => $customer->id]);
    }

    public function test_store_rejects_brand_that_does_not_belong_to_selected_category(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        $otherCategory = VehicleCategory::create(['name' => 'Mobil']);
        $user = $this->userWithPermissions(['vehicle.create']);

        $response = $this->actingAs($user)->post('/vehicles', [
            'customer_id' => $customer->id,
            'category_id' => $otherCategory->id,
            'brand_id' => $brand->id,
            'type_id' => $type->id,
        ]);

        $response->assertSessionHasErrors(['brand_id']);
    }

    public function test_store_rejects_type_that_does_not_belong_to_selected_brand(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand] = $this->makeHierarchy();
        $otherBrand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Yamaha']);
        $otherType = VehicleType::create(['brand_id' => $otherBrand->id, 'name' => 'Mio']);
        $user = $this->userWithPermissions(['vehicle.create']);

        $response = $this->actingAs($user)->post('/vehicles', [
            'customer_id' => $customer->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'type_id' => $otherType->id,
        ]);

        $response->assertSessionHasErrors(['type_id']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/vehicles', [
            'customer_id' => $customer->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'type_id' => $type->id,
        ]);

        $response->assertForbidden();
    }

    public function test_update_edits_vehicle_and_can_deactivate(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $user = $this->userWithPermissions(['vehicle.edit']);

        $response = $this->actingAs($user)->put("/vehicles/{$vehicle->id}", [
            'customer_id' => $customer->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'type_id' => $type->id,
            'plate_number' => 'B 1234 XYZ',
            'is_active' => '0',
        ]);

        $response->assertRedirect('/vehicles');
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'is_active' => false]);
    }

    public function test_lookup_returns_brands_scoped_to_category(): void
    {
        ['category' => $category] = $this->makeHierarchy();
        $otherCategory = VehicleCategory::create(['name' => 'Mobil']);
        VehicleBrand::create(['category_id' => $otherCategory->id, 'name' => 'Toyota']);
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->getJson("/vehicles/lookup/brands/{$category->id}");

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Honda']);
        $response->assertJsonMissing(['name' => 'Toyota']);
    }

    public function test_lookup_returns_types_scoped_to_brand(): void
    {
        ['brand' => $brand] = $this->makeHierarchy();
        $otherBrand = VehicleBrand::create(['category_id' => $brand->category_id, 'name' => 'Yamaha']);
        VehicleType::create(['brand_id' => $otherBrand->id, 'name' => 'Mio']);
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->getJson("/vehicles/lookup/types/{$brand->id}");

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Beat']);
        $response->assertJsonMissing(['name' => 'Mio']);
    }
}
