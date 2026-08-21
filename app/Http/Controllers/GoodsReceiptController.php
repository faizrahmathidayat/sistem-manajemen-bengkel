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
use App\Models\SparepartBranchStock;
use App\Services\DocumentNumberGenerator;
use App\Support\GoodsReceiptStatus;
use App\Support\InventoryMovementType;
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

        $goodsReceipts = GoodsReceipt::with('branch')
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($status, fn ($query, $s) => $query->where('status', $s))
            ->when($search, function ($query, $q) {
                $query->where('number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
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

        return view('goods-receipts.create', compact('branches'));
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
        $branch = Branch::findOrFail($data['branch_id']);

        $goodsReceipt = DB::transaction(function () use ($data, $branch) {
            $goodsReceipt = GoodsReceipt::create([
                'number' => (new DocumentNumberGenerator())->next($branch, 'PB'),
                'branch_id' => $branch->id,
                'receipt_date' => $data['receipt_date'],
                'reference_number' => $data['reference_number'] ?? null,
                'status' => GoodsReceiptStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($goodsReceipt, $data['lines']);

            return $goodsReceipt;
        });

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', 'Penerimaan barang berhasil dibuat.');
    }

    public function show(GoodsReceipt $goodsReceipt)
    {
        $this->authorize('view', $goodsReceipt);

        $goodsReceipt->load(['branch', 'lines.sparepartBranch.sparepart']);

        return view('goods-receipts.show', compact('goodsReceipt'));
    }

    public function edit(GoodsReceipt $goodsReceipt)
    {
        $this->authorize('update', $goodsReceipt);

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

            $this->syncLines($fresh, $data['lines']);
        });

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', 'Penerimaan barang berhasil diperbarui.');
    }

    public function post(GoodsReceipt $goodsReceipt)
    {
        $this->authorize('post', $goodsReceipt);

        DB::transaction(function () use ($goodsReceipt) {
            $fresh = GoodsReceipt::whereKey($goodsReceipt->id)->lockForUpdate()->first();
            if ($fresh->status !== GoodsReceiptStatus::DRAFT) {
                return;
            }

            $lines = $fresh->lines()->reorder()->orderBy('sparepart_branch_id')->get();

            foreach ($lines as $line) {
                $stock = SparepartBranchStock::where('sparepart_branch_id', $line->sparepart_branch_id)
                    ->lockForUpdate()
                    ->firstOrFail();

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

            $fresh->status = GoodsReceiptStatus::POSTED;
            $fresh->save();
        });

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
