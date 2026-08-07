<?php

namespace App\Http\Controllers;

use App\Exports\ReceivableReportExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\Invoice;
use App\Support\InvoiceStatus;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Facades\Excel;

class ReceivableReportController extends Controller
{
    use HandlesReportExport;

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.receivable.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.receivables.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches);

        $summary = (clone $query)->selectRaw(
            'COALESCE(SUM(grand_total), 0) as total_billed, ' .
            'COALESCE(SUM(paid_amount), 0) as total_paid, ' .
            'COALESCE(SUM(grand_total - paid_amount), 0) as total_outstanding'
        )->first();

        $invoices = $query->with(['branch', 'customer'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        $invoices->getCollection()->transform([$this, 'withAgingLabel']);

        return view('reports.receivables.index', [
            'invoices' => $invoices,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'customerSearch' => $filters['customerSearch'],
            'status' => $filters['status'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
        ]);
    }

    public function exportExcel()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.receivable.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches)->with(['branch', 'customer']);

        return Excel::download(
            new ReceivableReportExport($query, $this->filterSummaryText($filters)),
            'laporan-piutang-' . now()->format('Ymd-His') . '.xlsx'
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
        $permittedBranches = $user->branchesWithPermission('report.receivable.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches);

        $rows = $query->with(['branch', 'customer'])
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);
        $rows = $rows->map([$this, 'withAgingLabel']);

        return $this->streamPdf('reports.receivables.pdf', [
            'invoices' => $rows,
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-piutang', $disposition);
    }

    public function withAgingLabel(Invoice $invoice): Invoice
    {
        $referenceDate = $invoice->due_date ?? $invoice->invoice_date;
        $daysOverdue = (int) $referenceDate->diffInDays(now(), false);
        $invoice->aging_label = $daysOverdue >= 0 ? "{$daysOverdue} hari" : 'Belum jatuh tempo';

        return $invoice;
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $customerSearch = is_string(request('customer')) ? trim(request('customer')) : null;

        $status = request('status');
        $status = in_array($status, ['unpaid', 'paid', 'all'], true) ? $status : 'unpaid';

        return [
            'branchIds' => $branchIds,
            'customerSearch' => $customerSearch,
            'status' => $status,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
        ];
    }

    protected function buildQuery(array $filters, SupportCollection $permittedBranches)
    {
        if ($filters['status'] === 'paid') {
            $statuses = [InvoiceStatus::PAID];
        } elseif ($filters['status'] === 'all') {
            $statuses = [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID];
        } else {
            $statuses = [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID];
        }

        return Invoice::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->whereIn('status', $statuses)
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('branch_id', $filters['branchIds']))
            ->when($filters['customerSearch'], function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('customer', function ($inner) use ($escaped) {
                    $inner->where('name', 'like', "%{$escaped}%");
                });
            })
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoice_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoice_date', '<=', $filters['dateTo']));
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $statusLabels = ['unpaid' => 'Belum Lunas', 'paid' => 'Lunas', 'all' => 'Semua'];
        $statusLabel = $statusLabels[$filters['status']] ?? $filters['status'];
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $customerLabel = $filters['customerSearch'] ? " · Customer: {$filters['customerSearch']}" : '';

        return "Cabang: {$branchLabel} · Status: {$statusLabel} · Tanggal: {$dateLabel}{$customerLabel}";
    }

    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
