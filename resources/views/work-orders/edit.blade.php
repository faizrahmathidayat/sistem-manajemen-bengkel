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
                        <select name="customer_id" id="customerSelect" class="form-select @error('customer_id') is-invalid @enderror" required></select>
                        @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kendaraan</label>
                        <select name="vehicle_id" id="vehicleSelect" class="form-select @error('vehicle_id') is-invalid @enderror" required>
                            @foreach ($vehicles as $vehicle)
                                @php
                                    $vehicleBrandType = trim(($vehicle->brand->name ?? '') . ' ' . ($vehicle->type->name ?? '') . ' ' . ($vehicle->year ?? ''));
                                    $vehicleLabel = $vehicleBrandType !== '' ? $vehicleBrandType . ' - ' . ($vehicle->plate_number ?? $vehicle->frame_number) : ($vehicle->plate_number ?? $vehicle->frame_number);
                                @endphp
                                <option value="{{ $vehicle->id }}" {{ (int) old('vehicle_id', $workOrder->vehicle_id) === $vehicle->id ? 'selected' : '' }}>{{ $vehicleLabel }}</option>
                            @endforeach
                        </select>
                        @error('vehicle_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mekanik</label>
                        <select name="mechanic_id" id="mechanicSelect" class="form-select @error('mechanic_id') is-invalid @enderror" required></select>
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
                <div class="row g-2 small text-muted mb-1">
                    <div class="col-md-3">Katalog Jasa</div>
                    <div class="col-md-4">Deskripsi</div>
                    <div class="col-md-2">Qty</div>
                    <div class="col-md-2">Harga Satuan</div>
                    <div class="col-md-1"></div>
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
                <div class="row g-2 small text-muted mb-1">
                    <div class="col-md-5">Sparepart</div>
                    <div class="col-md-2">Qty</div>
                    <div class="col-md-2">Harga Satuan</div>
                    <div class="col-md-1"></div>
                </div>
                <div id="sparepartLines"></div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('work-orders.show', $workOrder) }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('work-orders._line_item_scripts', ['serviceCatalogs' => $serviceCatalogs])

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    @endpush

    @push('scripts')
    <script>
    (function () {
        const customerSelect = document.getElementById('customerSelect');
        const vehicleSelect = document.getElementById('vehicleSelect');
        const mechanicSelect = document.getElementById('mechanicSelect');
        const branchId = {{ $workOrder->branch_id }};
        window.currentWorkOrderBranchId = branchId;

        initAjaxSelect(customerSelect, {
            endpoint: '{{ route('lookup.customers') }}',
            extraParams: function () { return { branch_id: branchId }; },
            placeholder: '-- Pilih Customer --',
        });
        initAjaxSelect(mechanicSelect, {
            endpoint: '{{ route('lookup.mechanics') }}',
            extraParams: function () { return { branch_id: branchId }; },
            placeholder: '-- Pilih Mekanik --',
        });
        preselectAjaxOption(customerSelect, {
            endpoint: '{{ route('lookup.customers') }}',
            id: {{ $workOrder->customer_id }},
            extraParams: function () { return { branch_id: branchId }; },
        }).then(function () { $(customerSelect).trigger('change'); });
        preselectAjaxOption(mechanicSelect, {
            endpoint: '{{ route('lookup.mechanics') }}',
            id: {{ $workOrder->mechanic_id }},
            extraParams: function () { return { branch_id: branchId }; },
        }).then(function () { $(mechanicSelect).trigger('change'); });

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
            const row = WorkOrderLineItems.addSparepartLine(branchId);
            row.querySelector('.sparepart-qty').value = line.qty;
            row.querySelector('.sparepart-unit-price').value = line.unit_price;
            WorkOrderLineItems.preselectSparepartLine(row, line.sparepart_branch_id, branchId);
        });

        customerSelect.addEventListener('change', async function () {
            if (!this.value) {
                WorkOrderLineItems.fillSelect(vehicleSelect, [], '-- Pilih Kendaraan --', 'id', WorkOrderLineItems.vehicleLabel);
                return;
            }
            const vehicles = await WorkOrderLineItems.fetchJson(`/work-orders/lookup/vehicles/${this.value}`);
            WorkOrderLineItems.fillSelect(vehicleSelect, vehicles, '-- Pilih Kendaraan --', 'id', WorkOrderLineItems.vehicleLabel);
        });
    })();
    </script>
    @endpush
@endsection
