<?php

namespace App\Http\Controllers;

use App\Exports\SparepartMasterImportTemplateExport;
use App\Http\Requests\ImportSparepartMasterLinesRequest;
use App\Http\Requests\StoreSparepartMasterBulkRequest;
use App\Http\Requests\StoreSparepartRequest;
use App\Http\Requests\StoreSparepartToBranchRequest;
use App\Http\Requests\UpdateSparepartBranchRequest;
use App\Imports\SparepartMasterLinesImport;
use App\Models\Branch;
use App\Models\Rack;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

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

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $sparepartBranches = SparepartBranch::with(['sparepart', 'stock', 'rack'])
            ->where('branch_id', $currentBranch->id)
            ->when($search, function ($query, $q) {
                $query->whereHas('sparepart', function ($inner) use ($q) {
                    $inner->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('name', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            ->orderBy('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('sparepart-branches.index', compact('sparepartBranches', 'allowedBranches', 'currentBranch'))->with('search', $search);
    }

    public function create()
    {
        $branches = auth()->user()->branchesWithPermission('sparepart.create');

        if ($branches->isEmpty()) {
            return view('sparepart-branches.no-access');
        }

        $currentBranchId = session('current_sparepart_branch_id');
        $selectedBranch = $branches->firstWhere('id', $currentBranchId) ?? $branches->first();
        $racks = Rack::where('is_active', true)->orderBy('code')->get();

        return view('sparepart-branches.create', compact('branches', 'selectedBranch', 'racks'));
    }

    public function store(StoreSparepartRequest $request)
    {
        $branch = Branch::findOrFail((int) $request->input('branch_id'));
        $data = $request->validated();

        DB::transaction(function () use ($data, $branch) {
            $sparepart = Sparepart::create([
                'code' => $data['code'],
                'name' => $data['name'],
            ]);

            SparepartBranch::create([
                'sparepart_id' => $sparepart->id,
                'branch_id' => $branch->id,
                'rack_id' => $data['rack_id'] ?? null,
                'selling_price' => $data['selling_price'],
                'minimum_stock' => $data['minimum_stock'] ?? 0,
            ]);
        });

        return redirect()->route('sparepart-branches.index')->with('status', 'Sparepart berhasil ditambahkan.');
    }

    public function importPage()
    {
        $branches = auth()->user()->branchesWithPermission('sparepart.create');

        if ($branches->isEmpty()) {
            return view('sparepart-branches.no-access');
        }

        $currentBranchId = session('current_sparepart_branch_id');
        $selectedBranch = $branches->firstWhere('id', $currentBranchId) ?? $branches->first();
        $racks = Rack::where('is_active', true)->orderBy('code')->get();

        return view('sparepart-branches.import', compact('branches', 'selectedBranch', 'racks'));
    }

    public function downloadImportTemplate()
    {
        abort_if(auth()->user()->branchesWithPermission('sparepart.create')->isEmpty(), 403);

        return Excel::download(new SparepartMasterImportTemplateExport(), 'template-import-master-sparepart.xlsx');
    }

    public function importLines(ImportSparepartMasterLinesRequest $request)
    {
        $data = $request->validated();

        $import = new SparepartMasterLinesImport();
        Excel::import($import, $data['file']);

        if (! empty($import->errors)) {
            return response()->json(['errors' => $import->errors], 422);
        }

        return response()->json(['lines' => $import->lines]);
    }

    public function storeBulk(StoreSparepartMasterBulkRequest $request)
    {
        $branch = Branch::findOrFail((int) $request->input('branch_id'));
        $data = $request->validated();

        $count = DB::transaction(function () use ($data, $branch) {
            foreach ($data['lines'] as $line) {
                $sparepart = Sparepart::create([
                    'code' => $line['code'],
                    'name' => $line['name'],
                ]);

                SparepartBranch::create([
                    'sparepart_id' => $sparepart->id,
                    'branch_id' => $branch->id,
                    'rack_id' => $line['rack_id'] ?? null,
                    'selling_price' => $line['selling_price'],
                    'minimum_stock' => $line['minimum_stock'] ?? 0,
                ]);
            }

            return count($data['lines']);
        });

        return redirect()->route('sparepart-branches.index')->with('status', "{$count} sparepart berhasil diimport.");
    }

    public function createExisting()
    {
        $branch = $this->resolveCurrentBranch(auth()->user());

        if (! $branch || ! auth()->user()->hasPermissionToInBranch('sparepart.create', $branch->id)) {
            abort(403);
        }

        $racks = Rack::where('is_active', true)->orderBy('code')->get();

        return view('sparepart-branches.create-existing', compact('branch', 'racks'));
    }

    public function lookupUnconfigured(Request $request)
    {
        $branchId = (int) $request->query('branch_id');
        abort_if($branchId <= 0, 400, 'branch_id is required.');
        abort_unless(auth()->user()->hasPermissionToInBranch('sparepart.create', $branchId), 403);

        $q = $request->query('q');
        if (! is_string($q) || mb_strlen(trim($q)) < 3) {
            return response()->json([]);
        }
        $escaped = addcslashes(trim($q), '%_\\');

        $spareparts = Sparepart::where('is_active', true)
            ->whereDoesntHave('sparepartBranches', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->where(function ($inner) use ($escaped) {
                $inner->where('name', 'like', "%{$escaped}%")->orWhere('code', 'like', "%{$escaped}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json(
            $spareparts->map(fn (Sparepart $sparepart) => [
                'id' => $sparepart->id,
                'text' => $sparepart->code . ' — ' . $sparepart->name,
            ])->values()
        );
    }

    public function storeExisting(StoreSparepartToBranchRequest $request)
    {
        $branch = Branch::findOrFail((int) $request->input('branch_id'));
        $data = $request->validated();

        DB::transaction(function () use ($data, $branch) {
            SparepartBranch::create([
                'sparepart_id' => $data['sparepart_id'],
                'branch_id' => $branch->id,
                'rack_id' => $data['rack_id'] ?? null,
                'selling_price' => $data['selling_price'],
                'minimum_stock' => $data['minimum_stock'] ?? 0,
            ]);
        });

        return redirect()->route('sparepart-branches.index')->with('status', 'Sparepart berhasil ditambahkan ke cabang ini.');
    }

    public function edit(SparepartBranch $sparepartBranch)
    {
        $this->authorize('update', $sparepartBranch);

        $sparepartBranch->load('sparepart');
        $racks = Rack::where('is_active', true)->orderBy('code')->get();

        return view('sparepart-branches.edit', compact('sparepartBranch', 'racks'));
    }

    public function update(UpdateSparepartBranchRequest $request, SparepartBranch $sparepartBranch)
    {
        $sparepartBranch->update($request->validated());

        return redirect()->route('sparepart-branches.index')->with('status', 'Konfigurasi sparepart berhasil diperbarui.');
    }

    public function deactivate(SparepartBranch $sparepartBranch)
    {
        $this->authorize('delete', $sparepartBranch);

        $sparepartBranch->update(['is_active' => false]);

        return redirect()->route('sparepart-branches.index')->with('status', 'Sparepart dinonaktifkan di cabang ini.');
    }

    public function activate(SparepartBranch $sparepartBranch)
    {
        $this->authorize('delete', $sparepartBranch);

        $sparepartBranch->update(['is_active' => true]);

        return redirect()->route('sparepart-branches.index')->with('status', 'Sparepart diaktifkan kembali di cabang ini.');
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
