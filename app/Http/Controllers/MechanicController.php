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

        $mechanics = Mechanic::orderBy('name')->simplePaginate(15);

        return view('mechanics.index', compact('mechanics'));
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
