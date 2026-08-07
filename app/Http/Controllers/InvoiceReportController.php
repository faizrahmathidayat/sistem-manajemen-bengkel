<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\InvoiceStatus;

class InvoiceReportController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.invoice.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.invoices.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('search')) ? trim(request('search')) : null;

        $status = request('status');
        $status = in_array($status, [
            InvoiceStatus::DRAFT,
            InvoiceStatus::POSTED,
            InvoiceStatus::PARTIALLY_PAID,
            InvoiceStatus::PAID,
            InvoiceStatus::CANCELLED,
        ], true) ? $status : null;

        $dateFrom = $this->parseDate(request('date_from'));
        $dateTo = $this->parseDate(request('date_to'));

        $mode = request('mode') === 'detail' ? 'detail' : 'rekap';

        $query = Invoice::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when($search, function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->where(function ($inner) use ($escaped) {
                    $inner->where('number', 'like', "%{$escaped}%")
                        ->orWhereHas('customer', function ($c) use ($escaped) {
                            $c->where('name', 'like', "%{$escaped}%");
                        });
                });
            })
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($dateFrom, fn ($q) => $q->whereDate('invoice_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('invoice_date', '<=', $dateTo));

        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_invoice, ' .
            'COALESCE(SUM(grand_total), 0) as total_nominal, ' .
            'COALESCE(SUM(paid_amount), 0) as total_paid, ' .
            'COALESCE(SUM(grand_total - paid_amount), 0) as total_remaining'
        )->first();

        $invoices = $query->with(['branch', 'customer']);

        if ($mode === 'detail') {
            $invoices->with(['details']);
        }

        $invoices = $invoices->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('reports.invoices.index', [
            'invoices' => $invoices,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $branchIds,
            'search' => $search,
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
