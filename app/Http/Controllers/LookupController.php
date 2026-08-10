<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\SparepartBranch;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    public function customers(Request $request)
    {
        $this->authorize('customer.view');

        $query = Customer::query();
        $ids = array_map('intval', (array) $request->query('ids', []));

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        } else {
            $term = $this->searchTerm($request);
            if ($term === null) {
                return response()->json([]);
            }
            $query->where('is_active', true)
                ->where('name', 'like', '%' . addcslashes($term, '%_\\') . '%');
        }

        if ($branchId = $request->query('branch_id')) {
            $query->whereHas('customerBranches', function ($inner) use ($branchId) {
                $inner->where('branch_id', $branchId)->where('is_active', true);
            });
        }

        return response()->json(
            $query->orderBy('name')->limit(20)->get()
                ->map(fn (Customer $customer) => ['id' => $customer->id, 'text' => $customer->name])
                ->values()
        );
    }

    public function mechanics(Request $request)
    {
        $this->authorize('mechanic.view');

        $query = Mechanic::query();
        $ids = array_map('intval', (array) $request->query('ids', []));

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        } else {
            $term = $this->searchTerm($request);
            if ($term === null) {
                return response()->json([]);
            }
            $query->where('is_active', true)
                ->where('name', 'like', '%' . addcslashes($term, '%_\\') . '%');
        }

        if ($branchId = $request->query('branch_id')) {
            $query->whereHas('mechanicBranches', function ($inner) use ($branchId) {
                $inner->where('branch_id', $branchId)->where('is_active', true);
            });
        }

        return response()->json(
            $query->orderBy('name')->limit(20)->get()
                ->map(fn (Mechanic $mechanic) => [
                    'id' => $mechanic->id,
                    'text' => $mechanic->nip ? "{$mechanic->name} ({$mechanic->nip})" : $mechanic->name,
                ])
                ->values()
        );
    }

    public function spareparts(Request $request)
    {
        $branchId = (int) $request->query('branch_id');
        abort_if($branchId <= 0, 400, 'branch_id is required.');
        abort_unless(auth()->user()->hasPermissionToInBranch('sparepart.view', $branchId), 403);

        $query = SparepartBranch::with(['sparepart', 'stock'])
            ->where('branch_id', $branchId);
        $ids = array_map('intval', (array) $request->query('ids', []));
        $idField = $request->query('id_field') === 'sparepart_id' ? 'sparepart_id' : 'id';

        if (! empty($ids)) {
            // Most callers resolve by the SparepartBranch primary key (id). Stock Transfer
            // stores a bare sparepart_id on its lines (it spans two branches, so it can't
            // reference a single per-branch SparepartBranch row), so it opts into resolving
            // by sparepart_id via ?id_field=sparepart_id instead. Matching both columns
            // unconditionally would risk a false match whenever a sparepart_branch_id value
            // coincidentally equals an unrelated sparepart's id (both are separate
            // auto-increment counters) — keep the two resolution modes mutually exclusive.
            $query->whereIn($idField, $ids);
        } else {
            $term = $this->searchTerm($request);
            if ($term === null) {
                return response()->json([]);
            }
            $escaped = addcslashes($term, '%_\\');
            $query->where('is_active', true)
                ->whereHas('sparepart', function ($inner) use ($escaped) {
                    $inner->where('name', 'like', "%{$escaped}%")->orWhere('code', 'like', "%{$escaped}%");
                });
        }

        return response()->json(
            $query->get()
                ->sortBy(fn (SparepartBranch $sb) => $sb->sparepart->name)
                ->take(20)
                ->map(function (SparepartBranch $sb) {
                    return [
                        'id' => $sb->id,
                        'sparepart_id' => $sb->sparepart_id,
                        'text' => $sb->sparepart->code . ' — ' . $sb->sparepart->name,
                        'code' => $sb->sparepart->code,
                        'selling_price' => (float) $sb->selling_price,
                        'available_qty' => (float) $sb->stock->available_qty,
                        'on_hand_qty' => (float) $sb->stock->on_hand_qty,
                    ];
                })
                ->values()
        );
    }

    private function searchTerm(Request $request): ?string
    {
        $q = $request->query('q');
        if (! is_string($q)) {
            return null;
        }
        $q = trim($q);

        return mb_strlen($q) >= 3 ? $q : null;
    }
}
