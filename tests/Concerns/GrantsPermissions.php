<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use App\Services\UserBranchService;

trait GrantsPermissions
{
    protected function findOrCreatePermission(string $code): Permission
    {
        [$resource, $action] = array_pad(explode('.', $code, 2), 2, '');

        return Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
    }

    protected function grantGlobal(User $user, string $code): void
    {
        $permission = $this->findOrCreatePermission($code);

        UserPermission::firstOrCreate(['user_id' => $user->id, 'permission_id' => $permission->id]);
    }

    protected function grantBranch(User $user, Branch $branch, string $code): void
    {
        (new UserBranchService())->assign($user, $branch);
        $permission = $this->findOrCreatePermission($code);

        UserBranchPermission::firstOrCreate([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'permission_id' => $permission->id,
        ]);
    }
}
