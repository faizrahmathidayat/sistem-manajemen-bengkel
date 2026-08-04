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
            <input type="number" step="0.001" min="0" class="form-control stock-adjustment-physical-qty">
        </div>
        <div class="col-md-3">
            <input type="text" class="form-control stock-adjustment-reason" placeholder="Alasan baris ini">
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
        const template = document.getElementById('stockAdjustmentLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.stock-adjustment-line');
        const index = lineCount++;
        const select = wrapper.querySelector('.stock-adjustment-sparepart-select');
        select.name = `lines[${index}][sparepart_branch_id]`;
        wrapper.querySelector('.stock-adjustment-physical-qty').name = `lines[${index}][physical_qty]`;
        wrapper.querySelector('.stock-adjustment-reason').name = `lines[${index}][reason]`;
        fillSelect(select, sparepartOptionsCache, '-- Pilih Sparepart --');

        select.addEventListener('change', function () {
            const selectedOption = select.options[select.selectedIndex];
            wrapper.querySelector('.stock-adjustment-system-qty').value = selectedOption ? (selectedOption.dataset.onHandQty || '0') : '';
        });

        wrapper.querySelector('.remove-stock-adjustment-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('stockAdjustmentLines').appendChild(wrapper);
    }

    document.getElementById('addStockAdjustmentLine').addEventListener('click', addLine);

    window.StockAdjustmentLineItems = {
        setSparepartOptions: function (items) {
            sparepartOptionsCache = items;
            document.querySelectorAll('.stock-adjustment-sparepart-select').forEach(function (select) {
                const currentValue = select.value;
                fillSelect(select, items, '-- Pilih Sparepart --');
                select.value = currentValue;
                const selectedOption = select.options[select.selectedIndex];
                const row = select.closest('.stock-adjustment-line');
                if (row && selectedOption && selectedOption.value) {
                    row.querySelector('.stock-adjustment-system-qty').value = selectedOption.dataset.onHandQty || '0';
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
