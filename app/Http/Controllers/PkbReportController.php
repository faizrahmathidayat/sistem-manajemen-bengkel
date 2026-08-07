<?php

namespace App\Http\Controllers;

use App\Exports\PkbReportExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\WorkOrder;
use App\Support\WorkOrderStatus;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Facades\Excel;

class PkbReportController extends Controller
{
    use HandlesReportExport;

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.pkb.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.pkb.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches);

        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_pkb, ' .
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as total_completed, ' .
            'COALESCE(SUM(' .
                '(SELECT COALESCE(SUM(line_total), 0) FROM work_order_service_lines WHERE work_order_service_lines.work_order_id = work_orders.id) + ' .
                '(SELECT COALESCE(SUM(line_total), 0) FROM work_order_sparepart_lines WHERE work_order_sparepart_lines.work_order_id = work_orders.id)' .
            '), 0) as total_value',
            [WorkOrderStatus::COMPLETED]
        )->first();

        $workOrders = $query->with(['branch', 'customer', 'vehicle', 'mechanic']);

        if ($filters['mode'] === 'detail') {
            $workOrders->with(['serviceLines', 'sparepartLines']);
        } else {
            $workOrders->withSum('serviceLines as subtotal_service', 'line_total')
                ->withSum('sparepartLines as subtotal_sparepart', 'line_total');
        }

        $workOrders = $workOrders->orderByDesc('work_order_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('reports.pkb.index', [
            'workOrders' => $workOrders,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'mechanicSearch' => $filters['mechanicSearch'],
            'status' => $filters['status'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'mode' => $filters['mode'],
        ]);
    }

    public function exportExcel()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.pkb.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches)
            ->with(['branch', 'customer', 'vehicle', 'mechanic', 'serviceLines', 'sparepartLines']);

        return Excel::download(
            new PkbReportExport($query, $filters['mode'], $this->filterSummaryText($filters)),
            'laporan-pkb-' . now()->format('Ymd-His') . '.xlsx'
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
        $permittedBranches = $user->branchesWithPermission('report.pkb.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches)
            ->with(['branch', 'customer', 'vehicle', 'mechanic', 'serviceLines', 'sparepartLines']);

        $rows = $query->orderByDesc('work_order_date')->orderByDesc('id')->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);

        return $this->streamPdf('reports.pkb.pdf', [
            'workOrders' => $rows,
            'mode' => $filters['mode'],
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-pkb', $disposition);
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $mechanicSearch = is_string(request('mechanic')) ? trim(request('mechanic')) : null;

        $status = request('status');
        $status = in_array($status, [
            WorkOrderStatus::DRAFT, WorkOrderStatus::OPEN, WorkOrderStatus::SHORTAGE,
            WorkOrderStatus::COMPLETED, WorkOrderStatus::CANCELLED,
        ], true) ? $status : null;

        return [
            'branchIds' => $branchIds,
            'mechanicSearch' => $mechanicSearch,
            'status' => $status,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
            'mode' => request('mode') === 'detail' ? 'detail' : 'rekap',
        ];
    }

    protected function buildQuery(array $filters, SupportCollection $permittedBranches)
    {
        return WorkOrder::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('branch_id', $filters['branchIds']))
            ->when($filters['mechanicSearch'], function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('mechanic', function ($inner) use ($escaped) {
                    $inner->where('name', 'like', "%{$escaped}%");
                });
            })
            ->when($filters['status'], fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('work_order_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('work_order_date', '<=', $filters['dateTo']));
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $statusLabel = $filters['status'] ?? 'Semua Status';
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $mechanicLabel = $filters['mechanicSearch'] ? " · Mekanik: {$filters['mechanicSearch']}" : '';
        $modeLabel = $filters['mode'] === 'detail' ? 'Detail' : 'Rekap';

        return "Cabang: {$branchLabel} · Status: {$statusLabel} · Tanggal: {$dateLabel}{$mechanicLabel} · Tampilan: {$modeLabel}";
    }

    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
