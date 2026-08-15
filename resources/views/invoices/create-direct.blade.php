@extends('layouts.app')
@section('title', 'Invoice Langsung (Direct Sales)')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-receipt"></i></span>
            <div>
                <p class="eyebrow mb-1">Invoice</p>
                <h1 class="h3 mb-1">Tambah Invoice Langsung (Direct Sales)</h1>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('invoices.storeDirect') }}" id="invoiceForm">
        @csrf

        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-info-circle"></i><span>Informasi Invoice</span></h2>
                </div>
            </div>
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

        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-wrench-adjustable"></i><span>Baris Jasa</span></h2>
                </div>
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

        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-nut"></i><span>Baris Sparepart</span></h2>
                </div>
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

        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Simpan</button>
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
