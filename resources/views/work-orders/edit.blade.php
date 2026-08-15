@extends('layouts.app')
@section('title', 'Ubah PKB')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-clipboard-check"></i></span>
            <div>
                <p class="eyebrow mb-1">Perintah Kerja Bengkel</p>
                <h1 class="h3 mb-1">Ubah {{ $workOrder->number }}</h1>
                <p class="text-muted mb-0">{{ $workOrder->branch->name }}</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('work-orders.show', $workOrder) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('work-orders.update', $workOrder) }}" id="workOrderForm">
        @csrf
        @method('PUT')
        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-info-circle"></i><span>Informasi PKB</span></h2>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Kendaraan</label>
                    <select name="vehicle_id" id="vehicleSelect" class="form-select @error('vehicle_id') is-invalid @enderror" required></select>
                    @error('vehicle_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Customer</label>
                    <input type="text" id="customerDisplay" class="form-control @error('customer_id') is-invalid @enderror" value="{{ $workOrder->customer->name }}" readonly>
                    <input type="hidden" name="customer_id" id="customerIdInput" value="{{ old('customer_id', $workOrder->customer_id) }}">
                    @error('customer_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
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
                    <label class="form-label">Keluhan</label>
                    <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes', $workOrder->notes) }}</textarea>
                    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-wrench-adjustable"></i><span>Baris Jasa</span></h2>
                </div>
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

        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-nut"></i><span>Baris Sparepart</span></h2>
                </div>
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

        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('work-orders.show', $workOrder) }}" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </div>
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
        const vehicleSelect = document.getElementById('vehicleSelect');
        const customerDisplay = document.getElementById('customerDisplay');
        const customerIdInput = document.getElementById('customerIdInput');
        const mechanicSelect = document.getElementById('mechanicSelect');
        const branchId = {{ $workOrder->branch_id }};
        window.currentWorkOrderBranchId = branchId;

        function setCustomer(customerId, customerName) {
            customerIdInput.value = customerId || '';
            customerDisplay.value = customerName || '';
        }

        initAjaxSelect(vehicleSelect, {
            endpoint: '{{ route('lookup.vehicles') }}',
            extraParams: function () { return { branch_id: branchId }; },
            placeholder: '-- Pilih Kendaraan --',
            onSelect: function (item) { setCustomer(item.customer_id, item.customer_name); },
        });
        initAjaxSelect(mechanicSelect, {
            endpoint: '{{ route('lookup.mechanics') }}',
            extraParams: function () { return { branch_id: branchId }; },
            placeholder: '-- Pilih Mekanik --',
        });
        $(vehicleSelect).on('select2:clear', function () {
            setCustomer(null, null);
        });
        preselectAjaxOption(vehicleSelect, {
            endpoint: '{{ route('lookup.vehicles') }}',
            id: {{ $workOrder->vehicle_id }},
            extraParams: function () { return { branch_id: branchId }; },
        }).then(function (item) {
            if (item) setCustomer(item.customer_id, item.customer_name);
            $(vehicleSelect).trigger('change');
        });
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
    })();
    </script>
    @endpush
@endsection
