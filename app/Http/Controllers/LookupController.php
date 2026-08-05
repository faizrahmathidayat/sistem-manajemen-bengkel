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
                ->map(fn (Mechanic $mechanic) => ['id' => $mechanic->id, 'text' => $mechanic->name])
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

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
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
