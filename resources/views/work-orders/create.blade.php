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
                        <label class="form-label">Customer</label>
                        <select name="customer_id" id="customerSelect" class="form-select @error('customer_id') is-invalid @enderror" required disabled>
                            <option value="">-- Pilih Cabang Dulu --</option>
                        </select>
                        @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kendaraan</label>
                        <select name="vehicle_id" id="vehicleSelect" class="form-select @error('vehicle_id') is-invalid @enderror" required disabled>
                            <option value="">-- Pilih Customer Dulu --</option>
                        </select>
                        @error('vehicle_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                        <label class="form-label">Catatan</label>
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

    @push('scripts')
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const customerSelect = document.getElementById('customerSelect');
        const vehicleSelect = document.getElementById('vehicleSelect');
        const mechanicSelect = document.getElementById('mechanicSelect');
        const addSparepartButton = document.getElementById('addSparepartLine');
        let currentBranchId = branchSelect.value || null;
        window.currentWorkOrderBranchId = currentBranchId;

        function initPickers() {
            initAjaxSelect(customerSelect, {
                endpoint: '{{ route('lookup.customers') }}',
                extraParams: function () { return { branch_id: currentBranchId }; },
                placeholder: '-- Pilih Customer --',
            });
            initAjaxSelect(mechanicSelect, {
                endpoint: '{{ route('lookup.mechanics') }}',
                extraParams: function () { return { branch_id: currentBranchId }; },
                placeholder: '-- Pilih Mekanik --',
            });
        }

        function destroyPickers() {
            if ($(customerSelect).data('select2')) $(customerSelect).select2('destroy');
            if ($(mechanicSelect).data('select2')) $(mechanicSelect).select2('destroy');
        }

        branchSelect.addEventListener('change', function () {
            currentBranchId = this.value || null;
            window.currentWorkOrderBranchId = currentBranchId;
            destroyPickers();
            customerSelect.innerHTML = '<option value=""></option>';
            mechanicSelect.innerHTML = '<option value=""></option>';
            WorkOrderLineItems.fillSelect(vehicleSelect, [], '-- Pilih Customer Dulu --', 'id', function (i) { return i.plate_number; });
            vehicleSelect.disabled = true;
            if (!currentBranchId) {
                customerSelect.disabled = true;
                mechanicSelect.disabled = true;
                addSparepartButton.disabled = true;
                initPickers();
                return;
            }
            customerSelect.disabled = false;
            mechanicSelect.disabled = false;
            addSparepartButton.disabled = false;
            initPickers();
        });

        customerSelect.addEventListener('change', async function () {
            if (!this.value) {
                WorkOrderLineItems.fillSelect(vehicleSelect, [], '-- Pilih Customer Dulu --', 'id', function (i) { return i.plate_number; });
                vehicleSelect.disabled = true;
                return;
            }
            const vehicles = await WorkOrderLineItems.fetchJson(`/work-orders/lookup/vehicles/${this.value}`);
            WorkOrderLineItems.fillSelect(vehicleSelect, vehicles, '-- Pilih Kendaraan --', 'id', function (i) { return i.plate_number || i.frame_number; });
            vehicleSelect.disabled = false;
        });

        // Select2 replaces the native <select>'s change semantics with its own
        // jQuery events; customerSelect's cascade to vehicles must still fire on
        // a Select2-driven selection, so re-trigger the native listener via jQuery.
        $(customerSelect).on('select2:select select2:clear', function () {
            customerSelect.dispatchEvent(new Event('change'));
        });

        async function replayOldLines() {
            const oldServices = @json(old('services', []));
            oldServices.forEach(function (line) {
                WorkOrderLineItems.addServiceLine();
                const rows = document.querySelectorAll('#serviceLines .service-line');
                const row = rows[rows.length - 1];
                if (line.service_catalog_id) row.querySelector('.service-catalog-select').value = line.service_catalog_id;
                row.querySelector('.service-description').value = line.description || '';
                row.querySelector('.service-qty').value = line.qty || '';
                row.querySelector('.service-unit-price').value = line.unit_price || '';
            });

            const oldSpareparts = @json(old('spareparts', []));
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

            const oldCustomerId = @json(old('customer_id'));
            if (oldCustomerId) {
                await preselectAjaxOption(customerSelect, { endpoint: '{{ route('lookup.customers') }}', id: oldCustomerId, extraParams: function () { return { branch_id: currentBranchId }; } });
                $(customerSelect).trigger('change');
            }
            const oldMechanicId = @json(old('mechanic_id'));
            if (oldMechanicId) {
                await preselectAjaxOption(mechanicSelect, { endpoint: '{{ route('lookup.mechanics') }}', id: oldMechanicId, extraParams: function () { return { branch_id: currentBranchId }; } });
                $(mechanicSelect).trigger('change');
            }
        }

        if (branchSelect.value) {
            customerSelect.disabled = false;
            mechanicSelect.disabled = false;
            addSparepartButton.disabled = false;
            initPickers();
            replayOldLines().then(async function () {
                const oldCustomerId = @json(old('customer_id'));
                if (oldCustomerId) {
                    const vehicles = await WorkOrderLineItems.fetchJson(`/work-orders/lookup/vehicles/${oldCustomerId}`);
                    WorkOrderLineItems.fillSelect(vehicleSelect, vehicles, '-- Pilih Kendaraan --', 'id', function (i) { return i.plate_number || i.frame_number; });
                    vehicleSelect.disabled = false;
                    const oldVehicleId = @json(old('vehicle_id'));
                    if (oldVehicleId) vehicleSelect.value = oldVehicleId;
                }
            });
        } else {
            customerSelect.disabled = true;
            mechanicSelect.disabled = true;
            addSparepartButton.disabled = true;
            initPickers();
            replayOldLines();
        }
    })();
    </script>
    @endpush
@endsection
