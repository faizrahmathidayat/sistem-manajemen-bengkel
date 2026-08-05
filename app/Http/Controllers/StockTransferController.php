<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockTransferRequest;
use App\Http\Requests\UpdateStockTransferRequest;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\SparepartBranch;
use App\Models\SparepartBranchStock;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Services\DocumentNumberGenerator;
use App\Support\InventoryMovementType;
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

        $existingLines = $stockTransfer->lines->map(function ($line) {
            return ['sparepart_id' => $line->sparepart_id, 'qty' => (float) $line->qty];
        })->values();

        return view('stock-transfers.edit', compact('stockTransfer', 'allBranches', 'existingLines'));
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

    public function approve(StockTransfer $stockTransfer)
    {
        $this->authorize('approve', $stockTransfer);

        $noLongerDraft = false;

        DB::transaction(function () use ($stockTransfer, &$noLongerDraft) {
            $fresh = StockTransfer::whereKey($stockTransfer->id)->lockForUpdate()->first();
            if ($fresh->status !== TransferStatus::DRAFT) {
                $noLongerDraft = true;

                return;
            }

            $fresh->status = TransferStatus::APPROVED;
            $fresh->approved_by = auth()->id();
            $fresh->approved_at = now();
            $fresh->save();
        });

        if ($noLongerDraft) {
            return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock ini sudah tidak dalam status draft.');
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock berhasil disetujui.');
    }

    public function dispatchTransfer(StockTransfer $stockTransfer)
    {
        $this->authorize('dispatch', $stockTransfer);

        $noLongerApproved = false;
        $reservationViolations = [];

        DB::transaction(function () use ($stockTransfer, &$noLongerApproved, &$reservationViolations) {
            $fresh = StockTransfer::whereKey($stockTransfer->id)->lockForUpdate()->first();
            if ($fresh->status !== TransferStatus::APPROVED) {
                $noLongerApproved = true;

                return;
            }

            $lines = $fresh->lines()->reorder()->orderBy('sparepart_id')->with('sparepart')->get();

            // Pass 1a: resolve every line's ORIGIN SparepartBranch config — WITHOUT locking
            // any stock row yet. StockTransferLine only stores sparepart_id (a transfer spans
            // two branches, so lines can't reference a single per-branch SparepartBranch row
            // the way single-branch documents' lines do), so we cannot order the query itself
            // by sparepart_branch_id — we must resolve first, then sort, then lock.
            $resolvedLines = [];
            foreach ($lines as $line) {
                $sparepartBranch = SparepartBranch::where('sparepart_id', $line->sparepart_id)
                    ->where('branch_id', $fresh->from_branch_id)
                    ->where('is_active', true)
                    ->first();

                if (! $sparepartBranch) {
                    $reservationViolations[] = sprintf('%s sudah tidak dikonfigurasi atau tidak aktif di cabang asal', $line->sparepart->code);

                    continue;
                }

                $resolvedLines[] = ['line' => $line, 'sparepartBranch' => $sparepartBranch];
            }

            // Pass 1b: lock stock rows in ascending sparepart_branch_id order — the
            // project-wide lock-ordering invariant (see WorkOrderController,
            // GoodsReceiptController::post(), StockAdjustmentController::post()) that prevents
            // an AB-BA deadlock when two documents touch overlapping spareparts at the same
            // branch concurrently. Only after every row is locked do we validate qty against
            // the CURRENT reserved_qty — same two-pass all-or-nothing pattern already proven
            // in migration 008b's StockAdjustmentController::post().
            usort($resolvedLines, fn ($a, $b) => $a['sparepartBranch']->id <=> $b['sparepartBranch']->id);

            $lockedStocks = [];
            foreach ($resolvedLines as $resolved) {
                $line = $resolved['line'];

                $stock = SparepartBranchStock::where('sparepart_branch_id', $resolved['sparepartBranch']->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedStocks[$line->id] = $stock;

                $qty = (float) $line->qty;
                $onHandQty = (float) $stock->on_hand_qty;
                $reservedQty = (float) $stock->reserved_qty;

                if (($reservedQty - ($onHandQty - $qty)) >= 0.0005) {
                    $reservationViolations[] = sprintf(
                        '%s: stok %s dikurangi %s akan turun di bawah reservasi %s',
                        $line->sparepart->code,
                        $this->formatQtyForMessage($onHandQty),
                        $this->formatQtyForMessage($qty),
                        $this->formatQtyForMessage($reservedQty)
                    );
                }
            }

            if (! empty($reservationViolations)) {
                return;
            }

            // Pass 2: mutate, in the same sparepart_branch_id-sorted order the locks were
            // acquired in. Safe now that pass 1 confirmed every line's origin stock exists and
            // won't drop below its reserved_qty.
            foreach ($resolvedLines as $resolved) {
                $line = $resolved['line'];
                $stock = $lockedStocks[$line->id];
                $qty = (float) $line->qty;

                $stock->on_hand_qty = (float) $stock->on_hand_qty - $qty;
                $stock->save();

                InventoryMovement::create([
                    'movement_at' => now(),
                    'branch_id' => $fresh->from_branch_id,
                    'sparepart_branch_id' => $stock->sparepart_branch_id,
                    'movement_type' => InventoryMovementType::TRANSFER_OUT,
                    'qty_in' => 0,
                    'qty_out' => $qty,
                    'balance_after' => $stock->on_hand_qty,
                    'reference_type' => 'stock_transfer_line',
                    'reference_id' => $line->id,
                    'created_by' => auth()->id(),
                ]);
            }

            $fresh->status = TransferStatus::DISPATCHED;
            $fresh->dispatched_by = auth()->id();
            $fresh->dispatched_at = now();
            $fresh->save();
        });

        if ($noLongerApproved) {
            return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock ini sudah tidak dalam status disetujui.');
        }

        if (! empty($reservationViolations)) {
            $message = 'Tidak bisa mengirim: ' . implode('; ', $reservationViolations) . '.';

            return redirect()->route('stock-transfers.show', $stockTransfer)->with('error', $message);
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock berhasil dikirim.');
    }

    public function receive(StockTransfer $stockTransfer)
    {
        $this->authorize('receive', $stockTransfer);

        $noLongerDispatched = false;
        $configViolations = [];

        DB::transaction(function () use ($stockTransfer, &$noLongerDispatched, &$configViolations) {
            $fresh = StockTransfer::whereKey($stockTransfer->id)->lockForUpdate()->first();
            if ($fresh->status !== TransferStatus::DISPATCHED) {
                $noLongerDispatched = true;

                return;
            }

            $lines = $fresh->lines()->reorder()->orderBy('sparepart_id')->with('sparepart')->get();

            // Pass 1a: resolve every line's DESTINATION SparepartBranch config — WITHOUT
            // locking any stock row yet. A sparepart's SparepartBranch config at the
            // destination could have been deactivated between dispatch and receive. As in
            // dispatchTransfer(), StockTransferLine only stores sparepart_id, so we resolve
            // first, then sort by sparepart_branch_id, then lock — we cannot order the query
            // itself by sparepart_branch_id.
            $resolvedLines = [];
            foreach ($lines as $line) {
                $sparepartBranch = SparepartBranch::where('sparepart_id', $line->sparepart_id)
                    ->where('branch_id', $fresh->to_branch_id)
                    ->where('is_active', true)
                    ->first();

                if (! $sparepartBranch) {
                    $configViolations[] = sprintf('%s sudah tidak dikonfigurasi atau tidak aktif di cabang tujuan', $line->sparepart->code);

                    continue;
                }

                $resolvedLines[] = ['line' => $line, 'sparepartBranch' => $sparepartBranch];
            }

            // Pass 1b: lock stock rows in ascending sparepart_branch_id order — the
            // project-wide lock-ordering invariant (see WorkOrderController,
            // GoodsReceiptController::post(), StockAdjustmentController::post()) that prevents
            // an AB-BA deadlock when two documents touch overlapping spareparts at the same
            // branch concurrently. All-or-nothing: bail before mutating anything if any line's
            // config was missing/inactive.
            usort($resolvedLines, fn ($a, $b) => $a['sparepartBranch']->id <=> $b['sparepartBranch']->id);

            $lockedStocks = [];
            foreach ($resolvedLines as $resolved) {
                $lockedStocks[$resolved['line']->id] = SparepartBranchStock::where('sparepart_branch_id', $resolved['sparepartBranch']->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            if (! empty($configViolations)) {
                return;
            }

            // Pass 2: mutate, in the same sparepart_branch_id-sorted order the locks were
            // acquired in.
            foreach ($resolvedLines as $resolved) {
                $line = $resolved['line'];
                $stock = $lockedStocks[$line->id];
                $qty = (float) $line->qty;

                $stock->on_hand_qty = (float) $stock->on_hand_qty + $qty;
                $stock->save();

                InventoryMovement::create([
                    'movement_at' => now(),
                    'branch_id' => $fresh->to_branch_id,
                    'sparepart_branch_id' => $stock->sparepart_branch_id,
                    'movement_type' => InventoryMovementType::TRANSFER_IN,
                    'qty_in' => $qty,
                    'qty_out' => 0,
                    'balance_after' => $stock->on_hand_qty,
                    'reference_type' => 'stock_transfer_line',
                    'reference_id' => $line->id,
                    'created_by' => auth()->id(),
                ]);
            }

            $fresh->status = TransferStatus::RECEIVED;
            $fresh->received_by = auth()->id();
            $fresh->received_at = now();
            $fresh->save();
        });

        if ($noLongerDispatched) {
            return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock ini sudah tidak dalam status dikirim.');
        }

        if (! empty($configViolations)) {
            $message = 'Tidak bisa menerima: ' . implode('; ', $configViolations) . '.';

            return redirect()->route('stock-transfers.show', $stockTransfer)->with('error', $message);
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock berhasil diterima.');
    }

    public function cancel(StockTransfer $stockTransfer)
    {
        $this->authorize('cancel', $stockTransfer);

        $noLongerCancellable = false;

        DB::transaction(function () use ($stockTransfer, &$noLongerCancellable) {
            $fresh = StockTransfer::whereKey($stockTransfer->id)->lockForUpdate()->first();
            $cancellableStatuses = [TransferStatus::DRAFT, TransferStatus::APPROVED];
            if (! in_array($fresh->status, $cancellableStatuses, true)) {
                $noLongerCancellable = true;

                return;
            }

            $fresh->status = TransferStatus::CANCELLED;
            $fresh->save();
        });

        if ($noLongerCancellable) {
            return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock ini sudah tidak bisa dibatalkan.');
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock berhasil dibatalkan.');
    }

    protected function formatQtyForMessage(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
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
