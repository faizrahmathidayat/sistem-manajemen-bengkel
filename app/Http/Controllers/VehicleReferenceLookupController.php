<?php

namespace App\Http\Controllers;

use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;

class VehicleReferenceLookupController extends Controller
{
    public function brandsByCategory(VehicleCategory $category)
    {
        $this->authorize('vehicle.view');

        return response()->json(
            VehicleBrand::where('category_id', $category->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
        );
    }

    public function typesByBrand(VehicleBrand $brand)
    {
        $this->authorize('vehicle.view');

        return response()->json(
            VehicleType::where('brand_id', $brand->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
        );
    }
}
