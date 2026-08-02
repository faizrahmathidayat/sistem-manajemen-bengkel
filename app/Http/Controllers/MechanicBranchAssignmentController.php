<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;

class MechanicBranchAssignmentController extends Controller
{
    public function store(Mechanic $mechanic, Branch $branch)
    {
        $this->authorize('mechanic.edit');

        MechanicBranch::updateOrCreate(
            ['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id],
            ['is_active' => true]
        );

        return response()->json(['message' => 'Cabang berhasil ditambahkan.']);
    }

    public function destroy(Mechanic $mechanic, Branch $branch)
    {
        $this->authorize('mechanic.edit');

        MechanicBranch::where('mechanic_id', $mechanic->id)
            ->where('branch_id', $branch->id)
            ->update(['is_active' => false]);

        return response()->json(['message' => 'Cabang berhasil dihapus dari mekanik.']);
    }
}
