<?php

namespace App\Http\Controllers;

use App\Exports\InvoicePkbGapReportExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\Invoice;
use App\Support\InvoicePkbGapComparator;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Facades\Excel;

class InvoicePkbGapReportController extends Controller
{
    use HandlesReportExport;

    protected function pkbTotalExpression(): string
    {
        return '(
            COALESCE((SELECT SUM(line_total) FROM work_order_service_lines WHERE work_order_service_lines.work_order_id = invoices.work_order_id), 0)
            +
            COALESCE((SELECT SUM(line_total) FROM work_order_sparepart_lines WHERE work_order_sparepart_lines.work_order_id = invoices.work_order_id), 0)
        )';
    }

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.invoice_pkb_gap.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.invoice-pkb-gap.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches);
        $pkbTotalExpr = $this->pkbTotalExpression();

        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_transaksi, ' .
            "COALESCE(SUM({$pkbTotalExpr}), 0) as total_nilai_pkb, " .
            'COALESCE(SUM(invoices.grand_total), 0) as total_nilai_invoice, ' .
            "COALESCE(SUM(invoices.grand_total - {$pkbTotalExpr}), 0) as total_varian_netto"
        )->first();

        $invoicesQuery = $query->select('invoices.*')
            ->selectRaw("{$pkbTotalExpr} as pkb_total")
            ->with(['branch', 'customer', 'workOrder']);

        if ($filters['mode'] === 'detail') {
            $invoicesQuery->with(['details', 'workOrder.serviceLines', 'workOrder.sparepartLines']);
        }

        $invoices = $invoicesQuery->orderByDesc('invoices.invoice_date')
            ->orderByDesc('invoices.id')
            ->simplePaginate(15)
            ->withQueryString();

        if ($filters['mode'] === 'detail') {
            $invoices->getCollection()->transform(function (Invoice $invoice) {
                $invoice->comparisonLines = InvoicePkbGapComparator::build($invoice);

                return $invoice;
            });
        }

        return view('reports.invoice-pkb-gap.index', [
            'invoices' => $invoices,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'search' => $filters['search'],
            'gapStatus' => $filters['gapStatus'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'mode' => $filters['mode'],
        ]);
    }

    public function exportExcel()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.invoice_pkb_gap.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches)
            ->select('invoices.*')
            ->selectRaw("{$this->pkbTotalExpression()} as pkb_total")
            ->with(['branch', 'customer', 'workOrder.serviceLines', 'workOrder.sparepartLines', 'details']);

        return Excel::download(
            new InvoicePkbGapReportExport($query, $filters['mode'], $this->filterSummaryText($filters)),
            'laporan-gap-invoice-pkb-' . now()->format('Ymd-His') . '.xlsx'
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
        $permittedBranches = $user->branchesWithPermission('report.invoice_pkb_gap.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches)
            ->select('invoices.*')
            ->selectRaw("{$this->pkbTotalExpression()} as pkb_total")
            ->with(['branch', 'customer', 'workOrder.serviceLines', 'workOrder.sparepartLines', 'details']);

        $rows = $query->orderByDesc('invoices.invoice_date')->orderByDesc('invoices.id')->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);

        if ($filters['mode'] === 'detail') {
            $rows = $rows->map(function (Invoice $invoice) {
                $invoice->comparisonLines = InvoicePkbGapComparator::build($invoice);

                return $invoice;
            });
        }

        return $this->streamPdf('reports.invoice-pkb-gap.pdf', [
            'invoices' => $rows,
            'mode' => $filters['mode'],
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-gap-invoice-pkb', $disposition);
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('search')) ? trim(request('search')) : null;

        $gapStatus = request('gap_status');
        $gapStatus = in_array($gapStatus, ['ada_selisih', 'invoice_gt_pkb', 'invoice_lt_pkb', 'sesuai', 'semua'], true)
            ? $gapStatus : 'ada_selisih';

        return [
            'branchIds' => $branchIds,
            'search' => $search,
            'gapStatus' => $gapStatus,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
            'mode' => request('mode') === 'detail' ? 'detail' : 'rekap',
        ];
    }

    protected function buildQuery(array $filters, SupportCollection $permittedBranches)
    {
        $pkbTotalExpr = $this->pkbTotalExpression();

        return Invoice::query()
            ->whereNotNull('invoices.work_order_id')
            ->whereIn('invoices.branch_id', $permittedBranches->pluck('id'))
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('invoices.branch_id', $filters['branchIds']))
            ->when($filters['search'], function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->where(function ($inner) use ($escaped) {
                    $inner->where('invoices.number', 'like', "%{$escaped}%")
                        ->orWhereHas('customer', function ($c) use ($escaped) {
                            $c->where('name', 'like', "%{$escaped}%");
                        })
                        ->orWhereHas('workOrder', function ($w) use ($escaped) {
                            $w->where('number', 'like', "%{$escaped}%");
                        });
                });
            })
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoices.invoice_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoices.invoice_date', '<=', $filters['dateTo']))
            ->when($filters['gapStatus'] === 'ada_selisih', fn ($q) => $q->whereRaw("invoices.grand_total <> {$pkbTotalExpr}"))
            ->when($filters['gapStatus'] === 'invoice_gt_pkb', fn ($q) => $q->whereRaw("invoices.grand_total > {$pkbTotalExpr}"))
            ->when($filters['gapStatus'] === 'invoice_lt_pkb', fn ($q) => $q->whereRaw("invoices.grand_total < {$pkbTotalExpr}"))
            ->when($filters['gapStatus'] === 'sesuai', fn ($q) => $q->whereRaw("invoices.grand_total = {$pkbTotalExpr}"));
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $gapLabels = ['ada_selisih' => 'Ada Selisih', 'invoice_gt_pkb' => 'Invoice > PKB', 'invoice_lt_pkb' => 'Invoice < PKB', 'sesuai' => 'Sesuai', 'semua' => 'Semua'];
        $gapLabel = $gapLabels[$filters['gapStatus']] ?? $filters['gapStatus'];
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $searchLabel = $filters['search'] ? " · Cari: {$filters['search']}" : '';
        $modeLabel = $filters['mode'] === 'detail' ? 'Detail' : 'Rekap';

        return "Cabang: {$branchLabel} · Status Selisih: {$gapLabel} · Tanggal: {$dateLabel}{$searchLabel} · Tampilan: {$modeLabel}";
    }

    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
