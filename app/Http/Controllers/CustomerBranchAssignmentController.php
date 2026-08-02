<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;

class CustomerBranchAssignmentController extends Controller
{
    public function store(Customer $customer, Branch $branch)
    {
        $this->authorize('customer.edit');

        CustomerBranch::updateOrCreate(
            ['customer_id' => $customer->id, 'branch_id' => $branch->id],
            ['is_active' => true]
        );

        return response()->json(['message' => 'Cabang berhasil ditambahkan.']);
    }

    public function destroy(Customer $customer, Branch $branch)
    {
        $this->authorize('customer.edit');

        CustomerBranch::where('customer_id', $customer->id)
            ->where('branch_id', $branch->id)
            ->update(['is_active' => false]);

        return response()->json(['message' => 'Cabang berhasil dihapus dari customer.']);
    }
}
