<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserBranch;
use Illuminate\Support\Facades\DB;

class UserBranchService
{
    public function assign(User $user, Branch $branch, bool $makeDefault = false): UserBranch
    {
        return DB::transaction(function () use ($user, $branch, $makeDefault) {
            $userBranch = UserBranch::firstOrCreate(
                ['user_id' => $user->id, 'branch_id' => $branch->id],
                ['is_active' => true]
            );

            if (! $userBranch->is_active) {
                $userBranch->is_active = true;
                $userBranch->save();
            }

            if ($makeDefault) {
                $this->setDefault($user, $branch);
            }

            return $userBranch->refresh();
        });
    }

    public function setDefault(User $user, Branch $branch): void
    {
        DB::transaction(function () use ($user, $branch) {
            UserBranch::where('user_id', $user->id)
                ->where('is_default', true)
                ->lockForUpdate()
                ->update(['is_default' => false]);

            $userBranch = UserBranch::where('user_id', $user->id)
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $userBranch->update(['is_default' => true]);
        });
    }
}
