<template id="stockTransferLineTemplate">
    <div class="row g-2 align-items-start mb-2 stock-transfer-line">
        <div class="col-md-6">
            <select class="form-select stock-transfer-sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
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
    let sparepartOptionsCache = [];

    function fillSelect(select, items, placeholder) {
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        items.forEach(function (item) {
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.code + ' — ' + item.name;
            option.dataset.onHandQty = item.on_hand_qty;
            select.appendChild(option);
        });
    }

    function addLine() {
        const template = document.getElementById('stockTransferLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.stock-transfer-line');
        const index = lineCount++;
        const select = wrapper.querySelector('.stock-transfer-sparepart-select');
        select.name = `lines[${index}][sparepart_id]`;
        wrapper.querySelector('.stock-transfer-qty').name = `lines[${index}][qty]`;
        fillSelect(select, sparepartOptionsCache, '-- Pilih Sparepart --');

        select.addEventListener('change', function () {
            const selectedOption = select.options[select.selectedIndex];
            wrapper.querySelector('.stock-transfer-on-hand-qty').value = selectedOption ? (selectedOption.dataset.onHandQty || '0') : '';
        });

        wrapper.querySelector('.remove-stock-transfer-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('stockTransferLines').appendChild(wrapper);
    }

    document.getElementById('addStockTransferLine').addEventListener('click', addLine);

    window.StockTransferLineItems = {
        setSparepartOptions: function (items) {
            sparepartOptionsCache = items;
            document.querySelectorAll('.stock-transfer-sparepart-select').forEach(function (select) {
                const currentValue = select.value;
                fillSelect(select, items, '-- Pilih Sparepart --');
                select.value = currentValue;
                const selectedOption = select.options[select.selectedIndex];
                const row = select.closest('.stock-transfer-line');
                if (row && selectedOption && selectedOption.value) {
                    row.querySelector('.stock-transfer-on-hand-qty').value = selectedOption.dataset.onHandQty || '0';
                }
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
