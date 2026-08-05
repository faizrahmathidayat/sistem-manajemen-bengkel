<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Support\InventoryMovementType;
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

    protected function scopedBranchIds(User $user, array $selectedBranchIds): array
    {
        $permittedBranchIds = $user->branchesWithPermission('sparepart.view')->pluck('id')->all();

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

    protected function dummyPkbStatus(): array
    {
        return ['open' => 8, 'shortage' => 2, 'completed' => 15];
    }

    protected function dummyReceivables(): array
    {
        return ['revenue' => 42500000, 'unpaid' => 7300000];
    }

    protected function dummyChartTrend(): array
    {
        return [
            'labels' => ['Pekan 1', 'Pekan 2', 'Pekan 3', 'Pekan 4', 'Pekan 5', 'Pekan 6'],
            'pkb' => [12, 15, 9, 18, 14, 20],
            'invoice' => [10, 13, 8, 16, 12, 17],
        ];
    }

    protected function dummyChartReceivables(): array
    {
        return [
            'labels' => ['Belum Jatuh Tempo', '1-30 Hari', '31-60 Hari', '>60 Hari'],
            'values' => [4200000, 1800000, 900000, 400000],
        ];
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
        $scopedBranchIds = $this->scopedBranchIds($user, $selectedBranchIds);

        return [
            'selectedBranchIds' => $selectedBranchIds,
            'stockOverview' => $this->computeStockOverview($scopedBranchIds),
            'criticalStockCount' => $this->computeCriticalStockCount($scopedBranchIds),
            'pkbStatus' => $this->dummyPkbStatus(),
            'receivables' => $this->dummyReceivables(),
            'chartTrend' => $this->dummyChartTrend(),
            'chartReceivables' => $this->dummyChartReceivables(),
            'pkbInvoiceRows' => $this->dummyPkbInvoiceRows(),
            'auditLogRows' => $this->dummyAuditLogRows(),
            'kartuStok' => $this->computeKartuStok($scopedBranchIds, $sparepartId),
        ];
    }
}
