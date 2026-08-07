<?php

namespace App\Http\Controllers;

use App\Models\SparepartBranch;

class SparepartStockReportController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.sparepart.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.sparepart-stock.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('search')) ? trim(request('search')) : null;

        $stockStatus = request('stock_status');
        $stockStatus = in_array($stockStatus, ['habis', 'kritis', 'tersedia', 'semua'], true)
            ? $stockStatus : 'semua';

        $mode = request('mode') === 'detail' ? 'detail' : 'rekap';

        $query = SparepartBranch::query()
            ->join('sparepart_branch_stocks', 'sparepart_branch_stocks.sparepart_branch_id', '=', 'sparepart_branches.id')
            ->whereIn('sparepart_branches.branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($q) => $q->whereIn('sparepart_branches.branch_id', $branchIds))
            ->when($search, function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('sparepart', function ($inner) use ($escaped) {
                    $inner->where('code', 'like', "%{$escaped}%")
                        ->orWhere('name', 'like', "%{$escaped}%");
                });
            })
            ->when($stockStatus === 'habis', fn ($q) => $q->where('sparepart_branch_stocks.on_hand_qty', 0))
            ->when($stockStatus === 'kritis', function ($q) {
                $q->where('sparepart_branch_stocks.on_hand_qty', '>', 0)
                    ->where('sparepart_branches.minimum_stock', '>', 0)
                    ->whereRaw('(sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) < sparepart_branches.minimum_stock');
            })
            ->when($stockStatus === 'tersedia', function ($q) {
                $q->where('sparepart_branch_stocks.on_hand_qty', '>', 0)
                    ->where(function ($inner) {
                        $inner->where('sparepart_branches.minimum_stock', '<=', 0)
                            ->orWhereRaw('(sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) >= sparepart_branches.minimum_stock');
                    });
            });

        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_jenis_item, ' .
            'COALESCE(SUM(sparepart_branch_stocks.on_hand_qty), 0) as total_qty_on_hand, ' .
            'COALESCE(SUM(CASE WHEN sparepart_branches.minimum_stock > 0 AND (sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) < sparepart_branches.minimum_stock THEN 1 ELSE 0 END), 0) as total_item_kritis, ' .
            'COALESCE(SUM(sparepart_branch_stocks.on_hand_qty * sparepart_branches.selling_price), 0) as total_nilai_inventaris'
        )->first();

        $sparepartBranches = $query->select('sparepart_branches.*')
            ->addSelect(['sparepart_branch_stocks.on_hand_qty', 'sparepart_branch_stocks.reserved_qty'])
            ->with(['sparepart', 'branch'])
            ->orderBy('sparepart_branches.branch_id')
            ->orderBy('sparepart_branches.id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('reports.sparepart-stock.index', [
            'sparepartBranches' => $sparepartBranches,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $branchIds,
            'search' => $search,
            'stockStatus' => $stockStatus,
            'mode' => $mode,
        ]);
    }
}
