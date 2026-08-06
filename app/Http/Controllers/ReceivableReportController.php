<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\InvoiceStatus;

class ReceivableReportController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.receivable.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.receivables.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $customerSearch = is_string(request('customer')) ? trim(request('customer')) : null;

        $status = request('status');
        $status = in_array($status, ['unpaid', 'paid', 'all'], true) ? $status : 'unpaid';

        $dateFrom = $this->parseDate(request('date_from'));
        $dateTo = $this->parseDate(request('date_to'));

        if ($status === 'paid') {
            $statuses = [InvoiceStatus::PAID];
        } elseif ($status === 'all') {
            $statuses = [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID];
        } else {
            $statuses = [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID];
        }

        $query = Invoice::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->whereIn('status', $statuses)
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when($customerSearch, function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('customer', function ($inner) use ($escaped) {
                    $inner->where('name', 'like', "%{$escaped}%");
                });
            })
            ->when($dateFrom, fn ($q) => $q->whereDate('invoice_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('invoice_date', '<=', $dateTo));

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

        $invoices->getCollection()->transform(function (Invoice $invoice) {
            $referenceDate = $invoice->due_date ?? $invoice->invoice_date;
            $daysOverdue = (int) $referenceDate->diffInDays(now(), false);
            $invoice->aging_label = $daysOverdue >= 0 ? "{$daysOverdue} hari" : 'Belum jatuh tempo';

            return $invoice;
        });

        return view('reports.receivables.index', [
            'invoices' => $invoices,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $branchIds,
            'customerSearch' => $customerSearch,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
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
