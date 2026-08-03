<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MechanicManagementTest extends TestCase
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

    public function test_index_lists_mechanics_for_authorized_user(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertForbidden();
    }

    public function test_store_creates_mechanic(): void
    {
        $user = $this->userWithPermissions(['mechanic.create']);

        $response = $this->actingAs($user)->post('/mechanics', [
            'name' => 'Agus Setiawan',
            'phone' => '081234567890',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/mechanics');
        $this->assertDatabaseHas('mechanics', ['name' => 'Agus Setiawan']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithPermissions(['mechanic.create']);

        $response = $this->actingAs($user)->post('/mechanics', []);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/mechanics', ['name' => 'Agus Setiawan']);

        $response->assertForbidden();
    }

    public function test_show_renders_profil_tab_for_authorized_user(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get("/mechanics/{$mechanic->id}");

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
    }

    public function test_update_edits_mechanic_and_can_deactivate(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermissions(['mechanic.edit']);

        $response = $this->actingAs($user)->put("/mechanics/{$mechanic->id}", [
            'name' => 'Agus Setiawan Edited',
            'is_active' => '0',
        ]);

        $response->assertRedirect("/mechanics/{$mechanic->id}");
        $this->assertDatabaseHas('mechanics', [
            'id' => $mechanic->id,
            'name' => 'Agus Setiawan Edited',
            'is_active' => false,
        ]);
    }

    public function test_update_is_forbidden_without_permission(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put("/mechanics/{$mechanic->id}", ['name' => 'Agus Setiawan Edited']);

        $response->assertForbidden();
    }

    public function test_index_search_by_name_filters_results(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan']);
        Mechanic::create(['name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics?q=Agus');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }

    public function test_index_search_by_phone_filters_results(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan', 'phone' => '081111111111']);
        Mechanic::create(['name' => 'Budi Santoso', 'phone' => '082222222222']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics?q=081111');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics?q[]=Agus');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
    }

    public function test_index_branch_filter_scopes_to_selected_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $mechanicA = Mechanic::create(['name' => 'Agus Setiawan']);
        $mechanicB = Mechanic::create(['name' => 'Budi Santoso']);
        MechanicBranch::create(['mechanic_id' => $mechanicA->id, 'branch_id' => $branchA->id]);
        MechanicBranch::create(['mechanic_id' => $mechanicB->id, 'branch_id' => $branchB->id]);
        $user = $this->userWithPermissions(['mechanic.view']);
        (new UserBranchService())->assign($user, $branchA);
        (new UserBranchService())->assign($user, $branchB);

        $response = $this->actingAs(User::find($user->id))->get("/mechanics?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }

    public function test_index_branch_filter_drops_branch_ids_the_user_is_not_assigned_to(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $mechanicA = Mechanic::create(['name' => 'Agus Setiawan']);
        $mechanicB = Mechanic::create(['name' => 'Budi Santoso']);
        MechanicBranch::create(['mechanic_id' => $mechanicA->id, 'branch_id' => $branchA->id]);
        MechanicBranch::create(['mechanic_id' => $mechanicB->id, 'branch_id' => $branchB->id]);
        $user = $this->userWithPermissions(['mechanic.view']);
        (new UserBranchService())->assign($user, $branchA);

        $response = $this->actingAs(User::find($user->id))->get("/mechanics?branch_ids[]={$branchB->id}");

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertSee('Budi Santoso');
    }

    public function test_index_shows_empty_state_when_no_mechanics_match(): void
    {
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertSee('Belum ada mekanik');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['mechanic.view', 'mechanic.create']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertSee('Tambah Mekanik Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertDontSee('Tambah Mekanik Pertama');
    }

    public function test_index_renders_filter_bar(): void
    {
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari nama atau telepon...');
    }
}
