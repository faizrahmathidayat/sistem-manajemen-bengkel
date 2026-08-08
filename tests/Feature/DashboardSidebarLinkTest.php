<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSidebarLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_shows_dashboard_link_when_user_has_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch, true);
        $permission = Permission::firstOrCreate(
            ['code' => 'dashboard.view'],
            ['resource' => 'dashboard', 'action' => 'view', 'description' => 'Melihat dashboard']
        );
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('dashboard'), false);
        $response->assertSee('Dashboard');
    }

    public function test_sidebar_hides_dashboard_link_when_user_lacks_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch, true);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('bi-speedometer2', false);
    }
}
