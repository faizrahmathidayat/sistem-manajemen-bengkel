<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Vehicle;

class WorkOrderLookupController extends Controller
{
    public function vehiclesByCustomer(Customer $customer)
    {
        $userBranchIds = auth()->user()->branchesWithPermission('pkb.create')
            ->pluck('id')
            ->merge(auth()->user()->branchesWithPermission('pkb.edit')->pluck('id'))
            ->unique();
        $customerBranchIds = $customer->branches->pluck('id');
        abort_unless($userBranchIds->intersect($customerBranchIds)->isNotEmpty(), 403);

        $vehicles = $customer->vehicles()->where('is_active', true)
            ->with(['brand:id,name', 'type:id,name'])
            ->orderBy('plate_number')
            ->get(['id', 'plate_number', 'frame_number', 'brand_id', 'type_id'])
            ->map(fn (Vehicle $vehicle) => [
                'id' => $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'frame_number' => $vehicle->frame_number,
                'brand_name' => optional($vehicle->brand)->name,
                'type_name' => optional($vehicle->type)->name,
            ]);

        return response()->json($vehicles);
    }
}
