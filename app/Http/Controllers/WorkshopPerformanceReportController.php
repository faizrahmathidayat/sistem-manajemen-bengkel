<?php

namespace App\Http\Controllers;

use App\Exports\WorkshopPerformanceInvoiceDetailExport;
use App\Exports\WorkshopPerformanceMechanicExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\Invoice;
use App\Support\InvoiceStatus;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class WorkshopPerformanceReportController extends Controller
{
    use HandlesReportExport;

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.workshop_performance.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.workshop-performance.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);

        $viewData = [
            'viewType' => $filters['viewType'],
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'status' => $filters['status'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'mechanicRows' => null,
            'invoices' => null,
        ];

        if ($filters['viewType'] === 'mechanic') {
            $rows = $this->buildMechanicQuery($filters, $permittedBranches)->get();
            $page = LengthAwarePaginator::resolveCurrentPage();
            $perPage = 15;
            $viewData['mechanicRows'] = new LengthAwarePaginator(
                $rows->forPage($page, $perPage)->values(),
                $rows->count(),
                $perPage,
                $page,
                ['path' => request()->url(), 'query' => request()->query()]
            );

            return view('reports.workshop-performance.index', $viewData);
        }

        $viewData['invoices'] = $this->buildInvoiceDetailQuery($filters, $permittedBranches)
            ->with(['branch', 'customer', 'workOrder.mechanic', 'details'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('reports.workshop-performance.index', $viewData);
    }

    public function exportExcel()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.workshop_performance.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);

        if ($filters['viewType'] === 'mechanic') {
            $rows = $this->buildMechanicQuery($filters, $permittedBranches)->get();

            return Excel::download(
                new WorkshopPerformanceMechanicExport($rows, $this->filterSummaryText($filters)),
                'laporan-performance-mekanik-' . now()->format('Ymd-His') . '.xlsx'
            );
        }

        $query = $this->buildInvoiceDetailQuery($filters, $permittedBranches)
            ->with(['branch', 'customer', 'workOrder.mechanic', 'details']);
        $rows = $query->orderByDesc('invoice_date')->orderByDesc('id')->limit(1001)->get();
        [$rows, ] = $this->capRows($rows);

        return Excel::download(
            new WorkshopPerformanceInvoiceDetailExport($rows, $this->filterSummaryText($filters)),
            'laporan-performance-invoice-detail-' . now()->format('Ymd-His') . '.xlsx'
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
        $permittedBranches = $user->branchesWithPermission('report.workshop_performance.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);

        if ($filters['viewType'] === 'mechanic') {
            $rows = $this->buildMechanicQuery($filters, $permittedBranches)->get();

            return $this->streamPdf('reports.workshop-performance.pdf', [
                'viewType' => $filters['viewType'],
                'mechanicRows' => $rows,
                'invoices' => collect(),
                'truncated' => false,
                'filterSummary' => $this->filterSummaryText($filters),
            ], 'laporan-performance-mekanik', $disposition);
        }

        $query = $this->buildInvoiceDetailQuery($filters, $permittedBranches)
            ->with(['branch', 'customer', 'workOrder.mechanic', 'details']);
        $rows = $query->orderByDesc('invoice_date')->orderByDesc('id')->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);

        return $this->streamPdf('reports.workshop-performance.pdf', [
            'viewType' => $filters['viewType'],
            'mechanicRows' => collect(),
            'invoices' => $rows,
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-performance-invoice-detail', $disposition);
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $status = request('status');
        $status = in_array($status, [
            InvoiceStatus::DRAFT, InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID,
            InvoiceStatus::PAID, InvoiceStatus::CANCELLED,
        ], true) ? $status : null;

        return [
            'branchIds' => $branchIds,
            'status' => $status,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
            'viewType' => request('view_type') === 'invoice_detail' ? 'invoice_detail' : 'mechanic',
        ];
    }

    protected function buildMechanicQuery(array $filters, SupportCollection $permittedBranches)
    {
        return Invoice::query()
            ->join('work_orders', 'work_orders.id', '=', 'invoices.work_order_id')
            ->join('mechanics', 'mechanics.id', '=', 'work_orders.mechanic_id')
            ->join('invoice_details', 'invoice_details.invoice_id', '=', 'invoices.id')
            ->whereIn('invoices.branch_id', $permittedBranches->pluck('id'))
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('invoices.branch_id', $filters['branchIds']))
            ->when($filters['status'], fn ($q) => $q->where('invoices.status', $filters['status']))
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoices.invoice_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoices.invoice_date', '<=', $filters['dateTo']))
            ->groupBy('mechanics.id')
            ->select([
                'mechanics.id as mechanic_id',
                'mechanics.name as mechanic_name',
                'mechanics.nip as mechanic_nip',
                DB::raw('COUNT(DISTINCT invoices.customer_id) as total_customer'),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'service' THEN invoice_details.qty ELSE 0 END), 0) as total_qty_jasa"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'service' THEN invoice_details.discount_amount ELSE 0 END), 0) as total_discount_jasa"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'service' THEN invoice_details.line_total ELSE 0 END), 0) as subtotal_jasa"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'sparepart' THEN invoice_details.qty ELSE 0 END), 0) as total_qty_sparepart"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'sparepart' THEN invoice_details.discount_amount ELSE 0 END), 0) as total_discount_sparepart"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'sparepart' THEN invoice_details.line_total ELSE 0 END), 0) as subtotal_sparepart"),
                DB::raw('MAX(invoices.invoice_date) as last_invoice_date'),
            ])
            ->orderByDesc('last_invoice_date');
    }

    protected function buildInvoiceDetailQuery(array $filters, SupportCollection $permittedBranches)
    {
        return Invoice::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('branch_id', $filters['branchIds']))
            ->when($filters['status'], fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoice_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoice_date', '<=', $filters['dateTo']));
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $statusLabel = $filters['status'] ?? 'Semua Status';
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $viewTypeLabel = $filters['viewType'] === 'invoice_detail' ? 'Invoice Detail' : 'Mekanik';

        return "Cabang: {$branchLabel} · Status: {$statusLabel} · Tanggal: {$dateLabel} · Tampilan: {$viewTypeLabel}";
    }

    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
