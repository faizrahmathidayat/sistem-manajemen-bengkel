<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\SparepartBranchStock;
use App\Models\User;
use App\Support\GoodsReceiptStatus;
use App\Support\PurchaseOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class GoodsReceiptPostingProcurementTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function receiver(Branch $branch): User
    {
        $user = User::factory()->create();
        foreach (['receipt.view', 'receipt.create', 'receipt.post', 'receipt.cancel'] as $code) {
            $this->grantBranch($user, $branch, $code);
        }

        return User::find($user->id);
    }

    /** Creates a draft receipt through the real form endpoint. */
    protected function draftReceipt(User $user, PurchaseOrder $order, array $qtyByLine): GoodsReceipt
    {
        $lines = [];
        foreach ($order->lines as $index => $line) {
            $lines[] = ['purchase_order_line_id' => $line->id, 'qty' => $qtyByLine[$index] ?? 0];
        }
        $this->actingAs($user)->post('/goods-receipts', [
            'purchase_order_id' => $order->id,
            'receipt_date' => '2026-11-20',
            'reference_number' => 'SJ-1',
            'lines' => $lines,
        ])->assertSessionHasNoErrors();

        return GoodsReceipt::orderByDesc('id')->firstOrFail();
    }

    protected function stock($line): SparepartBranchStock
    {
        return SparepartBranchStock::where('sparepart_branch_id', $line->sparepart_branch_id)->firstOrFail();
    }

    public function test_posting_adds_stock_average_cost_ledger_and_updates_po_progress(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->makeIssuedPurchaseOrder($bks);
        $user = $this->receiver($bks);
        $receipt = $this->draftReceipt($user, $order, [80, 60]);

        $this->actingAs($user)->patch("/goods-receipts/{$receipt->id}/post")
            ->assertRedirect(route('goods-receipts.show', $receipt));

        $this->assertSame(GoodsReceiptStatus::POSTED, $receipt->fresh()->status);
        $stock = $this->stock($order->lines[0]);
        $this->assertSame('80.000', (string) $stock->on_hand_qty);
        $this->assertSame('85000.0000', number_format((float) $stock->average_cost, 4, '.', ''));
        $movement = InventoryMovement::where('sparepart_branch_id', $order->lines[0]->sparepart_branch_id)->firstOrFail();
        $this->assertSame('receipt', $movement->movement_type);
        $this->assertSame('80.000', (string) $movement->qty_in);

        $order->refresh();
        $this->assertSame('80.000', (string) $order->lines[0]->fresh()->qty_received);
        $this->assertSame('60.000', (string) $order->lines[1]->fresh()->qty_received);
        $this->assertSame(PurchaseOrderStatus::PARTIALLY_RECEIVED, $order->status);
    }

    public function test_posting_the_remaining_quantity_completes_the_purchase_order(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->makeIssuedPurchaseOrder($bks);
        $user = $this->receiver($bks);

        $first = $this->draftReceipt($user, $order, [80, 60]);
        $this->actingAs($user)->patch("/goods-receipts/{$first->id}/post");
        $second = $this->draftReceipt($user, $order->fresh('lines'), [40, 0]);
        $this->actingAs($user)->patch("/goods-receipts/{$second->id}/post");

        $this->assertSame(PurchaseOrderStatus::COMPLETED, $order->fresh()->status);
        $this->assertSame('120.000', (string) $order->lines[0]->fresh()->qty_received);
        $this->assertSame('120.000', (string) $this->stock($order->lines[0])->on_hand_qty);
    }

    public function test_second_draft_exceeding_remaining_is_rejected_after_first_posted_and_stock_is_unchanged(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->makeIssuedPurchaseOrder($bks);
        $user = $this->receiver($bks);

        // Both drafts are valid when created (120 open each time): drafts reserve nothing.
        $first = $this->draftReceipt($user, $order, [80, 0]);
        $second = $this->draftReceipt($user, $order, [80, 0]);

        $this->actingAs($user)->patch("/goods-receipts/{$first->id}/post");
        $response = $this->actingAs($user)->patch("/goods-receipts/{$second->id}/post");

        $response->assertRedirect(route('goods-receipts.show', $second));
        $this->assertStringContainsString('melebihi sisa', session('error'));
        $this->assertSame(GoodsReceiptStatus::DRAFT, $second->fresh()->status);
        $this->assertSame('80.000', (string) $this->stock($order->lines[0])->on_hand_qty);
        $this->assertSame('80.000', (string) $order->lines[0]->fresh()->qty_received);
        $this->assertSame(1, InventoryMovement::where('sparepart_branch_id', $order->lines[0]->sparepart_branch_id)->count());
    }

    public function test_posting_is_refused_when_the_purchase_order_is_no_longer_receivable(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->makeIssuedPurchaseOrder($bks);
        $user = $this->receiver($bks);
        $receipt = $this->draftReceipt($user, $order, [10, 0]);
        $order->update(['status' => PurchaseOrderStatus::CANCELLED]);

        $this->actingAs($user)->patch("/goods-receipts/{$receipt->id}/post")->assertRedirect();

        $this->assertNotNull(session('error'));
        $this->assertSame(GoodsReceiptStatus::DRAFT, $receipt->fresh()->status);
        $this->assertSame('0.000', (string) $this->stock($order->lines[0])->on_hand_qty);
    }

    public function test_posting_twice_changes_nothing_the_second_time(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->makeIssuedPurchaseOrder($bks);
        $user = $this->receiver($bks);
        $receipt = $this->draftReceipt($user, $order, [10, 0]);

        $this->actingAs($user)->patch("/goods-receipts/{$receipt->id}/post");
        $this->actingAs($user)->patch("/goods-receipts/{$receipt->id}/post")->assertForbidden();

        $this->assertSame('10.000', (string) $this->stock($order->lines[0])->on_hand_qty);
        $this->assertSame('10.000', (string) $order->lines[0]->fresh()->qty_received);
    }

    public function test_purchase_order_cannot_be_cancelled_after_a_partial_receipt_was_posted(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->makeIssuedPurchaseOrder($bks);
        $user = $this->receiver($bks);
        $receipt = $this->draftReceipt($user, $order, [10, 0]);
        $this->actingAs($user)->patch("/goods-receipts/{$receipt->id}/post");

        $buyer = User::factory()->create();
        foreach (['purchase_order.view', 'purchase_order.cancel'] as $code) {
            $this->grantGlobal($buyer, $code);
        }

        $this->actingAs(User::find($buyer->id))->patch("/purchase-orders/{$order->id}/cancel")->assertForbidden();
        $this->assertSame(PurchaseOrderStatus::PARTIALLY_RECEIVED, $order->fresh()->status);
    }

    public function test_legacy_receipt_posting_is_unchanged_and_never_touches_purchase_orders(): void
    {
        $bks = $this->makeBranch('BKS');
        $order = $this->makeIssuedPurchaseOrder($bks);
        $sp = $this->makeSparepartBranch($bks);
        $user = $this->receiver($bks);
        $legacy = GoodsReceipt::create(['number' => 'PB/BKS/202608/00001', 'branch_id' => $bks->id, 'receipt_date' => '2026-08-14']);
        $legacy->lines()->create(['sparepart_branch_id' => $sp->id, 'qty' => 5, 'purchase_price' => 40000, 'line_total' => 200000, 'sort_order' => 0]);

        $this->actingAs($user)->patch("/goods-receipts/{$legacy->id}/post")->assertRedirect();

        $this->assertSame(GoodsReceiptStatus::POSTED, $legacy->fresh()->status);
        $this->assertSame('5.000', (string) $this->stock((object) ['sparepart_branch_id' => $sp->id])->on_hand_qty);
        $this->assertSame(PurchaseOrderStatus::ISSUED, $order->fresh()->status);
        $this->assertSame('0.000', (string) $order->lines[0]->fresh()->qty_received);
    }
}
