<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Database\Seeder;

class DashboardPermissionSeeder extends Seeder
{
    public function run()
    {
        $this->call(MenuPermissionSeeder::class);

        $permission = Permission::where('code', 'dashboard.view')->first();

        if (! $permission) {
            $this->command->error('Permission dashboard.view tidak ditemukan setelah MenuPermissionSeeder dijalankan.');

            return;
        }

        User::query()->select('id')->chunkById(200, function ($users) use ($permission) {
            foreach ($users as $user) {
                UserPermission::firstOrCreate([
                    'user_id' => $user->id,
                    'permission_id' => $permission->id,
                ]);
            }
        });

        $this->command->info('Permission dashboard.view diberikan ke seluruh user (' . User::count() . ' user).');
    }
}
