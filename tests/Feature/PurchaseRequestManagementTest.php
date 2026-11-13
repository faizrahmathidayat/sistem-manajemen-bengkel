<?php

namespace Tests\Feature;

use App\Models\PurchaseRequest;
use App\Models\SparepartBranchStock;
use App\Models\User;
use App\Support\PurchaseRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseRequestManagementTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function requester($branch, array $codes = ['purchase_request.view', 'purchase_request.create']): User
    {
        $user = User::factory()->create();
        foreach ($codes as $code) {
            $this->grantBranch($user, $branch, $code);
        }

        return User::find($user->id);
    }

    protected function payload($branch, array $lines, array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $branch->id,
            'request_date' => '2026-11-13',
            'notes' => 'Stok menipis',
            'lines' => $lines,
        ], $overrides);
    }

    public function test_requester_creates_request_with_number_status_and_unassigned_lines(): void
    {
        $bks = $this->makeBranch('BKS');
        $a = $this->makeSparepartBranch($bks);
        $b = $this->makeSparepartBranch($bks);
        $owner = $this->requester($bks);

        $response = $this->actingAs($owner)->post('/purchase-requests', $this->payload($bks, [
            ['sparepart_branch_id' => $a->id, 'qty' => 120],
            ['sparepart_branch_id' => $b->id, 'qty' => 60],
        ]));

        $request = PurchaseRequest::with('lines')->firstOrFail();
        $response->assertRedirect(route('purchase-requests.show', $request));
        $this->assertStringStartsWith('RQ/BKS/', $request->number);
        $this->assertSame(PurchaseRequestStatus::WAITING_SUPPLIER, $request->status);
        $this->assertSame($owner->id, (int) $request->created_by);
        $this->assertCount(2, $request->lines);
        $this->assertNull($request->lines->first()->supplier_id);
        $this->assertNull($request->lines->first()->unit_price);
    }

    public function test_store_is_forbidden_without_create_permission_in_that_branch(): void
    {
        $bks = $this->makeBranch('BKS');
        $utm = $this->makeBranch('UTM', 'Cabang Utama');
        $sp = $this->makeSparepartBranch($bks);
        $user = $this->requester($utm);

        $this->actingAs($user)->post('/purchase-requests', $this->payload($bks, [
            ['sparepart_branch_id' => $sp->id, 'qty' => 1],
        ]))->assertForbidden();
    }

    public function test_store_rejects_duplicate_zero_decimal_and_foreign_branch_lines(): void
    {
        $bks = $this->makeBranch('BKS');
        $utm = $this->makeBranch('UTM', 'Cabang Utama');
        $a = $this->makeSparepartBranch($bks);
        $foreign = $this->makeSparepartBranch($utm);
        $owner = $this->requester($bks);

        $this->actingAs($owner)->post('/purchase-requests', $this->payload($bks, [
            ['sparepart_branch_id' => $a->id, 'qty' => 1],
            ['sparepart_branch_id' => $a->id, 'qty' => 2],
        ]))->assertSessionHasErrors();

        $this->actingAs($owner)->post('/purchase-requests', $this->payload($bks, [
            ['sparepart_branch_id' => $a->id, 'qty' => 0],
        ]))->assertSessionHasErrors('lines.0.qty');

        $this->actingAs($owner)->post('/purchase-requests', $this->payload($bks, [
            ['sparepart_branch_id' => $a->id, 'qty' => '1.5'],
        ]))->assertSessionHasErrors('lines.0.qty');

        $this->actingAs($owner)->post('/purchase-requests', $this->payload($bks, [
            ['sparepart_branch_id' => $foreign->id, 'qty' => 1],
        ]))->assertSessionHasErrors('lines.0.sparepart_branch_id');

        $this->actingAs($owner)->post('/purchase-requests', $this->payload($bks, []))
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, PurchaseRequest::count());
    }

    public function test_index_shows_own_branch_only_but_view_all_sees_every_branch(): void
    {
        $bks = $this->makeBranch('BKS');
        $utm = $this->makeBranch('UTM', 'Cabang Utama');
        $mine = $this->actingAs($this->requester($bks))->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 1],
        ], ['number' => 'RQ/BKS/202611/00001']);
        $other = $this->makePurchaseRequest($utm, [
            ['sparepart_branch_id' => $this->makeSparepartBranch($utm)->id, 'qty' => 1],
        ], ['number' => 'RQ/UTM/202611/00001']);

        $owner = $this->requester($bks);
        $this->actingAs($owner)->get('/purchase-requests')->assertOk()
            ->assertSee('RQ/BKS/202611/00001')->assertDontSee('RQ/UTM/202611/00001');

        $gudang = User::factory()->create();
        $this->grantGlobal($gudang, 'purchase_request.view_all');
        $this->actingAs(User::find($gudang->id))->get('/purchase-requests')->assertOk()
            ->assertSee('RQ/BKS/202611/00001')->assertSee('RQ/UTM/202611/00001');
    }

    public function test_index_filters_by_search_and_status_and_survives_array_input(): void
    {
        $bks = $this->makeBranch('BKS');
        $owner = $this->requester($bks);
        $this->actingAs($owner);
        $this->makePurchaseRequest($bks, [['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 1]], ['number' => 'RQ/BKS/202611/00001']);
        $this->makePurchaseRequest($bks, [['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 1]], ['number' => 'RQ/BKS/202611/00002', 'status' => PurchaseRequestStatus::CANCELLED]);

        $this->get('/purchase-requests?q=00002')->assertOk()->assertSee('00002')->assertDontSee('RQ/BKS/202611/00001');
        $this->get('/purchase-requests?status=cancelled')->assertOk()->assertSee('00002')->assertDontSee('RQ/BKS/202611/00001');
        $this->get('/purchase-requests?q[]=x&status[]=y&branch_ids=oops')->assertOk();
    }

    public function test_index_renders_no_access_view_for_user_without_any_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/purchase-requests')
            ->assertOk()->assertViewIs('purchase-requests.no-access');
    }

    public function test_create_edit_and_show_pages_render(): void
    {
        $bks = $this->makeBranch('BKS');
        $owner = $this->requester($bks);
        $request = $this->actingAs($owner)->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 7],
        ]);

        $this->get('/purchase-requests/create')->assertOk()->assertSee('Simpan Permintaan');
        $this->get("/purchase-requests/{$request->id}/edit")->assertOk()->assertSee('Barang yang Diminta');
        $this->get("/purchase-requests/{$request->id}")->assertOk()->assertSee($request->number)->assertSee('Menunggu Supplier');
    }

    public function test_update_replaces_lines_and_header_fields(): void
    {
        $bks = $this->makeBranch('BKS');
        $a = $this->makeSparepartBranch($bks);
        $b = $this->makeSparepartBranch($bks);
        $owner = $this->requester($bks);
        $request = $this->actingAs($owner)->makePurchaseRequest($bks, [['sparepart_branch_id' => $a->id, 'qty' => 2]]);

        $this->put("/purchase-requests/{$request->id}", [
            'request_date' => '2026-11-14',
            'notes' => 'Revisi',
            'lines' => [['sparepart_branch_id' => $b->id, 'qty' => 9]],
        ])->assertRedirect(route('purchase-requests.show', $request));

        $request->refresh()->load('lines');
        $this->assertSame('Revisi', $request->notes);
        $this->assertCount(1, $request->lines);
        $this->assertSame($b->id, (int) $request->lines->first()->sparepart_branch_id);
        $this->assertSame('9.000', (string) $request->lines->first()->qty);
    }

    public function test_update_is_refused_after_a_supplier_was_assigned(): void
    {
        $bks = $this->makeBranch('BKS');
        $a = $this->makeSparepartBranch($bks);
        $owner = $this->requester($bks);
        $supplier = $this->makeSupplier();
        $request = $this->actingAs($owner)->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $a->id, 'qty' => 2, 'supplier_id' => $supplier->id],
        ]);

        $this->put("/purchase-requests/{$request->id}", [
            'request_date' => '2026-11-14',
            'lines' => [['sparepart_branch_id' => $a->id, 'qty' => 99]],
        ])->assertForbidden();

        $this->assertSame('2.000', (string) $request->fresh()->lines->first()->qty);
    }

    public function test_cancel_marks_cancelled_and_writes_audit_log(): void
    {
        $bks = $this->makeBranch('BKS');
        $owner = $this->requester($bks);
        $request = $this->actingAs($owner)->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 1],
        ]);

        $this->patch("/purchase-requests/{$request->id}/cancel")
            ->assertRedirect(route('purchase-requests.show', $request));

        $this->assertSame(PurchaseRequestStatus::CANCELLED, $request->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'purchase_request.cancelled', 'auditable_id' => $request->id]);

        // Second cancel is a no-op / refused, never an error or a second audit row.
        $this->patch("/purchase-requests/{$request->id}/cancel")->assertStatus(403);
        $this->assertSame(1, \App\Models\AuditLog::where('event', 'purchase_request.cancelled')->count());
    }

    public function test_sparepart_lookup_requires_create_in_branch_and_returns_stock(): void
    {
        $bks = $this->makeBranch('BKS');
        $utm = $this->makeBranch('UTM', 'Cabang Utama');
        $sp = $this->makeSparepartBranch($bks, 'OLI-001');
        SparepartBranchStock::where('sparepart_branch_id', $sp->id)->update(['on_hand_qty' => 14]);
        $owner = $this->requester($bks);

        $json = $this->actingAs($owner)->getJson('/purchase-requests/lookup/spareparts?branch_id=' . $bks->id . '&q=OLI')
            ->assertOk()->json();
        $this->assertSame($sp->id, $json[0]['id']);
        $this->assertSame(14.0, (float) $json[0]['on_hand_qty']);

        $this->getJson('/purchase-requests/lookup/spareparts?branch_id=' . $utm->id . '&q=OLI')->assertForbidden();
        $this->getJson('/purchase-requests/lookup/spareparts?q=OLI')->assertStatus(400);
    }
}
