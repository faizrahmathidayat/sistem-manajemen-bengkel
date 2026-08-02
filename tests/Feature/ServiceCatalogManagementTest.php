<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogManagementTest extends TestCase
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

    public function test_index_lists_service_catalogs_for_authorized_user(): void
    {
        ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        $user = $this->userWithPermissions(['service.view']);

        $response = $this->actingAs($user)->get('/service-catalogs');

        $response->assertOk();
        $response->assertSee('Ganti Oli');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/service-catalogs');

        $response->assertForbidden();
    }

    public function test_store_creates_service_catalog(): void
    {
        $user = $this->userWithPermissions(['service.create']);

        $response = $this->actingAs($user)->post('/service-catalogs', [
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli',
            'default_price' => '75000',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/service-catalogs');
        $this->assertDatabaseHas('service_catalogs', ['code' => 'GANTI-OLI', 'name' => 'Ganti Oli']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithPermissions(['service.create']);

        $response = $this->actingAs($user)->post('/service-catalogs', []);

        $response->assertSessionHasErrors(['code', 'name', 'default_price']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        $user = $this->userWithPermissions(['service.create']);

        $response = $this->actingAs($user)->post('/service-catalogs', [
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli Lagi',
            'default_price' => '50000',
        ]);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/service-catalogs', [
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli',
            'default_price' => '75000',
        ]);

        $response->assertForbidden();
    }

    public function test_update_edits_service_catalog_and_can_deactivate(): void
    {
        $service = ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        $user = $this->userWithPermissions(['service.edit']);

        $response = $this->actingAs($user)->put("/service-catalogs/{$service->id}", [
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli Mesin',
            'default_price' => '80000',
            'is_active' => '0',
        ]);

        $response->assertRedirect('/service-catalogs');
        $this->assertDatabaseHas('service_catalogs', [
            'id' => $service->id,
            'name' => 'Ganti Oli Mesin',
            'is_active' => false,
        ]);
    }

    public function test_update_allows_keeping_the_same_code(): void
    {
        $service = ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        $user = $this->userWithPermissions(['service.edit']);

        $response = $this->actingAs($user)->put("/service-catalogs/{$service->id}", [
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli',
            'default_price' => '75000',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/service-catalogs');
        $response->assertSessionDoesntHaveErrors();
    }

    public function test_update_is_forbidden_without_permission(): void
    {
        $service = ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put("/service-catalogs/{$service->id}", [
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli Edited',
            'default_price' => '75000',
        ]);

        $response->assertForbidden();
    }
}
