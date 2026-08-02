<?php

namespace App\Models\Concerns;

use App\Models\UserBranchPermission;
use App\Models\UserPermission;

trait AuthorizesByPermission
{
    protected $permissionCodesCache = null;

    protected $branchPermissionCodesCache = [];

    public function userPermissions()
    {
        return $this->hasMany(UserPermission::class);
    }

    public function userBranchPermissions()
    {
        return $this->hasMany(UserBranchPermission::class);
    }

    public function permissionCodes(): array
    {
        if ($this->permissionCodesCache === null) {
            $this->permissionCodesCache = $this->userPermissions()
                ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
                ->where('permissions.is_active', true)
                ->pluck('permissions.code')
                ->all();
        }

        return $this->permissionCodesCache;
    }

    public function branchPermissionCodes(int $branchId): array
    {
        if (! array_key_exists($branchId, $this->branchPermissionCodesCache)) {
            $this->branchPermissionCodesCache[$branchId] = $this->userBranchPermissions()
                ->where('branch_id', $branchId)
                ->join('permissions', 'permissions.id', '=', 'user_branch_permissions.permission_id')
                ->where('permissions.is_active', true)
                ->pluck('permissions.code')
                ->all();
        }

        return $this->branchPermissionCodesCache[$branchId];
    }

    public function hasPermissionTo(string $code): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return in_array($code, $this->permissionCodes(), true);
    }

    public function hasPermissionToInBranch(string $code, int $branchId): bool
    {
        if (! $this->is_active || ! $this->hasAccessToBranch($branchId)) {
            return false;
        }

        return in_array($code, $this->branchPermissionCodes($branchId), true);
    }
}
