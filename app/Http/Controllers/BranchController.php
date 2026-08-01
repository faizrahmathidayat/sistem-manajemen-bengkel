<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index()
    {
        $this->authorize('branch.view');

        $branches = Branch::orderBy('name')->simplePaginate(15);

        return view('branches.index', compact('branches'));
    }

    public function create()
    {
        $this->authorize('branch.create');

        $branch = new Branch();

        return view('branches.create', compact('branch'));
    }

    public function store(Request $request)
    {
        $this->authorize('branch.create');

        $data = $this->validateData($request);
        $data['is_active'] = $request->boolean('is_active');

        Branch::create($data);

        return redirect()->route('branches.index')->with('status', 'Cabang berhasil ditambahkan.');
    }

    public function edit(Branch $branch)
    {
        $this->authorize('branch.edit');

        return view('branches.edit', compact('branch'));
    }

    public function update(Request $request, Branch $branch)
    {
        $this->authorize('branch.edit');

        $data = $this->validateData($request, $branch);
        $data['is_active'] = $request->boolean('is_active');

        $branch->update($data);

        return redirect()->route('branches.index')->with('status', 'Cabang berhasil diperbarui.');
    }

    protected function validateData(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:branches,code,' . optional($branch)->id],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
    }
}
