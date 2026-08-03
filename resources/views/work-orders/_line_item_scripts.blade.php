<template id="serviceLineTemplate">
    <div class="row g-2 align-items-start mb-2 service-line">
        <div class="col-md-3">
            <select class="form-select service-catalog-select" data-name-prefix="services">
                <option value="">-- Manual --</option>
                @foreach ($serviceCatalogs as $catalog)
                    <option value="{{ $catalog->id }}" data-price="{{ $catalog->default_price }}" data-name="{{ $catalog->name }}">{{ $catalog->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <input type="text" class="form-control service-description" placeholder="Deskripsi jasa">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" min="0.001" class="form-control service-qty" value="1">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" min="0" class="form-control service-unit-price">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-line">&times;</button>
        </div>
    </div>
</template>

<template id="sparepartLineTemplate">
    <div class="row g-2 align-items-start mb-2 sparepart-line">
        <div class="col-md-5">
            <select class="form-select sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
            <div class="form-text sparepart-availability"></div>
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" min="0.001" class="form-control sparepart-qty" value="1">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" min="0" class="form-control sparepart-unit-price">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-line">&times;</button>
        </div>
    </div>
</template>

@push('scripts')
<script>
(function () {
    let serviceLineCount = 0;
    let sparepartLineCount = 0;
    let sparepartOptionsCache = [];

    function fillSelect(select, items, placeholder, valueKey, labelFn) {
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        items.forEach(function (item) {
            const option = document.createElement('option');
            option.value = item[valueKey];
            option.textContent = labelFn(item);
            option.dataset.item = JSON.stringify(item);
            select.appendChild(option);
        });
    }

    async function fetchJson(url) {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        return response.json();
    }

    function addServiceLine() {
        const template = document.getElementById('serviceLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.service-line');
        const index = serviceLineCount++;
        wrapper.querySelector('.service-catalog-select').name = `services[${index}][service_catalog_id]`;
        wrapper.querySelector('.service-description').name = `services[${index}][description]`;
        wrapper.querySelector('.service-qty').name = `services[${index}][qty]`;
        wrapper.querySelector('.service-unit-price').name = `services[${index}][unit_price]`;
        wrapper.querySelector('.service-catalog-select').addEventListener('change', function () {
            const selected = this.selectedOptions[0];
            const description = wrapper.querySelector('.service-description');
            const unitPrice = wrapper.querySelector('.service-unit-price');
            if (this.value) {
                description.value = selected.dataset.name || '';
                unitPrice.value = selected.dataset.price || 0;
            }
        });
        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('serviceLines').appendChild(wrapper);
    }

    function addSparepartLine() {
        const template = document.getElementById('sparepartLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.sparepart-line');
        const index = sparepartLineCount++;
        const select = wrapper.querySelector('.sparepart-select');
        select.name = `spareparts[${index}][sparepart_branch_id]`;
        wrapper.querySelector('.sparepart-qty').name = `spareparts[${index}][qty]`;
        wrapper.querySelector('.sparepart-unit-price').name = `spareparts[${index}][unit_price]`;
        fillSelect(select, sparepartOptionsCache, '-- Pilih Sparepart --', 'id', function (item) {
            return item.code + ' — ' + item.name;
        });
        select.addEventListener('change', function () {
            const selected = this.selectedOptions[0];
            const availability = wrapper.querySelector('.sparepart-availability');
            const unitPrice = wrapper.querySelector('.sparepart-unit-price');
            if (this.value && selected.dataset.item) {
                const item = JSON.parse(selected.dataset.item);
                unitPrice.value = item.selling_price;
                availability.textContent = 'Stok tersedia: ' + item.available_qty;
            } else {
                availability.textContent = '';
            }
        });
        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('sparepartLines').appendChild(wrapper);
    }

    document.getElementById('addServiceLine').addEventListener('click', addServiceLine);
    document.getElementById('addSparepartLine').addEventListener('click', addSparepartLine);

    window.WorkOrderLineItems = {
        setSparepartOptions: function (items) {
            sparepartOptionsCache = items;
            document.querySelectorAll('.sparepart-select').forEach(function (select) {
                const currentValue = select.value;
                fillSelect(select, items, '-- Pilih Sparepart --', 'id', function (item) {
                    return item.code + ' — ' + item.name;
                });
                select.value = currentValue;
            });
        },
        addServiceLine: addServiceLine,
        addSparepartLine: addSparepartLine,
        fetchJson: fetchJson,
        fillSelect: fillSelect,
    };
})();
</script>
@endpush
