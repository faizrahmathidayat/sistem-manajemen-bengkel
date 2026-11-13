<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderGenerator;
use App\Support\GoodsReceiptStatus;
use App\Support\PurchaseOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseOrderManagementTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function buyer(array $codes = ['purchase_order.view', 'purchase_order.issue', 'purchase_order.cancel']): User
    {
        $user = User::factory()->create();
        foreach ($codes as $code) {
            $this->grantGlobal($user, $code);
        }

        return User::find($user->id);
    }

    protected function makeOrder(array $orderOverrides = [], int $total = 12900000): PurchaseOrder
    {
        $bks = $this->makeBranch('BKS');
        $request = $this->makeAssignedRequest($bks, $total);
        $order = (new PurchaseOrderGenerator())->generateFor($request)->first();
        if ($orderOverrides) {
            $order->update($orderOverrides);
        }

        return $order->fresh(['lines', 'supplier']);
    }

    public function test_every_screen_requires_purchase_order_view(): void
    {
        $order = $this->makeOrder();
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get('/purchase-orders')->assertForbidden();
        $this->actingAs($nobody)->get("/purchase-orders/{$order->id}")->assertForbidden();
        $this->actingAs($nobody)->get("/purchase-orders/{$order->id}/print")->assertForbidden();
    }

    public function test_index_lists_filters_and_survives_array_input(): void
    {
        $first = $this->makeOrder();
        $second = $this->makeOrder(['status' => PurchaseOrderStatus::ISSUED], 5000000);
        $buyer = $this->buyer(['purchase_order.view']);

        $this->actingAs($buyer)->get('/purchase-orders')->assertOk()
            ->assertSee($first->number)->assertSee($second->number);
        $this->actingAs($buyer)->get('/purchase-orders?status=issued')->assertOk()
            ->assertSee($second->number)->assertDontSee($first->number);
        $this->actingAs($buyer)->get('/purchase-orders?supplier_id=' . $first->supplier_id)->assertOk()
            ->assertSee($first->number)->assertDontSee($second->number);
        $this->actingAs($buyer)->get('/purchase-orders?q=' . substr($second->number, -5))->assertOk()
            ->assertSee($second->number);
        $this->actingAs($buyer)->get('/purchase-orders?q[]=x&status[]=y&supplier_id[]=1&branch_ids=oops')->assertOk();
    }

    public function test_show_renders_lines_progress_source_request_and_receipts(): void
    {
        $order = $this->makeOrder(['status' => PurchaseOrderStatus::PARTIALLY_RECEIVED]);
        $order->lines->first()->update(['qty_received' => 1]);
        GoodsReceipt::create([
            'number' => 'PB/BKS/202611/00011', 'branch_id' => $order->branch_id, 'receipt_date' => '2026-11-18',
            'purchase_order_id' => $order->id, 'status' => GoodsReceiptStatus::POSTED,
        ]);

        $this->actingAs($this->buyer(['purchase_order.view']))->get("/purchase-orders/{$order->id}")
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee($order->purchaseRequest->number)
            ->assertSee('PB/BKS/202611/00011')
            ->assertSee('Diterima Sebagian');
    }

    public function test_issue_moves_draft_to_issued_once_and_is_audited(): void
    {
        $order = $this->makeOrder();
        $buyer = $this->buyer();

        $this->actingAs($buyer)->patch("/purchase-orders/{$order->id}/issue")
            ->assertRedirect(route('purchase-orders.show', $order));

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::ISSUED, $order->status);
        $this->assertSame($buyer->id, (int) $order->issued_by);
        $this->assertNotNull($order->issued_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'purchase_order.issued', 'auditable_id' => $order->id]);

        $this->actingAs($buyer)->patch("/purchase-orders/{$order->id}/issue")->assertForbidden();
        $this->assertSame(1, AuditLog::where('event', 'purchase_order.issued')->count());
    }

    public function test_issue_and_cancel_require_their_own_permissions(): void
    {
        $order = $this->makeOrder();
        $viewer = $this->buyer(['purchase_order.view']);

        $this->actingAs($viewer)->patch("/purchase-orders/{$order->id}/issue")->assertForbidden();
        $this->actingAs($viewer)->patch("/purchase-orders/{$order->id}/cancel")->assertForbidden();
        $this->assertSame(PurchaseOrderStatus::DRAFT, $order->fresh()->status);
    }

    public function test_cancel_works_for_draft_and_issued_without_receipts_and_cancels_draft_receipts(): void
    {
        $draft = $this->makeOrder();
        $issued = $this->makeOrder(['status' => PurchaseOrderStatus::ISSUED], 4000000);
        $draftReceipt = GoodsReceipt::create([
            'number' => 'PB/BKS/202611/00012', 'branch_id' => $issued->branch_id, 'receipt_date' => '2026-11-18',
            'purchase_order_id' => $issued->id, 'status' => GoodsReceiptStatus::DRAFT,
        ]);
        $buyer = $this->buyer();

        $this->actingAs($buyer)->patch("/purchase-orders/{$draft->id}/cancel")->assertRedirect();
        $this->actingAs($buyer)->patch("/purchase-orders/{$issued->id}/cancel")->assertRedirect();

        $this->assertSame(PurchaseOrderStatus::CANCELLED, $draft->fresh()->status);
        $this->assertSame(PurchaseOrderStatus::CANCELLED, $issued->fresh()->status);
        $this->assertSame(GoodsReceiptStatus::CANCELLED, $draftReceipt->fresh()->status);
        $this->assertSame(2, AuditLog::where('event', 'purchase_order.cancelled')->count());
    }

    public function test_cancel_is_refused_once_a_posted_receipt_exists(): void
    {
        $order = $this->makeOrder(['status' => PurchaseOrderStatus::PARTIALLY_RECEIVED]);
        GoodsReceipt::create([
            'number' => 'PB/BKS/202611/00013', 'branch_id' => $order->branch_id, 'receipt_date' => '2026-11-18',
            'purchase_order_id' => $order->id, 'status' => GoodsReceiptStatus::POSTED,
        ]);

        $this->actingAs($this->buyer())->patch("/purchase-orders/{$order->id}/cancel")->assertForbidden();
        $this->assertSame(PurchaseOrderStatus::PARTIALLY_RECEIVED, $order->fresh()->status);
    }

    public function test_cancelled_and_completed_orders_cannot_be_cancelled_and_lines_cannot_be_edited(): void
    {
        $done = $this->makeOrder(['status' => PurchaseOrderStatus::COMPLETED]);
        $buyer = $this->buyer();

        $this->actingAs($buyer)->patch("/purchase-orders/{$done->id}/cancel")->assertForbidden();
        $this->actingAs($buyer)->put("/purchase-orders/{$done->id}", ['notes' => 'x'])->assertStatus(405);
    }

    public function test_print_streams_a_pdf(): void
    {
        $order = $this->makeOrder();

        $response = $this->actingAs($this->buyer(['purchase_order.view']))->get("/purchase-orders/{$order->id}/print");

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_request_detail_links_to_its_purchase_orders_and_sidebar_link_follows_permission(): void
    {
        $order = $this->makeOrder();
        $order->purchaseRequest->update(['status' => \App\Support\PurchaseRequestStatus::APPROVED]);
        $buyer = $this->buyer(['purchase_order.view', 'purchase_request.view_all']);

        $this->actingAs($buyer)->get("/purchase-requests/{$order->purchase_request_id}")
            ->assertOk()->assertSee(route('purchase-orders.show', $order), false);
        $this->actingAs($buyer)->get('/purchase-orders')->assertSee(route('purchase-orders.index'), false);

        $other = User::factory()->create();
        $this->grantGlobal($other, 'dashboard.view');
        $this->actingAs(User::find($other->id))->get('/dashboard')->assertDontSee(route('purchase-orders.index'), false);
    }

    public function test_cancel_locks_draft_receipts_before_the_purchase_order(): void
    {
        $order = $this->makeOrder(['status' => PurchaseOrderStatus::ISSUED]);
        GoodsReceipt::create([
            'number' => 'PB/BKS/202611/00020', 'branch_id' => $order->branch_id, 'receipt_date' => '2026-11-18',
            'purchase_order_id' => $order->id, 'status' => GoodsReceiptStatus::DRAFT,
        ]);
        $locks = [];
        \DB::listen(function ($query) use (&$locks) {
            if (stripos($query->sql, 'for update') !== false) {
                $locks[] = $query->sql;
            }
        });

        $this->actingAs($this->buyer())->patch("/purchase-orders/{$order->id}/cancel")->assertRedirect();

        $receiptLock = $orderLock = null;
        foreach ($locks as $position => $sql) {
            if ($receiptLock === null && strpos($sql, 'goods_receipts') !== false) {
                $receiptLock = $position;
            }
            if ($orderLock === null && strpos($sql, 'from `purchase_orders`') !== false) {
                $orderLock = $position;
            }
        }
        $this->assertNotNull($receiptLock, 'draft receipts must be locked');
        $this->assertNotNull($orderLock, 'purchase order must be locked');
        // Posting takes receipt -> purchase order; cancelling must use the same order.
        $this->assertLessThan($orderLock, $receiptLock);
    }
}
