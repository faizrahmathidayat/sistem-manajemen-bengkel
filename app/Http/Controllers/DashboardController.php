<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\InventoryMovement;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\AuditEvent;
use App\Support\InventoryMovementType;
use App\Support\InvoiceStatus;
use App\Support\WorkOrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $allowedBranches = $user->branches;

        $selectedBranchIds = $this->resolveSelectedBranchIds($request, $user, $allowedBranches);
        $sparepartId = filter_var($request->input('sparepart_id'), FILTER_VALIDATE_INT) ?: null;

        $payload = $this->buildPayload($user, $selectedBranchIds, $sparepartId);

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return view('dashboard.index', array_merge($payload, [
            'allowedBranches' => $allowedBranches,
            'selectedBranchIds' => $selectedBranchIds,
        ]));
    }

    protected function resolveSelectedBranchIds(Request $request, User $user, Collection $allowedBranches): array
    {
        $allowedIds = $allowedBranches->pluck('id')->all();

        if ($request->has('branch_ids')) {
            $requested = array_map('intval', (array) $request->input('branch_ids', []));
            $valid = array_values(array_intersect($requested, $allowedIds));
            session(['dashboard_selected_branch_ids' => $valid]);

            return $valid;
        }

        $sessionValue = session('dashboard_selected_branch_ids');
        if (is_array($sessionValue)) {
            $valid = array_values(array_intersect($sessionValue, $allowedIds));
            if (! empty($valid)) {
                return $valid;
            }
        }

        $default = $user->defaultBranch();
        if ($default && in_array($default->id, $allowedIds, true)) {
            return [$default->id];
        }

        return $allowedBranches->isNotEmpty() ? [$allowedBranches->first()->id] : [];
    }

    protected function scopedBranchIdsFor(User $user, array $selectedBranchIds, string $permissionCode): array
    {
        $permittedBranchIds = $user->branchesWithPermission($permissionCode)->pluck('id')->all();

        return array_values(array_intersect($selectedBranchIds, $permittedBranchIds));
    }

    protected function computeStockOverview(array $scopedBranchIds): array
    {
        if (empty($scopedBranchIds)) {
            return ['onHand' => 0.0, 'reserved' => 0.0, 'available' => 0.0];
        }

        $totals = SparepartBranch::whereIn('branch_id', $scopedBranchIds)
            ->where('is_active', true)
            ->join('sparepart_branch_stocks', 'sparepart_branch_stocks.sparepart_branch_id', '=', 'sparepart_branches.id')
            ->selectRaw('SUM(sparepart_branch_stocks.on_hand_qty) as on_hand, SUM(sparepart_branch_stocks.reserved_qty) as reserved')
            ->first();

        $onHand = (float) ($totals->on_hand ?? 0);
        $reserved = (float) ($totals->reserved ?? 0);

        return ['onHand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];
    }

    protected function computeCriticalStockCount(array $scopedBranchIds): int
    {
        if (empty($scopedBranchIds)) {
            return 0;
        }

        return SparepartBranch::whereIn('branch_id', $scopedBranchIds)
            ->where('is_active', true)
            ->where('minimum_stock', '>', 0)
            ->join('sparepart_branch_stocks', 'sparepart_branch_stocks.sparepart_branch_id', '=', 'sparepart_branches.id')
            ->whereRaw('(sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) < sparepart_branches.minimum_stock')
            ->count();
    }

    protected function computePkbStatusToday(array $scopedBranchIds): array
    {
        $defaults = ['draft' => 0, 'open' => 0, 'shortage' => 0, 'completed' => 0];
        if (empty($scopedBranchIds)) {
            return $defaults;
        }

        $counts = WorkOrder::whereIn('branch_id', $scopedBranchIds)
            ->whereDate('work_order_date', now()->toDateString())
            ->whereIn('status', [WorkOrderStatus::DRAFT, WorkOrderStatus::OPEN, WorkOrderStatus::SHORTAGE, WorkOrderStatus::COMPLETED])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'draft' => (int) ($counts[WorkOrderStatus::DRAFT] ?? 0),
            'open' => (int) ($counts[WorkOrderStatus::OPEN] ?? 0),
            'shortage' => (int) ($counts[WorkOrderStatus::SHORTAGE] ?? 0),
            'completed' => (int) ($counts[WorkOrderStatus::COMPLETED] ?? 0),
        ];
    }

    protected function computeReceivablesSummary(array $scopedBranchIds): array
    {
        if (empty($scopedBranchIds)) {
            return ['revenue' => 0.0, 'unpaid' => 0.0];
        }

        $revenue = Invoice::whereIn('branch_id', $scopedBranchIds)
            ->whereIn('status', [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID])
            ->sum('grand_total');

        $unpaid = Invoice::whereIn('branch_id', $scopedBranchIds)
            ->whereIn('status', [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID])
            ->selectRaw('COALESCE(SUM(grand_total - paid_amount), 0) as total')
            ->value('total');

        return ['revenue' => (float) $revenue, 'unpaid' => (float) $unpaid];
    }

    protected function computeWeeklyTrend(array $pkbScopedBranchIds, array $invoiceScopedBranchIds): array
    {
        $weekStarts = collect(range(7, 0))->map(fn ($i) => now()->subWeeks($i)->startOfWeek());
        $labels = $weekStarts->map(fn ($d) => $d->translatedFormat('d M'))->all();

        $pkbCounts = empty($pkbScopedBranchIds) ? collect() : WorkOrder::whereIn('branch_id', $pkbScopedBranchIds)
            ->where('created_at', '>=', $weekStarts->first())
            ->selectRaw('YEARWEEK(created_at, 3) as yw, COUNT(*) as total')
            ->groupBy('yw')->pluck('total', 'yw');

        $invoiceCounts = empty($invoiceScopedBranchIds) ? collect() : AuditLog::whereIn('branch_id', $invoiceScopedBranchIds)
            ->where('event', AuditEvent::INVOICE_POSTED)
            ->where('created_at', '>=', $weekStarts->first())
            ->selectRaw('YEARWEEK(created_at, 3) as yw, COUNT(*) as total')
            ->groupBy('yw')->pluck('total', 'yw');

        $pkb = $weekStarts->map(fn ($d) => (int) ($pkbCounts[(int) $d->format('oW')] ?? 0))->all();
        $invoice = $weekStarts->map(fn ($d) => (int) ($invoiceCounts[(int) $d->format('oW')] ?? 0))->all();

        return ['labels' => $labels, 'pkb' => $pkb, 'invoice' => $invoice];
    }

    protected function computeReceivablesAging(array $scopedBranchIds): array
    {
        $labels = ['Belum Jatuh Tempo', '1-30 Hari', '31-60 Hari', '>60 Hari'];
        if (empty($scopedBranchIds)) {
            return ['labels' => $labels, 'values' => [0, 0, 0, 0]];
        }

        $row = Invoice::whereIn('branch_id', $scopedBranchIds)
            ->whereIn('status', [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, invoice_date)) < 0 THEN grand_total - paid_amount ELSE 0 END), 0) as not_due,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, invoice_date)) BETWEEN 0 AND 30 THEN grand_total - paid_amount ELSE 0 END), 0) as d1_30,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, invoice_date)) BETWEEN 31 AND 60 THEN grand_total - paid_amount ELSE 0 END), 0) as d31_60,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, invoice_date)) > 60 THEN grand_total - paid_amount ELSE 0 END), 0) as d60_plus
            ")->first();

        return ['labels' => $labels, 'values' => [(float) $row->not_due, (float) $row->d1_30, (float) $row->d31_60, (float) $row->d60_plus]];
    }

    protected function computeKartuStok(array $scopedBranchIds, ?int $sparepartId): array
    {
        $spareparts = Sparepart::where('is_active', true)
            ->whereHas('sparepartBranches', function ($query) use ($scopedBranchIds) {
                $query->whereIn('branch_id', $scopedBranchIds)->where('is_active', true);
            })
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $resolvedId = $sparepartId ?? optional($spareparts->first())->id;

        $selected = ['id' => $resolvedId, 'onHand' => 0.0, 'reserved' => 0.0, 'available' => 0.0];
        $mutations = [];

        if ($resolvedId && ! empty($scopedBranchIds)) {
            $totals = SparepartBranch::where('sparepart_id', $resolvedId)
                ->whereIn('branch_id', $scopedBranchIds)
                ->where('is_active', true)
                ->join('sparepart_branch_stocks', 'sparepart_branch_stocks.sparepart_branch_id', '=', 'sparepart_branches.id')
                ->selectRaw('SUM(sparepart_branch_stocks.on_hand_qty) as on_hand, SUM(sparepart_branch_stocks.reserved_qty) as reserved')
                ->first();

            $onHand = (float) ($totals->on_hand ?? 0);
            $reserved = (float) ($totals->reserved ?? 0);
            $selected = ['id' => $resolvedId, 'onHand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];

            // This preview shows only the first scoped branch's ledger (a single
            // running balance can't be meaningfully merged across branches) —
            // the dedicated /stock-card page always operates on one branch via
            // its own switcher and has no such ambiguity.
            $firstBranchSparepartBranch = SparepartBranch::where('sparepart_id', $resolvedId)
                ->whereIn('branch_id', $scopedBranchIds)
                ->where('is_active', true)
                ->first();

            if ($firstBranchSparepartBranch) {
                $mutations = $this->recentMutationRows($firstBranchSparepartBranch->id);
            }
        }

        return [
            'spareparts' => $spareparts->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->all(),
            'selected' => $selected,
            'mutations' => $mutations,
        ];
    }

    protected function recentMutationRows(int $sparepartBranchId): array
    {
        $typeLabels = [
            InventoryMovementType::RECEIPT => 'Penerimaan',
            InventoryMovementType::ADJUSTMENT_IN => 'Penyesuaian Masuk',
            InventoryMovementType::ADJUSTMENT_OUT => 'Penyesuaian Keluar',
            InventoryMovementType::TRANSFER_IN => 'Transfer Masuk',
            InventoryMovementType::TRANSFER_OUT => 'Transfer Keluar',
        ];

        return InventoryMovement::where('sparepart_branch_id', $sparepartBranchId)
            ->orderByDesc('movement_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(function (InventoryMovement $movement) use ($typeLabels) {
                return [
                    'date' => $movement->movement_at->format('d/m/Y H:i'),
                    'type' => $typeLabels[$movement->movement_type] ?? $movement->movement_type,
                    'reference' => "{$movement->reference_type} #{$movement->reference_id}",
                    'in' => (float) $movement->qty_in > 0 ? number_format($movement->qty_in, 0, ',', '.') : '-',
                    'out' => (float) $movement->qty_out > 0 ? number_format($movement->qty_out, 0, ',', '.') : '-',
                    'reserved' => 0,
                    'balance' => number_format($movement->balance_after, 0, ',', '.'),
                ];
            })
            ->all();
    }

    protected function dummyPkbInvoiceRows(): array
    {
        return [
            ['number' => 'PKB-2026080001', 'customer' => 'Budi Santoso', 'plate' => 'B 1234 ABC', 'branch' => 'Cabang Jakarta', 'status' => 'OPEN'],
            ['number' => 'PKB-2026080002', 'customer' => 'Siti Aminah', 'plate' => 'B 5678 XYZ', 'branch' => 'Cabang Jakarta', 'status' => 'SHORTAGE'],
            ['number' => 'INV-2026080001', 'customer' => 'Andi Wijaya', 'plate' => 'D 4321 DEF', 'branch' => 'Cabang Bandung', 'status' => 'POSTED'],
            ['number' => 'PKB-2026080003', 'customer' => 'Dewi Lestari', 'plate' => 'B 9999 GHI', 'branch' => 'Cabang Jakarta', 'status' => 'COMPLETED'],
        ];
    }

    protected function dummyAuditLogRows(): array
    {
        return [
            ['timestamp' => '2026-08-02 10:12', 'user' => 'faiz_rahmat', 'permission' => 'sparepart.create', 'description' => 'Menambahkan sparepart BAN-01 ke Cabang Jakarta', 'impact' => 'LOW'],
            ['timestamp' => '2026-08-02 09:48', 'user' => 'romi_ramdani', 'permission' => 'pkb.create', 'description' => 'Membuat PKB baru untuk B 1234 ABC', 'impact' => 'MEDIUM'],
            ['timestamp' => '2026-08-01 16:30', 'user' => 'faiz_rahmat', 'permission' => 'user_permission.manage', 'description' => 'Mengubah permission user romi_ramdani', 'impact' => 'HIGH'],
        ];
    }

    protected function buildPayload(User $user, array $selectedBranchIds, ?int $sparepartId = null): array
    {
        $stockScopedIds = $this->scopedBranchIdsFor($user, $selectedBranchIds, 'sparepart.view');
        $pkbScopedIds = $this->scopedBranchIdsFor($user, $selectedBranchIds, 'pkb.view');
        $invoiceScopedIds = $this->scopedBranchIdsFor($user, $selectedBranchIds, 'invoice.view');

        return [
            'selectedBranchIds' => $selectedBranchIds,
            'stockOverview' => $this->computeStockOverview($stockScopedIds),
            'criticalStockCount' => $this->computeCriticalStockCount($stockScopedIds),
            'pkbStatus' => $this->computePkbStatusToday($pkbScopedIds),
            'receivables' => $this->computeReceivablesSummary($invoiceScopedIds),
            'chartTrend' => $this->computeWeeklyTrend($pkbScopedIds, $invoiceScopedIds),
            'chartReceivables' => $this->computeReceivablesAging($invoiceScopedIds),
            'pkbInvoiceRows' => $this->dummyPkbInvoiceRows(),
            'auditLogRows' => $this->dummyAuditLogRows(),
            'kartuStok' => $this->computeKartuStok($stockScopedIds, $sparepartId),
        ];
    }
}
