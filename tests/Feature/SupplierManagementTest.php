<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions;

    protected function userWith(array $codes): User
    {
        $user = User::factory()->create();
        foreach ($codes as $code) {
            $this->grantGlobal($user, $code);
        }

        return User::find($user->id);
    }

    protected function makeSupplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge(['code' => 'SUP-001', 'name' => 'PT Sumber Oli Jaya'], $overrides));
    }

    public function test_index_is_forbidden_without_supplier_view(): void
    {
        $this->actingAs(User::factory()->create())->get('/suppliers')->assertForbidden();
    }

    public function test_index_lists_and_filters_by_search_and_status(): void
    {
        $this->makeSupplier();
        $this->makeSupplier(['code' => 'SUP-002', 'name' => 'CV Mitra Otomotif']);
        $this->makeSupplier(['code' => 'SUP-003', 'name' => 'PT Aki Prima', 'is_active' => false]);
        $user = $this->userWith(['supplier.view']);

        $this->actingAs($user)->get('/suppliers')->assertOk()
            ->assertSee('Sumber Oli')->assertSee('Mitra Otomotif')->assertSee('Aki Prima');

        $this->actingAs($user)->get('/suppliers?q=mitra')->assertOk()
            ->assertSee('Mitra Otomotif')->assertDontSee('Sumber Oli');

        $this->actingAs($user)->get('/suppliers?status=inactive')->assertOk()
            ->assertSee('Aki Prima')->assertDontSee('Mitra Otomotif');
    }

    public function test_index_survives_array_query_input(): void
    {
        $user = $this->userWith(['supplier.view']);

        $this->actingAs($user)->get('/suppliers?q[]=x&status[]=y')->assertOk();
    }

    public function test_store_creates_supplier_and_rejects_duplicate_code(): void
    {
        $user = $this->userWith(['supplier.create']);

        $this->actingAs($user)->post('/suppliers', [
            'code' => 'SUP-010', 'name' => 'UD Sinar Spare', 'contact_person' => 'Agus',
            'phone' => '0813', 'address' => 'Bekasi', 'is_active' => '1',
        ])->assertRedirect('/suppliers');

        $this->assertDatabaseHas('suppliers', ['code' => 'SUP-010', 'is_active' => true]);

        $this->actingAs($user)->post('/suppliers', ['code' => 'SUP-010', 'name' => 'Lain'])
            ->assertSessionHasErrors('code');
    }

    public function test_create_and_edit_forms_render(): void
    {
        $supplier = $this->makeSupplier();
        $user = $this->userWith(['supplier.create', 'supplier.edit']);

        $this->actingAs($user)->get('/suppliers/create')->assertOk()->assertSee('Simpan');
        $this->actingAs($user)->get("/suppliers/{$supplier->id}/edit")->assertOk()->assertSee('SUP-001');
    }

    public function test_update_changes_data_and_can_deactivate(): void
    {
        $supplier = $this->makeSupplier();
        $user = $this->userWith(['supplier.edit']);

        $this->actingAs($user)->put("/suppliers/{$supplier->id}", [
            'code' => 'SUP-001', 'name' => 'PT Baru', // is_active omitted => unchecked
        ])->assertRedirect('/suppliers');

        $supplier->refresh();
        $this->assertSame('PT Baru', $supplier->name);
        $this->assertFalse($supplier->is_active);
    }

    public function test_update_requires_edit_permission_and_there_is_no_delete_route(): void
    {
        $supplier = $this->makeSupplier();
        $user = $this->userWith(['supplier.view']);

        $this->actingAs($user)->put("/suppliers/{$supplier->id}", ['code' => 'X', 'name' => 'Y'])->assertForbidden();
        $this->actingAs($this->userWith(['supplier.edit']))->delete("/suppliers/{$supplier->id}")->assertStatus(405);
    }

    public function test_lookup_returns_only_active_suppliers_for_search_but_resolves_inactive_by_id(): void
    {
        $this->makeSupplier(['name' => 'Sumber Oli Jaya']);
        $inactive = $this->makeSupplier(['code' => 'SUP-009', 'name' => 'Sumber Lama', 'is_active' => false]);
        $user = $this->userWith(['supplier.view']);

        $search = $this->actingAs($user)->getJson('/lookup/suppliers?q=Sumber')->assertOk()->json();
        $this->assertCount(1, $search);
        $this->assertSame('SUP-001 — Sumber Oli Jaya', $search[0]['text']);

        $resolved = $this->actingAs($user)->getJson('/lookup/suppliers?ids[]=' . $inactive->id)->assertOk()->json();
        $this->assertSame($inactive->id, $resolved[0]['id']);
    }

    public function test_lookup_is_available_to_assign_supplier_holders_and_forbidden_otherwise(): void
    {
        $this->makeSupplier();

        $this->actingAs($this->userWith(['purchase_request.assign_supplier']))
            ->getJson('/lookup/suppliers?q=Sumber')->assertOk();
        $this->actingAs(User::factory()->create())->getJson('/lookup/suppliers?q=Sumber')->assertForbidden();
    }

    public function test_sidebar_shows_supplier_link_only_with_supplier_view(): void
    {
        $with = $this->userWith(['supplier.view']);
        $this->actingAs($with)->get('/suppliers')->assertSee(route('suppliers.index'), false);

        $without = $this->userWith(['customer.view']);
        $this->actingAs($without)->get('/customers')->assertDontSee(route('suppliers.index'), false);
    }
}
