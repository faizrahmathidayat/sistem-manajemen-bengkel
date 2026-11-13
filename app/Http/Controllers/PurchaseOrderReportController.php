<?php

namespace App\Http\Controllers;

use App\Exports\PurchaseOrderReportExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\Supplier;
use App\Support\PurchaseOrderStatus;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Purchase order report: rekap (per PO), detail (per line) and per supplier. Global permission
 * (report.purchase_order.view), every branch. Cancelled orders appear in the lists but are
 * never counted in the totals; "received" is derived from qty_received, not from the status.
 */
class PurchaseOrderReportController extends Controller
{
    use HandlesReportExport;

    public function index()
    {
        $this->authorizeReport();

        $branches = Branch::orderBy('name')->get();
        $filters = $this->resolveFilters($branches);

        $rows = $this->buildRowsQuery($filters)->paginate(10)->withQueryString();

        return view('reports.purchase-order.index', [
            'rows' => $rows,
            'summary' => $this->buildSummary($filters),
            'branches' => $branches,
            'selectedBranchIds' => $filters['branchIds'],
            'status' => $filters['status'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'mode' => $filters['mode'],
            'outstanding' => $filters['outstanding'],
            'sparepartId' => $filters['sparepartId'],
            'supplier' => $filters['supplierId'] ? Supplier::find($filters['supplierId']) : null,
        ]);
    }

    public function exportExcel()
    {
        $this->authorizeReport();
        $filters = $this->resolveFilters(Branch::orderBy('name')->get());

        return Excel::download(
            new PurchaseOrderReportExport($this->buildRowsQuery($filters), $filters['mode'], $this->filterSummaryText($filters)),
            'laporan-pembelian-barang-' . now()->format('Ymd-His') . '.xlsx'
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
        $this->authorizeReport();
        $filters = $this->resolveFilters(Branch::orderBy('name')->get());

        $rows = $this->buildRowsQuery($filters)->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);

        return $this->streamPdf('reports.purchase-order.pdf', [
            'rows' => $rows,
            'mode' => $filters['mode'],
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-pembelian-barang', $disposition);
    }

    protected function authorizeReport(): void
    {
        abort_unless(auth()->user()->hasPermissionTo('report.purchase_order.view'), 403);
    }

    protected function resolveFilters($branches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->filter(fn ($id) => is_scalar($id))
            ->map(fn ($id) => (int) $id)
            ->intersect($branches->pluck('id'))
            ->values()->all();

        $status = is_string(request('status')) && array_key_exists(request('status'), PurchaseOrderStatus::LABELS)
            ? request('status') : null;
        $mode = in_array(request('mode'), ['detail', 'supplier'], true) ? request('mode') : 'rekap';
        $supplierId = is_scalar(request('supplier_id')) ? (int) request('supplier_id') : 0;
        $sparepartId = is_scalar(request('sparepart_id')) ? (int) request('sparepart_id') : 0;

        return [
            'branchIds' => $branchIds,
            'status' => $status,
            'supplierId' => $supplierId > 0 ? $supplierId : null,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
            'mode' => $mode,
            'outstanding' => request('outstanding') === '1',
            'sparepartId' => $mode === 'detail' && $sparepartId > 0 ? $sparepartId : null,
        ];
    }

    protected function buildRowsQuery(array $filters)
    {
        if ($filters['mode'] === 'detail') {
            $query = PurchaseOrderLine::query()
                ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
                ->select('purchase_order_lines.*')
                ->with(['purchaseOrder.branch', 'purchaseOrder.supplier', 'sparepartBranch.sparepart'])
                ->when($filters['sparepartId'], fn ($q, $id) => $q->whereIn(
                    'purchase_order_lines.sparepart_branch_id',
                    SparepartBranch::where('sparepart_id', $id)->select('id')
                ));
            $this->applyOrderFilters($query, $filters);

            return $query
                ->orderByDesc('purchase_orders.order_date')
                ->orderByDesc('purchase_orders.id')
                ->orderBy('purchase_order_lines.sort_order');
        }

        if ($filters['mode'] === 'supplier') {
            $query = PurchaseOrder::query()
                ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
                ->join('purchase_order_lines as pol', 'pol.purchase_order_id', '=', 'purchase_orders.id')
                ->where('purchase_orders.status', '!=', PurchaseOrderStatus::CANCELLED)
                ->groupBy('suppliers.id', 'suppliers.name')
                ->selectRaw(
                    'suppliers.id as supplier_id, suppliers.name as supplier_name, '
                    . 'COUNT(DISTINCT purchase_orders.id) as po_count, '
                    . 'COALESCE(SUM(pol.line_total), 0) as ordered_value, '
                    . 'COALESCE(SUM(pol.qty_received * pol.unit_price), 0) as received_value'
                );
            $this->applyOrderFilters($query, $filters);

            return $query->orderBy('suppliers.name');
        }

        $lines = fn () => DB::table('purchase_order_lines')->whereColumn('purchase_order_lines.purchase_order_id', 'purchase_orders.id');

        $query = PurchaseOrder::query()
            ->select('purchase_orders.*')
            ->selectSub($lines()->selectRaw('COALESCE(SUM(qty_received * unit_price), 0)'), 'received_value')
            ->selectSub($lines()->selectRaw('COALESCE(SUM(qty_ordered), 0)'), 'qty_ordered_sum')
            ->selectSub($lines()->selectRaw('COALESCE(SUM(qty_received), 0)'), 'qty_received_sum')
            ->with(['branch', 'supplier']);
        $this->applyOrderFilters($query, $filters);

        return $query->orderByDesc('purchase_orders.order_date')->orderByDesc('purchase_orders.id');
    }

    protected function applyOrderFilters($query, array $filters): void
    {
        $query
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('purchase_orders.branch_id', $filters['branchIds']))
            ->when($filters['supplierId'], fn ($q, $id) => $q->where('purchase_orders.supplier_id', $id))
            ->when($filters['status'], fn ($q, $status) => $q->where('purchase_orders.status', $status))
            ->when($filters['outstanding'], fn ($q) => $q->whereIn('purchase_orders.status', PurchaseOrderStatus::RECEIVABLE))
            ->when($filters['dateFrom'], fn ($q, $d) => $q->whereDate('purchase_orders.order_date', '>=', $d))
            ->when($filters['dateTo'], fn ($q, $d) => $q->whereDate('purchase_orders.order_date', '<=', $d));
    }

    protected function buildSummary(array $filters): array
    {
        $orders = PurchaseOrder::query()->where('purchase_orders.status', '!=', PurchaseOrderStatus::CANCELLED);
        $this->applyOrderFilters($orders, $filters);

        $totals = PurchaseOrderLine::query()
            ->whereIn('purchase_order_id', (clone $orders)->select('purchase_orders.id'))
            ->when($filters['sparepartId'], fn ($q, $id) => $q->whereIn(
                'sparepart_branch_id',
                SparepartBranch::where('sparepart_id', $id)->select('id')
            ))
            ->selectRaw('COALESCE(SUM(line_total), 0) as ordered, COALESCE(SUM(qty_received * unit_price), 0) as received')
            ->first();

        $ordered = (float) $totals->ordered;
        $received = (float) $totals->received;

        return [
            'jumlah_po' => (clone $orders)->count(),
            'nilai_dipesan' => $ordered,
            'nilai_diterima' => $received,
            'nilai_outstanding' => round($ordered - $received, 2),
        ];
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds'])
            ? 'Semua Cabang'
            : Branch::whereIn('id', $filters['branchIds'])->orderBy('name')->pluck('name')->implode(', ');
        $statusLabel = $filters['status'] ? PurchaseOrderStatus::LABELS[$filters['status']] : 'Semua Status';
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $supplierLabel = $filters['supplierId'] && ($supplier = Supplier::find($filters['supplierId']))
            ? " · Supplier: {$supplier->name}" : '';
        $sparepartLabel = '';
        if ($filters['sparepartId'] && ($sparepart = Sparepart::find($filters['sparepartId']))) {
            $sparepartLabel = " · Sparepart: {$sparepart->code} — {$sparepart->name}";
        }
        $outstandingLabel = $filters['outstanding'] ? ' · Hanya belum selesai diterima' : '';
        $modeLabel = ['rekap' => 'Rekap', 'detail' => 'Detail', 'supplier' => 'Per Supplier'][$filters['mode']];

        return "Cabang: {$branchLabel} · Status: {$statusLabel} · Tanggal: {$dateLabel}{$supplierLabel}{$sparepartLabel}{$outstandingLabel} · Tampilan: {$modeLabel}";
    }

    protected function parseDate($value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
