<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Http\Requests\UpdateStockAdjustmentRequest;
use App\Models\Branch;
use App\Models\SparepartBranch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Services\DocumentNumberGenerator;
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
