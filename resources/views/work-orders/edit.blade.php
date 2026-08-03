@extends('layouts.app')
@section('title', 'Ubah PKB')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-clipboard-check me-2"></i>Ubah PKB {{ $workOrder->number }} — {{ $workOrder->branch->name }}</h1>
    </div>
    <form method="POST" action="{{ route('work-orders.update', $workOrder) }}" id="workOrderForm">
        @csrf
        @method('PUT')
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" id="customerSelect" class="form-select @error('customer_id') is-invalid @enderror" required>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}" {{ (int) old('customer_id', $workOrder->customer_id) === $customer->id ? 'selected' : '' }}>{{ $customer->name }}</option>
                            @endforeach
                        </select>
                        @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kendaraan</label>
                        <select name="vehicle_id" id="vehicleSelect" class="form-select @error('vehicle_id') is-invalid @enderror" required>
                            @foreach ($vehicles as $vehicle)
                                <option value="{{ $vehicle->id }}" {{ (int) old('vehicle_id', $workOrder->vehicle_id) === $vehicle->id ? 'selected' : '' }}>{{ $vehicle->plate_number ?? $vehicle->frame_number }}</option>
                            @endforeach
                        </select>
                        @error('vehicle_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mekanik</label>
                        <select name="mechanic_id" id="mechanicSelect" class="form-select @error('mechanic_id') is-invalid @enderror" required>
                            @foreach ($mechanics as $mechanic)
                                <option value="{{ $mechanic->id }}" {{ (int) old('mechanic_id', $workOrder->mechanic_id) === $mechanic->id ? 'selected' : '' }}>{{ $mechanic->name }}</option>
                            @endforeach
                        </select>
                        @error('mechanic_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tanggal PKB</label>
                        <input type="date" name="work_order_date" value="{{ old('work_order_date', $workOrder->work_order_date->format('Y-m-d')) }}" class="form-control @error('work_order_date') is-invalid @enderror" required>
                        @error('work_order_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kilometer</label>
                        <input type="number" step="0.1" min="0" name="odometer_km" value="{{ old('odometer_km', $workOrder->odometer_km) }}" class="form-control @error('odometer_km') is-invalid @enderror">
                        @error('odometer_km')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes', $workOrder->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Jasa</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addServiceLine">+ Tambah Jasa</button>
                </div>
                <div id="serviceLines"></div>
                @error('services')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Sparepart</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addSparepartLine">+ Tambah Sparepart</button>
                </div>
                <div id="sparepartLines"></div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('work-orders.show', $workOrder) }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('work-orders._line_item_scripts', ['serviceCatalogs' => $serviceCatalogs])

    @push('scripts')
    <script>
    (function () {
        const customerSelect = document.getElementById('customerSelect');
        const vehicleSelect = document.getElementById('vehicleSelect');

        const existingSparepartOptions = @json($sparepartOptionsForEdit);
        WorkOrderLineItems.setSparepartOptions(existingSparepartOptions);

        const existingServiceLines = @json($existingServiceLines);
        existingServiceLines.forEach(function (line) {
            WorkOrderLineItems.addServiceLine();
            const rows = document.querySelectorAll('#serviceLines .service-line');
            const row = rows[rows.length - 1];
            if (line.service_catalog_id) row.querySelector('.service-catalog-select').value = line.service_catalog_id;
            row.querySelector('.service-description').value = line.description;
            row.querySelector('.service-qty').value = line.qty;
            row.querySelector('.service-unit-price').value = line.unit_price;
        });

        const existingSparepartLines = @json($existingSparepartLines);
        existingSparepartLines.forEach(function (line) {
            WorkOrderLineItems.addSparepartLine();
            const rows = document.querySelectorAll('#sparepartLines .sparepart-line');
            const row = rows[rows.length - 1];
            row.querySelector('.sparepart-select').value = line.sparepart_branch_id;
            row.querySelector('.sparepart-qty').value = line.qty;
            row.querySelector('.sparepart-unit-price').value = line.unit_price;
        });

        customerSelect.addEventListener('change', async function () {
            if (!this.value) {
                WorkOrderLineItems.fillSelect(vehicleSelect, [], '-- Pilih Kendaraan --', 'id', function (i) { return i.plate_number; });
                return;
            }
            const vehicles = await WorkOrderLineItems.fetchJson(`/work-orders/lookup/vehicles/${this.value}`);
            WorkOrderLineItems.fillSelect(vehicleSelect, vehicles, '-- Pilih Kendaraan --', 'id', function (i) { return i.plate_number || i.frame_number; });
        });
    })();
    </script>
    @endpush
@endsection
