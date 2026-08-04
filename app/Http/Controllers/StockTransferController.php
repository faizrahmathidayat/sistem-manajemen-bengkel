<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockTransferRequest;
use App\Http\Requests\UpdateStockTransferRequest;
use App\Models\Branch;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Services\DocumentNumberGenerator;
use App\Support\TransferStatus;
use Illuminate\Support\Facades\DB;

class StockTransferController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('stock_transfer.view');

        if ($permittedBranches->isEmpty()) {
            return view('stock-transfers.no-access');
        }

        $permittedBranchIds = $permittedBranches->pluck('id');

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranchIds)
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $stockTransfers = StockTransfer::with(['fromBranch', 'toBranch'])
            ->where(function ($query) use ($permittedBranchIds) {
                $query->whereIn('from_branch_id', $permittedBranchIds)
                    ->orWhereIn('to_branch_id', $permittedBranchIds);
            })
            ->when($branchIds, function ($query) use ($branchIds) {
                $query->where(function ($query) use ($branchIds) {
                    $query->whereIn('from_branch_id', $branchIds)
                        ->orWhereIn('to_branch_id', $branchIds);
                });
            })
            ->when($search, function ($query, $q) {
                $query->where('number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('stock-transfers.index', compact('stockTransfers'))
            ->with('branches', $permittedBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function create()
    {
        $fromBranches = auth()->user()->branchesWithPermission('stock_transfer.create');

        if ($fromBranches->isEmpty()) {
            return view('stock-transfers.no-access');
        }

        $allBranches = Branch::where('is_active', true)->orderBy('name')->get();

        return view('stock-transfers.create', compact('fromBranches', 'allBranches'));
    }

    public function store(StoreStockTransferRequest $request)
    {
        $data = $request->validated();
        $fromBranch = Branch::findOrFail($data['from_branch_id']);

        $stockTransfer = DB::transaction(function () use ($data, $fromBranch) {
            $stockTransfer = StockTransfer::create([
                'number' => (new DocumentNumberGenerator())->next($fromBranch, 'ST'),
                'from_branch_id' => $fromBranch->id,
                'to_branch_id' => $data['to_branch_id'],
                'transfer_date' => $data['transfer_date'],
                'status' => TransferStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($stockTransfer, $data['lines']);

            return $stockTransfer;
        });

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock berhasil dibuat.');
    }

    public function show(StockTransfer $stockTransfer)
    {
        $this->authorize('view', $stockTransfer);

        $stockTransfer->load(['fromBranch', 'toBranch', 'approvedBy', 'dispatchedBy', 'receivedBy', 'lines.sparepart']);

        return view('stock-transfers.show', compact('stockTransfer'));
    }

    public function edit(StockTransfer $stockTransfer)
    {
        $this->authorize('update', $stockTransfer);

        $stockTransfer->load('lines');
        $allBranches = Branch::where('is_active', true)->orderBy('name')->get();

        $spareparts = Sparepart::whereHas('sparepartBranches', function ($query) use ($stockTransfer) {
            $query->where('branch_id', $stockTransfer->from_branch_id)->where('is_active', true);
        })->get();
        $missingIds = $stockTransfer->lines->pluck('sparepart_id')->unique()->diff($spareparts->pluck('id'));
        if ($missingIds->isNotEmpty()) {
            $spareparts = $spareparts->concat(Sparepart::whereIn('id', $missingIds)->get());
        }

        $sparepartOptions = $spareparts->map(function (Sparepart $sparepart) {
            return ['id' => $sparepart->id, 'code' => $sparepart->code, 'name' => $sparepart->name];
        })->values();

        $existingLines = $stockTransfer->lines->map(function ($line) {
            return ['sparepart_id' => $line->sparepart_id, 'qty' => (float) $line->qty];
        })->values();

        return view('stock-transfers.edit', compact('stockTransfer', 'allBranches', 'sparepartOptions', 'existingLines'));
    }

    public function update(UpdateStockTransferRequest $request, StockTransfer $stockTransfer)
    {
        $data = $request->validated();

        $noLongerDraft = false;

        DB::transaction(function () use ($data, $stockTransfer, &$noLongerDraft) {
            $fresh = StockTransfer::whereKey($stockTransfer->id)->lockForUpdate()->first();
            if ($fresh->status !== TransferStatus::DRAFT) {
                $noLongerDraft = true;

                return;
            }

            $fresh->update([
                'to_branch_id' => $data['to_branch_id'],
                'transfer_date' => $data['transfer_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($fresh, $data['lines']);
        });

        if ($noLongerDraft) {
            return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock ini sudah tidak dalam status draft.');
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock berhasil diperbarui.');
    }

    public function sparepartsByBranch(Branch $branch)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('stock_transfer.create', $branch->id), 403);

        return response()->json(
            SparepartBranch::with('sparepart')
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->get()
                ->map(function (SparepartBranch $sb) {
                    return [
                        'id' => $sb->sparepart->id,
                        'code' => $sb->sparepart->code,
                        'name' => $sb->sparepart->name,
                        'on_hand_qty' => (float) $sb->stock->on_hand_qty,
                    ];
                })
                ->values()
        );
    }

    protected function syncLines(StockTransfer $stockTransfer, array $lines): void
    {
        $stockTransfer->lines()->delete();

        foreach (array_values(array_filter($lines)) as $index => $line) {
            StockTransferLine::create([
                'stock_transfer_id' => $stockTransfer->id,
                'sparepart_id' => $line['sparepart_id'],
                'qty' => (float) $line['qty'],
                'sort_order' => $index,
            ]);
        }
    }
}
