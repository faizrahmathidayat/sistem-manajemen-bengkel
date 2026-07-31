<?php

namespace App\Models\Concerns;

use App\Models\UserPermission;

trait AuthorizesByPermission
{
    protected $permissionCodesCache = null;

    public function userPermissions()
    {
        return $this->hasMany(UserPermission::class);
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

    public function hasPermissionTo(string $code): bool
    {
        return in_array($code, $this->permissionCodes(), true);
    }
}
