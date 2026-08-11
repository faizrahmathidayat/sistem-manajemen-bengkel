<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Http\Request;

class UserPermissionAssignmentController extends Controller
{
    public function store(Request $request, User $user, Permission $permission)
    {
        $this->authorize('user_permission.manage');
        $this->authorize('update', $user);

        UserPermission::firstOrCreate(
            ['user_id' => $user->id, 'permission_id' => $permission->id],
            ['granted_by' => $request->user()->id]
        );

        return response()->json(['message' => 'Permission berhasil diberikan.']);
    }

    public function destroy(Request $request, User $user, Permission $permission)
    {
        $this->authorize('user_permission.manage');
        $this->authorize('update', $user);

        if ($this->wouldStripLastSelfManagePermission($request->user(), $user, $permission)) {
            return response()->json([
                'message' => 'Tidak dapat mencabut permission ini dari akun sendiri karena tidak akan ada user aktif lain yang memilikinya.',
            ], 422);
        }

        UserPermission::where('user_id', $user->id)
            ->where('permission_id', $permission->id)
            ->delete();

        return response()->json(['message' => 'Permission berhasil dicabut.']);
    }

    protected function wouldStripLastSelfManagePermission(User $actingUser, User $targetUser, Permission $permission): bool
    {
        if ($permission->code !== 'user_permission.manage' || $targetUser->id !== $actingUser->id) {
            return false;
        }

        return ! UserPermission::where('permission_id', $permission->id)
            ->where('user_id', '!=', $targetUser->id)
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->exists();
    }
}
