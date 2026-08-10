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

    public function test_create_page_renders_without_customer_id_query_param(): void
    {
        $user = $this->userWithPermissions(['vehicle.create']);

        $response = $this->actingAs($user)->get('/vehicles/create');

        $response->assertOk();
    }

    public function test_create_page_preselects_customer_from_query_param(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $user = $this->userWithPermissions(['vehicle.create']);

        $response = $this->actingAs($user)->get("/vehicles/create?customer_id={$customer->id}");

        $response->assertOk();
        $response->assertSee("const existingCustomerId = {$customer->id};", false);
    }

    public function test_store_creates_vehicle_with_year(): void
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
            'year' => 2020,
            'is_active' => '1',
        ]);

        $response->assertRedirect('/vehicles');
        $this->assertDatabaseHas('vehicles', ['plate_number' => 'B 1234 XYZ', 'year' => 2020]);
    }

    public function test_store_rejects_year_outside_valid_range(): void
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
            'year' => 1899,
            'is_active' => '1',
        ]);

        $response->assertSessionHasErrors(['year']);
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

    public function test_edit_page_renders_year_input_prefilled(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ', 'year' => 2019,
        ]);
        $user = $this->userWithPermissions(['vehicle.edit']);

        $response = $this->actingAs($user)->get("/vehicles/{$vehicle->id}/edit");

        $response->assertOk();
        $response->assertSee('name="year"', false);
        $response->assertSee('value="2019"', false);
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

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->get('/vehicles?q[]=B%201234');

        $response->assertOk();
        $response->assertSee('B 1234 XYZ');
    }

    public function test_index_shows_empty_state_when_no_vehicles_match(): void
    {
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->get('/vehicles');

        $response->assertOk();
        $response->assertSee('Belum ada kendaraan');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['vehicle.view', 'vehicle.create']);

        $response = $this->actingAs($user)->get('/vehicles');

        $response->assertOk();
        $response->assertSee('Tambah Kendaraan Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->get('/vehicles');

        $response->assertOk();
        $response->assertDontSee('Tambah Kendaraan Pertama');
    }

    public function test_index_renders_filter_bar_with_customer_dropdown(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->get('/vehicles');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari no. polisi/rangka/mesin...');
        $response->assertSee('Budi');
    }

    public function test_index_customer_filter_still_scopes_results_after_retrofit(): void
    {
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti', 'stnk_name' => 'Siti']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        Vehicle::create([
            'customer_id' => $customerA->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1111 AAA',
        ]);
        Vehicle::create([
            'customer_id' => $customerB->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 2222 BBB',
        ]);
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->get("/vehicles?customer_id={$customerA->id}");

        $response->assertOk();
        $response->assertSee('B 1111 AAA');
        $response->assertDontSee('B 2222 BBB');
    }

    public function test_create_page_loads_select2_for_customer_picker(): void
    {
        $user = $this->userWithPermissions(['vehicle.create']);

        $response = $this->actingAs($user)->get('/vehicles/create');

        $response->assertOk();
        $response->assertSee('select2-ajax-picker.js', false);
        $response->assertSee('id="customer_id"', false);
    }

    public function test_edit_page_preselects_existing_customer(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 ABC',
        ]);
        $user = $this->userWithPermissions(['vehicle.edit']);

        $response = $this->actingAs($user)->get("/vehicles/{$vehicle->id}/edit");

        $response->assertOk();
        $response->assertSee('select2-ajax-picker.js', false);
        $response->assertSee("const existingCustomerId = {$vehicle->customer_id};", false);
    }
}
