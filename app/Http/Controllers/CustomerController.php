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

        $customers = Customer::orderBy('name')->simplePaginate(15);

        return view('customers.index', compact('customers'));
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
