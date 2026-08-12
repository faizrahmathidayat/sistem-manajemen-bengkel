@extends('layouts.app')
@section('title', 'PKB Baru')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-clipboard-check me-2"></i>PKB Baru</h1>
    </div>
    <form method="POST" action="{{ route('work-orders.store') }}" id="workOrderForm">
        @csrf
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Cabang</label>
                        <select name="branch_id" id="branchSelect" class="form-select @error('branch_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Cabang --</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" {{ (int) old('branch_id') === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kendaraan</label>
                        <select name="vehicle_id" id="vehicleSelect" class="form-select @error('vehicle_id') is-invalid @enderror" required disabled>
                            <option value="">-- Pilih Cabang Dulu --</option>
                        </select>
                        @error('vehicle_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <input type="text" id="customerDisplay" class="form-control @error('customer_id') is-invalid @enderror" value="" readonly placeholder="-- Pilih Kendaraan Dulu --">
                        <input type="hidden" name="customer_id" id="customerIdInput" value="{{ old('customer_id') }}">
                        @error('customer_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mekanik</label>
                        <select name="mechanic_id" id="mechanicSelect" class="form-select @error('mechanic_id') is-invalid @enderror" required disabled>
                            <option value="">-- Pilih Cabang Dulu --</option>
                        </select>
                        @error('mechanic_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tanggal PKB</label>
                        <input type="date" name="work_order_date" value="{{ old('work_order_date', now()->format('Y-m-d')) }}" class="form-control @error('work_order_date') is-invalid @enderror" required>
                        @error('work_order_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kilometer</label>
                        <input type="number" step="0.1" min="0" name="odometer_km" value="{{ old('odometer_km') }}" class="form-control @error('odometer_km') is-invalid @enderror">
                        @error('odometer_km')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Keluhan</label>
                        <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes') }}</textarea>
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
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addSparepartLine" disabled>+ Tambah Sparepart</button>
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
        <a href="{{ route('work-orders.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('work-orders._line_item_scripts', ['serviceCatalogs' => \App\Models\ServiceCatalog::where('is_active', true)->orderBy('name')->get()])

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    @endpush

    @php
        // Laravel 8.75's @json directive naively comma-splits its argument, so any @json()
        // call whose argument contains an internal comma (e.g. old('x', [])'s default-value
        // argument) can silently mangle the JSON_HEX_* escaping-flags argument it implicitly
        // passes through. Compute old-input values as plain PHP variables here and reference
        // them via bare @json($var) below, never @json(old(...)) directly.
        $oldServices = old('services', []);
        $oldSpareparts = old('spareparts', []);
        $oldMechanicId = old('mechanic_id');
        $oldVehicleId = old('vehicle_id');
    @endphp
    @push('scripts')
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const vehicleSelect = document.getElementById('vehicleSelect');
        const customerDisplay = document.getElementById('customerDisplay');
        const customerIdInput = document.getElementById('customerIdInput');
        const mechanicSelect = document.getElementById('mechanicSelect');
        const addSparepartButton = document.getElementById('addSparepartLine');
        let currentBranchId = branchSelect.value || null;
        window.currentWorkOrderBranchId = currentBranchId;

        function setCustomer(customerId, customerName) {
            customerIdInput.value = customerId || '';
            customerDisplay.value = customerName || '';
        }

        function initPickers() {
            initAjaxSelect(vehicleSelect, {
                endpoint: '{{ route('lookup.vehicles') }}',
                extraParams: function () { return { branch_id: currentBranchId }; },
                placeholder: '-- Pilih Kendaraan --',
                onSelect: function (item) { setCustomer(item.customer_id, item.customer_name); },
            });
            initAjaxSelect(mechanicSelect, {
                endpoint: '{{ route('lookup.mechanics') }}',
                extraParams: function () { return { branch_id: currentBranchId }; },
                placeholder: '-- Pilih Mekanik --',
            });
        }

        function destroyPickers() {
            if ($(vehicleSelect).data('select2')) $(vehicleSelect).select2('destroy');
            if ($(mechanicSelect).data('select2')) $(mechanicSelect).select2('destroy');
        }

        branchSelect.addEventListener('change', function () {
            currentBranchId = this.value || null;
            window.currentWorkOrderBranchId = currentBranchId;
            destroyPickers();
            vehicleSelect.innerHTML = '<option value=""></option>';
            mechanicSelect.innerHTML = '<option value=""></option>';
            setCustomer(null, null);
            if (!currentBranchId) {
                vehicleSelect.disabled = true;
                mechanicSelect.disabled = true;
                addSparepartButton.disabled = true;
                initPickers();
                return;
            }
            vehicleSelect.disabled = false;
            mechanicSelect.disabled = false;
            addSparepartButton.disabled = false;
            initPickers();
        });

        $(vehicleSelect).on('select2:clear', function () {
            setCustomer(null, null);
        });

        async function replayOldLines() {
            const oldServices = @json($oldServices);
            oldServices.forEach(function (line) {
                WorkOrderLineItems.addServiceLine();
                const rows = document.querySelectorAll('#serviceLines .service-line');
                const row = rows[rows.length - 1];
                if (line.service_catalog_id) row.querySelector('.service-catalog-select').value = line.service_catalog_id;
                row.querySelector('.service-description').value = line.description || '';
                row.querySelector('.service-qty').value = line.qty || '';
                row.querySelector('.service-unit-price').value = line.unit_price || '';
            });

            const oldSpareparts = @json($oldSpareparts);
            for (const line of oldSpareparts) {
                WorkOrderLineItems.addSparepartLine(currentBranchId);
                const rows = document.querySelectorAll('#sparepartLines .sparepart-line');
                const row = rows[rows.length - 1];
                row.querySelector('.sparepart-qty').value = line.qty || '';
                row.querySelector('.sparepart-unit-price').value = line.unit_price || '';
                if (line.sparepart_branch_id) {
                    await WorkOrderLineItems.preselectSparepartLine(row, line.sparepart_branch_id, currentBranchId);
                }
            }

            const oldVehicleId = @json($oldVehicleId);
            if (oldVehicleId) {
                const item = await preselectAjaxOption(vehicleSelect, { endpoint: '{{ route('lookup.vehicles') }}', id: oldVehicleId, extraParams: function () { return { branch_id: currentBranchId }; } });
                if (item) setCustomer(item.customer_id, item.customer_name);
                $(vehicleSelect).trigger('change');
            }
            const oldMechanicId = @json($oldMechanicId);
            if (oldMechanicId) {
                await preselectAjaxOption(mechanicSelect, { endpoint: '{{ route('lookup.mechanics') }}', id: oldMechanicId, extraParams: function () { return { branch_id: currentBranchId }; } });
                $(mechanicSelect).trigger('change');
            }
        }

        if (branchSelect.value) {
            vehicleSelect.disabled = false;
            mechanicSelect.disabled = false;
            addSparepartButton.disabled = false;
            initPickers();
            replayOldLines();
        } else {
            vehicleSelect.disabled = true;
            mechanicSelect.disabled = true;
            addSparepartButton.disabled = true;
            initPickers();
            replayOldLines();
        }
    })();
    </script>
    @endpush
@endsection
