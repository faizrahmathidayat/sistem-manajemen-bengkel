<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    public function run()
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->error('DemoUsersSeeder only runs in local/testing environments (demo accounts use weak, guessable passwords).');

            return;
        }

        $this->call(MenuPermissionSeeder::class);

        $branches = $this->seedBranches();
        $users = $this->seedUsers();

        $branchService = new UserBranchService();

        // Faiz: all access, all branches, all permissions.
        foreach ($branches as $index => $branch) {
            $branchService->assign($users['faiz'], $branch, $index === 0);
        }
        $this->grantPermissions($users['faiz'], Permission::pluck('code')->all());

        // Romi: Bengkel 1 only, PKB view/create + view all laporan.
        $branchService->assign($users['romi'], $branches->first(), true);
        $this->grantPermissions($users['romi'], $this->laporanCodes([
            'pkb.view',
            'pkb.create',
        ]));

        // Syilawati: Bengkel 1 only, invoice view/create + view all laporan.
        $branchService->assign($users['syilawati'], $branches->first(), true);
        $this->grantPermissions($users['syilawati'], $this->laporanCodes([
            'invoice.view',
            'invoice.create',
        ]));

        $this->command->info('Demo users seeded (local/testing only): faiz_rahmat, romi_ramdani, syilawati_rn — password sama dengan username.');
    }

    protected function seedBranches()
    {
        $definitions = [
            ['code' => 'BENGKEL1', 'name' => 'Bengkel 1'],
            ['code' => 'BENGKEL2', 'name' => 'Bengkel 2'],
            ['code' => 'BENGKEL3', 'name' => 'Bengkel 3'],
        ];

        return collect($definitions)->map(function ($definition) {
            return Branch::updateOrCreate(
                ['code' => $definition['code']],
                ['name' => $definition['name'], 'is_active' => true]
            );
        });
    }

    protected function seedUsers()
    {
        $definitions = [
            'faiz' => ['username' => 'faiz_rahmat', 'name' => 'Faiz Rahmat Hidayat'],
            'romi' => ['username' => 'romi_ramdani', 'name' => 'Romi Ramdani'],
            'syilawati' => ['username' => 'syilawati_rn', 'name' => 'Syilawati'],
        ];

        $users = [];

        foreach ($definitions as $key => $definition) {
            $users[$key] = User::updateOrCreate(
                ['username' => $definition['username']],
                [
                    'name' => $definition['name'],
                    'password' => Hash::make($definition['username']),
                    'is_active' => true,
                ]
            );
        }

        return $users;
    }

    protected function laporanCodes(array $extra): array
    {
        return array_merge($extra, [
            'report.pkb.view',
            'report.invoice.view',
            'report.receivable.view',
            'report.invoice_pkb_gap.view',
            'report.sparepart.view',
        ]);
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
}
