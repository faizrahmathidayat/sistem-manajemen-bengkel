<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_hides_links_the_user_has_no_permission_for(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee(route('branches.index'), false);
        $response->assertDontSee(route('users.index'), false);
    }

    public function test_sidebar_shows_cabang_link_when_user_has_branch_view_permission(): void
    {
        $permission = Permission::create([
            'code' => 'branch.view',
            'resource' => 'branch',
            'action' => 'view',
            'description' => 'Melihat cabang',
        ]);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('branches.index'), false);
        $response->assertDontSee(route('users.index'), false);
    }

    public function test_sidebar_shows_users_link_when_user_has_user_view_permission(): void
    {
        $permission = Permission::create([
            'code' => 'user.view',
            'resource' => 'user',
            'action' => 'view',
            'description' => 'Melihat user',
        ]);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('users.index'), false);
        $response->assertDontSee(route('branches.index'), false);
    }
}
