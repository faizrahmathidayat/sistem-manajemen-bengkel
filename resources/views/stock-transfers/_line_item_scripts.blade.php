<template id="stockTransferLineTemplate">
    <div class="row g-2 align-items-start mb-2 stock-transfer-line">
        <div class="col-md-6">
            <select class="form-select stock-transfer-sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
            <input type="hidden" class="sparepart-id-hidden">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" class="form-control stock-transfer-on-hand-qty" readonly tabindex="-1">
        </div>
        <div class="col-md-3">
            <input type="number" step="0.001" min="0.001" class="form-control stock-transfer-qty" value="1">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-stock-transfer-line">&times;</button>
        </div>
    </div>
</template>

@push('scripts')
<script>
(function () {
    let lineCount = 0;

    function addLine(branchId) {
        const template = document.getElementById('stockTransferLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.stock-transfer-line');
        const index = lineCount++;
        const select = wrapper.querySelector('.stock-transfer-sparepart-select');
        select.removeAttribute('name'); // Select2 UI only, sparepart_branch_id is never submitted
        wrapper.querySelector('.sparepart-id-hidden').name = `lines[${index}][sparepart_id]`;
        wrapper.querySelector('.stock-transfer-qty').name = `lines[${index}][qty]`;

        wrapper.querySelector('.remove-stock-transfer-line').addEventListener('click', function () {
            if ($(select).data('select2')) $(select).select2('destroy');
            wrapper.remove();
        });
        document.getElementById('stockTransferLines').appendChild(wrapper);

        initAjaxSelect(select, {
            endpoint: '{{ route('lookup.spareparts') }}',
            extraParams: function () { return { branch_id: branchId }; },
            placeholder: '-- Pilih Sparepart --',
            onSelect: function (item) {
                wrapper.querySelector('.sparepart-id-hidden').value = item.sparepart_id;
                wrapper.querySelector('.stock-transfer-on-hand-qty').value = item.on_hand_qty;
            },
        });

        return wrapper;
    }

    async function preselectLine(row, sparepartId, branchId) {
        const select = row.querySelector('.stock-transfer-sparepart-select');
        // Set the submitted value immediately from the already-known sparepart_id —
        // no need to wait for the async Select2 display resolve below.
        row.querySelector('.sparepart-id-hidden').value = sparepartId;
        const item = await preselectAjaxOption(select, {
            endpoint: '{{ route('lookup.spareparts') }}',
            id: sparepartId,
            extraParams: function () { return { branch_id: branchId }; },
        });
        $(select).trigger('change');
        if (item) {
            row.querySelector('.stock-transfer-on-hand-qty').value = item.on_hand_qty;
        }

        return item;
    }

    document.getElementById('addStockTransferLine').addEventListener('click', function () {
        addLine(window.currentStockTransferFromBranchId || null);
    });

    window.StockTransferLineItems = {
        addLine: addLine,
        preselectLine: preselectLine,
    };
})();
</script>
@endpush
