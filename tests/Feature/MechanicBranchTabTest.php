<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MechanicBranchTabTest extends TestCase
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
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $admin = $this->userWithPermissions(['mechanic.edit']);

        $response = $this->actingAs($admin)->postJson("/mechanics/{$mechanic->id}/branches/{$branch->id}");

        $response->assertOk();
        $this->assertDatabaseHas('mechanic_branches', [
            'mechanic_id' => $mechanic->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    public function test_unassigning_a_branch_deactivates_the_link(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $admin = $this->userWithPermissions(['mechanic.edit']);

        $response = $this->actingAs($admin)->deleteJson("/mechanics/{$mechanic->id}/branches/{$branch->id}");

        $response->assertOk();
        $this->assertDatabaseHas('mechanic_branches', [
            'mechanic_id' => $mechanic->id,
            'branch_id' => $branch->id,
            'is_active' => false,
        ]);
    }

    public function test_branch_endpoints_are_forbidden_without_permission(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/mechanics/{$mechanic->id}/branches/{$branch->id}")->assertForbidden();
    }

    public function test_show_page_renders_cabang_tab(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $admin = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($admin)->get("/mechanics/{$mechanic->id}");

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
    }
}
