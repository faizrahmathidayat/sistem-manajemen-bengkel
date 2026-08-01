<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use Illuminate\Http\Request;

class UserBranchPermissionAssignmentController extends Controller
{
    public function store(Request $request, User $user, Branch $branch, Permission $permission)
    {
        $this->authorize('user_permission.manage');

        UserBranchPermission::firstOrCreate(
            ['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id],
            ['granted_by' => $request->user()->id]
        );

        return response()->json(['message' => 'Permission berhasil diberikan untuk cabang ini.']);
    }

    public function destroy(User $user, Branch $branch, Permission $permission)
    {
        $this->authorize('user_permission.manage');

        UserBranchPermission::where('user_id', $user->id)
            ->where('branch_id', $branch->id)
            ->where('permission_id', $permission->id)
            ->delete();

        return response()->json(['message' => 'Permission berhasil dicabut dari cabang ini.']);
    }
}
