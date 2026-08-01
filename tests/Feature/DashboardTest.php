<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_active_branch_and_user_counts(): void
    {
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta', 'is_active' => true]);
        Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung', 'is_active' => true]);
        Branch::create(['code' => 'SBY', 'name' => 'Cabang Surabaya', 'is_active' => false]);

        User::factory()->create(['is_active' => true]);
        User::factory()->create(['is_active' => true]);
        User::factory()->create(['is_active' => false]);
        $viewer = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($viewer)->get('/dashboard');

        $response->assertOk();
        $response->assertViewHas('activeBranchCount', 2);
        $response->assertViewHas('activeUserCount', 3);
    }
}
