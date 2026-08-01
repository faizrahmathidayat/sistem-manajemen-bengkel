<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_faiz_gets_global_permissions_globally_and_branch_scoped_permissions_in_every_branch(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $faiz = User::where('username', 'faiz_rahmat')->firstOrFail();
        $bengkel1 = Branch::where('code', 'BENGKEL1')->firstOrFail();
        $bengkel2 = Branch::where('code', 'BENGKEL2')->firstOrFail();

        $this->assertTrue($faiz->userPermissions()->whereHas('permission', fn ($q) => $q->where('code', 'user.view'))->exists());
        $this->assertTrue(UserBranchPermission::where('user_id', $faiz->id)->where('branch_id', $bengkel1->id)
            ->whereHas('permission', fn ($q) => $q->where('code', 'pkb.view'))->exists());
        $this->assertTrue(UserBranchPermission::where('user_id', $faiz->id)->where('branch_id', $bengkel2->id)
            ->whereHas('permission', fn ($q) => $q->where('code', 'pkb.view'))->exists());
    }

    public function test_romi_gets_pkb_and_laporan_permissions_scoped_to_bengkel1_only(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $romi = User::where('username', 'romi_ramdani')->firstOrFail();
        $bengkel1 = Branch::where('code', 'BENGKEL1')->firstOrFail();

        $this->assertTrue(UserBranchPermission::where('user_id', $romi->id)->where('branch_id', $bengkel1->id)
            ->whereHas('permission', fn ($q) => $q->where('code', 'pkb.view'))->exists());
        $this->assertTrue(UserBranchPermission::where('user_id', $romi->id)->where('branch_id', $bengkel1->id)
            ->whereHas('permission', fn ($q) => $q->where('code', 'report.pkb.view'))->exists());
        $this->assertSame(0, UserPermission::where('user_id', $romi->id)
            ->whereHas('permission', fn ($q) => $q->where('code', 'pkb.view'))->count());
    }
}
