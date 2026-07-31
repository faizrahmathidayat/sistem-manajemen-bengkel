<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserBranch;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserBranchAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_a_branch_to_a_user_creates_an_active_link(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $service = new UserBranchService();

        $service->assign($user, $branch);

        $this->assertTrue($user->hasAccessToBranch($branch->id));
    }

    public function test_setting_default_branch_unsets_previous_default(): void
    {
        $user = User::factory()->create();
        $jakarta = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $bandung = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $service = new UserBranchService();

        $service->assign($user, $jakarta, true);
        $service->assign($user, $bandung, true);

        $this->assertSame($bandung->id, $user->defaultBranch()->id);
        $this->assertSame(
            1,
            UserBranch::where('user_id', $user->id)->where('is_default', true)->count()
        );
    }

    public function test_setting_default_to_a_branch_the_user_is_not_assigned_to_fails(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $service = new UserBranchService();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->setDefault($user, $branch);
    }

    public function test_database_rejects_two_active_default_branches_for_the_same_user(): void
    {
        $user = User::factory()->create();
        $jakarta = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $bandung = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);

        UserBranch::create(['user_id' => $user->id, 'branch_id' => $jakarta->id, 'is_default' => true]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        UserBranch::create(['user_id' => $user->id, 'branch_id' => $bandung->id, 'is_default' => true]);
    }
}
