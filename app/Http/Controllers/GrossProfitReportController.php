<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\Invoice;
use App\Support\InvoiceStatus;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class GrossProfitReportController extends Controller
{
    use HandlesReportExport;

    const COUNTED_STATUSES = [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID];

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.gross_profit.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.gross-profit.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);

        $viewData = [
            'viewType' => $filters['viewType'],
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'summaryRows' => null,
            'invoices' => null,
        ];

        if ($filters['viewType'] === 'invoice_detail') {
            $viewData['invoices'] = $this->buildInvoiceDetailQuery($filters, $permittedBranches)
                ->with(['branch', 'customer'])
                ->orderByDesc('invoice_date')
                ->orderByDesc('id')
                ->simplePaginate(15)
                ->withQueryString();

            return view('reports.gross-profit.index', $viewData);
        }

        $rows = $this->buildSummaryQuery($filters, $permittedBranches)->get();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $perPage = 15;
        $viewData['summaryRows'] = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return view('reports.gross-profit.index', $viewData);
    }

    public function exportExcel()
    {
        abort(501, 'Belum diimplementasikan — lihat Task 9.');
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
        $permittedBranches = $user->branchesWithPermission('report.gross_profit.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);

        if ($filters['viewType'] === 'invoice_detail') {
            $rows = $this->buildInvoiceDetailQuery($filters, $permittedBranches)
                ->with(['branch', 'customer'])
                ->orderByDesc('invoice_date')->orderByDesc('id')
                ->limit(1001)->get();
            [$rows, $truncated] = $this->capRows($rows);

            return $this->streamPdf('reports.gross-profit.pdf', [
                'viewType' => $filters['viewType'],
                'branches' => $permittedBranches,
                'summaryRows' => collect(),
                'invoices' => $rows,
                'truncated' => $truncated,
                'filterSummary' => $this->filterSummaryText($filters),
            ], 'laporan-laba-rugi-detail', $disposition);
        }

        $rows = $this->buildSummaryQuery($filters, $permittedBranches)->get();

        return $this->streamPdf('reports.gross-profit.pdf', [
            'viewType' => $filters['viewType'],
            'branches' => $permittedBranches,
            'summaryRows' => $rows,
            'invoices' => collect(),
            'truncated' => false,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-laba-rugi-ringkasan', $disposition);
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        return [
            'branchIds' => $branchIds,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
            'viewType' => request('view_type') === 'invoice_detail' ? 'invoice_detail' : 'summary',
        ];
    }

    protected function buildSummaryQuery(array $filters, SupportCollection $permittedBranches)
    {
        return Invoice::query()
            ->join('invoice_details', 'invoice_details.invoice_id', '=', 'invoices.id')
            ->whereIn('invoices.branch_id', $permittedBranches->pluck('id'))
            ->whereIn('invoices.status', self::COUNTED_STATUSES)
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('invoices.branch_id', $filters['branchIds']))
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoices.invoice_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoices.invoice_date', '<=', $filters['dateTo']))
            ->groupBy('invoices.branch_id', DB::raw("DATE_FORMAT(invoices.invoice_date, '%Y-%m')"))
            ->select([
                'invoices.branch_id',
                DB::raw("DATE_FORMAT(invoices.invoice_date, '%Y-%m') as period"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'service' THEN invoice_details.line_total ELSE 0 END), 0) as pendapatan_jasa"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'sparepart' THEN invoice_details.line_total ELSE 0 END), 0) as pendapatan_sparepart"),
                DB::raw('COALESCE(SUM(invoice_details.qty * invoice_details.hpp_snapshot), 0) as total_hpp'),
            ])
            ->orderBy('period')->orderBy('invoices.branch_id');
    }

    protected function buildInvoiceDetailQuery(array $filters, SupportCollection $permittedBranches)
    {
        return Invoice::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->whereIn('status', self::COUNTED_STATUSES)
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('branch_id', $filters['branchIds']))
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoice_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoice_date', '<=', $filters['dateTo']))
            ->select('invoices.*')
            ->selectSub(function ($q) {
                $q->from('invoice_details')
                    ->selectRaw('COALESCE(SUM(line_total), 0)')
                    ->whereColumn('invoice_details.invoice_id', 'invoices.id')
                    ->where('item_type', 'service');
            }, 'pendapatan_jasa')
            ->selectSub(function ($q) {
                $q->from('invoice_details')
                    ->selectRaw('COALESCE(SUM(line_total), 0)')
                    ->whereColumn('invoice_details.invoice_id', 'invoices.id')
                    ->where('item_type', 'sparepart');
            }, 'pendapatan_sparepart')
            ->selectSub(function ($q) {
                $q->from('invoice_details')
                    ->selectRaw('COALESCE(SUM(qty * hpp_snapshot), 0)')
                    ->whereColumn('invoice_details.invoice_id', 'invoices.id');
            }, 'total_hpp');
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $viewTypeLabel = $filters['viewType'] === 'invoice_detail' ? 'Detail per Invoice' : 'Ringkasan';

        return "Cabang: {$branchLabel} · Tanggal: {$dateLabel} · Tampilan: {$viewTypeLabel} · Status: Diposting/Dibayar Sebagian/Lunas";
    }

    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
