<?php

namespace Tests\Feature;

use App\Models\Mechanic;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MechanicNipIntegrationTest extends TestCase
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

    public function test_full_lifecycle_create_search_and_lookup_label_includes_nip(): void
    {
        $user = $this->userWithPermissions(['mechanic.create', 'mechanic.view', 'mechanic.edit']);

        $createResponse = $this->actingAs($user)->post('/mechanics', [
            'name' => 'Agus Setiawan',
            'nip' => 'NIP-2020-001',
            'join_date' => '2020-03-01',
            'is_active' => '1',
        ]);
        $createResponse->assertRedirect('/mechanics');

        $mechanic = Mechanic::where('nip', 'NIP-2020-001')->firstOrFail();

        $indexResponse = $this->actingAs($user)->get('/mechanics?q=NIP-2020-001');
        $indexResponse->assertOk();
        $indexResponse->assertSee('Agus Setiawan');

        $showResponse = $this->actingAs($user)->get("/mechanics/{$mechanic->id}");
        $showResponse->assertOk();
        $showResponse->assertSee('value="NIP-2020-001"', false);

        $lookupResponse = $this->actingAs($user)->getJson('/lookup/mechanics?q=Agus');
        $lookupResponse->assertOk();
        $lookupResponse->assertJsonFragment(['text' => 'Agus Setiawan (NIP-2020-001)']);

        $duplicateResponse = $this->actingAs($user)->post('/mechanics', [
            'name' => 'Budi Santoso',
            'nip' => 'NIP-2020-001',
        ]);
        $duplicateResponse->assertSessionHasErrors(['nip']);
    }

    public function test_mechanic_created_without_nip_still_appears_safely_in_index_and_lookup(): void
    {
        Mechanic::create(['name' => 'Mekanik Lama Tanpa Nip']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $indexResponse = $this->actingAs($user)->get('/mechanics');
        $indexResponse->assertOk();
        $indexResponse->assertSee('Mekanik Lama Tanpa Nip');

        $lookupResponse = $this->actingAs($user)->getJson('/lookup/mechanics?q=Lama');
        $lookupResponse->assertOk();
        $lookupResponse->assertJsonFragment(['text' => 'Mekanik Lama Tanpa Nip']);
    }
}
