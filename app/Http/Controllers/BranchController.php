<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;

class BranchController extends Controller
{
    public function index()
    {
        $this->authorize('branch.view');

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $branches = Branch::orderBy('name')
            ->when($search, function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('name', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            ->simplePaginate(15)
            ->withQueryString();

        return view('branches.index', compact('branches'))->with('search', $search);
    }

    public function create()
    {
        $this->authorize('branch.create');

        $branch = new Branch();

        return view('branches.create', compact('branch'));
    }

    public function store(StoreBranchRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        Branch::create($data);

        return redirect()->route('branches.index')->with('status', 'Cabang berhasil ditambahkan.');
    }

    public function edit(Branch $branch)
    {
        $this->authorize('branch.edit');

        return view('branches.edit', compact('branch'));
    }

    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $branch->update($data);

        return redirect()->route('branches.index')->with('status', 'Cabang berhasil diperbarui.');
    }
}
