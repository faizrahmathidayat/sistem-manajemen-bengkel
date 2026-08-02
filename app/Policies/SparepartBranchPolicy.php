<?php

namespace App\Policies;

use App\Models\SparepartBranch;
use App\Models\User;

class SparepartBranchPolicy
{
    public function view(User $user, SparepartBranch $sparepartBranch): bool
    {
        return $user->hasPermissionToInBranch('sparepart.view', $sparepartBranch->branch_id);
    }

    public function update(User $user, SparepartBranch $sparepartBranch): bool
    {
        return $user->hasPermissionToInBranch('sparepart.edit', $sparepartBranch->branch_id);
    }

    public function delete(User $user, SparepartBranch $sparepartBranch): bool
    {
        return $user->hasPermissionToInBranch('sparepart.delete', $sparepartBranch->branch_id);
    }
}
