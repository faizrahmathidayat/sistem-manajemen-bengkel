<?php

namespace App\Http\Controllers;

use App\Exports\GoodsReceiptReportExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Support\GoodsReceiptStatus;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class GoodsReceiptReportController extends Controller
{
    use HandlesReportExport;

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.goods_receipt.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.goods-receipt.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);

        $rows = $this->buildRowsQuery($filters, $permittedBranches)
            ->paginate(10)
            ->withQueryString();

        return view('reports.goods-receipt.index', [
            'rows' => $rows,
            'summary' => $this->buildSummary($filters, $permittedBranches),
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'status' => $filters['status'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'mode' => $filters['mode'],
            'sparepartId' => $filters['sparepartId'],
        ]);
    }

    public function exportExcel()
    {
        $permittedBranches = auth()->user()->branchesWithPermission('report.goods_receipt.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);

        return Excel::download(
            new GoodsReceiptReportExport(
                $this->buildRowsQuery($filters, $permittedBranches),
                $filters['mode'],
                $this->filterSummaryText($filters)
            ),
            'laporan-penerimaan-barang-' . now()->format('Ymd-His') . '.xlsx'
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
        $permittedBranches = auth()->user()->branchesWithPermission('report.goods_receipt.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);

        $rows = $this->buildRowsQuery($filters, $permittedBranches)->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);

        return $this->streamPdf('reports.goods-receipt.pdf', [
            'rows' => $rows,
            'mode' => $filters['mode'],
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-penerimaan-barang', $disposition);
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $status = request('status');
        $status = in_array($status, [
            GoodsReceiptStatus::DRAFT, GoodsReceiptStatus::POSTED, GoodsReceiptStatus::CANCELLED,
        ], true) ? $status : null;

        $mode = request('mode') === 'detail' ? 'detail' : 'rekap';
        $sparepartId = (int) request('sparepart_id');

        return [
            'branchIds' => $branchIds,
            'status' => $status,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
            'mode' => $mode,
            // The sparepart filter is a detail-mode concern; rekap rows are whole documents.
            'sparepartId' => $mode === 'detail' && $sparepartId > 0 ? $sparepartId : null,
        ];
    }

    /**
     * Rekap: one row per goods receipt. Detail: one row per goods receipt line.
     */
    protected function buildRowsQuery(array $filters, SupportCollection $permittedBranches)
    {
        if ($filters['mode'] === 'detail') {
            $query = GoodsReceiptLine::query()
                ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')
                ->select('goods_receipt_lines.*')
                ->with(['goodsReceipt.branch', 'sparepartBranch.sparepart'])
                ->when($filters['sparepartId'], fn ($q, $id) => $q->whereIn(
                    'goods_receipt_lines.sparepart_branch_id',
                    SparepartBranch::where('sparepart_id', $id)->select('id')
                ));
            $this->applyReceiptFilters($query, $filters, $permittedBranches);

            return $query
                ->orderByDesc('goods_receipts.receipt_date')
                ->orderByDesc('goods_receipts.id')
                ->orderBy('goods_receipt_lines.sort_order');
        }

        $query = GoodsReceipt::query()
            ->select('goods_receipts.*')
            ->selectSub(
                DB::table('goods_receipt_lines')->selectRaw('COUNT(*)')->whereColumn('goods_receipt_lines.goods_receipt_id', 'goods_receipts.id'),
                'lines_count'
            )
            ->selectSub(
                DB::table('goods_receipt_lines')->selectRaw('COALESCE(SUM(qty), 0)')->whereColumn('goods_receipt_lines.goods_receipt_id', 'goods_receipts.id'),
                'total_qty'
            )
            ->selectSub(
                DB::table('goods_receipt_lines')->selectRaw('COALESCE(SUM(line_total), 0)')->whereColumn('goods_receipt_lines.goods_receipt_id', 'goods_receipts.id'),
                'total_nilai'
            )
            ->with('branch');
        $this->applyReceiptFilters($query, $filters, $permittedBranches);

        return $query->orderByDesc('goods_receipts.receipt_date')->orderByDesc('goods_receipts.id');
    }

    /**
     * Totals follow the active filters but skip cancelled receipts, which stay
     * visible in the list without inflating quantities or values.
     */
    protected function buildSummary(array $filters, SupportCollection $permittedBranches)
    {
        $query = GoodsReceipt::query()
            ->leftJoin('goods_receipt_lines', 'goods_receipt_lines.goods_receipt_id', '=', 'goods_receipts.id')
            ->where('goods_receipts.status', '!=', GoodsReceiptStatus::CANCELLED)
            ->when($filters['sparepartId'], fn ($q, $id) => $q->whereIn(
                'goods_receipt_lines.sparepart_branch_id',
                SparepartBranch::where('sparepart_id', $id)->select('id')
            ));
        $this->applyReceiptFilters($query, $filters, $permittedBranches);

        return $query->selectRaw(
            'COUNT(DISTINCT goods_receipts.id) as total_dokumen, ' .
            'COALESCE(SUM(goods_receipt_lines.qty), 0) as total_qty, ' .
            'COALESCE(SUM(goods_receipt_lines.line_total), 0) as total_nilai'
        )->first();
    }

    protected function applyReceiptFilters($query, array $filters, SupportCollection $permittedBranches): void
    {
        $query->whereIn('goods_receipts.branch_id', $permittedBranches->pluck('id'))
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('goods_receipts.branch_id', $filters['branchIds']))
            ->when($filters['status'], fn ($q, $status) => $q->where('goods_receipts.status', $status))
            ->when($filters['dateFrom'], fn ($q, $d) => $q->whereDate('goods_receipts.receipt_date', '>=', $d))
            ->when($filters['dateTo'], fn ($q, $d) => $q->whereDate('goods_receipts.receipt_date', '<=', $d));
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $statusLabel = $filters['status'] ?? 'Semua Status';
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $sparepartLabel = '';
        if ($filters['sparepartId'] && ($sparepart = Sparepart::find($filters['sparepartId']))) {
            $sparepartLabel = " · Sparepart: {$sparepart->code} — {$sparepart->name}";
        }
        $modeLabel = $filters['mode'] === 'detail' ? 'Detail' : 'Rekap';

        return "Cabang: {$branchLabel} · Status: {$statusLabel} · Tanggal: {$dateLabel}{$sparepartLabel} · Tampilan: {$modeLabel}";
    }

    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
