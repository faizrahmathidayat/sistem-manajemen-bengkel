<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRackRequest;
use App\Http\Requests\UpdateRackRequest;
use App\Models\Rack;

class RackController extends Controller
{
    public function index()
    {
        $this->authorize('rack.view');

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $racks = Rack::orderBy('code')
            ->when($search, function ($query, $q) {
                $query->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->simplePaginate(15)
            ->withQueryString();

        return view('racks.index', compact('racks'))->with('search', $search);
    }

    public function create()
    {
        $this->authorize('rack.create');

        $rack = new Rack();

        return view('racks.create', compact('rack'));
    }

    public function store(StoreRackRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        Rack::create($data);

        return redirect()->route('racks.index')->with('status', 'Rak berhasil ditambahkan.');
    }

    public function edit(Rack $rack)
    {
        $this->authorize('rack.edit');

        return view('racks.edit', compact('rack'));
    }

    public function update(UpdateRackRequest $request, Rack $rack)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $rack->update($data);

        return redirect()->route('racks.index')->with('status', 'Rak berhasil diperbarui.');
    }
}
