<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\InvoiceDetailItemType;

class InvoicePkbGapReportController extends Controller
{
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

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('search')) ? trim(request('search')) : null;

        $gapStatus = request('gap_status');
        $gapStatus = in_array($gapStatus, ['ada_selisih', 'invoice_gt_pkb', 'invoice_lt_pkb', 'sesuai', 'semua'], true)
            ? $gapStatus : 'ada_selisih';

        $dateFrom = $this->parseDate(request('date_from'));
        $dateTo = $this->parseDate(request('date_to'));

        $mode = request('mode') === 'detail' ? 'detail' : 'rekap';

        $pkbTotalExpr = $this->pkbTotalExpression();

        $query = Invoice::query()
            ->whereNotNull('invoices.work_order_id')
            ->whereIn('invoices.branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($q) => $q->whereIn('invoices.branch_id', $branchIds))
            ->when($search, function ($q, $term) {
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
            ->when($dateFrom, fn ($q) => $q->whereDate('invoices.invoice_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('invoices.invoice_date', '<=', $dateTo))
            ->when($gapStatus === 'ada_selisih', fn ($q) => $q->whereRaw("invoices.grand_total <> {$pkbTotalExpr}"))
            ->when($gapStatus === 'invoice_gt_pkb', fn ($q) => $q->whereRaw("invoices.grand_total > {$pkbTotalExpr}"))
            ->when($gapStatus === 'invoice_lt_pkb', fn ($q) => $q->whereRaw("invoices.grand_total < {$pkbTotalExpr}"))
            ->when($gapStatus === 'sesuai', fn ($q) => $q->whereRaw("invoices.grand_total = {$pkbTotalExpr}"));

        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_transaksi, ' .
            "COALESCE(SUM({$pkbTotalExpr}), 0) as total_nilai_pkb, " .
            'COALESCE(SUM(invoices.grand_total), 0) as total_nilai_invoice, ' .
            "COALESCE(SUM(invoices.grand_total - {$pkbTotalExpr}), 0) as total_varian_netto"
        )->first();

        $invoicesQuery = $query->select('invoices.*')
            ->selectRaw("{$pkbTotalExpr} as pkb_total")
            ->with(['branch', 'customer', 'workOrder']);

        if ($mode === 'detail') {
            $invoicesQuery->with(['details', 'workOrder.serviceLines', 'workOrder.sparepartLines']);
        }

        $invoices = $invoicesQuery->orderByDesc('invoices.invoice_date')
            ->orderByDesc('invoices.id')
            ->simplePaginate(15)
            ->withQueryString();

        if ($mode === 'detail') {
            $invoices->getCollection()->transform(function (Invoice $invoice) {
                $invoice->comparisonLines = $this->buildComparisonLines($invoice);

                return $invoice;
            });
        }

        return view('reports.invoice-pkb-gap.index', [
            'invoices' => $invoices,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $branchIds,
            'search' => $search,
            'gapStatus' => $gapStatus,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'mode' => $mode,
        ]);
    }

    protected function buildComparisonLines(Invoice $invoice): array
    {
        $workOrder = $invoice->workOrder;
        $detailsByServiceLineId = $invoice->details->whereNotNull('work_order_service_line_id')->keyBy('work_order_service_line_id');
        $detailsBySparepartLineId = $invoice->details->whereNotNull('work_order_sparepart_line_id')->keyBy('work_order_sparepart_line_id');

        $rows = [];

        foreach ($workOrder->serviceLines as $line) {
            $rows[] = $this->compareLine('Jasa', $line->description, $line, $detailsByServiceLineId->get($line->id));
        }

        foreach ($workOrder->sparepartLines as $line) {
            $rows[] = $this->compareLine('Sparepart', $line->item_name_snapshot, $line, $detailsBySparepartLineId->get($line->id));
        }

        $addedDetails = $invoice->details
            ->whereNull('work_order_service_line_id')
            ->whereNull('work_order_sparepart_line_id');

        foreach ($addedDetails as $detail) {
            $rows[] = [
                'item_type' => $detail->item_type === InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart',
                'item_name' => $detail->description,
                'pkb_qty' => null,
                'pkb_price' => null,
                'invoice_qty' => (float) $detail->qty,
                'invoice_price' => (float) $detail->unit_price,
                'category' => 'added',
            ];
        }

        return $rows;
    }

    protected function compareLine(string $itemType, string $itemName, $pkbLine, $detail): array
    {
        if (! $detail) {
            return [
                'item_type' => $itemType,
                'item_name' => $itemName,
                'pkb_qty' => (float) $pkbLine->qty,
                'pkb_price' => (float) $pkbLine->unit_price,
                'invoice_qty' => null,
                'invoice_price' => null,
                'category' => 'removed',
            ];
        }

        $unchanged = (float) $pkbLine->qty === (float) $detail->qty
            && (float) $pkbLine->unit_price === (float) $detail->unit_price;

        return [
            'item_type' => $itemType,
            'item_name' => $itemName,
            'pkb_qty' => (float) $pkbLine->qty,
            'pkb_price' => (float) $pkbLine->unit_price,
            'invoice_qty' => (float) $detail->qty,
            'invoice_price' => (float) $detail->unit_price,
            'category' => $unchanged ? 'sesuai' : 'changed',
        ];
    }

    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
