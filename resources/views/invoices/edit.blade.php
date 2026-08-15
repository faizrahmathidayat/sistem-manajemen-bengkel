@extends('layouts.app')
@section('title', 'Ubah Invoice')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-receipt"></i></span>
            <div>
                <p class="eyebrow mb-1">Invoice</p>
                <h1 class="h3 mb-1">Ubah {{ $invoice->number }}</h1>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('invoices.update', $invoice) }}" id="invoiceForm">
        @csrf
        @method('PUT')

        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-info-circle"></i><span>Informasi Invoice</span></h2>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="discount_percent" class="form-label">Diskon (%)</label>
                    <input type="number" step="0.01" min="0" max="100" name="discount_percent" id="discount_percent"
                        class="form-control @error('discount_percent') is-invalid @enderror"
                        value="{{ old('discount_percent', $invoice->discount_percent) }}" required>
                    @error('discount_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label for="tax_percent" class="form-label">PPN (%)</label>
                    <input type="number" step="0.01" min="0" name="tax_percent" id="tax_percent"
                        class="form-control @error('tax_percent') is-invalid @enderror"
                        value="{{ old('tax_percent', $invoice->tax_percent) }}" required>
                    @error('tax_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label for="due_date" class="form-label">Tanggal Jatuh Tempo</label>
                    <input type="date" name="due_date" id="due_date"
                        class="form-control @error('due_date') is-invalid @enderror"
                        value="{{ old('due_date', optional($invoice->due_date)->toDateString()) }}">
                    @error('due_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-12">
                    <label for="notes" class="form-label">Catatan</label>
                    <textarea name="notes" id="notes" class="form-control @error('notes') is-invalid @enderror" rows="2">{{ old('notes', $invoice->notes) }}</textarea>
                    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
            <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-outline-secondary btn-sm">Batal</a>
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
        const branchId = {{ $invoice->branch_id }};
        window.currentInvoiceBranchId = branchId;

        const existingServiceLines = @json($existingServiceLines);
        existingServiceLines.forEach(function (line) {
            // Existing lines always render read-only, regardless of PKB trace: the Master Jasa
            // dropdown has no reliable way to preselect a catalog entry for an already-saved line
            // (invoice_details doesn't store service_catalog_id), so showing it unselected next to
            // a filled-in description is misleading. It only makes sense for genuinely new lines
            // added via "+ Tambah Jasa", where there is nothing to preselect yet.
            const row = InvoiceLineItems.addServiceLine(true);
            row.querySelector('.service-wo-line-id').value = line.work_order_service_line_id || '';
            row.querySelector('.service-locked-description').value = line.description;
            row.querySelector('.service-qty').value = line.qty;
            row.querySelector('.service-unit-price').value = line.unit_price;
            row.querySelector('.service-discount-percent').value = line.discount_percent || 0;
        });

        const existingSparepartLines = @json($existingSparepartLines);
        existingSparepartLines.forEach(function (line) {
            const locked = !!line.work_order_sparepart_line_id;
            const row = InvoiceLineItems.addSparepartLine(branchId, locked);
            row.querySelector('.sparepart-wo-line-id').value = line.work_order_sparepart_line_id || '';
            row.querySelector('.sparepart-qty').value = line.qty;
            row.querySelector('.sparepart-unit-price').value = line.unit_price;
            row.querySelector('.sparepart-discount-percent').value = line.discount_percent || 0;
            if (locked) {
                row.querySelector('.sparepart-locked-branch-id').value = line.sparepart_branch_id;
                row.querySelector('.sparepart-locked-label').value = (line.item_code_snapshot ? line.item_code_snapshot + ' — ' : '') + line.description;
            } else {
                InvoiceLineItems.preselectFreeFormSparepartLine(row, line.sparepart_branch_id, branchId);
            }
        });
    })();
    </script>
    @endpush
@endsection
