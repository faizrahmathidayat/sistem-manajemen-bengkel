<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Rack;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RackManagementTest extends TestCase
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

    public function test_index_lists_racks_for_authorized_user(): void
    {
        Rack::create(['code' => 'A1']);
        $user = $this->userWithPermissions(['rack.view']);

        $response = $this->actingAs($user)->get('/racks');

        $response->assertOk();
        $response->assertSee('A1');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/racks');

        $response->assertForbidden();
    }

    public function test_store_creates_rack(): void
    {
        $user = $this->userWithPermissions(['rack.create']);

        $response = $this->actingAs($user)->post('/racks', [
            'code' => 'A1',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/racks');
        $this->assertDatabaseHas('racks', ['code' => 'A1']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithPermissions(['rack.create']);

        $response = $this->actingAs($user)->post('/racks', []);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        Rack::create(['code' => 'A1']);
        $user = $this->userWithPermissions(['rack.create']);

        $response = $this->actingAs($user)->post('/racks', ['code' => 'A1']);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/racks', ['code' => 'A1']);

        $response->assertForbidden();
    }

    public function test_update_edits_rack_and_can_deactivate(): void
    {
        $rack = Rack::create(['code' => 'A1']);
        $user = $this->userWithPermissions(['rack.edit']);

        $response = $this->actingAs($user)->put("/racks/{$rack->id}", [
            'code' => 'A2',
            'is_active' => '0',
        ]);

        $response->assertRedirect('/racks');
        $this->assertDatabaseHas('racks', ['id' => $rack->id, 'code' => 'A2', 'is_active' => false]);
    }

    public function test_update_allows_keeping_the_same_code(): void
    {
        $rack = Rack::create(['code' => 'A1']);
        $user = $this->userWithPermissions(['rack.edit']);

        $response = $this->actingAs($user)->put("/racks/{$rack->id}", [
            'code' => 'A1',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/racks');
        $response->assertSessionDoesntHaveErrors();
    }

    public function test_update_is_forbidden_without_permission(): void
    {
        $rack = Rack::create(['code' => 'A1']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put("/racks/{$rack->id}", ['code' => 'A2']);

        $response->assertForbidden();
    }

    public function test_index_search_by_code_filters_results(): void
    {
        Rack::create(['code' => 'A1']);
        Rack::create(['code' => 'B2']);
        $user = $this->userWithPermissions(['rack.view']);

        $response = $this->actingAs($user)->get('/racks?q=A1');

        $response->assertOk();
        $response->assertSee('A1');
        $response->assertDontSee('B2');
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        Rack::create(['code' => 'A1']);
        $user = $this->userWithPermissions(['rack.view']);

        $response = $this->actingAs($user)->get('/racks?q[]=A1');

        $response->assertOk();
        $response->assertSee('A1');
    }

    public function test_index_shows_empty_state_when_no_racks_match(): void
    {
        $user = $this->userWithPermissions(['rack.view']);

        $response = $this->actingAs($user)->get('/racks');

        $response->assertOk();
        $response->assertSee('Belum ada rak');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['rack.view', 'rack.create']);

        $response = $this->actingAs($user)->get('/racks');

        $response->assertOk();
        $response->assertSee('Tambah Rak Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['rack.view']);

        $response = $this->actingAs($user)->get('/racks');

        $response->assertOk();
        $response->assertDontSee('Tambah Rak Pertama');
    }

    public function test_index_renders_filter_bar(): void
    {
        $user = $this->userWithPermissions(['rack.view']);

        $response = $this->actingAs($user)->get('/racks');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari kode rak...');
    }
}
