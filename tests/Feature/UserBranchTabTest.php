<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranch;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserBranchTabTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermissions(array $codes): User
    {
        $user = User::factory()->create();

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $code]
            );
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        return User::find($user->id);
    }

    public function test_assigning_a_branch_creates_active_link(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $admin = $this->userWithPermissions(['user_branch.manage']);

        $response = $this->actingAs($admin)->postJson("/users/{$target->id}/branches/{$branch->id}");

        $response->assertOk();
        $this->assertDatabaseHas('user_branches', [
            'user_id' => $target->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    public function test_unassigning_a_branch_deactivates_the_link(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        UserBranch::create(['user_id' => $target->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $admin = $this->userWithPermissions(['user_branch.manage']);

        $response = $this->actingAs($admin)->deleteJson("/users/{$target->id}/branches/{$branch->id}");

        $response->assertOk();
        $this->assertDatabaseHas('user_branches', [
            'user_id' => $target->id,
            'branch_id' => $branch->id,
            'is_active' => false,
        ]);
    }

    public function test_setting_default_branch_requires_existing_access(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $admin = $this->userWithPermissions(['user_branch.manage']);

        $response = $this->actingAs($admin)->putJson("/users/{$target->id}/branches/{$branch->id}/default");

        $response->assertStatus(422);
    }

    public function test_setting_default_branch_succeeds_when_assigned(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        UserBranch::create(['user_id' => $target->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $admin = $this->userWithPermissions(['user_branch.manage']);

        $response = $this->actingAs($admin)->putJson("/users/{$target->id}/branches/{$branch->id}/default");

        $response->assertOk();
        $this->assertDatabaseHas('user_branches', [
            'user_id' => $target->id,
            'branch_id' => $branch->id,
            'is_default' => true,
        ]);
    }

    public function test_branch_endpoints_are_forbidden_without_permission(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/users/{$target->id}/branches/{$branch->id}")->assertForbidden();
    }

    public function test_show_page_renders_cabang_tab_when_authorized(): void
    {
        $target = User::factory()->create();
        $admin = $this->userWithPermissions(['user.view', 'user_branch.manage']);

        $response = $this->actingAs($admin)->get("/users/{$target->id}");

        $response->assertOk();
        $response->assertSee('Cabang');
    }
}
