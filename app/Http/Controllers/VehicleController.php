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

        $vehicles = Vehicle::with(['customer', 'category', 'brand', 'type'])
            ->when(request('customer_id'), fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when(request('q'), function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('plate_number', 'like', "%{$q}%")
                        ->orWhere('frame_number', 'like', "%{$q}%")
                        ->orWhere('engine_number', 'like', "%{$q}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->simplePaginate(15)
            ->withQueryString();

        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        return view('vehicles.index', compact('vehicles', 'customers'));
    }

    public function create()
    {
        $this->authorize('vehicle.create');

        $vehicle = new Vehicle();
        $customers = Customer::where('is_active', true)->orderBy('name')->get();
        $categories = VehicleCategory::where('is_active', true)->orderBy('name')->get();
        $brands = collect();
        $types = collect();
        $selectedCustomerId = request()->integer('customer_id') ?: null;

        return view('vehicles.create', compact('vehicle', 'customers', 'categories', 'brands', 'types', 'selectedCustomerId'));
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

        $customers = Customer::where('is_active', true)->orderBy('name')->get();
        $categories = VehicleCategory::where('is_active', true)->orderBy('name')->get();
        $brands = VehicleBrand::where('category_id', $vehicle->category_id)->where('is_active', true)->orderBy('name')->get();
        $types = VehicleType::where('brand_id', $vehicle->brand_id)->where('is_active', true)->orderBy('name')->get();
        $selectedCustomerId = null;

        return view('vehicles.edit', compact('vehicle', 'customers', 'categories', 'brands', 'types', 'selectedCustomerId'));
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $vehicle->update($data);

        return redirect()->route('vehicles.index')->with('status', 'Kendaraan berhasil diperbarui.');
    }
}
