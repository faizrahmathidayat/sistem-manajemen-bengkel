<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    public function run()
    {
        $this->call(MenuPermissionSeeder::class);

        $branch = Branch::updateOrCreate(
            ['code' => 'CABANGUTAMA'],
            ['name' => 'CABANGUTAMA', 'is_active' => true]
        );

        $superAdmin = User::updateOrCreate(
            ['username' => config('app.superadmin_username')],
            [
                'name' => 'Super Admin',
                'password' => Hash::make(config('app.superadmin_password')),
                'is_active' => true,
            ]
        );

        (new UserBranchService())->assign($superAdmin, $branch, true);

        $globalCodes = Permission::whereHas('menu', fn ($query) => $query->where('is_branch_scoped', false))->pluck('code')->all();
        $branchScopedCodes = Permission::whereHas('menu', fn ($query) => $query->where('is_branch_scoped', true))->pluck('code')->all();

        $this->grantPermissions($superAdmin, $globalCodes);
        $this->grantBranchPermissions($superAdmin, $branch, $branchScopedCodes);

        $this->command->info("Superadmin seeded: {$superAdmin->username} — cabang {$branch->code} — semua permission diberikan.");
    }

    protected function grantPermissions(User $user, array $codes)
    {
        $permissionIds = Permission::whereIn('code', $codes)->pluck('id', 'code');

        foreach ($codes as $code) {
            if (! isset($permissionIds[$code])) {
                $this->command->warn("Permission code not found, skipped: {$code}");

                continue;
            }

            UserPermission::firstOrCreate([
                'user_id' => $user->id,
                'permission_id' => $permissionIds[$code],
            ]);
        }
    }

    protected function grantBranchPermissions(User $user, Branch $branch, array $codes)
    {
        $permissionIds = Permission::whereIn('code', $codes)->pluck('id', 'code');

        foreach ($codes as $code) {
            if (! isset($permissionIds[$code])) {
                $this->command->warn("Permission code not found, skipped: {$code}");

                continue;
            }

            UserBranchPermission::firstOrCreate([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'permission_id' => $permissionIds[$code],
            ]);
        }
    }
}
