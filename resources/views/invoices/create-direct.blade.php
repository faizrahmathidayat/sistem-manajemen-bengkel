@extends('layouts.app')
@section('title', 'Invoice Langsung (Direct Sales)')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-receipt me-2"></i>Tambah Invoice Langsung (Direct Sales)</h1>
    </div>

    <form method="POST" action="{{ route('invoices.storeDirect') }}" id="invoiceForm">
        @csrf

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="branch_id" class="form-label">Cabang</label>
                        <select name="branch_id" id="branch_id" class="form-select @error('branch_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Cabang --</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" {{ (int) old('branch_id') === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="customer_id" class="form-label">Customer</label>
                        <select name="customer_id" id="customer_id" class="form-select @error('customer_id') is-invalid @enderror" required>
                        </select>
                        @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="invoice_date" class="form-label">Tanggal Invoice</label>
                        <input type="date" name="invoice_date" id="invoice_date"
                            class="form-control @error('invoice_date') is-invalid @enderror"
                            value="{{ old('invoice_date', now()->toDateString()) }}" required>
                        @error('invoice_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Jasa</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addInvoiceServiceLine">+ Tambah Jasa</button>
                </div>
                <div class="row g-2 small text-muted mb-1">
                    <div class="col-md-6">Jasa</div>
                    <div class="col-md-2">Qty</div>
                    <div class="col-md-1">Harga Satuan</div>
                    <div class="col-md-1">Diskon %</div>
                    <div class="col-md-1"></div>
                </div>
                <div id="invoiceServiceLines"></div>
                @error('services')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Sparepart</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addInvoiceSparepartLine">+ Tambah Sparepart</button>
                </div>
                <div class="row g-2 small text-muted mb-1">
                    <div class="col-md-4">Sparepart</div>
                    <div class="col-md-2">Qty</div>
                    <div class="col-md-1">Harga Satuan</div>
                    <div class="col-md-1">Diskon %</div>
                    <div class="col-md-1"></div>
                </div>
                <div id="invoiceSparepartLines"></div>
                @error('spareparts')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Simpan</button>
            <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
        </div>
    </form>

    @include('invoices._line_item_scripts')

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    @endpush

    @push('scripts')
    <script>
    (function () {
        const customerSelect = document.getElementById('customer_id');

        function initCustomerPicker(branchId) {
            if ($(customerSelect).data('select2')) {
                $(customerSelect).select2('destroy');
            }
            customerSelect.innerHTML = '';
            initAjaxSelect(customerSelect, {
                endpoint: '{{ route('lookup.customers') }}',
                extraParams: function () { return { branch_id: branchId }; },
                placeholder: '-- Pilih Customer --',
            });
        }

        document.getElementById('branch_id').addEventListener('change', function () {
            window.currentInvoiceBranchId = this.value || null;
            initCustomerPicker(this.value || null);
        });

        const initialBranchId = document.getElementById('branch_id').value || null;
        window.currentInvoiceBranchId = initialBranchId;
        initCustomerPicker(initialBranchId);
    })();
    </script>
    @endpush
@endsection
