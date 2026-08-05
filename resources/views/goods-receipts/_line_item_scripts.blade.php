<template id="goodsReceiptLineTemplate">
    <div class="row g-2 align-items-start mb-2 goods-receipt-line">
        <div class="col-md-5">
            <select class="form-select goods-receipt-sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
        </div>
        <div class="col-md-3">
            <input type="number" step="0.001" min="0.001" class="form-control goods-receipt-qty" value="1">
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" min="0" class="form-control goods-receipt-purchase-price">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-goods-receipt-line">&times;</button>
        </div>
    </div>
</template>

@push('scripts')
<script>
(function () {
    let lineCount = 0;

    function addLine(branchId) {
        const template = document.getElementById('goodsReceiptLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.goods-receipt-line');
        const index = lineCount++;
        const select = wrapper.querySelector('.goods-receipt-sparepart-select');
        select.name = `lines[${index}][sparepart_branch_id]`;
        wrapper.querySelector('.goods-receipt-qty').name = `lines[${index}][qty]`;
        wrapper.querySelector('.goods-receipt-purchase-price').name = `lines[${index}][purchase_price]`;
        wrapper.querySelector('.remove-goods-receipt-line').addEventListener('click', function () {
            if ($(select).data('select2')) $(select).select2('destroy');
            wrapper.remove();
        });
        document.getElementById('goodsReceiptLines').appendChild(wrapper);

        initAjaxSelect(select, {
            endpoint: '{{ route('lookup.spareparts') }}',
            extraParams: function () { return { branch_id: branchId }; },
            placeholder: '-- Pilih Sparepart --',
            onSelect: function (item) {
                wrapper.querySelector('.goods-receipt-purchase-price').value = item.selling_price;
            },
        });

        return wrapper;
    }

    async function preselectLine(row, sparepartBranchId, branchId) {
        const select = row.querySelector('.goods-receipt-sparepart-select');
        const item = await preselectAjaxOption(select, {
            endpoint: '{{ route('lookup.spareparts') }}',
            id: sparepartBranchId,
            extraParams: function () { return { branch_id: branchId }; },
        });
        if (item) {
            row.querySelector('.goods-receipt-purchase-price').value = item.selling_price;
        }
        $(select).trigger('change');

        return item;
    }

    document.getElementById('addGoodsReceiptLine').addEventListener('click', function () {
        addLine(window.currentGoodsReceiptBranchId || null);
    });

    window.GoodsReceiptLineItems = {
        addLine: addLine,
        preselectLine: preselectLine,
    };
})();
</script>
@endpush
