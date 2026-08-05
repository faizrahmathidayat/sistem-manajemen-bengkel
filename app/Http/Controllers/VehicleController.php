<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;

class VehicleController extends Controller
{
    public function index()
    {
        $this->authorize('vehicle.view');

        $search = is_string(request('q')) ? trim(request('q')) : null;
        $customerId = request('customer_id') ? (int) request('customer_id') : null;

        $vehicles = Vehicle::with(['customer', 'category', 'brand', 'type'])
            ->when($customerId, fn ($query, $id) => $query->where('customer_id', $id))
            ->when($search, function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('plate_number', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('frame_number', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('engine_number', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            ->orderBy('created_at', 'desc')
            ->simplePaginate(15)
            ->withQueryString();

        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        return view('vehicles.index', compact('vehicles', 'customers'))
            ->with('search', $search)
            ->with('selectedCustomerId', $customerId);
    }

    public function create()
    {
        $this->authorize('vehicle.create');

        $vehicle = new Vehicle();
        $categories = VehicleCategory::where('is_active', true)->orderBy('name')->get();
        $brands = collect();
        $types = collect();
        $selectedCustomerId = request()->query('customer_id') ? (int) request()->query('customer_id') : null;

        return view('vehicles.create', compact('vehicle', 'categories', 'brands', 'types', 'selectedCustomerId'));
    }

    public function store(StoreVehicleRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', true);

        Vehicle::create($data);

        return redirect()->route('vehicles.index')->with('status', 'Kendaraan berhasil ditambahkan.');
    }

    public function edit(Vehicle $vehicle)
    {
        $this->authorize('vehicle.edit');

        $categories = VehicleCategory::where('is_active', true)->orderBy('name')->get();
        $brands = VehicleBrand::where('category_id', $vehicle->category_id)->where('is_active', true)->orderBy('name')->get();
        $types = VehicleType::where('brand_id', $vehicle->brand_id)->where('is_active', true)->orderBy('name')->get();
        $selectedCustomerId = null;

        return view('vehicles.edit', compact('vehicle', 'categories', 'brands', 'types', 'selectedCustomerId'));
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $vehicle->update($data);

        return redirect()->route('vehicles.index')->with('status', 'Kendaraan berhasil diperbarui.');
    }
}
