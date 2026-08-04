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
    let sparepartOptionsCache = [];

    function fillSelect(select, items, placeholder) {
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        items.forEach(function (item) {
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.code + ' — ' + item.name;
            select.appendChild(option);
        });
    }

    function addLine() {
        const template = document.getElementById('goodsReceiptLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.goods-receipt-line');
        const index = lineCount++;
        const select = wrapper.querySelector('.goods-receipt-sparepart-select');
        select.name = `lines[${index}][sparepart_branch_id]`;
        wrapper.querySelector('.goods-receipt-qty').name = `lines[${index}][qty]`;
        wrapper.querySelector('.goods-receipt-purchase-price').name = `lines[${index}][purchase_price]`;
        fillSelect(select, sparepartOptionsCache, '-- Pilih Sparepart --');
        wrapper.querySelector('.remove-goods-receipt-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('goodsReceiptLines').appendChild(wrapper);
    }

    document.getElementById('addGoodsReceiptLine').addEventListener('click', addLine);

    window.GoodsReceiptLineItems = {
        setSparepartOptions: function (items) {
            sparepartOptionsCache = items;
            document.querySelectorAll('.goods-receipt-sparepart-select').forEach(function (select) {
                const currentValue = select.value;
                fillSelect(select, items, '-- Pilih Sparepart --');
                select.value = currentValue;
            });
        },
        addLine: addLine,
        fetchJson: async function (url) {
            const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
            return response.json();
        },
    };
})();
</script>
@endpush
