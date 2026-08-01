<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchScopedPermissionTabRenderingTest extends TestCase
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

    public function test_permission_tab_shows_a_sub_tab_per_assigned_branch(): void
    {
        $target = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        (new UserBranchService())->assign($target, $branchA, true);
        (new UserBranchService())->assign($target, $branchB);
        $admin = $this->userWithPermissions(['user.view', 'user_permission.manage']);

        $response = $this->actingAs($admin)->get("/users/{$target->id}");

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
        $response->assertSee('Cabang Bandung');
    }

    public function test_permission_tab_shows_message_when_user_has_no_assigned_branches(): void
    {
        $target = User::factory()->create();
        $admin = $this->userWithPermissions(['user.view', 'user_permission.manage']);

        $response = $this->actingAs($admin)->get("/users/{$target->id}");

        $response->assertOk();
        $response->assertSee('Tetapkan cabang dulu di tab Cabang');
    }

    public function test_branch_scoped_menu_appears_under_branch_sub_tab(): void
    {
        $branchMenu = Menu::create(['code' => 'operasional.test', 'name' => 'Menu Operasional Uji', 'sort_order' => 1, 'is_branch_scoped' => true]);
        Permission::create(['menu_id' => $branchMenu->id, 'code' => 'test_op.view', 'resource' => 'test_op', 'action' => 'view', 'description' => 'Lihat Uji Operasional']);

        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        (new UserBranchService())->assign($target, $branch, true);
        $admin = $this->userWithPermissions(['user.view', 'user_permission.manage']);

        $response = $this->actingAs($admin)->get("/users/{$target->id}");

        $response->assertOk();
        $response->assertSee('Menu Operasional Uji');
        $response->assertSee('Lihat Uji Operasional');
    }
}
