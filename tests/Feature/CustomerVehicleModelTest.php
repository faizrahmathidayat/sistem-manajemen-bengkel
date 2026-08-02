<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerVehicleModelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeVehicle(Customer $customer, array $overrides = []): Vehicle
    {
        $category = VehicleCategory::firstOrCreate(['name' => 'Motor']);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Honda']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Beat']);

        return Vehicle::create(array_merge([
            'customer_id' => $customer->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'type_id' => $type->id,
        ], $overrides));
    }

    public function test_customer_can_be_created_with_fillable_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $customer = Customer::create([
            'customer_type' => 'INDIVIDUAL',
            'name' => 'Budi Santoso',
            'stnk_name' => 'Budi Santoso',
            'phone' => '081234567890',
        ]);

        $this->assertSame('Budi Santoso', $customer->name);
        $this->assertTrue($customer->is_active);
        $this->assertSame($user->id, $customer->created_by);
    }

    public function test_customer_branches_rejects_duplicate_pair(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);

        $this->expectException(QueryException::class);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
    }

    public function test_vehicle_brand_name_is_unique_per_category_but_reusable_across_categories(): void
    {
        $mobil = VehicleCategory::create(['name' => 'Mobil']);
        $motor = VehicleCategory::create(['name' => 'Motor']);

        VehicleBrand::create(['category_id' => $mobil->id, 'name' => 'Honda']);
        VehicleBrand::create(['category_id' => $motor->id, 'name' => 'Honda']);

        $this->assertSame(2, VehicleBrand::where('name', 'Honda')->count());

        $this->expectException(QueryException::class);
        VehicleBrand::create(['category_id' => $mobil->id, 'name' => 'Honda']);
    }

    public function test_vehicle_type_name_is_unique_per_brand(): void
    {
        $category = VehicleCategory::create(['name' => 'Motor']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);

        $this->expectException(QueryException::class);
        VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);
    }

    public function test_two_vehicles_can_both_have_no_plate_number(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);

        $this->makeVehicle($customer, ['plate_number' => null]);
        $this->makeVehicle($customer, ['plate_number' => null]);

        $this->assertSame(2, Vehicle::whereNull('plate_number')->count());
    }

    public function test_duplicate_plate_number_is_rejected(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $this->makeVehicle($customer, ['plate_number' => 'B 1234 XYZ']);

        $this->expectException(QueryException::class);
        $this->makeVehicle($customer, ['plate_number' => 'B 1234 XYZ']);
    }

    public function test_deleting_customer_cascades_to_customer_branches_and_vehicles(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $vehicle = $this->makeVehicle($customer);

        $customer->delete();

        $this->assertDatabaseMissing('customer_branches', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
    }
}
