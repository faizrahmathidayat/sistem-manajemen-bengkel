<?php

namespace Tests\Feature;

use App\Models\Mechanic;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
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
}
