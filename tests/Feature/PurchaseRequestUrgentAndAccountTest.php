<?php

namespace Tests\Feature;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\PurchaseRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseRequestUrgentAndAccountTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function requester($branch): User
    {
        $user = User::factory()->create();
        $this->grantBranch($user, $branch, 'purchase_request.view');
        $this->grantBranch($user, $branch, 'purchase_request.create');

        return User::find($user->id);
    }

    protected function gudang(): User
    {
        $user = User::factory()->create();
        $this->grantGlobal($user, 'purchase_request.assign_supplier');

        return User::find($user->id);
    }

    public function test_request_is_not_urgent_by_default_and_can_be_marked_urgent_on_create(): void
    {
        $bks = $this->makeBranch('BKS');
        $sp = $this->makeSparepartBranch($bks);
        $owner = $this->requester($bks);
        $payload = ['branch_id' => $bks->id, 'request_date' => '2026-11-13', 'lines' => [['sparepart_branch_id' => $sp->id, 'qty' => 3]]];

        $this->actingAs($owner)->post('/purchase-requests', $payload)->assertRedirect();
        $this->assertFalse((bool) PurchaseRequest::firstOrFail()->is_urgent);

        $this->actingAs($owner)->post('/purchase-requests', $payload + ['is_urgent' => 1])->assertRedirect();
        $this->assertTrue((bool) PurchaseRequest::orderByDesc('id')->first()->is_urgent);
    }

    public function test_urgent_flag_can_be_changed_while_editing_and_is_shown(): void
    {
        $bks = $this->makeBranch('BKS');
        $sp = $this->makeSparepartBranch($bks);
        $owner = $this->requester($bks);
        $request = $this->actingAs($owner)->makePurchaseRequest($bks, [['sparepart_branch_id' => $sp->id, 'qty' => 3]]);
        $lineKey = ['sparepart_branch_id' => $sp->id, 'qty' => 3];

        $this->actingAs($owner)->get("/purchase-requests/{$request->id}")->assertOk()->assertDontSee('URGENT');

        $this->actingAs($owner)->put("/purchase-requests/{$request->id}", [
            'request_date' => '2026-11-13', 'is_urgent' => 1, 'lines' => [$lineKey],
        ])->assertRedirect();
        $this->assertTrue((bool) $request->fresh()->is_urgent);

        $this->actingAs($owner)->get("/purchase-requests/{$request->id}")->assertOk()->assertSee('URGENT');
        $this->actingAs($owner)->get('/purchase-requests')->assertOk()->assertSee('URGENT');

        $this->actingAs($owner)->put("/purchase-requests/{$request->id}", [
            'request_date' => '2026-11-13', 'lines' => [$lineKey],
        ])->assertRedirect();
        $this->assertFalse((bool) $request->fresh()->is_urgent);
    }

    public function test_supplier_account_number_is_saved_on_the_master(): void
    {
        $admin = User::factory()->create();
        $this->grantGlobal($admin, 'supplier.view');
        $this->grantGlobal($admin, 'supplier.create');
        $this->grantGlobal($admin, 'supplier.edit');
        $admin = User::find($admin->id);

        $this->actingAs($admin)->post('/suppliers', [
            'code' => 'SUP-77', 'name' => 'PT Rekening', 'account_number' => 'BCA 123-456-7890',
        ])->assertRedirect();

        $supplier = \App\Models\Supplier::where('code', 'SUP-77')->firstOrFail();
        $this->assertSame('BCA 123-456-7890', $supplier->account_number);

        $this->actingAs($admin)->put("/suppliers/{$supplier->id}", [
            'code' => 'SUP-77', 'name' => 'PT Rekening', 'account_number' => 'BCA 999', 'is_active' => 1,
        ])->assertRedirect();
        $this->assertSame('BCA 999', $supplier->fresh()->account_number);
    }

    public function test_assigning_stores_account_number_on_the_line_and_falls_back_to_the_master(): void
    {
        $bks = $this->makeBranch('BKS');
        $owner = $this->requester($bks);
        $a = $this->makeSparepartBranch($bks);
        $b = $this->makeSparepartBranch($bks);
        $request = $this->actingAs($owner)->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $a->id, 'qty' => 5],
            ['sparepart_branch_id' => $b->id, 'qty' => 5],
        ]);
        $supplier = $this->makeSupplier('SUP-1', ['account_number' => 'BCA 111']);
        [$lineA, $lineB] = [$request->lines[0], $request->lines[1]];

        $this->actingAs($this->gudang())->patch("/purchase-requests/{$request->id}/assign-suppliers", ['lines' => [
            $lineA->id => ['supplier_id' => $supplier->id, 'unit_price' => 1000, 'supplier_account_number' => 'Mandiri 222'],
            $lineB->id => ['supplier_id' => $supplier->id, 'unit_price' => 1000, 'supplier_account_number' => ''],
        ]])->assertRedirect();

        $this->assertSame('Mandiri 222', $lineA->fresh()->supplier_account_number);
        $this->assertSame('BCA 111', $lineB->fresh()->supplier_account_number);
        $this->assertSame('BCA 111', $supplier->fresh()->account_number); // the master is not overwritten

        // still editable while no approval has been requested
        $this->actingAs($this->gudang())->patch("/purchase-requests/{$request->id}/assign-suppliers", ['lines' => [
            $lineA->id => ['supplier_id' => $supplier->id, 'unit_price' => 1000, 'supplier_account_number' => 'BNI 333'],
        ]])->assertRedirect();
        $this->assertSame('BNI 333', $lineA->fresh()->supplier_account_number);
    }

    public function test_account_number_is_cleared_when_the_supplier_is_removed_and_length_is_validated(): void
    {
        $bks = $this->makeBranch('BKS');
        $sp = $this->makeSparepartBranch($bks);
        $supplier = $this->makeSupplier('SUP-2');
        $request = $this->actingAs($this->requester($bks))->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $sp->id, 'qty' => 5, 'supplier_id' => $supplier->id, 'unit_price' => 10],
        ]);
        $line = $request->lines[0];
        $line->update(['supplier_account_number' => 'BCA 1']);

        $this->actingAs($this->gudang())->patch("/purchase-requests/{$request->id}/assign-suppliers", ['lines' => [
            $line->id => ['supplier_id' => $supplier->id, 'unit_price' => 10, 'supplier_account_number' => str_repeat('9', 61)],
        ]])->assertSessionHasErrors("lines.{$line->id}.supplier_account_number");

        $this->actingAs($this->gudang())->patch("/purchase-requests/{$request->id}/assign-suppliers", ['lines' => [
            $line->id => ['supplier_id' => '', 'unit_price' => '', 'supplier_account_number' => 'BCA 1'],
        ]])->assertRedirect();
        $this->assertNull($line->fresh()->supplier_account_number);
    }

    public function test_purchase_order_carries_the_account_number_from_the_request_lines(): void
    {
        $bks = $this->makeBranch('BKS');
        $sp = $this->makeSparepartBranch($bks);
        $supplier = $this->makeSupplier('SUP-3');
        $request = $this->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $sp->id, 'qty' => 5, 'supplier_id' => $supplier->id, 'unit_price' => 10],
        ]);
        $request->lines[0]->update(['supplier_account_number' => 'BCA 777']);

        $order = (new \App\Services\PurchaseOrderGenerator())->generateFor($request->fresh())->first();

        $this->assertSame('BCA 777', $order->fresh()->supplier_account_number);
    }
}
