<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
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
        }

        return [
            'spareparts' => $spareparts->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->all(),
            'selected' => $selected,
            'mutations' => $this->dummyMutationRows(),
        ];
    }

    protected function dummyMutationRows(): array
    {
        return [
            ['date' => '2026-08-01 09:15', 'type' => 'RECEIPT', 'reference' => 'RCV-2026080001', 'in' => 20, 'out' => 0, 'reserved' => 0, 'balance' => 20],
            ['date' => '2026-08-01 14:30', 'type' => 'PKB_RESERVATION', 'reference' => 'PKB-2026080001', 'in' => 0, 'out' => 0, 'reserved' => 2, 'balance' => 20],
            ['date' => '2026-08-02 10:00', 'type' => 'INVOICE', 'reference' => 'INV-2026080001', 'in' => 0, 'out' => 2, 'reserved' => -2, 'balance' => 18],
        ];
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
            'kartuStok' => $this->computeKartuStok($scopedBranchIds, $sparepartId),
        ];
    }
}
