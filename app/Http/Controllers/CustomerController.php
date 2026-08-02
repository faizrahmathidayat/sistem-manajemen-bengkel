<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Branch;
use App\Models\Customer;

class CustomerController extends Controller
{
    public function index()
    {
        $this->authorize('customer.view');

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect(auth()->user()->branches->pluck('id'))
            ->values()->all();

        $customers = Customer::orderBy('name')
            ->when(request('q'), function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%");
                });
            })
            ->when($branchIds, fn ($query) => $query->whereHas('customerBranches', fn ($q) => $q->whereIn('branch_id', $branchIds)->where('is_active', true)))
            ->simplePaginate(15)
            ->withQueryString();

        $branches = auth()->user()->branches;

        return view('customers.index', compact('customers', 'branches'))->with('selectedBranchIds', $branchIds);
    }

    public function create()
    {
        $this->authorize('customer.create');

        $customer = new Customer();

        return view('customers.create', compact('customer'));
    }

    public function store(StoreCustomerRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        Customer::create($data);

        return redirect()->route('customers.index')->with('status', 'Customer berhasil ditambahkan.');
    }

    public function show(Customer $customer)
    {
        $this->authorize('customer.view');

        $customer->load(['customerBranches', 'vehicles.category', 'vehicles.brand', 'vehicles.type']);
        $allBranches = Branch::orderBy('name')->get();

        return view('customers.show', compact('customer', 'allBranches'));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $customer->update($data);

        return redirect()->route('customers.show', $customer)->with('status', 'Customer berhasil diperbarui.');
    }
}
