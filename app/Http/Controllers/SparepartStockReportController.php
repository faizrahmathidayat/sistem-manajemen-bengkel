<?php

namespace App\Http\Controllers;

use App\Exports\SparepartStockReportExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\SparepartBranch;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Facades\Excel;

class SparepartStockReportController extends Controller
{
    use HandlesReportExport;

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.sparepart.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.sparepart-stock.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);
        $baseQuery = $this->buildBaseQuery($filters, $permittedBranches);

        $summary = (clone $baseQuery)->selectRaw(
            'COUNT(*) as total_jenis_item, ' .
            'COALESCE(SUM(sparepart_branch_stocks.on_hand_qty), 0) as total_qty_on_hand, ' .
            'COALESCE(SUM(CASE WHEN sparepart_branches.minimum_stock > 0 AND (sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) < sparepart_branches.minimum_stock THEN 1 ELSE 0 END), 0) as total_item_kritis, ' .
            'COALESCE(SUM(sparepart_branch_stocks.on_hand_qty * sparepart_branches.selling_price), 0) as total_nilai_inventaris'
        )->first();

        $query = $this->applyStockStatus(clone $baseQuery, $filters['stockStatus']);

        $sparepartBranches = $query->select('sparepart_branches.*')
            ->addSelect(['sparepart_branch_stocks.on_hand_qty', 'sparepart_branch_stocks.reserved_qty'])
            ->with(['sparepart', 'branch', 'rack'])
            ->orderBy('sparepart_branches.branch_id')
            ->orderBy('sparepart_branches.id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('reports.sparepart-stock.index', [
            'sparepartBranches' => $sparepartBranches,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'search' => $filters['search'],
            'stockStatus' => $filters['stockStatus'],
            'mode' => $filters['mode'],
        ]);
    }

    public function exportExcel()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.sparepart.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->applyStockStatus($this->buildBaseQuery($filters, $permittedBranches), $filters['stockStatus'])
            ->select('sparepart_branches.*')
            ->addSelect(['sparepart_branch_stocks.on_hand_qty', 'sparepart_branch_stocks.reserved_qty'])
            ->with(['sparepart', 'branch', 'rack']);

        return Excel::download(
            new SparepartStockReportExport($query, $filters['mode'], $this->filterSummaryText($filters)),
            'laporan-sparepart-stok-' . now()->format('Ymd-His') . '.xlsx'
        );
    }

    public function previewPdf()
    {
        return $this->renderPdf('inline');
    }

    public function downloadPdf()
    {
        return $this->renderPdf('attachment');
    }

    protected function renderPdf(string $disposition)
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.sparepart.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->applyStockStatus($this->buildBaseQuery($filters, $permittedBranches), $filters['stockStatus'])
            ->select('sparepart_branches.*')
            ->addSelect(['sparepart_branch_stocks.on_hand_qty', 'sparepart_branch_stocks.reserved_qty'])
            ->with(['sparepart', 'branch', 'rack']);

        $rows = $query->orderBy('sparepart_branches.branch_id')->orderBy('sparepart_branches.id')->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);

        return $this->streamPdf('reports.sparepart-stock.pdf', [
            'sparepartBranches' => $rows,
            'mode' => $filters['mode'],
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-sparepart-stok', $disposition);
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('search')) ? trim(request('search')) : null;

        $stockStatus = request('stock_status');
        $stockStatus = in_array($stockStatus, ['habis', 'kritis', 'tersedia', 'semua'], true)
            ? $stockStatus : 'semua';

        return [
            'branchIds' => $branchIds,
            'search' => $search,
            'stockStatus' => $stockStatus,
            'mode' => request('mode') === 'detail' ? 'detail' : 'rekap',
        ];
    }

    protected function buildBaseQuery(array $filters, SupportCollection $permittedBranches)
    {
        return SparepartBranch::query()
            ->join('sparepart_branch_stocks', 'sparepart_branch_stocks.sparepart_branch_id', '=', 'sparepart_branches.id')
            ->whereIn('sparepart_branches.branch_id', $permittedBranches->pluck('id'))
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('sparepart_branches.branch_id', $filters['branchIds']))
            ->when($filters['search'], function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('sparepart', function ($inner) use ($escaped) {
                    $inner->where('code', 'like', "%{$escaped}%")
                        ->orWhere('name', 'like', "%{$escaped}%");
                });
            });
    }

    protected function applyStockStatus($query, string $stockStatus)
    {
        return $query
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
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $statusLabels = ['semua' => 'Semua', 'kritis' => 'Kritis/Minimum', 'habis' => 'Habis', 'tersedia' => 'Tersedia'];
        $statusLabel = $statusLabels[$filters['stockStatus']] ?? $filters['stockStatus'];
        $searchLabel = $filters['search'] ? " · Cari: {$filters['search']}" : '';
        $modeLabel = $filters['mode'] === 'detail' ? 'Detail' : 'Rekap';

        return "Cabang: {$branchLabel} · Status Stok: {$statusLabel}{$searchLabel} · Tampilan: {$modeLabel}";
    }
}
