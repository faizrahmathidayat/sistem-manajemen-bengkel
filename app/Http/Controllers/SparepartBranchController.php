<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSparepartRequest;
use App\Http\Requests\StoreSparepartToBranchRequest;
use App\Models\Branch;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SparepartBranchController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $allowedBranches = $user->branchesWithPermission('sparepart.view');

        if ($allowedBranches->isEmpty()) {
            return view('sparepart-branches.no-access');
        }

        $requestedBranchId = request('branch_id');
        if ($requestedBranchId && $allowedBranches->firstWhere('id', (int) $requestedBranchId)) {
            session(['current_sparepart_branch_id' => (int) $requestedBranchId]);
        }

        $currentBranch = $allowedBranches->firstWhere('id', session('current_sparepart_branch_id'))
            ?? $allowedBranches->first();
        session(['current_sparepart_branch_id' => $currentBranch->id]);

        $sparepartBranches = SparepartBranch::with(['sparepart', 'stock'])
            ->where('branch_id', $currentBranch->id)
            ->when(request('q'), function ($query, $q) {
                $query->whereHas('sparepart', function ($inner) use ($q) {
                    $inner->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->orderBy('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('sparepart-branches.index', compact('sparepartBranches', 'allowedBranches', 'currentBranch'));
    }

    public function create()
    {
        $branch = $this->resolveCurrentBranch(auth()->user());

        if (! $branch || ! auth()->user()->hasPermissionToInBranch('sparepart.create', $branch->id)) {
            abort(403);
        }

        return view('sparepart-branches.create', compact('branch'));
    }

    public function store(StoreSparepartRequest $request)
    {
        $branch = $this->resolveCurrentBranch(auth()->user());
        $data = $request->validated();

        DB::transaction(function () use ($data, $branch) {
            $sparepart = Sparepart::create([
                'code' => $data['code'],
                'name' => $data['name'],
            ]);

            SparepartBranch::create([
                'sparepart_id' => $sparepart->id,
                'branch_id' => $branch->id,
                'rack_number' => $data['rack_number'] ?? null,
                'selling_price' => $data['selling_price'],
                'minimum_stock' => $data['minimum_stock'] ?? 0,
            ]);
        });

        return redirect()->route('sparepart-branches.index')->with('status', 'Sparepart berhasil ditambahkan.');
    }

    public function createExisting()
    {
        $branch = $this->resolveCurrentBranch(auth()->user());

        if (! $branch || ! auth()->user()->hasPermissionToInBranch('sparepart.create', $branch->id)) {
            abort(403);
        }

        $availableSpareparts = Sparepart::where('is_active', true)
            ->whereDoesntHave('sparepartBranches', function ($query) use ($branch) {
                $query->where('branch_id', $branch->id);
            })
            ->orderBy('name')
            ->get();

        return view('sparepart-branches.create-existing', compact('availableSpareparts', 'branch'));
    }

    public function storeExisting(StoreSparepartToBranchRequest $request)
    {
        $branch = $this->resolveCurrentBranch(auth()->user());
        $data = $request->validated();

        DB::transaction(function () use ($data, $branch) {
            SparepartBranch::create([
                'sparepart_id' => $data['sparepart_id'],
                'branch_id' => $branch->id,
                'rack_number' => $data['rack_number'] ?? null,
                'selling_price' => $data['selling_price'],
                'minimum_stock' => $data['minimum_stock'] ?? 0,
            ]);
        });

        return redirect()->route('sparepart-branches.index')->with('status', 'Sparepart berhasil ditambahkan ke cabang ini.');
    }

    protected function resolveCurrentBranch(User $user): ?Branch
    {
        $allowedBranches = $user->branchesWithPermission('sparepart.view');

        if ($allowedBranches->isEmpty()) {
            return null;
        }

        return $allowedBranches->firstWhere('id', session('current_sparepart_branch_id'))
            ?? $allowedBranches->first();
    }
}
