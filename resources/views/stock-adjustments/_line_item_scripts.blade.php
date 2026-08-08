<template id="stockAdjustmentLineTemplate">
    <div class="row g-2 align-items-start mb-2 stock-adjustment-line">
        <div class="col-md-4">
            <select class="form-select stock-adjustment-sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" class="form-control stock-adjustment-system-qty" readonly tabindex="-1">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" min="0" required class="form-control stock-adjustment-physical-qty">
        </div>
        <div class="col-md-3">
            <input type="text" required class="form-control stock-adjustment-reason" placeholder="Alasan baris ini">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-stock-adjustment-line">&times;</button>
        </div>
    </div>
</template>

@push('scripts')
<script>
(function () {
    let lineCount = 0;

    function addLine(branchId) {
        const template = document.getElementById('stockAdjustmentLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.stock-adjustment-line');
        const index = lineCount++;
        const select = wrapper.querySelector('.stock-adjustment-sparepart-select');
        select.name = `lines[${index}][sparepart_branch_id]`;
        wrapper.querySelector('.stock-adjustment-physical-qty').name = `lines[${index}][physical_qty]`;
        wrapper.querySelector('.stock-adjustment-reason').name = `lines[${index}][reason]`;

        wrapper.querySelector('.remove-stock-adjustment-line').addEventListener('click', function () {
            if ($(select).data('select2')) $(select).select2('destroy');
            wrapper.remove();
        });
        document.getElementById('stockAdjustmentLines').appendChild(wrapper);

        initAjaxSelect(select, {
            endpoint: '{{ route('lookup.spareparts') }}',
            extraParams: function () { return { branch_id: branchId }; },
            placeholder: '-- Pilih Sparepart --',
            onSelect: function (item) {
                wrapper.querySelector('.stock-adjustment-system-qty').value = item.on_hand_qty;
            },
        });

        return wrapper;
    }

    async function preselectLine(row, sparepartBranchId, branchId) {
        const select = row.querySelector('.stock-adjustment-sparepart-select');
        const item = await preselectAjaxOption(select, {
            endpoint: '{{ route('lookup.spareparts') }}',
            id: sparepartBranchId,
            extraParams: function () { return { branch_id: branchId }; },
        });
        $(select).trigger('change');
        if (item) {
            row.querySelector('.stock-adjustment-system-qty').value = item.on_hand_qty;
        }

        return item;
    }

    document.getElementById('addStockAdjustmentLine').addEventListener('click', function () {
        addLine(window.currentStockAdjustmentBranchId || null);
    });

    window.StockAdjustmentLineItems = {
        addLine: addLine,
        preselectLine: preselectLine,
    };
})();
</script>
@endpush
