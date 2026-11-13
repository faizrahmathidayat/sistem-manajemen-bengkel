<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Support\AuditEvent;
use App\Support\GoodsReceiptStatus;
use App\Support\PurchaseOrderStatus;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index()
    {
        $this->authorize('purchase_order.view');

        $branches = Branch::orderBy('name')->get();
        $branchIds = collect(request('branch_ids', []))
            ->filter(fn ($id) => is_scalar($id))
            ->map(fn ($id) => (int) $id)
            ->intersect($branches->pluck('id'))
            ->values()->all();
        $supplierId = is_scalar(request('supplier_id')) ? (int) request('supplier_id') : 0;
        $status = is_string(request('status')) && array_key_exists(request('status'), PurchaseOrderStatus::LABELS)
            ? request('status') : null;
        $search = is_string(request('q')) ? trim(request('q')) : null;

        $purchaseOrders = PurchaseOrder::with(['branch', 'supplier', 'purchaseRequest'])
            ->withSum('lines as qty_ordered_sum', 'qty_ordered')
            ->withSum('lines as qty_received_sum', 'qty_received')
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($supplierId > 0, fn ($query) => $query->where('supplier_id', $supplierId))
            ->when($status, fn ($query, $s) => $query->where('status', $s))
            ->when($search, function ($query, $q) {
                $query->where('number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return view('purchase-orders.index', compact('purchaseOrders', 'branches'))
            ->with('selectedBranchIds', $branchIds)
            ->with('selectedSupplier', $supplierId > 0 ? Supplier::find($supplierId) : null)
            ->with('selectedStatus', $status)
            ->with('search', $search);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('view', $purchaseOrder);

        $purchaseOrder->load([
            'branch', 'supplier', 'purchaseRequest', 'issuer',
            'lines.sparepartBranch.sparepart', 'goodsReceipts',
        ]);

        return view('purchase-orders.show', compact('purchaseOrder'));
    }

    public function issue(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('issue', $purchaseOrder);

        DB::transaction(function () use ($purchaseOrder) {
            $fresh = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->status === PurchaseOrderStatus::DRAFT, 409, 'Pembelian sudah diterbitkan atau dibatalkan.');

            $fresh->update([
                'status' => PurchaseOrderStatus::ISSUED,
                'issued_at' => now(),
                'issued_by' => auth()->id(),
            ]);

            (new AuditLogger())->log(
                AuditEvent::PURCHASE_ORDER_ISSUED, $fresh->branch_id, $fresh,
                ['status' => PurchaseOrderStatus::DRAFT], ['status' => PurchaseOrderStatus::ISSUED]
            );
        });

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('status', 'Pembelian berhasil diterbitkan.');
    }

    public function cancel(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('cancel', $purchaseOrder);

        DB::transaction(function () use ($purchaseOrder) {
            // Same lock order as posting a receipt (receipt -> purchase order): lock this order's
            // draft receipts first, otherwise a concurrent post and cancel can deadlock.
            GoodsReceipt::where('purchase_order_id', $purchaseOrder->id)
                ->where('status', GoodsReceiptStatus::DRAFT)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $fresh = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();

            // Re-check under the lock: a receipt may have been posted since the policy ran.
            abort_unless(
                in_array($fresh->status, [PurchaseOrderStatus::DRAFT, PurchaseOrderStatus::ISSUED], true)
                    && ! $fresh->goodsReceipts()->where('status', GoodsReceiptStatus::POSTED)->exists(),
                409,
                'Pembelian tidak dapat dibatalkan.'
            );

            $previous = $fresh->status;
            $fresh->update(['status' => PurchaseOrderStatus::CANCELLED]);
            GoodsReceipt::where('purchase_order_id', $fresh->id)
                ->where('status', GoodsReceiptStatus::DRAFT)
                ->update(['status' => GoodsReceiptStatus::CANCELLED]);

            (new AuditLogger())->log(
                AuditEvent::PURCHASE_ORDER_CANCELLED, $fresh->branch_id, $fresh,
                ['status' => $previous], ['status' => PurchaseOrderStatus::CANCELLED]
            );
        });

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('status', 'Pembelian berhasil dibatalkan.');
    }

    public function print(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('print', $purchaseOrder);

        $purchaseOrder->load(['branch', 'supplier', 'lines.sparepartBranch.sparepart']);

        $pdf = Pdf::loadView('purchase-orders.print-pdf', compact('purchaseOrder'))->setPaper('a4', 'portrait');

        return $pdf->stream('po-' . str_replace('/', '-', $purchaseOrder->number) . '.pdf');
    }
}
