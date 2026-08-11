<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\AuditLogger;
use App\Support\AuditEvent;
use Illuminate\Http\Request;

class UserBranchPermissionAssignmentController extends Controller
{
    public function store(Request $request, User $user, Branch $branch, Permission $permission)
    {
        $this->authorize('user_permission.manage');
        $this->authorize('update', $user);

        if (! optional($permission->menu)->is_branch_scoped) {
            return response()->json(['message' => 'Permission ini bersifat global, tidak bisa diberikan per cabang.'], 422);
        }

        if (! $user->hasAccessToBranch($branch->id)) {
            return response()->json(['message' => 'User tidak terdaftar di cabang ini.'], 422);
        }

        UserBranchPermission::firstOrCreate(
            ['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id],
            ['granted_by' => $request->user()->id]
        );

        (new AuditLogger())->log(
            AuditEvent::USER_BRANCH_PERMISSION_GRANTED,
            $branch->id,
            $user,
            [],
            ['permission' => $permission->code, 'branch' => $branch->code]
        );

        return response()->json(['message' => 'Permission berhasil diberikan untuk cabang ini.']);
    }

    public function destroy(User $user, Branch $branch, Permission $permission)
    {
        $this->authorize('user_permission.manage');
        $this->authorize('update', $user);

        UserBranchPermission::where('user_id', $user->id)
            ->where('branch_id', $branch->id)
            ->where('permission_id', $permission->id)
            ->delete();

        (new AuditLogger())->log(
            AuditEvent::USER_BRANCH_PERMISSION_REVOKED,
            $branch->id,
            $user,
            ['permission' => $permission->code, 'branch' => $branch->code],
            []
        );

        return response()->json(['message' => 'Permission berhasil dicabut dari cabang ini.']);
    }
}
