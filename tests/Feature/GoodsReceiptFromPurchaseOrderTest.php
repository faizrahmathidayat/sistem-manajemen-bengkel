<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\PurchaseOrderGenerator;
use App\Support\GoodsReceiptStatus;
use App\Support\PurchaseOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class GoodsReceiptFromPurchaseOrderTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function receiver(Branch $branch, array $codes = ['receipt.view', 'receipt.create', 'receipt.post', 'receipt.cancel']): User
    {
        $user = User::factory()->create();
        foreach ($codes as $code) {
            $this->grantBranch($user, $branch, $code);
        }

        return User::find($user->id);
    }

    /** An issued PO with two lines: 120 oli @ 85.000 and 60 filter @ 45.000. */
    protected function issuedOrder(?Branch $branch = null, string $status = PurchaseOrderStatus::ISSUED): PurchaseOrder
    {
        $branch = $branch ?: $this->makeBranch('BKS');
        $supplier = $this->makeSupplier();
        $request = $this->makePurchaseRequest($branch, [
            ['sparepart_branch_id' => $this->makeSparepartBranch($branch)->id, 'qty' => 120, 'supplier_id' => $supplier->id, 'unit_price' => 85000],
            ['sparepart_branch_id' => $this->makeSparepartBranch($branch)->id, 'qty' => 60, 'supplier_id' => $supplier->id, 'unit_price' => 45000],
        ]);
        $order = (new PurchaseOrderGenerator())->generateFor($request)->first();
        $order->update(['status' => $status]);

        return $order->fresh(['lines', 'supplier', 'branch']);
    }

    protected function payload(PurchaseOrder $order, array $qtyByLineIndex, array $extra = []): array
    {
        $lines = [];
        foreach ($order->lines as $index => $line) {
            $lines[] = ['purchase_order_line_id' => $line->id, 'qty' => $qtyByLineIndex[$index] ?? 0];
        }

        return array_merge([
            'purchase_order_id' => $order->id,
            'receipt_date' => '2026-11-20',
            'reference_number' => 'SJ-8870',
            'lines' => $lines,
        ], $extra);
    }

    public function test_cannot_create_receipt_without_purchase_order(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->issuedOrder($bks);

        $this->actingAs($this->receiver($bks))->post('/goods-receipts', [
            'branch_id' => $bks->id,
            'receipt_date' => '2026-11-20',
            'lines' => [['sparepart_branch_id' => $order->lines[0]->sparepart_branch_id, 'qty' => 1, 'purchase_price' => 1000]],
        ])->assertSessionHasErrors('purchase_order_id');

        $this->assertSame(0, GoodsReceipt::count());
    }

    public function test_create_from_issued_po_copies_prices_and_takes_branch_from_po(): void
    {
        $bks = $this->makeBranch('BKS');
        $utm = $this->makeBranch('UTM', 'Cabang Utama');
        $order = $this->issuedOrder($bks);

        $this->actingAs($this->receiver($bks))->post('/goods-receipts', $this->payload($order, [40, 0], [
            'branch_id' => $utm->id,                  // must be ignored
            'lines' => [
                ['purchase_order_line_id' => $order->lines[0]->id, 'qty' => 40, 'purchase_price' => 1],  // price must be ignored
                ['purchase_order_line_id' => $order->lines[1]->id, 'qty' => 0],
            ],
        ]))->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::with('lines')->firstOrFail();
        $this->assertSame($bks->id, (int) $receipt->branch_id);
        $this->assertSame($order->id, (int) $receipt->purchase_order_id);
        $this->assertSame('SJ-8870', $receipt->reference_number);
        $this->assertSame(GoodsReceiptStatus::DRAFT, $receipt->status);
        $this->assertStringStartsWith('PB/BKS/', $receipt->number);
        $this->assertCount(1, $receipt->lines);
        $line = $receipt->lines->first();
        $this->assertSame($order->lines[0]->id, (int) $line->purchase_order_line_id);
        $this->assertSame($order->lines[0]->sparepart_branch_id, (int) $line->sparepart_branch_id);
        $this->assertSame('85000.00', (string) $line->purchase_price);
        $this->assertSame('3400000.00', (string) $line->line_total);
        $this->assertSame('40.000', (string) $line->qty);
    }

    public function test_qty_above_remaining_is_rejected_and_remaining_is_allowed(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->issuedOrder($bks);
        $order->lines[0]->update(['qty_received' => 80]); // 40 left on the first line
        $user = $this->receiver($bks);

        $this->actingAs($user)->post('/goods-receipts', $this->payload($order, [41, 0]))
            ->assertSessionHasErrors('lines.0.qty');
        $this->assertSame(0, GoodsReceipt::count());

        $this->actingAs($user)->post('/goods-receipts', $this->payload($order, [40, 0]))->assertSessionHasNoErrors();
        $this->assertSame(1, GoodsReceipt::count());
    }

    public function test_at_least_one_line_with_positive_qty_is_required_and_decimals_are_refused(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->issuedOrder($bks);
        $user = $this->receiver($bks);

        $this->actingAs($user)->post('/goods-receipts', $this->payload($order, [0, 0]))->assertSessionHasErrors('lines');
        $this->actingAs($user)->post('/goods-receipts', $this->payload($order, ['1.5', 0]))->assertSessionHasErrors('lines.0.qty');
        $this->assertSame(0, GoodsReceipt::count());
    }

    public function test_only_issued_or_partially_received_orders_can_be_received(): void
    {
        $bks = $this->makeBranch('BKS');
        $user = $this->receiver($bks);

        foreach ([PurchaseOrderStatus::DRAFT, PurchaseOrderStatus::CANCELLED, PurchaseOrderStatus::COMPLETED] as $status) {
            $order = $this->issuedOrder($bks, $status);
            $this->actingAs($user)->post('/goods-receipts', $this->payload($order, [1, 0]))
                ->assertSessionHasErrors('purchase_order_id');
        }

        $partial = $this->issuedOrder($bks, PurchaseOrderStatus::PARTIALLY_RECEIVED);
        $this->actingAs($user)->post('/goods-receipts', $this->payload($partial, [1, 0]))->assertSessionHasNoErrors();
        $this->assertSame(1, GoodsReceipt::count());
    }

    public function test_user_without_receipt_create_in_the_po_branch_is_forbidden(): void
    {
        $bks = $this->makeBranch('BKS');
        $utm = $this->makeBranch('UTM', 'Cabang Utama');
        $order = $this->issuedOrder($bks);

        $this->actingAs($this->receiver($utm))->post('/goods-receipts', $this->payload($order, [1, 0]))->assertForbidden();
        $this->actingAs($this->receiver($bks, ['receipt.view']))->post('/goods-receipts', $this->payload($order, [1, 0]))->assertForbidden();
    }

    public function test_lines_of_another_order_and_duplicate_lines_are_rejected(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->issuedOrder($bks);
        $other = $this->issuedOrder($bks);
        $user = $this->receiver($bks);

        $this->actingAs($user)->post('/goods-receipts', $this->payload($order, [1, 0], [
            'lines' => [['purchase_order_line_id' => $other->lines[0]->id, 'qty' => 1]],
        ]))->assertSessionHasErrors();

        $this->actingAs($user)->post('/goods-receipts', $this->payload($order, [1, 0], [
            'lines' => [
                ['purchase_order_line_id' => $order->lines[0]->id, 'qty' => 1],
                ['purchase_order_line_id' => $order->lines[0]->id, 'qty' => 1],
            ],
        ]))->assertSessionHasErrors();

        $this->assertSame(0, GoodsReceipt::count());
    }

    public function test_po_lookup_lists_only_receivable_orders_in_the_users_branches(): void
    {
        $bks = $this->makeBranch('BKS');
        $utm = $this->makeBranch('UTM', 'Cabang Utama');
        $mine = $this->issuedOrder($bks);
        $draft = $this->issuedOrder($bks, PurchaseOrderStatus::DRAFT);
        $foreign = $this->issuedOrder($utm);
        $user = $this->receiver($bks);

        $ids = collect($this->actingAs($user)->getJson('/goods-receipts/lookup/purchase-orders?q=BKS')->assertOk()->json())->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_po_lines_endpoint_returns_ordered_received_remaining_and_price(): void
    {
        $bks = $this->makeBranch('BKS');
        $utm = $this->makeBranch('UTM', 'Cabang Utama');
        $order = $this->issuedOrder($bks);
        $order->lines[0]->update(['qty_received' => 80]);

        $json = $this->actingAs($this->receiver($bks))->getJson("/goods-receipts/po-lines/{$order->id}")->assertOk()->json();

        $this->assertSame(2, count($json));
        $this->assertSame($order->lines[0]->id, $json[0]['purchase_order_line_id']);
        $this->assertSame(120.0, (float) $json[0]['ordered']);
        $this->assertSame(80.0, (float) $json[0]['received']);
        $this->assertSame(40.0, (float) $json[0]['remaining']);
        $this->assertSame(85000.0, (float) $json[0]['unit_price']);

        $this->actingAs($this->receiver($utm))->getJson("/goods-receipts/po-lines/{$order->id}")->assertForbidden();
    }

    public function test_update_changes_qty_and_header_but_never_prices_or_remaining_limit(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->issuedOrder($bks);
        $user = $this->receiver($bks);
        $this->actingAs($user)->post('/goods-receipts', $this->payload($order, [10, 5]));
        $receipt = GoodsReceipt::firstOrFail();

        $this->actingAs($user)->put("/goods-receipts/{$receipt->id}", [
            'receipt_date' => '2026-11-21',
            'reference_number' => 'SJ-9999',
            'lines' => [
                ['purchase_order_line_id' => $order->lines[0]->id, 'qty' => 25, 'purchase_price' => 1],
                ['purchase_order_line_id' => $order->lines[1]->id, 'qty' => 0],
            ],
        ])->assertSessionHasNoErrors();

        $receipt->refresh()->load('lines');
        $this->assertSame('SJ-9999', $receipt->reference_number);
        $this->assertCount(1, $receipt->lines);
        $this->assertSame('25.000', (string) $receipt->lines->first()->qty);
        $this->assertSame('85000.00', (string) $receipt->lines->first()->purchase_price);

        $this->actingAs($user)->put("/goods-receipts/{$receipt->id}", [
            'receipt_date' => '2026-11-21',
            'lines' => [['purchase_order_line_id' => $order->lines[0]->id, 'qty' => 121]],
        ])->assertSessionHasErrors('lines.0.qty');
    }

    public function test_legacy_receipt_without_po_stays_viewable_editable_and_listed_with_dash(): void
    {
        $bks = $this->makeBranch('BKS');
        $user = $this->receiver($bks);
        $sp = $this->makeSparepartBranch($bks);
        $legacy = GoodsReceipt::create([
            'number' => 'PB/BKS/202608/00003', 'branch_id' => $bks->id, 'receipt_date' => '2026-08-14',
            'reference_number' => 'NB-2210',
        ]);
        $legacy->lines()->create(['sparepart_branch_id' => $sp->id, 'qty' => 5, 'purchase_price' => 40000, 'line_total' => 200000, 'sort_order' => 0]);

        $this->actingAs($user)->get('/goods-receipts')->assertOk()->assertSee('PB/BKS/202608/00003')->assertSee('data lama');
        $this->actingAs($user)->get("/goods-receipts/{$legacy->id}")->assertOk()->assertSee('NB-2210');
        $this->actingAs($user)->get("/goods-receipts/{$legacy->id}/edit")->assertOk()->assertSee('Tambah Sparepart');

        $this->actingAs($user)->put("/goods-receipts/{$legacy->id}", [
            'receipt_date' => '2026-08-15', 'reference_number' => 'NB-2211',
            'lines' => [['sparepart_branch_id' => $sp->id, 'qty' => 7, 'purchase_price' => 41000]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('7.000', (string) $legacy->fresh()->lines->first()->qty);
    }

    public function test_create_form_has_po_picker_and_no_import_controls_but_import_endpoint_still_works(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->issuedOrder($bks);
        $user = $this->receiver($bks);

        $this->actingAs($user)->get('/goods-receipts/create')->assertOk()
            ->assertSee('Pembelian (PO)')->assertSee('Sudah Diterima')->assertSee('Qty Diterima Sekarang')
            ->assertDontSee('Import Baris')->assertDontSee('Download Template');
        $this->actingAs($user)->get('/goods-receipts/create?purchase_order_id=' . $order->id)->assertOk()->assertSee($order->number);
        $this->actingAs($user)->get('/goods-receipts/import-template')->assertOk();
    }

    public function test_show_and_index_display_supplier_and_purchase_order_number(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->issuedOrder($bks);
        $user = $this->receiver($bks);
        $this->actingAs($user)->post('/goods-receipts', $this->payload($order, [10, 0]));
        $receipt = GoodsReceipt::firstOrFail();
        $this->grantGlobal($user, 'purchase_order.view');

        $this->actingAs($user)->get("/goods-receipts/{$receipt->id}")->assertOk()
            ->assertSee($order->number)->assertSee($order->supplier->name)->assertSee('Dipesan')->assertSee('Sisa');
        $this->actingAs($user)->get('/goods-receipts')->assertOk()
            ->assertSee($order->supplier->name)->assertSee($order->number);
        $this->actingAs($user)->get('/goods-receipts?q=' . substr($order->number, 0, 12))->assertOk()->assertSee($receipt->number);
        $this->actingAs($user)->get("/goods-receipts/{$receipt->id}/edit")->assertOk()->assertSee('Qty Diterima Sekarang');
    }
    public function test_po_detail_shows_receive_button_only_for_receivable_orders_and_receivers(): void
    {
        $bks = $this->makeBranch('BKS');
        $issued = $this->issuedOrder($bks);
        $draft = $this->issuedOrder($bks, PurchaseOrderStatus::DRAFT);
        $receiver = $this->receiver($bks);
        $this->grantGlobal($receiver, 'purchase_order.view');
        $viewerOnly = User::factory()->create();
        $this->grantGlobal($viewerOnly, 'purchase_order.view');
        $url = route('goods-receipts.create', ['purchase_order_id' => $issued->id]);

        $this->actingAs($receiver)->get("/purchase-orders/{$issued->id}")->assertOk()->assertSee('Terima Barang')->assertSee($url, false);
        $this->actingAs($receiver)->get("/purchase-orders/{$draft->id}")->assertOk()->assertDontSee('Terima Barang');
        $this->actingAs(User::find($viewerOnly->id))->get("/purchase-orders/{$issued->id}")->assertOk()->assertDontSee('Terima Barang');
    }
}
