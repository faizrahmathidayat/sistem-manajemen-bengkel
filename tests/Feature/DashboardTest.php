<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_defaults_filter_to_users_default_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $branchService = new UserBranchService();
        $branchService->assign($user, $branchA, true);
        $branchService->assign($user, $branchB, false);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta', false);
    }

    public function test_dashboard_ignores_branch_ids_the_user_is_not_assigned_to(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branchA, true);

        $response = $this->actingAs(User::find($user->id))
            ->getJson('/dashboard?branch_ids[]=' . $branchA->id . '&branch_ids[]=' . $branchB->id);

        $response->assertOk();
        $response->assertJson(['selectedBranchIds' => [$branchA->id]]);
    }

    public function test_dashboard_json_response_shape(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch, true);

        $response = $this->actingAs(User::find($user->id))->getJson('/dashboard');

        $response->assertOk();
        $response->assertJsonStructure(['selectedBranchIds']);
    }

    public function test_dashboard_shows_empty_state_for_user_with_zero_branches(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('belum ditugaskan ke cabang manapun', false);
    }
}
