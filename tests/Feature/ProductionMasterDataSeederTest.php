<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Rack;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleType;
use Database\Seeders\ProductionMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionMasterDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function seedWithBranch(): Branch
    {
        $branch = Branch::create(['code' => 'CABANGUTAMA', 'name' => 'CABANGUTAMA', 'is_active' => true]);
        $this->seed(ProductionMasterDataSeeder::class);

        return $branch;
    }

    public function test_seeds_a_single_rack(): void
    {
        $this->seedWithBranch();

        $this->assertSame(1, Rack::count());
        $this->assertDatabaseHas('racks', ['code' => '001', 'is_active' => true]);
    }

    public function test_seeds_vehicle_reference_brands_and_types(): void
    {
        $this->seedWithBranch();

        $this->assertSame(3, VehicleBrand::count());
        $this->assertSame(11, VehicleType::count());
        $this->assertDatabaseHas('vehicle_brands', ['name' => 'HONDA']);
        $this->assertDatabaseHas('vehicle_brands', ['name' => 'YAMAHA']);
        $this->assertDatabaseHas('vehicle_brands', ['name' => 'SUZUKI']);
    }

    public function test_seeds_75_spareparts_with_duplicate_codes_disambiguated(): void
    {
        $branch = $this->seedWithBranch();
        $rack = Rack::where('code', '001')->firstOrFail();

        $this->assertSame(75, Sparepart::count());
        $this->assertSame(75, SparepartBranch::where('branch_id', $branch->id)->count());

        $ircSeventy1 = Sparepart::where('code', 'IRC 70/9-1')->firstOrFail();
        $ircSeventy2 = Sparepart::where('code', 'IRC 70/9-2')->firstOrFail();
        $ircSeventy3 = Sparepart::where('code', 'IRC 70/9-3')->firstOrFail();

        $this->assertNotSame($ircSeventy1->id, $ircSeventy2->id);
        $this->assertNotSame($ircSeventy2->id, $ircSeventy3->id);

        $price1 = SparepartBranch::where('sparepart_id', $ircSeventy1->id)->where('branch_id', $branch->id)->value('selling_price');
        $price3 = SparepartBranch::where('sparepart_id', $ircSeventy3->id)->where('branch_id', $branch->id)->value('selling_price');
        $this->assertEquals(228000, $price1);
        $this->assertEquals(262000, $price3);

        $this->assertSame(4, Sparepart::where('code', 'like', 'IRC 80/9-%')->count());

        $sample = SparepartBranch::where('sparepart_id', $ircSeventy1->id)->where('branch_id', $branch->id)->firstOrFail();
        $this->assertSame($rack->id, $sample->rack_id);
    }

    public function test_seeds_10_service_catalogs(): void
    {
        $this->seedWithBranch();

        $this->assertSame(10, ServiceCatalog::count());
        $this->assertDatabaseHas('service_catalogs', ['code' => 'JSA-001', 'name' => 'Servis Berkala / Ringan', 'default_price' => 45000]);
    }

    public function test_seeds_31_customers_and_vehicles_linked_to_cabangutama(): void
    {
        $branch = $this->seedWithBranch();

        $this->assertSame(31, Customer::count());
        $this->assertSame(31, Vehicle::count());
        $this->assertSame(31, CustomerBranch::where('branch_id', $branch->id)->count());
    }

    public function test_customer_name_and_stnk_name_are_mapped_correctly(): void
    {
        $this->seedWithBranch();

        $customer = Customer::where('phone', '0895 3862 22954')->firstOrFail();

        $this->assertSame('ARTIK MARDIANI', $customer->name);
        $this->assertSame('YUNIZAR', $customer->stnk_name);
        $this->assertSame('INDIVIDUAL', $customer->customer_type);
    }

    public function test_duplicate_engine_and_frame_numbers_are_nulled_on_the_second_occurrence(): void
    {
        $this->seedWithBranch();

        $kept = Vehicle::where('plate_number', 'B 6431 JJH')->firstOrFail();
        $nulled = Vehicle::where('plate_number', 'F 6862 FJN')->firstOrFail();

        $this->assertSame('KF41E2007743', $kept->engine_number);
        $this->assertSame('MH1KF41264K007743', $kept->frame_number);
        $this->assertNull($nulled->engine_number);
        $this->assertNull($nulled->frame_number);
    }

    public function test_seeder_is_idempotent_when_run_twice(): void
    {
        Branch::create(['code' => 'CABANGUTAMA', 'name' => 'CABANGUTAMA', 'is_active' => true]);
        $this->seed(ProductionMasterDataSeeder::class);
        $this->seed(ProductionMasterDataSeeder::class);

        $this->assertSame(75, Sparepart::count());
        $this->assertSame(10, ServiceCatalog::count());
        $this->assertSame(31, Customer::count());
        $this->assertSame(31, Vehicle::count());
        $this->assertSame(1, Rack::count());
    }
}
