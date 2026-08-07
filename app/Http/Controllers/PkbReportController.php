<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Support\WorkOrderStatus;

class PkbReportController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.pkb.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.pkb.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $mechanicSearch = is_string(request('mechanic')) ? trim(request('mechanic')) : null;

        $status = request('status');
        $status = in_array($status, [
            WorkOrderStatus::DRAFT,
            WorkOrderStatus::OPEN,
            WorkOrderStatus::SHORTAGE,
            WorkOrderStatus::COMPLETED,
            WorkOrderStatus::CANCELLED,
        ], true) ? $status : null;

        $dateFrom = $this->parseDate(request('date_from'));
        $dateTo = $this->parseDate(request('date_to'));

        $mode = request('mode') === 'detail' ? 'detail' : 'rekap';

        $query = WorkOrder::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when($mechanicSearch, function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('mechanic', function ($inner) use ($escaped) {
                    $inner->where('name', 'like', "%{$escaped}%");
                });
            })
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($dateFrom, fn ($q) => $q->whereDate('work_order_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('work_order_date', '<=', $dateTo));

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

        if ($mode === 'detail') {
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
            'selectedBranchIds' => $branchIds,
            'mechanicSearch' => $mechanicSearch,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'mode' => $mode,
        ]);
    }

    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
