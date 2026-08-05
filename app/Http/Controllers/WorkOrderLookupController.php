<?php

namespace App\Http\Controllers;

use App\Models\Customer;

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

        return response()->json(
            $customer->vehicles()->where('is_active', true)->orderBy('plate_number')->get(['id', 'plate_number', 'frame_number'])
        );
    }
}
