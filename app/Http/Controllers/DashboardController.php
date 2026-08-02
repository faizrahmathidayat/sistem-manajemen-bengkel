<?php

namespace App\Http\Controllers;

use App\Models\Branch;
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

        $payload = $this->buildPayload($user, $selectedBranchIds);

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

    protected function dummyPkbStatus(): array
    {
        return ['open' => 8, 'shortage' => 2, 'completed' => 15];
    }

    protected function dummyReceivables(): array
    {
        return ['revenue' => 42500000, 'unpaid' => 7300000];
    }

    protected function buildPayload(User $user, array $selectedBranchIds): array
    {
        $scopedBranchIds = $this->scopedBranchIds($user, $selectedBranchIds);

        return [
            'selectedBranchIds' => $selectedBranchIds,
            'stockOverview' => $this->computeStockOverview($scopedBranchIds),
            'criticalStockCount' => $this->computeCriticalStockCount($scopedBranchIds),
            'pkbStatus' => $this->dummyPkbStatus(),
            'receivables' => $this->dummyReceivables(),
        ];
    }
}
