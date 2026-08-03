<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\SparepartBranch;

class WorkOrderLookupController extends Controller
{
    public function customersByBranch(Branch $branch)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('pkb.create', $branch->id), 403);

        return response()->json(
            Customer::whereHas('customerBranches', function ($query) use ($branch) {
                $query->where('branch_id', $branch->id)->where('is_active', true);
            })
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
        );
    }

    public function vehiclesByCustomer(Customer $customer)
    {
        $userBranchIds = auth()->user()->branchesWithPermission('pkb.create')->pluck('id');
        $customerBranchIds = $customer->branches->pluck('id');
        abort_unless($userBranchIds->intersect($customerBranchIds)->isNotEmpty(), 403);

        return response()->json(
            $customer->vehicles()->where('is_active', true)->orderBy('plate_number')->get(['id', 'plate_number', 'frame_number'])
        );
    }

    public function mechanicsByBranch(Branch $branch)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('pkb.create', $branch->id), 403);

        return response()->json(
            Mechanic::whereHas('mechanicBranches', function ($query) use ($branch) {
                $query->where('branch_id', $branch->id)->where('is_active', true);
            })
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
        );
    }

    public function sparepartsByBranch(Branch $branch)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('pkb.create', $branch->id), 403);

        return response()->json(
            SparepartBranch::with(['sparepart', 'stock'])
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->get()
                ->map(function (SparepartBranch $sparepartBranch) {
                    return [
                        'id' => $sparepartBranch->id,
                        'code' => $sparepartBranch->sparepart->code,
                        'name' => $sparepartBranch->sparepart->name,
                        'selling_price' => (float) $sparepartBranch->selling_price,
                        'available_qty' => (float) $sparepartBranch->stock->available_qty,
                    ];
                })
                ->values()
        );
    }
}
