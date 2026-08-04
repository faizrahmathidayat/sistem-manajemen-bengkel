<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Http\Requests\UpdateStockAdjustmentRequest;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\SparepartBranch;
use App\Models\SparepartBranchStock;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Services\DocumentNumberGenerator;
use App\Support\InventoryMovementType;
use App\Support\StockAdjustmentStatus;
use Illuminate\Support\Facades\DB;

class StockAdjustmentController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('stock_adjustment.view');

        if ($permittedBranches->isEmpty()) {
            return view('stock-adjustments.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $stockAdjustments = StockAdjustment::with('branch')
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($search, function ($query, $q) {
                $escaped = '%' . addcslashes($q, '%_\\') . '%';
                $query->where(function ($query) use ($escaped) {
                    $query->where('number', 'like', $escaped)
                        ->orWhere('reason', 'like', $escaped);
                });
            })
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('stock-adjustments.index', compact('stockAdjustments'))
            ->with('branches', $permittedBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function create()
    {
        $branches = auth()->user()->branchesWithPermission('stock_adjustment.create');

        if ($branches->isEmpty()) {
            return view('stock-adjustments.no-access');
        }

        return view('stock-adjustments.create', compact('branches'));
    }

    public function store(StoreStockAdjustmentRequest $request)
    {
        $data = $request->validated();
        $branch = Branch::findOrFail($data['branch_id']);

        $stockAdjustment = DB::transaction(function () use ($data, $branch) {
            $stockAdjustment = StockAdjustment::create([
                'number' => (new DocumentNumberGenerator())->next($branch, 'SA'),
                'branch_id' => $branch->id,
                'adjustment_date' => $data['adjustment_date'],
                'reason' => $data['reason'],
                'status' => StockAdjustmentStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($stockAdjustment, $data['lines']);

            return $stockAdjustment;
        });

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil dibuat.');
    }

    public function show(StockAdjustment $stockAdjustment)
    {
        $this->authorize('view', $stockAdjustment);

        $stockAdjustment->load(['branch', 'approvedBy', 'lines.sparepartBranch.sparepart']);

        return view('stock-adjustments.show', compact('stockAdjustment'));
    }

    public function edit(StockAdjustment $stockAdjustment)
    {
        $this->authorize('update', $stockAdjustment);

        $stockAdjustment->load('lines');
        $sparepartBranches = SparepartBranch::with(['sparepart', 'stock'])
            ->where('branch_id', $stockAdjustment->branch_id)
            ->where('is_active', true)
            ->get();
        $missingIds = $stockAdjustment->lines->pluck('sparepart_branch_id')->unique()->diff($sparepartBranches->pluck('id'));
        if ($missingIds->isNotEmpty()) {
            $sparepartBranches = $sparepartBranches->concat(
                SparepartBranch::with(['sparepart', 'stock'])->whereIn('id', $missingIds)->get()
            );
        }

        $sparepartOptions = $sparepartBranches->map(function ($sb) {
            return [
                'id' => $sb->id,
                'code' => $sb->sparepart->code,
                'name' => $sb->sparepart->name,
                'on_hand_qty' => (float) $sb->stock->on_hand_qty,
            ];
        })->values();

        $existingLines = $stockAdjustment->lines->map(function ($line) {
            return [
                'sparepart_branch_id' => $line->sparepart_branch_id,
                'physical_qty' => (float) $line->physical_qty,
                'reason' => $line->reason,
            ];
        })->values();

        return view('stock-adjustments.edit', compact('stockAdjustment', 'sparepartOptions', 'existingLines'));
    }

    public function update(UpdateStockAdjustmentRequest $request, StockAdjustment $stockAdjustment)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $stockAdjustment) {
            $fresh = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->first();
            if ($fresh->status !== StockAdjustmentStatus::DRAFT) {
                return;
            }

            $fresh->update([
                'adjustment_date' => $data['adjustment_date'],
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($fresh, $data['lines']);
        });

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil diperbarui.');
    }

    public function submit(StockAdjustment $stockAdjustment)
    {
        $this->authorize('submit', $stockAdjustment);

        $noLongerDraft = false;

        DB::transaction(function () use ($stockAdjustment, &$noLongerDraft) {
            $fresh = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->first();
            if ($fresh->status !== StockAdjustmentStatus::DRAFT) {
                $noLongerDraft = true;

                return;
            }

            $fresh->status = StockAdjustmentStatus::PENDING_APPROVAL;
            $fresh->save();
        });

        if ($noLongerDraft) {
            return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment ini sudah tidak dalam status draft.');
        }

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil diajukan untuk persetujuan.');
    }

    public function approve(StockAdjustment $stockAdjustment)
    {
        $this->authorize('approve', $stockAdjustment);

        $noLongerPendingApproval = false;

        DB::transaction(function () use ($stockAdjustment, &$noLongerPendingApproval) {
            $fresh = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->first();
            if ($fresh->status !== StockAdjustmentStatus::PENDING_APPROVAL) {
                $noLongerPendingApproval = true;

                return;
            }

            $fresh->status = StockAdjustmentStatus::APPROVED;
            $fresh->approved_by = auth()->id();
            $fresh->approved_at = now();
            $fresh->save();
        });

        if ($noLongerPendingApproval) {
            return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment ini sudah tidak dalam status diajukan.');
        }

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil disetujui.');
    }

    public function post(StockAdjustment $stockAdjustment)
    {
        $this->authorize('post', $stockAdjustment);

        $noLongerApproved = false;
        $reservationViolations = [];

        DB::transaction(function () use ($stockAdjustment, &$noLongerApproved, &$reservationViolations) {
            $fresh = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->first();
            if ($fresh->status !== StockAdjustmentStatus::APPROVED) {
                $noLongerApproved = true;

                return;
            }

            $lines = $fresh->lines()->reorder()->orderBy('sparepart_branch_id')->with('sparepartBranch.sparepart')->get();

            // Pass 1: lock every affected stock row (already in ascending sparepart_branch_id
            // order) and validate physical_qty against the CURRENT reserved_qty before mutating
            // anything. sparepart_branch_stocks enforces CHECK (reserved_qty <= on_hand_qty); a
            // physical count that comes in below what's currently reserved for open PKBs would
            // violate that constraint. Validating in a fully separate pass — rather than
            // check-then-mutate per line — guarantees this is all-or-nothing: a violation
            // discovered on line 2 must not leave line 1 already posted.
            $lockedStocks = [];
            foreach ($lines as $line) {
                $stock = SparepartBranchStock::where('sparepart_branch_id', $line->sparepart_branch_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedStocks[$line->id] = $stock;

                $physicalQty = (float) $line->physical_qty;
                $reservedQty = (float) $stock->reserved_qty;

                if (($reservedQty - $physicalQty) >= 0.0005) {
                    $reservationViolations[] = sprintf(
                        '%s sedang direservasi %s, tapi qty fisik hanya %s',
                        $line->sparepartBranch->sparepart->code,
                        $this->formatQtyForMessage($reservedQty),
                        $this->formatQtyForMessage($physicalQty)
                    );
                }
            }

            if (! empty($reservationViolations)) {
                return;
            }

            // Pass 2: recompute each line's delta against the CURRENT on_hand_qty (locked above)
            // and mutate. Safe now that pass 1 has confirmed no line will drive reserved_qty
            // above on_hand_qty.
            foreach ($lines as $line) {
                $stock = $lockedStocks[$line->id];

                $currentOnHandQty = (float) $stock->on_hand_qty;
                $physicalQty = (float) $line->physical_qty;
                $delta = round($physicalQty - $currentOnHandQty, 3);
                $recordedDelta = round((float) $line->adjustment_qty, 3);

                if (abs($delta) < 0.0005) {
                    // No ledger row is written for a zero-delta line (the CHECK constraint
                    // forbids a zero qty_in/qty_out movement), but if the ORIGINALLY recorded
                    // adjustment_qty was non-zero, stock drifted between approval and posting
                    // and happened to land exactly back on physical_qty — that fact would
                    // otherwise be lost entirely. Record it on the document's own notes instead.
                    if (abs($recordedDelta) >= 0.0005) {
                        $driftNote = sprintf(
                            'Baris %s: selisih tercatat %+.3f tidak diterapkan karena stok sudah sesuai (%s) saat posting.',
                            $line->sparepartBranch->sparepart->code,
                            $recordedDelta,
                            $this->formatQtyForMessage($physicalQty)
                        );
                        $fresh->notes = $fresh->notes ? $fresh->notes . "\n" . $driftNote : $driftNote;
                    }

                    continue;
                }

                $stock->on_hand_qty = $physicalQty;
                $stock->save();

                $notes = null;
                if (abs($recordedDelta - $delta) >= 0.0005) {
                    $notes = sprintf(
                        'Tercatat saat diajukan: %+.3f, diterapkan saat posting: %+.3f (stok bergeser sejak diajukan).',
                        $recordedDelta,
                        $delta
                    );
                }

                InventoryMovement::create([
                    'movement_at' => now(),
                    'branch_id' => $fresh->branch_id,
                    'sparepart_branch_id' => $line->sparepart_branch_id,
                    'movement_type' => $delta > 0 ? InventoryMovementType::ADJUSTMENT_IN : InventoryMovementType::ADJUSTMENT_OUT,
                    'qty_in' => $delta > 0 ? $delta : 0,
                    'qty_out' => $delta < 0 ? abs($delta) : 0,
                    'balance_after' => $physicalQty,
                    'reference_type' => 'stock_adjustment_line',
                    'reference_id' => $line->id,
                    'notes' => $notes,
                    'created_by' => auth()->id(),
                ]);
            }

            $fresh->status = StockAdjustmentStatus::POSTED;
            $fresh->save();
        });

        if ($noLongerApproved) {
            return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment ini sudah tidak dalam status disetujui.');
        }

        if (! empty($reservationViolations)) {
            $message = 'Tidak bisa memposting: ' . implode('; ', $reservationViolations) . '. Selesaikan atau batalkan PKB terkait dahulu.';

            return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', $message);
        }

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil diposting.');
    }

    public function cancel(StockAdjustment $stockAdjustment)
    {
        $this->authorize('cancel', $stockAdjustment);

        $noLongerCancellable = false;

        DB::transaction(function () use ($stockAdjustment, &$noLongerCancellable) {
            $fresh = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->first();
            $cancellableStatuses = [StockAdjustmentStatus::DRAFT, StockAdjustmentStatus::PENDING_APPROVAL, StockAdjustmentStatus::APPROVED];
            if (! in_array($fresh->status, $cancellableStatuses, true)) {
                $noLongerCancellable = true;

                return;
            }

            $fresh->status = StockAdjustmentStatus::CANCELLED;
            $fresh->save();
        });

        if ($noLongerCancellable) {
            return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment ini sudah tidak bisa dibatalkan.');
        }

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil dibatalkan.');
    }

    protected function formatQtyForMessage(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }

    public function sparepartsByBranch(Branch $branch)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('stock_adjustment.create', $branch->id), 403);

        return response()->json(
            SparepartBranch::with(['sparepart', 'stock'])
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->get()
                ->map(function (SparepartBranch $sb) {
                    return [
                        'id' => $sb->id,
                        'code' => $sb->sparepart->code,
                        'name' => $sb->sparepart->name,
                        'on_hand_qty' => (float) $sb->stock->on_hand_qty,
                    ];
                })
                ->values()
        );
    }

    protected function syncLines(StockAdjustment $stockAdjustment, array $lines): void
    {
        $stockAdjustment->lines()->delete();

        foreach (array_values(array_filter($lines)) as $index => $line) {
            $physicalQty = (float) $line['physical_qty'];
            $stock = \App\Models\SparepartBranchStock::where('sparepart_branch_id', $line['sparepart_branch_id'])->first();
            $systemQty = $stock ? (float) $stock->on_hand_qty : 0.0;

            StockAdjustmentLine::create([
                'stock_adjustment_id' => $stockAdjustment->id,
                'sparepart_branch_id' => $line['sparepart_branch_id'],
                'system_qty' => $systemQty,
                'physical_qty' => $physicalQty,
                'adjustment_qty' => round($physicalQty - $systemQty, 3),
                'reason' => $line['reason'],
                'sort_order' => $index,
            ]);
        }
    }
}
