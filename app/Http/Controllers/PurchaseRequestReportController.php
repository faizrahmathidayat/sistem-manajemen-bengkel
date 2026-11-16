<?php

namespace App\Http\Controllers;

use App\Exports\PurchaseRequestReportExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\Branch;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\Supplier;
use App\Support\PurchaseRequestStatus;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Purchase request report. Its permission is global (report.purchase_request.view) and it
 * covers every branch, with an optional branch filter.
 */
class PurchaseRequestReportController extends Controller
{
    use HandlesReportExport;

    public function index()
    {
        $this->authorizeReport();

        $branches = Branch::orderBy('name')->get();
        $filters = $this->resolveFilters($branches);

        $rows = $this->buildRowsQuery($filters)->paginate(10)->withQueryString();

        return view('reports.purchase-request.index', [
            'rows' => $rows,
            'summary' => $this->buildSummary($filters),
            'branches' => $branches,
            'selectedBranchIds' => $filters['branchIds'],
            'status' => $filters['status'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'mode' => $filters['mode'],
            'sparepartId' => $filters['sparepartId'],
            'supplier' => $filters['supplierId'] ? Supplier::find($filters['supplierId']) : null,
        ]);
    }

    public function exportExcel()
    {
        $this->authorizeReport();
        $filters = $this->resolveFilters(Branch::orderBy('name')->get());

        return Excel::download(
            new PurchaseRequestReportExport($this->buildRowsQuery($filters), $filters['mode'], $this->filterSummaryText($filters)),
            'laporan-permintaan-barang-' . now()->format('Ymd-His') . '.xlsx'
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

        return $this->streamPdf('reports.purchase-request.pdf', [
            'rows' => $rows,
            'mode' => $filters['mode'],
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-permintaan-barang', $disposition);
    }

    protected function authorizeReport(): void
    {
        abort_unless(auth()->user()->hasPermissionTo('report.purchase_request.view'), 403);
    }

    protected function resolveFilters($branches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->filter(fn ($id) => is_scalar($id))
            ->map(fn ($id) => (int) $id)
            ->intersect($branches->pluck('id'))
            ->values()->all();

        $status = is_string(request('status')) && array_key_exists(request('status'), PurchaseRequestStatus::LABELS)
            ? request('status') : null;
        $mode = request('mode') === 'detail' ? 'detail' : 'rekap';
        $supplierId = is_scalar(request('supplier_id')) ? (int) request('supplier_id') : 0;
        $sparepartId = is_scalar(request('sparepart_id')) ? (int) request('sparepart_id') : 0;

        return [
            'branchIds' => $branchIds,
            'status' => $status,
            'supplierId' => $supplierId > 0 ? $supplierId : null,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
            'mode' => $mode,
            // The sparepart filter only makes sense per line (detail mode).
            'sparepartId' => $mode === 'detail' && $sparepartId > 0 ? $sparepartId : null,
        ];
    }

    /** Rekap: one row per request. Detail: one row per request line. */
    protected function buildRowsQuery(array $filters)
    {
        if ($filters['mode'] === 'detail') {
            $query = PurchaseRequestLine::query()
                ->join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_lines.purchase_request_id')
                ->select('purchase_request_lines.*')
                ->with(['purchaseRequest.branch', 'sparepartBranch.sparepart', 'supplier'])
                ->when($filters['supplierId'], fn ($q, $id) => $q->where('purchase_request_lines.supplier_id', $id))
                ->when($filters['sparepartId'], fn ($q, $id) => $q->whereIn(
                    'purchase_request_lines.sparepart_branch_id',
                    SparepartBranch::where('sparepart_id', $id)->select('id')
                ));
            $this->applyRequestFilters($query, $filters);

            return $query
                ->orderByDesc('purchase_requests.request_date')
                ->orderByDesc('purchase_requests.id')
                ->orderBy('purchase_request_lines.sort_order');
        }

        $query = PurchaseRequest::query()
            ->select('purchase_requests.*')
            ->with(['branch', 'creator', 'approvals'])
            ->withCount('lines')
            ->withSum('lines as lines_amount', 'line_total')
            ->when($filters['supplierId'], function ($q, $id) {
                $q->whereHas('lines', fn ($lines) => $lines->where('supplier_id', $id));
            });
        $this->applyRequestFilters($query, $filters);

        return $query->orderByDesc('purchase_requests.request_date')->orderByDesc('purchase_requests.id');
    }

    protected function applyRequestFilters($query, array $filters): void
    {
        $query
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('purchase_requests.branch_id', $filters['branchIds']))
            ->when($filters['status'], fn ($q, $status) => $q->where('purchase_requests.status', $status))
            ->when($filters['dateFrom'], fn ($q, $d) => $q->whereDate('purchase_requests.request_date', '>=', $d))
            ->when($filters['dateTo'], fn ($q, $d) => $q->whereDate('purchase_requests.request_date', '<=', $d));
    }

    /**
     * Totals follow the active filters. Cancelled requests stay in the list and in the document
     * count but never add to the value.
     */
    protected function buildSummary(array $filters): array
    {
        $requests = function () use ($filters) {
            $query = PurchaseRequest::query()
                ->when($filters['supplierId'], function ($q, $id) {
                    $q->whereHas('lines', fn ($lines) => $lines->where('supplier_id', $id));
                });
            $this->applyRequestFilters($query, $filters);

            return $query;
        };

        $valueIds = $requests()->where('purchase_requests.status', '!=', PurchaseRequestStatus::CANCELLED)->select('purchase_requests.id');

        return [
            'total_dokumen' => $requests()->count(),
            'menunggu_approval' => $requests()->where('purchase_requests.status', PurchaseRequestStatus::WAITING_APPROVAL)->count(),
            'ditolak' => $requests()->where('purchase_requests.status', PurchaseRequestStatus::REJECTED)->count(),
            'total_nilai' => (float) PurchaseRequestLine::whereIn('purchase_request_id', $valueIds)
                ->where('is_selected', true)
                ->when($filters['supplierId'], fn ($q, $id) => $q->where('supplier_id', $id))
                ->sum('line_total'),
        ];
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds'])
            ? 'Semua Cabang'
            : Branch::whereIn('id', $filters['branchIds'])->orderBy('name')->pluck('name')->implode(', ');
        $statusLabel = $filters['status'] ? PurchaseRequestStatus::LABELS[$filters['status']] : 'Semua Status';
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $supplierLabel = $filters['supplierId'] && ($supplier = Supplier::find($filters['supplierId']))
            ? " · Supplier: {$supplier->name}" : '';
        $sparepartLabel = '';
        if ($filters['sparepartId'] && ($sparepart = Sparepart::find($filters['sparepartId']))) {
            $sparepartLabel = " · Sparepart: {$sparepart->code} — {$sparepart->name}";
        }
        $modeLabel = $filters['mode'] === 'detail' ? 'Detail' : 'Rekap';

        return "Cabang: {$branchLabel} · Status: {$statusLabel} · Tanggal: {$dateLabel}{$supplierLabel}{$sparepartLabel} · Tampilan: {$modeLabel}";
    }

    protected function parseDate($value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
