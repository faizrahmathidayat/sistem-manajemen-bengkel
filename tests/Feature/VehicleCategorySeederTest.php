<?php

namespace Tests\Feature;

use App\Models\VehicleCategory;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_mobil_and_motor(): void
    {
        $this->seed(VehicleCategorySeeder::class);

        $this->assertDatabaseHas('vehicle_categories', ['name' => 'Mobil']);
        $this->assertDatabaseHas('vehicle_categories', ['name' => 'Motor']);
    }

    public function test_running_it_twice_does_not_duplicate(): void
    {
        $this->seed(VehicleCategorySeeder::class);
        $this->seed(VehicleCategorySeeder::class);

        $this->assertSame(2, VehicleCategory::count());
    }
}
