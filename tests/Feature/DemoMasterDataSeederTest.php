<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\ServiceCatalog;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleType;
use Database\Seeders\DemoMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoMasterDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_three_customers_across_different_branches(): void
    {
        $this->seed(DemoMasterDataSeeder::class);

        $this->assertSame(3, Customer::count());

        $ahmad = Customer::where('name', 'Ahmad Fauzi')->firstOrFail();
        $dewi = Customer::where('name', 'Dewi Anggraini')->firstOrFail();
        $sinarMotor = Customer::where('name', 'CV Sinar Motor')->firstOrFail();

        $this->assertSame('INDIVIDUAL', $ahmad->customer_type);
        $this->assertSame('COMPANY', $sinarMotor->customer_type);

        $this->assertDatabaseHas('customer_branches', ['customer_id' => $ahmad->id, 'branch_id' => \App\Models\Branch::where('code', 'BENGKEL1')->value('id')]);
        $this->assertDatabaseHas('customer_branches', ['customer_id' => $dewi->id, 'branch_id' => \App\Models\Branch::where('code', 'BENGKEL2')->value('id')]);
        $this->assertDatabaseHas('customer_branches', ['customer_id' => $sinarMotor->id, 'branch_id' => \App\Models\Branch::where('code', 'BENGKEL3')->value('id')]);
    }

    public function test_it_seeds_five_vehicles_distributed_two_two_one(): void
    {
        $this->seed(DemoMasterDataSeeder::class);

        $this->assertSame(5, Vehicle::count());

        $ahmad = Customer::where('name', 'Ahmad Fauzi')->firstOrFail();
        $dewi = Customer::where('name', 'Dewi Anggraini')->firstOrFail();
        $sinarMotor = Customer::where('name', 'CV Sinar Motor')->firstOrFail();

        $this->assertSame(2, Vehicle::where('customer_id', $ahmad->id)->count());
        $this->assertSame(2, Vehicle::where('customer_id', $dewi->id)->count());
        $this->assertSame(1, Vehicle::where('customer_id', $sinarMotor->id)->count());
    }

    public function test_it_seeds_the_honda_yamaha_brand_and_type_reference_data(): void
    {
        $this->seed(DemoMasterDataSeeder::class);

        $this->assertDatabaseHas('vehicle_brands', ['name' => 'Honda']);
        $this->assertDatabaseHas('vehicle_brands', ['name' => 'Yamaha']);

        $honda = VehicleBrand::where('name', 'Honda')->firstOrFail();
        $yamaha = VehicleBrand::where('name', 'Yamaha')->firstOrFail();

        $this->assertDatabaseHas('vehicle_types', ['brand_id' => $honda->id, 'name' => 'Beat']);
        $this->assertDatabaseHas('vehicle_types', ['brand_id' => $honda->id, 'name' => 'Vario']);
        $this->assertDatabaseHas('vehicle_types', ['brand_id' => $yamaha->id, 'name' => 'NMAX']);
        $this->assertDatabaseHas('vehicle_types', ['brand_id' => $yamaha->id, 'name' => 'Jupiter Z']);
    }

    public function test_it_seeds_eight_mechanics_distributed_three_three_two(): void
    {
        $this->seed(DemoMasterDataSeeder::class);

        $this->assertSame(8, Mechanic::count());

        $branchIds = \App\Models\Branch::whereIn('code', ['BENGKEL1', 'BENGKEL2', 'BENGKEL3'])->pluck('id', 'code');

        $this->assertSame(3, MechanicBranch::where('branch_id', $branchIds['BENGKEL1'])->count());
        $this->assertSame(3, MechanicBranch::where('branch_id', $branchIds['BENGKEL2'])->count());
        $this->assertSame(2, MechanicBranch::where('branch_id', $branchIds['BENGKEL3'])->count());
    }

    public function test_it_seeds_ten_service_catalogs_with_correct_prices(): void
    {
        $this->seed(DemoMasterDataSeeder::class);

        $this->assertSame(10, ServiceCatalog::count());
        $this->assertDatabaseHas('service_catalogs', ['code' => 'SVC-TUN01', 'default_price' => 70000]);
        $this->assertDatabaseHas('service_catalogs', ['code' => 'SVC-OVR02', 'default_price' => 1250000]);
    }

    public function test_running_it_twice_does_not_duplicate(): void
    {
        $this->seed(DemoMasterDataSeeder::class);
        $this->seed(DemoMasterDataSeeder::class);

        $this->assertSame(3, Customer::count());
        $this->assertSame(5, Vehicle::count());
        $this->assertSame(8, Mechanic::count());
        $this->assertSame(10, ServiceCatalog::count());
    }
}
