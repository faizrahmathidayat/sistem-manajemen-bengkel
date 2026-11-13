<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Models\Supplier;

class SupplierController extends Controller
{
    public function index()
    {
        $this->authorize('supplier.view');

        $search = is_string(request('q')) ? trim(request('q')) : null;
        $status = in_array(request('status'), ['active', 'inactive'], true) ? request('status') : null;

        $suppliers = Supplier::orderBy('code')
            ->when($search, function ($query, $q) {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('code', 'like', $like)->orWhere('name', 'like', $like);
                });
            })
            ->when($status, fn ($query, $s) => $query->where('is_active', $s === 'active'))
            ->paginate(10)
            ->withQueryString();

        return view('suppliers.index', compact('suppliers'))
            ->with('search', $search)
            ->with('selectedStatus', $status);
    }

    public function create()
    {
        $this->authorize('supplier.create');

        $supplier = new Supplier();

        return view('suppliers.create', compact('supplier'));
    }

    public function store(StoreSupplierRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        Supplier::create($data);

        return redirect()->route('suppliers.index')->with('status', 'Supplier berhasil ditambahkan.');
    }

    public function edit(Supplier $supplier)
    {
        $this->authorize('supplier.edit');

        return view('suppliers.edit', compact('supplier'));
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $supplier->update($data);

        return redirect()->route('suppliers.index')->with('status', 'Supplier berhasil diperbarui.');
    }
}
