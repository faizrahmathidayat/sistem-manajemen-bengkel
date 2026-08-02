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
            ->when(is_string(request('q')) ? trim(request('q')) : null, function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('phone', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            // Deliberately duplicates Customer::branches()'s is_active pivot filter here
            // (instead of reusing that relation) to avoid an extra join.
            ->when($branchIds, fn ($query) => $query->whereHas('customerBranches', fn ($q) => $q->whereIn('branch_id', $branchIds)->where('is_active', true)))
            ->simplePaginate(15)
            ->withQueryString();

        $userBranches = auth()->user()->branches;

        // Fail-open by design: an empty/all-dropped branch selection shows all customers,
        // not zero — this matches how Customer::orderBy('name') was already globally
        // unscoped before this branch (not a new leak introduced here).
        return view('customers.index', compact('customers'))
            ->with('branches', $userBranches)
            ->with('selectedBranchIds', $branchIds);
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
