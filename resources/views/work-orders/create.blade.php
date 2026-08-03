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
                <div id="sparepartLines"></div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('work-orders.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('work-orders._line_item_scripts', ['serviceCatalogs' => \App\Models\ServiceCatalog::where('is_active', true)->orderBy('name')->get()])

    @push('scripts')
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const customerSelect = document.getElementById('customerSelect');
        const vehicleSelect = document.getElementById('vehicleSelect');
        const mechanicSelect = document.getElementById('mechanicSelect');
        const addSparepartButton = document.getElementById('addSparepartLine');

        async function handleBranchChange(branchId) {
            customerSelect.disabled = true;
            mechanicSelect.disabled = true;
            addSparepartButton.disabled = true;
            WorkOrderLineItems.fillSelect(vehicleSelect, [], '-- Pilih Customer Dulu --', 'id', function (i) { return i.plate_number; });
            vehicleSelect.disabled = true;
            if (!branchId) {
                WorkOrderLineItems.fillSelect(customerSelect, [], '-- Pilih Cabang Dulu --', 'id', function (i) { return i.name; });
                WorkOrderLineItems.fillSelect(mechanicSelect, [], '-- Pilih Cabang Dulu --', 'id', function (i) { return i.name; });
                return;
            }
            const [customers, mechanics, spareparts] = await Promise.all([
                WorkOrderLineItems.fetchJson(`/work-orders/lookup/customers/${branchId}`),
                WorkOrderLineItems.fetchJson(`/work-orders/lookup/mechanics/${branchId}`),
                WorkOrderLineItems.fetchJson(`/work-orders/lookup/spareparts/${branchId}`),
            ]);
            WorkOrderLineItems.fillSelect(customerSelect, customers, '-- Pilih Customer --', 'id', function (i) { return i.name; });
            customerSelect.disabled = false;
            WorkOrderLineItems.fillSelect(mechanicSelect, mechanics, '-- Pilih Mekanik --', 'id', function (i) { return i.name; });
            mechanicSelect.disabled = false;
            WorkOrderLineItems.setSparepartOptions(spareparts);
            addSparepartButton.disabled = false;
        }

        branchSelect.addEventListener('change', function () {
            handleBranchChange(this.value);
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

        // Validation-error round-trip: replay the jasa/sparepart line rows submitted
        // before the failed validation, the same way edit.blade.php replays a work
        // order's persisted lines. These rows only exist in JS-managed DOM state
        // (added via <template> cloning), so without this the user would have to
        // retype every line from scratch after any validation error.
        function replayOldLines() {
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
            oldSpareparts.forEach(function (line) {
                WorkOrderLineItems.addSparepartLine();
                const rows = document.querySelectorAll('#sparepartLines .sparepart-line');
                const row = rows[rows.length - 1];
                if (line.sparepart_branch_id) row.querySelector('.sparepart-select').value = line.sparepart_branch_id;
                row.querySelector('.sparepart-qty').value = line.qty || '';
                row.querySelector('.sparepart-unit-price').value = line.unit_price || '';
            });
        }

        // Validation-error round-trip: old('branch_id') re-selects the branch option but
        // does not fire a native `change` event, so the customer/mechanic/sparepart
        // cascade would otherwise stay empty and disabled. Re-run it once on load.
        //
        // The sparepart <select> options are populated dynamically from the branch's
        // AJAX response (via WorkOrderLineItems.setSparepartOptions inside
        // handleBranchChange), so line-replay MUST run after that AJAX call resolves —
        // otherwise replayed sparepart rows would be added with no options to select
        // from yet. handleBranchChange() is async and returns a promise, so we chain
        // replayOldLines() onto it here instead of calling it eagerly.
        if (branchSelect.value) {
            handleBranchChange(branchSelect.value).then(replayOldLines);
        } else {
            replayOldLines();
        }
    })();
    </script>
    @endpush
@endsection
