<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view(User $actingUser, User $targetUser): bool
    {
        return $actingUser->isSuperAdmin() || ! $targetUser->isSuperAdmin();
    }

    public function update(User $actingUser, User $targetUser): bool
    {
        return $actingUser->isSuperAdmin() || ! $targetUser->isSuperAdmin();
    }
}
