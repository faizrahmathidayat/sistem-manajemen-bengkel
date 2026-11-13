<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PurchaseRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseRequestAssignSupplierTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function gudang(): User
    {
        $user = User::factory()->create();
        $this->grantGlobal($user, 'purchase_request.assign_supplier');

        return User::find($user->id);
    }

    protected function requester($branch): User
    {
        $user = User::factory()->create();
        $this->grantBranch($user, $branch, 'purchase_request.view');
        $this->grantBranch($user, $branch, 'purchase_request.create');

        return User::find($user->id);
    }

    protected function fixture(): array
    {
        $bks = $this->makeBranch('BKS');
        $owner = $this->requester($bks);
        $a = $this->makeSparepartBranch($bks);
        $b = $this->makeSparepartBranch($bks);
        $request = $this->actingAs($owner)->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $a->id, 'qty' => 120],
            ['sparepart_branch_id' => $b->id, 'qty' => 60],
        ]);

        return [$bks, $owner, $request, $request->lines[0], $request->lines[1]];
    }

    public function test_warehouse_head_can_save_partial_assignment_and_totals_are_server_computed(): void
    {
        [, , $request, $lineA, $lineB] = $this->fixture();
        $supplier = $this->makeSupplier('SUP-001');

        $this->actingAs($this->gudang())->patch("/purchase-requests/{$request->id}/assign-suppliers", [
            'lines' => [
                $lineA->id => ['supplier_id' => $supplier->id, 'unit_price' => 85000, 'line_total' => 1],
                $lineB->id => ['supplier_id' => '', 'unit_price' => ''],
            ],
        ])->assertRedirect(route('purchase-requests.show', $request));

        $lineA->refresh();
        $lineB->refresh();
        $this->assertSame($supplier->id, (int) $lineA->supplier_id);
        $this->assertSame('85000.00', (string) $lineA->unit_price);
        $this->assertSame('10200000.00', (string) $lineA->line_total); // 120 x 85.000, client value ignored
        $this->assertNull($lineB->supplier_id);
        $this->assertNull($lineB->line_total);
        $this->assertSame(PurchaseRequestStatus::WAITING_SUPPLIER, $request->fresh()->status);
    }

    public function test_inactive_supplier_is_rejected_unless_already_on_the_line(): void
    {
        [, , $request, $lineA] = $this->fixture();
        $inactive = $this->makeSupplier('SUP-009', ['is_active' => false]);
        $gudang = $this->gudang();

        $this->actingAs($gudang)->patch("/purchase-requests/{$request->id}/assign-suppliers", [
            'lines' => [$lineA->id => ['supplier_id' => $inactive->id, 'unit_price' => 1000]],
        ])->assertSessionHasErrors("lines.{$lineA->id}.supplier_id");

        // Same supplier already attached before it was deactivated: still editable (price change).
        $lineA->update(['supplier_id' => $inactive->id, 'unit_price' => 1000, 'line_total' => 120000]);
        $this->actingAs($gudang)->patch("/purchase-requests/{$request->id}/assign-suppliers", [
            'lines' => [$lineA->id => ['supplier_id' => $inactive->id, 'unit_price' => 2000]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('2000.00', (string) $lineA->fresh()->unit_price);
    }

    public function test_negative_or_decimal_price_and_foreign_line_are_rejected(): void
    {
        [$bks, , $request, $lineA] = $this->fixture();
        $supplier = $this->makeSupplier();
        $otherLine = $this->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 1],
        ])->lines[0];
        $gudang = $this->gudang();

        $this->actingAs($gudang)->patch("/purchase-requests/{$request->id}/assign-suppliers", [
            'lines' => [$lineA->id => ['supplier_id' => $supplier->id, 'unit_price' => -5]],
        ])->assertSessionHasErrors("lines.{$lineA->id}.unit_price");

        $this->actingAs($gudang)->patch("/purchase-requests/{$request->id}/assign-suppliers", [
            'lines' => [$lineA->id => ['supplier_id' => $supplier->id, 'unit_price' => '10.5']],
        ])->assertSessionHasErrors("lines.{$lineA->id}.unit_price");

        $this->actingAs($gudang)->patch("/purchase-requests/{$request->id}/assign-suppliers", [
            'lines' => [$otherLine->id => ['supplier_id' => $supplier->id, 'unit_price' => 1000]],
        ])->assertSessionHasErrors();

        $this->assertNull($lineA->fresh()->supplier_id);
        $this->assertNull($otherLine->fresh()->supplier_id);
    }

    public function test_requester_without_assign_permission_is_forbidden(): void
    {
        [, $owner, $request, $lineA] = $this->fixture();
        $supplier = $this->makeSupplier();

        $this->actingAs($owner)->patch("/purchase-requests/{$request->id}/assign-suppliers", [
            'lines' => [$lineA->id => ['supplier_id' => $supplier->id, 'unit_price' => 1000]],
        ])->assertForbidden();
    }

    public function test_assignment_is_refused_when_request_is_no_longer_waiting_for_supplier(): void
    {
        [, , $request, $lineA] = $this->fixture();
        $request->update(['status' => PurchaseRequestStatus::WAITING_APPROVAL]);
        $supplier = $this->makeSupplier();

        $this->actingAs($this->gudang())->patch("/purchase-requests/{$request->id}/assign-suppliers", [
            'lines' => [$lineA->id => ['supplier_id' => $supplier->id, 'unit_price' => 1000]],
        ])->assertForbidden();
    }

    public function test_requester_can_no_longer_edit_once_a_supplier_is_assigned(): void
    {
        [, $owner, $request, $lineA, $lineB] = $this->fixture();
        $supplier = $this->makeSupplier();

        $this->actingAs($this->gudang())->patch("/purchase-requests/{$request->id}/assign-suppliers", [
            'lines' => [$lineA->id => ['supplier_id' => $supplier->id, 'unit_price' => 1000]],
        ]);

        $this->actingAs($owner)->put("/purchase-requests/{$request->id}", [
            'request_date' => '2026-11-14',
            'lines' => [['sparepart_branch_id' => $lineA->sparepart_branch_id, 'qty' => 1]],
        ])->assertForbidden();
        $this->actingAs($owner)->patch("/purchase-requests/{$request->id}/cancel")->assertForbidden();
    }

    public function test_show_renders_assignment_form_for_warehouse_head_and_read_only_for_requester(): void
    {
        [, $owner, $request, $lineA] = $this->fixture();
        $this->makeSupplier('SUP-001', ['name' => 'PT Sumber Oli Jaya']);
        $lineA->update(['supplier_id' => \App\Models\Supplier::first()->id, 'unit_price' => 85000, 'line_total' => 10200000]);

        $this->actingAs($this->gudang())->get("/purchase-requests/{$request->id}")
            ->assertOk()
            ->assertSee('Simpan Draft')
            ->assertSee("lines[{$lineA->id}][supplier_id]", false)
            ->assertSee('PT Sumber Oli Jaya')
            ->assertSee('belum lengkap');

        $this->actingAs($owner)->get("/purchase-requests/{$request->id}")
            ->assertOk()
            ->assertDontSee('Simpan Draft')
            ->assertSee('PT Sumber Oli Jaya');
    }
}
