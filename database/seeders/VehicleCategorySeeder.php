<?php

namespace Database\Seeders;

use App\Models\VehicleCategory;
use Illuminate\Database\Seeder;

class VehicleCategorySeeder extends Seeder
{
    public function run()
    {
        foreach (['Mobil', 'Motor'] as $name) {
            VehicleCategory::firstOrCreate(['name' => $name]);
        }
    }
}
