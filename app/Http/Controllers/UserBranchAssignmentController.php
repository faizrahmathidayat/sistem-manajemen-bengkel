<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserBranch;
use App\Services\UserBranchService;

class UserBranchAssignmentController extends Controller
{
    public function store(User $user, Branch $branch, UserBranchService $service)
    {
        $this->authorize('user_branch.manage');

        $service->assign($user, $branch);

        return response()->json(['message' => 'Cabang berhasil ditambahkan.']);
    }

    public function destroy(User $user, Branch $branch)
    {
        $this->authorize('user_branch.manage');

        UserBranch::where('user_id', $user->id)
            ->where('branch_id', $branch->id)
            ->update(['is_active' => false, 'is_default' => false]);

        return response()->json(['message' => 'Cabang berhasil dihapus dari user.']);
    }

    public function setDefault(User $user, Branch $branch, UserBranchService $service)
    {
        $this->authorize('user_branch.manage');

        if (! $user->hasAccessToBranch($branch->id)) {
            return response()->json(['message' => 'User belum memiliki akses ke cabang ini.'], 422);
        }

        $service->setDefault($user, $branch);

        return response()->json(['message' => 'Cabang default berhasil diubah.']);
    }
}
