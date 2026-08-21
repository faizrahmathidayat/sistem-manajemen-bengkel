<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleCategory;

class CustomerController extends Controller
{
    protected const VEHICLE_FIELDS = [
        'vehicle_category_id', 'vehicle_brand_id', 'vehicle_type_id',
        'vehicle_plate_number', 'vehicle_frame_number', 'vehicle_engine_number', 'vehicle_year',
    ];

    protected function createVehicleForCustomer(Customer $customer, array $data): void
    {
        if (! auth()->user()->can('vehicle.create')) {
            return;
        }

        $vehicleData = collect($data)->only(self::VEHICLE_FIELDS)->filter(fn ($value) => filled($value));

        if ($vehicleData->isEmpty()) {
            return;
        }

        Vehicle::create([
            'customer_id' => $customer->id,
            'category_id' => $data['vehicle_category_id'],
            'brand_id' => $data['vehicle_brand_id'],
            'type_id' => $data['vehicle_type_id'],
            'plate_number' => $data['vehicle_plate_number'] ?? null,
            'frame_number' => $data['vehicle_frame_number'] ?? null,
            'engine_number' => $data['vehicle_engine_number'] ?? null,
            'year' => $data['vehicle_year'] ?? null,
        ]);
    }
    public function index()
    {
        $this->authorize('customer.view');

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect(auth()->user()->branches->pluck('id'))
            ->values()->all();

        // Sanitize once here and reuse for both the query and the view's search
        // input value — request('q') can be an array (?q[]=x), which would crash
        // Blade's {{ }} (htmlspecialchars) if read raw a second time in the view.
        $search = is_string(request('q')) ? trim(request('q')) : null;

        $customers = Customer::orderBy('name')
            ->when($search, function ($query, $q) {
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
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function create()
    {
        $this->authorize('customer.create');

        $customer = new Customer();
        $categories = VehicleCategory::where('is_active', true)->orderBy('name')->get();

        return view('customers.create', compact('customer', 'categories'));
    }

    public function store(StoreCustomerRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $customer = Customer::create(collect($data)->except(self::VEHICLE_FIELDS)->all());
        $this->createVehicleForCustomer($customer, $data);

        return redirect()->route('customers.index')->with('status', 'Customer berhasil ditambahkan.');
    }

    public function show(Customer $customer)
    {
        $this->authorize('customer.view');

        $customer->load(['customerBranches', 'vehicles.category', 'vehicles.brand', 'vehicles.type']);
        $allBranches = Branch::orderBy('name')->get();
        $categories = VehicleCategory::where('is_active', true)->orderBy('name')->get();

        return view('customers.show', compact('customer', 'allBranches', 'categories'));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $customer->update(collect($data)->except(self::VEHICLE_FIELDS)->all());
        $this->createVehicleForCustomer($customer, $data);

        return redirect()->route('customers.show', $customer)->with('status', 'Customer berhasil diperbarui.');
    }
}
