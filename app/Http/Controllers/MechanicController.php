<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMechanicRequest;
use App\Http\Requests\UpdateMechanicRequest;
use App\Models\Branch;
use App\Models\Mechanic;

class MechanicController extends Controller
{
    public function index()
    {
        $this->authorize('mechanic.view');

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect(auth()->user()->branches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $mechanics = Mechanic::orderBy('name')
            ->when($search, function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $escaped = addcslashes($q, '%_\\');
                    $inner->where('name', 'like', '%' . $escaped . '%')
                        ->orWhere('phone', 'like', '%' . $escaped . '%')
                        ->orWhere('nip', 'like', '%' . $escaped . '%');
                });
            })
            ->when($branchIds, fn ($query) => $query->whereHas('mechanicBranches', fn ($q) => $q->whereIn('branch_id', $branchIds)->where('is_active', true)))
            ->simplePaginate(15)
            ->withQueryString();

        $userBranches = auth()->user()->branches;

        return view('mechanics.index', compact('mechanics'))
            ->with('branches', $userBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function create()
    {
        $this->authorize('mechanic.create');

        $mechanic = new Mechanic();

        return view('mechanics.create', compact('mechanic'));
    }

    public function store(StoreMechanicRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        Mechanic::create($data);

        return redirect()->route('mechanics.index')->with('status', 'Mekanik berhasil ditambahkan.');
    }

    public function show(Mechanic $mechanic)
    {
        $this->authorize('mechanic.view');

        $mechanic->load('mechanicBranches');
        $allBranches = Branch::orderBy('name')->get();

        return view('mechanics.show', compact('mechanic', 'allBranches'));
    }

    public function update(UpdateMechanicRequest $request, Mechanic $mechanic)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $mechanic->update($data);

        return redirect()->route('mechanics.show', $mechanic)->with('status', 'Mekanik berhasil diperbarui.');
    }
}
