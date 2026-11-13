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
        $sp->update(['minimum_stock' => 20]); // below the minimum, so it can be requested
        $owner = $this->requester($bks);

        $json = $this->actingAs($owner)->getJson('/purchase-requests/lookup/spareparts?branch_id=' . $bks->id . '&q=OLI')
            ->assertOk()->json();
        $this->assertSame($sp->id, $json[0]['id']);
        $this->assertSame(14.0, (float) $json[0]['on_hand_qty']);

        $this->getJson('/purchase-requests/lookup/spareparts?branch_id=' . $utm->id . '&q=OLI')->assertForbidden();
        $this->getJson('/purchase-requests/lookup/spareparts?q=OLI')->assertStatus(400);
    }

    protected function setStock(\App\Models\SparepartBranch $sparepartBranch, float $onHand, float $minimum, float $reserved = 0): void
    {
        SparepartBranchStock::where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => $onHand, 'reserved_qty' => $reserved]);
        $sparepartBranch->update(['minimum_stock' => $minimum]);
    }

    public function test_store_only_accepts_spareparts_whose_stock_is_below_the_minimum(): void
    {
        $bks = $this->makeBranch('BKS');
        $below = $this->makeSparepartBranch($bks);
        $atMinimum = $this->makeSparepartBranch($bks);
        $above = $this->makeSparepartBranch($bks);
        $noMinimum = $this->makeSparepartBranch($bks);
        $this->setStock($below, 3, 10);
        $this->setStock($atMinimum, 10, 10);
        $this->setStock($above, 25, 10);
        $this->setStock($noMinimum, 0, 0);
        $owner = $this->requester($bks);

        foreach ([$atMinimum, $above, $noMinimum] as $blocked) {
            $this->actingAs($owner)->post('/purchase-requests', $this->payload($bks, [
                ['sparepart_branch_id' => $blocked->id, 'qty' => 5],
            ]))->assertSessionHasErrors('lines.0.sparepart_branch_id');
        }
        $this->assertSame(0, PurchaseRequest::count());

        $this->actingAs($owner)->post('/purchase-requests', $this->payload($bks, [
            ['sparepart_branch_id' => $below->id, 'qty' => 5],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(1, PurchaseRequest::count());
    }

    public function test_stock_is_measured_as_available_stock_so_reserved_quantity_counts(): void
    {
        $bks = $this->makeBranch('BKS');
        $reservedHeavily = $this->makeSparepartBranch($bks);   // 12 on hand, 5 reserved by PKB -> 7 available
        $plentiful = $this->makeSparepartBranch($bks);         // 12 on hand, none reserved -> 12 available
        $this->setStock($reservedHeavily, 12, 10, 5);
        $this->setStock($plentiful, 12, 10, 0);
        $owner = $this->requester($bks);

        $this->actingAs($owner)->post('/purchase-requests', $this->payload($bks, [
            ['sparepart_branch_id' => $plentiful->id, 'qty' => 5],
        ]))->assertSessionHasErrors('lines.0.sparepart_branch_id');

        $this->actingAs($owner)->post('/purchase-requests', $this->payload($bks, [
            ['sparepart_branch_id' => $reservedHeavily->id, 'qty' => 5],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(1, PurchaseRequest::count());
    }

    public function test_the_stock_rule_message_names_the_stock_and_minimum(): void
    {
        $bks = $this->makeBranch('BKS');
        $sp = $this->makeSparepartBranch($bks);
        $this->setStock($sp, 25, 10);

        $response = $this->actingAs($this->requester($bks))->post('/purchase-requests', $this->payload($bks, [
            ['sparepart_branch_id' => $sp->id, 'qty' => 5],
        ]));

        $message = session('errors')->first('lines.0.sparepart_branch_id');
        $this->assertStringContainsString('stok minimal', $message);
        $this->assertStringContainsString('tersedia', $message);
        $this->assertStringContainsString('25', $message);
        $this->assertStringContainsString('10', $message);
    }

    public function test_update_applies_the_same_stock_rule(): void
    {
        $bks = $this->makeBranch('BKS');
        $below = $this->makeSparepartBranch($bks);
        $above = $this->makeSparepartBranch($bks);
        $this->setStock($below, 1, 10);
        $this->setStock($above, 50, 10);
        $owner = $this->requester($bks);
        $request = $this->actingAs($owner)->makePurchaseRequest($bks, [['sparepart_branch_id' => $below->id, 'qty' => 2]]);

        $this->put("/purchase-requests/{$request->id}", [
            'request_date' => '2026-11-14',
            'lines' => [['sparepart_branch_id' => $above->id, 'qty' => 2]],
        ])->assertSessionHasErrors('lines.0.sparepart_branch_id');

        $this->assertSame($below->id, (int) $request->fresh()->lines->first()->sparepart_branch_id);
    }

    public function test_sparepart_search_lists_only_below_minimum_items_but_ids_still_resolve_any(): void
    {
        $bks = $this->makeBranch('BKS');
        $below = $this->makeSparepartBranch($bks, 'OLI-001');
        $above = $this->makeSparepartBranch($bks, 'OLI-002');
        $this->setStock($below, 2, 10);
        $this->setStock($above, 40, 10);
        $owner = $this->requester($bks);

        $search = $this->actingAs($owner)->getJson('/purchase-requests/lookup/spareparts?branch_id=' . $bks->id . '&q=OLI')->assertOk()->json();
        $this->assertCount(1, $search);
        $this->assertSame($below->id, $search[0]['id']);
        $this->assertSame(10.0, (float) $search[0]['minimum_stock']);
        $this->assertSame(2.0, (float) $search[0]['available_qty']);

        // Re-selecting lines of an existing/old-input request must still resolve, whatever the stock is now.
        $resolved = $this->getJson('/purchase-requests/lookup/spareparts?branch_id=' . $bks->id . '&ids[]=' . $above->id)->assertOk()->json();
        $this->assertSame($above->id, $resolved[0]['id']);
    }

    public function test_the_form_explains_the_stock_rule(): void
    {
        $bks = $this->makeBranch('BKS');

        $this->actingAs($this->requester($bks))->get('/purchase-requests/create')
            ->assertOk()->assertSee('stok di bawah stok minimal');
    }

    /**
     * Stock can rise between form validation and the insert (e.g. a receipt is posted at that
     * moment). The rule is therefore checked again inside the transaction, under a row lock.
     * The listener below plays that "other transaction": it bumps the stock the first time the
     * given query runs, which is after validation passed but before the request is saved.
     */
    protected function raiseStockWhen(string $sqlFragment, int $onHand): void
    {
        $done = false;
        \DB::listen(function ($query) use (&$done, $sqlFragment, $onHand) {
            if (! $done && stripos($query->sql, $sqlFragment) !== false) {
                $done = true;
                \DB::table('sparepart_branch_stocks')->update(['on_hand_qty' => $onHand]);
            }
        });
    }

    public function test_store_rechecks_the_stock_rule_inside_the_transaction_and_saves_nothing_on_a_late_change(): void
    {
        $bks = $this->makeBranch('BKS');
        $sp = $this->makeSparepartBranch($bks);
        $this->setStock($sp, 2, 10);
        $owner = $this->requester($bks);

        $this->raiseStockWhen('document_number_sequences', 50);
        $response = $this->actingAs($owner)->post('/purchase-requests', $this->payload($bks, [
            ['sparepart_branch_id' => $sp->id, 'qty' => 5],
        ]));

        $response->assertSessionHasErrors('lines.0.sparepart_branch_id');
        $this->assertSame(0, PurchaseRequest::count());
        $this->assertSame(0, \App\Models\PurchaseRequestLine::count());
    }

    public function test_update_rechecks_the_stock_rule_inside_the_transaction_and_keeps_the_old_lines(): void
    {
        $bks = $this->makeBranch('BKS');
        $old = $this->makeSparepartBranch($bks);
        $new = $this->makeSparepartBranch($bks);
        $this->setStock($old, 1, 10);
        $this->setStock($new, 2, 10);
        $owner = $this->requester($bks);
        $request = $this->actingAs($owner)->makePurchaseRequest($bks, [['sparepart_branch_id' => $old->id, 'qty' => 3]]);

        $this->raiseStockWhen('limit 1 for update', 50);
        $this->put("/purchase-requests/{$request->id}", [
            'request_date' => '2026-11-14',
            'lines' => [['sparepart_branch_id' => $new->id, 'qty' => 4]],
        ])->assertSessionHasErrors('lines.0.sparepart_branch_id');

        $lines = $request->fresh()->lines;
        $this->assertCount(1, $lines);
        $this->assertSame($old->id, (int) $lines->first()->sparepart_branch_id);
    }
}
