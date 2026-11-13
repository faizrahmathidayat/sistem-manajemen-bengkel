<div class="d-flex flex-wrap gap-2">
    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width: 170px;">
        <option value="">-- Semua Status --</option>
        @foreach (\App\Support\PurchaseOrderStatus::LABELS as $value => $label)
            <option value="{{ $value }}" {{ $selectedStatus === $value ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
    <select name="supplier_id" id="poSupplierFilter" class="form-select form-select-sm" style="min-width: 200px;">
        @if ($selectedSupplier)
            <option value="{{ $selectedSupplier->id }}" selected>{{ $selectedSupplier->label }}</option>
        @endif
    </select>
</div>

@push('scripts')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
<script>
(function () {
    const select = document.getElementById('poSupplierFilter');
    initAjaxSelect(select, { endpoint: @json(route('lookup.suppliers')), placeholder: 'Semua Supplier' });
    $(select).on('select2:select select2:clear', function () { select.form.submit(); });
})();
</script>
@endpush
