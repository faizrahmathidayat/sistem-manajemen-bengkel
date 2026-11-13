<?php

namespace App\Http\Controllers;

use App\Exports\GoodsReceiptLineImportTemplateExport;
use App\Http\Requests\ImportGoodsReceiptLinesRequest;
use App\Http\Requests\StoreGoodsReceiptRequest;
use App\Http\Requests\UpdateGoodsReceiptRequest;
use App\Imports\GoodsReceiptLinesImport;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SparepartBranchStock;
use App\Services\DocumentNumberGenerator;
use App\Services\InventoryCostService;
use App\Support\GoodsReceiptStatus;
use App\Support\InventoryMovementType;
use App\Support\PurchaseOrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class GoodsReceiptController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('receipt.view');

        if ($permittedBranches->isEmpty()) {
            return view('goods-receipts.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;
        $status = request('status') ?: null;

        $goodsReceipts = GoodsReceipt::with(['branch', 'purchaseOrder.supplier'])
            ->withSum('lines as lines_value', 'line_total')
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($status, fn ($query, $s) => $query->where('status', $s))
            ->when($search, function ($query, $q) {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('number', 'like', $like)
                        ->orWhereHas('purchaseOrder', function ($order) use ($like) {
                            $order->where('number', 'like', $like);
                        });
                });
            })
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return view('goods-receipts.index', compact('goodsReceipts'))
            ->with('branches', $permittedBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search)
            ->with('selectedStatus', $status);
    }

    public function create()
    {
        $branches = auth()->user()->branchesWithPermission('receipt.create');

        if ($branches->isEmpty()) {
            return view('goods-receipts.no-access');
        }

        // Preselect a purchase order from ?purchase_order_id= (the "Terima Barang" button) or
        // from old input after a validation error; only orders this user may actually receive.
        $orderId = (int) old('purchase_order_id', request('purchase_order_id'));
        $preselectedOrder = null;
        if ($orderId > 0) {
            $preselectedOrder = PurchaseOrder::with(['supplier', 'branch'])
                ->whereIn('branch_id', $branches->pluck('id'))
                ->whereIn('status', PurchaseOrderStatus::RECEIVABLE)
                ->find($orderId);
        }

        return view('goods-receipts.create', compact('branches', 'preselectedOrder'));
    }

    /**
     * Receivable purchase orders (issued / partially received) in the branches where the user
     * may create receipts. Select2 source for the receipt form.
     */
    public function lookupPurchaseOrders(Request $request)
    {
        $branchIds = auth()->user()->branchesWithPermission('receipt.create')->pluck('id');
        abort_if($branchIds->isEmpty(), 403);

        $query = PurchaseOrder::with(['supplier', 'branch'])->whereIn('branch_id', $branchIds);
        $ids = array_map('intval', (array) $request->query('ids', []));

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        } else {
            $term = $request->query('q');
            $term = is_string($term) ? trim($term) : '';
            if (mb_strlen($term) < 3) {
                return response()->json([]);
            }
            $query->whereIn('status', PurchaseOrderStatus::RECEIVABLE)
                ->where('number', 'like', '%' . addcslashes($term, '%_\\') . '%');
        }

        return response()->json(
            $query->orderByDesc('id')->limit(20)->get()->map(function (PurchaseOrder $order) {
                return [
                    'id' => $order->id,
                    'text' => $order->number . ' — ' . $order->supplier->name,
                    'supplier' => $order->supplier->name,
                    'branch' => $order->branch->name,
                ];
            })->values()
        );
    }

    /** Lines of a purchase order with what is already received / still open, for the receipt form. */
    public function poLines(PurchaseOrder $purchaseOrder)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('receipt.create', $purchaseOrder->branch_id), 403);

        $purchaseOrder->load('lines.sparepartBranch.sparepart');

        return response()->json(
            $purchaseOrder->lines->map(function ($line) {
                return [
                    'purchase_order_line_id' => $line->id,
                    'code' => $line->sparepartBranch->sparepart->code,
                    'name' => $line->sparepartBranch->sparepart->name,
                    'ordered' => (float) $line->qty_ordered,
                    'received' => (float) $line->qty_received,
                    'remaining' => $line->remainingQty(),
                    'unit_price' => (float) $line->unit_price,
                ];
            })->values()
        );
    }

    public function downloadImportTemplate()
    {
        abort_if(auth()->user()->branchesWithPermission('receipt.create')->isEmpty(), 403);

        return Excel::download(new GoodsReceiptLineImportTemplateExport(), 'template-import-sparepart-penerimaan-barang.xlsx');
    }

    public function importLines(ImportGoodsReceiptLinesRequest $request)
    {
        $data = $request->validated();

        $import = new GoodsReceiptLinesImport((int) $data['branch_id']);
        Excel::import($import, $data['file']);

        if (! empty($import->errors)) {
            return response()->json(['errors' => $import->errors], 422);
        }

        return response()->json(['lines' => $import->lines]);
    }

    public function store(StoreGoodsReceiptRequest $request)
    {
        $data = $request->validated();
        $order = PurchaseOrder::with(['lines', 'branch'])->findOrFail($data['purchase_order_id']);

        $goodsReceipt = DB::transaction(function () use ($data, $order) {
            $goodsReceipt = GoodsReceipt::create([
                'number' => (new DocumentNumberGenerator())->next($order->branch, 'PB'),
                'branch_id' => $order->branch_id,
                'purchase_order_id' => $order->id,
                'receipt_date' => $data['receipt_date'],
                'reference_number' => $data['reference_number'] ?? null,
                'status' => GoodsReceiptStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncPurchaseOrderLines($goodsReceipt, $order, $data['lines']);

            return $goodsReceipt;
        });

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', 'Penerimaan barang berhasil dibuat.');
    }

    public function show(GoodsReceipt $goodsReceipt)
    {
        $this->authorize('view', $goodsReceipt);

        $goodsReceipt->load(['branch', 'purchaseOrder.supplier', 'lines.sparepartBranch.sparepart', 'lines.purchaseOrderLine']);

        return view('goods-receipts.show', compact('goodsReceipt'));
    }

    public function edit(GoodsReceipt $goodsReceipt)
    {
        $this->authorize('update', $goodsReceipt);

        if ($goodsReceipt->purchase_order_id) {
            $goodsReceipt->load(['branch', 'purchaseOrder.supplier', 'purchaseOrder.lines.sparepartBranch.sparepart', 'lines']);
            $currentQty = $goodsReceipt->lines->pluck('qty', 'purchase_order_line_id');

            $orderLines = $goodsReceipt->purchaseOrder->lines->map(function ($line) use ($currentQty) {
                return [
                    'purchase_order_line_id' => $line->id,
                    'code' => $line->sparepartBranch->sparepart->code,
                    'name' => $line->sparepartBranch->sparepart->name,
                    'ordered' => (float) $line->qty_ordered,
                    'received' => (float) $line->qty_received,
                    'remaining' => $line->remainingQty(),
                    'unit_price' => (float) $line->unit_price,
                    'qty' => (int) ($currentQty[$line->id] ?? 0),
                ];
            })->values();

            return view('goods-receipts.edit-po', compact('goodsReceipt', 'orderLines'));
        }

        $goodsReceipt->load('lines');

        $existingLines = $goodsReceipt->lines->map(function ($line) {
            return [
                'sparepart_branch_id' => $line->sparepart_branch_id,
                'qty' => (float) $line->qty,
                'purchase_price' => (float) $line->purchase_price,
            ];
        })->values();

        return view('goods-receipts.edit', compact('goodsReceipt', 'existingLines'));
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $goodsReceipt) {
            $fresh = GoodsReceipt::whereKey($goodsReceipt->id)->lockForUpdate()->first();
            if ($fresh->status !== GoodsReceiptStatus::DRAFT) {
                return;
            }

            $fresh->update([
                'receipt_date' => $data['receipt_date'],
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($fresh->purchase_order_id) {
                $this->syncPurchaseOrderLines($fresh, PurchaseOrder::with('lines')->findOrFail($fresh->purchase_order_id), $data['lines']);
            } else {
                $this->syncLines($fresh, $data['lines']);
            }
        });

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', 'Penerimaan barang berhasil diperbarui.');
    }

    public function post(GoodsReceipt $goodsReceipt)
    {
        $this->authorize('post', $goodsReceipt);

        try {
            DB::transaction(function () use ($goodsReceipt) {
                // Lock order (see plan Global Constraints): receipt -> purchase order ->
                // purchase order lines (id asc) -> stock rows (sparepart_branch_id asc).
                $fresh = GoodsReceipt::whereKey($goodsReceipt->id)->lockForUpdate()->first();
                if ($fresh->status !== GoodsReceiptStatus::DRAFT) {
                    return;
                }

                $lines = $fresh->lines()->reorder()->orderBy('sparepart_branch_id')->get();

                [$order, $orderLines, $receivedByOrderLine] = $this->lockPurchaseOrderForPosting($fresh, $lines);

                foreach ($lines as $line) {
                    $stock = SparepartBranchStock::where('sparepart_branch_id', $line->sparepart_branch_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $qtyBefore = (float) $stock->on_hand_qty;
                    $stock->average_cost = app(InventoryCostService::class)->recalculateOnReceipt(
                        $qtyBefore, (float) $stock->average_cost, (float) $line->qty, (float) $line->purchase_price
                    );
                    $stock->on_hand_qty += $line->qty;
                    $stock->save();

                    InventoryMovement::create([
                        'movement_at' => now(),
                        'branch_id' => $fresh->branch_id,
                        'sparepart_branch_id' => $line->sparepart_branch_id,
                        'movement_type' => InventoryMovementType::RECEIPT,
                        'qty_in' => $line->qty,
                        'qty_out' => 0,
                        'balance_after' => $stock->on_hand_qty,
                        'reference_type' => 'goods_receipt_line',
                        'reference_id' => $line->id,
                        'created_by' => auth()->id(),
                    ]);
                }

                if ($order) {
                    $this->applyReceiptToPurchaseOrder($order, $orderLines, $receivedByOrderLine);
                }

                $fresh->status = GoodsReceiptStatus::POSTED;
                $fresh->save();
            });
        } catch (\DomainException $e) {
            return redirect()->route('goods-receipts.show', $goodsReceipt)->with('error', $e->getMessage());
        }

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', 'Penerimaan barang berhasil diposting.');
    }

    public function cancel(GoodsReceipt $goodsReceipt)
    {
        $this->authorize('cancel', $goodsReceipt);

        DB::transaction(function () use ($goodsReceipt) {
            $fresh = GoodsReceipt::whereKey($goodsReceipt->id)->lockForUpdate()->first();
            if ($fresh->status !== GoodsReceiptStatus::DRAFT) {
                return;
            }

            $fresh->status = GoodsReceiptStatus::CANCELLED;
            $fresh->save();
        });

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', 'Penerimaan barang berhasil dibatalkan.');
    }

    /**
     * For a receipt that came from a purchase order: lock the order and the order lines it
     * touches, and verify under the lock that the order is still receivable and that no order
     * line would be over-received (two drafts can both look valid until the first one posts).
     * Legacy receipts (no purchase order) return [null, empty, empty] and skip all of this.
     *
     * @return array{0: ?PurchaseOrder, 1: \Illuminate\Support\Collection, 2: \Illuminate\Support\Collection}
     *
     * @throws \DomainException with a user-facing message when the receipt can no longer be posted
     */
    protected function lockPurchaseOrderForPosting(GoodsReceipt $receipt, $lines): array
    {
        if (! $receipt->purchase_order_id) {
            return [null, collect(), collect()];
        }

        $order = PurchaseOrder::whereKey($receipt->purchase_order_id)->lockForUpdate()->firstOrFail();
        if (! in_array($order->status, PurchaseOrderStatus::RECEIVABLE, true)) {
            throw new \DomainException('Pembelian sudah tidak dapat menerima barang (status: '
                . (PurchaseOrderStatus::LABELS[$order->status] ?? $order->status) . ').');
        }

        if ($lines->contains(function ($line) {
            return ! $line->purchase_order_line_id;
        })) {
            throw new \DomainException('Ada baris penerimaan yang tidak terhubung ke baris pembelian.');
        }

        $receivedByOrderLine = $lines->groupBy('purchase_order_line_id')->map(function ($group) {
            return (float) $group->sum('qty');
        });

        $orderLines = PurchaseOrderLine::whereIn('id', $receivedByOrderLine->keys()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($receivedByOrderLine as $orderLineId => $qty) {
            $orderLine = $orderLines->get($orderLineId);
            if (! $orderLine || (int) $orderLine->purchase_order_id !== (int) $order->id) {
                throw new \DomainException('Baris penerimaan tidak sesuai dengan pembelian.');
            }
            if ($qty > $orderLine->remainingQty() + 0.0005) {
                $orderLine->loadMissing('sparepartBranch.sparepart');
                throw new \DomainException('Qty melebihi sisa pembelian untuk ' . $orderLine->sparepartBranch->sparepart->code
                    . ' (sisa ' . rtrim(rtrim(number_format($orderLine->remainingQty(), 3, '.', ''), '0'), '.') . ').');
            }
        }

        return [$order, $orderLines, $receivedByOrderLine];
    }

    /** Add the posted quantities to the order lines and move the order to partial/completed. */
    protected function applyReceiptToPurchaseOrder(PurchaseOrder $order, $orderLines, $receivedByOrderLine): void
    {
        foreach ($receivedByOrderLine as $orderLineId => $qty) {
            $orderLine = $orderLines->get($orderLineId);
            $orderLine->qty_received = round((float) $orderLine->qty_received + $qty, 3);
            $orderLine->save();
        }

        $complete = $order->lines()->get()->every(function ($line) {
            return $line->remainingQty() <= 0;
        });

        $order->status = $complete ? PurchaseOrderStatus::COMPLETED : PurchaseOrderStatus::PARTIALLY_RECEIVED;
        $order->save();
    }

    /**
     * Lines of a receipt that comes from a purchase order: only lines with a positive qty are
     * kept; sparepart and unit price are taken from the order line, never from the request.
     */
    protected function syncPurchaseOrderLines(GoodsReceipt $goodsReceipt, PurchaseOrder $order, array $lines): void
    {
        $goodsReceipt->lines()->delete();
        $orderLines = $order->lines->keyBy('id');
        $sortOrder = 0;

        foreach ($lines as $line) {
            $qty = (float) $line['qty'];
            if ($qty <= 0) {
                continue;
            }
            $orderLine = $orderLines[(int) $line['purchase_order_line_id']];
            GoodsReceiptLine::create([
                'goods_receipt_id' => $goodsReceipt->id,
                'sparepart_branch_id' => $orderLine->sparepart_branch_id,
                'purchase_order_line_id' => $orderLine->id,
                'qty' => $qty,
                'purchase_price' => $orderLine->unit_price,
                'line_total' => round($qty * (float) $orderLine->unit_price, 2),
                'sort_order' => $sortOrder++,
            ]);
        }
    }

    protected function syncLines(GoodsReceipt $goodsReceipt, array $lines): void
    {
        $goodsReceipt->lines()->delete();

        foreach (array_values(array_filter($lines)) as $index => $line) {
            $qty = (float) $line['qty'];
            $purchasePrice = (float) $line['purchase_price'];
            GoodsReceiptLine::create([
                'goods_receipt_id' => $goodsReceipt->id,
                'sparepart_branch_id' => $line['sparepart_branch_id'],
                'qty' => $qty,
                'purchase_price' => $purchasePrice,
                'line_total' => round($qty * $purchasePrice, 2),
                'sort_order' => $index,
            ]);
        }
    }
}
