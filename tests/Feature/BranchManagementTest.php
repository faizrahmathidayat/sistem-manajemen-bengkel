<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchManagementTest extends TestCase
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

    public function test_index_lists_branches_for_authorized_user(): void
    {
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/branches');

        $response->assertForbidden();
    }

    public function test_store_creates_branch(): void
    {
        $user = $this->userWithPermissions(['branch.create']);

        $response = $this->actingAs($user)->post('/branches', [
            'code' => 'BDG',
            'name' => 'Cabang Bandung',
            'address' => 'Jl. Asia Afrika',
            'phone' => '022123456',
            'email' => 'bandung@bengkel.test',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/branches');
        $this->assertDatabaseHas('branches', ['code' => 'BDG', 'name' => 'Cabang Bandung']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithPermissions(['branch.create']);

        $response = $this->actingAs($user)->post('/branches', []);

        $response->assertSessionHasErrors(['code', 'name']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/branches', [
            'code' => 'BDG',
            'name' => 'Cabang Bandung',
        ]);

        $response->assertForbidden();
    }

    public function test_update_edits_branch_and_can_deactivate(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = $this->userWithPermissions(['branch.edit']);

        $response = $this->actingAs($user)->put("/branches/{$branch->id}", [
            'code' => 'JKT',
            'name' => 'Cabang Jakarta Pusat',
            'is_active' => '0',
        ]);

        $response->assertRedirect('/branches');
        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'name' => 'Cabang Jakarta Pusat',
            'is_active' => false,
        ]);
    }

    public function test_index_search_by_code_filters_results(): void
    {
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches?q=JKT');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
        $response->assertDontSee('Cabang Bandung');
    }

    public function test_index_search_by_name_filters_results(): void
    {
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches?q=Bandung');

        $response->assertOk();
        $response->assertSee('Cabang Bandung');
        $response->assertDontSee('Cabang Jakarta');
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches?q[]=JKT');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
    }

    public function test_index_shows_empty_state_when_no_branches_match(): void
    {
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches');

        $response->assertOk();
        $response->assertSee('Belum ada cabang');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['branch.view', 'branch.create']);

        $response = $this->actingAs($user)->get('/branches');

        $response->assertOk();
        $response->assertSee('Tambah Cabang Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches');

        $response->assertOk();
        $response->assertDontSee('Tambah Cabang Pertama');
    }

    public function test_index_renders_filter_bar(): void
    {
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari kode atau nama cabang...');
    }
}
