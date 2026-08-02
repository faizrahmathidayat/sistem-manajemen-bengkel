<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleReferenceControllerTest extends TestCase
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

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/vehicle-references')->assertForbidden();
    }

    public function test_index_renders_categories_for_authorized_user(): void
    {
        VehicleCategory::create(['name' => 'Motor']);
        $user = $this->userWithPermissions(['vehicle_reference.view']);

        $response = $this->actingAs($user)->get('/vehicle-references');

        $response->assertOk();
        $response->assertSee('Motor');
    }

    public function test_store_category_creates_it(): void
    {
        $user = $this->userWithPermissions(['vehicle_reference.manage']);

        $response = $this->actingAs($user)->postJson('/vehicle-references/categories', ['name' => 'Motor']);

        $response->assertOk();
        $this->assertDatabaseHas('vehicle_categories', ['name' => 'Motor']);
    }

    public function test_store_category_is_forbidden_without_manage_permission(): void
    {
        $user = $this->userWithPermissions(['vehicle_reference.view']);

        $this->actingAs($user)->postJson('/vehicle-references/categories', ['name' => 'Motor'])->assertForbidden();
    }

    public function test_update_category_can_deactivate(): void
    {
        $category = VehicleCategory::create(['name' => 'Motor']);
        $user = $this->userWithPermissions(['vehicle_reference.manage']);

        $response = $this->actingAs($user)->putJson("/vehicle-references/categories/{$category->id}", [
            'name' => 'Motor',
            'is_active' => false,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('vehicle_categories', ['id' => $category->id, 'is_active' => false]);
    }

    public function test_store_brand_rejects_duplicate_name_within_same_category(): void
    {
        $category = VehicleCategory::create(['name' => 'Motor']);
        VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $user = $this->userWithPermissions(['vehicle_reference.manage']);

        $response = $this->actingAs($user)->postJson('/vehicle-references/brands', [
            'category_id' => $category->id,
            'name' => 'Honda',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_type_creates_it_under_brand(): void
    {
        $category = VehicleCategory::create(['name' => 'Motor']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $user = $this->userWithPermissions(['vehicle_reference.manage']);

        $response = $this->actingAs($user)->postJson('/vehicle-references/types', [
            'brand_id' => $brand->id,
            'name' => 'Beat',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('vehicle_types', ['brand_id' => $brand->id, 'name' => 'Beat']);
    }
}
