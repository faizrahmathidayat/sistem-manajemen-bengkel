<template id="invoiceServiceLineTemplate">
    <div class="row g-2 align-items-start mb-2 service-line">
        <div class="col-md-6 service-item-locked d-none">
            <input type="text" class="form-control-plaintext fw-bold service-locked-description" readonly>
        </div>
        <div class="col-md-6 service-item-free">
            <select class="form-select service-catalog-select">
                <option value="">-- Manual --</option>
                @foreach ($serviceCatalogs as $catalog)
                    <option value="{{ $catalog->id }}" data-price="{{ $catalog->default_price }}" data-name="{{ $catalog->name }}">{{ $catalog->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" min="0.001" class="form-control service-qty" value="1">
        </div>
        <div class="col-md-1">
            <input type="number" step="0.01" min="0" class="form-control service-unit-price">
        </div>
        <div class="col-md-1">
            <input type="number" step="0.01" min="0" max="100" class="form-control service-discount-percent" value="0">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-line">&times;</button>
        </div>
    </div>
</template>

<template id="invoiceSparepartLineTemplate">
    <div class="row g-2 align-items-start mb-2 sparepart-line">
        <div class="col-md-4 sparepart-item-locked d-none">
            <input type="text" class="form-control-plaintext fw-bold sparepart-locked-label" readonly>
        </div>
        <div class="col-md-4 sparepart-item-free">
            <select class="form-select sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" min="0.001" class="form-control sparepart-qty" value="1">
        </div>
        <div class="col-md-1">
            <input type="number" step="0.01" min="0" class="form-control sparepart-unit-price">
        </div>
        <div class="col-md-1">
            <input type="number" step="0.01" min="0" max="100" class="form-control sparepart-discount-percent" value="0">
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

    function addServiceLine(locked) {
        const template = document.getElementById('invoiceServiceLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.service-line');
        const index = serviceLineCount++;

        const hiddenId = document.createElement('input');
        hiddenId.type = 'hidden';
        hiddenId.className = 'service-wo-line-id';
        hiddenId.name = `services[${index}][work_order_service_line_id]`;
        wrapper.appendChild(hiddenId);

        wrapper.querySelector('.service-qty').name = `services[${index}][qty]`;
        wrapper.querySelector('.service-unit-price').name = `services[${index}][unit_price]`;
        wrapper.querySelector('.service-discount-percent').name = `services[${index}][discount_percent]`;

        if (locked) {
            wrapper.querySelector('.service-item-locked').classList.remove('d-none');
            wrapper.querySelector('.service-item-free').classList.add('d-none');
            wrapper.querySelector('.service-locked-description').name = `services[${index}][description]`;
        } else {
            const hiddenDescription = document.createElement('input');
            hiddenDescription.type = 'hidden';
            hiddenDescription.className = 'service-description';
            hiddenDescription.name = `services[${index}][description]`;
            wrapper.appendChild(hiddenDescription);

            wrapper.querySelector('.service-catalog-select').addEventListener('change', function () {
                const selected = this.selectedOptions[0];
                hiddenDescription.value = this.value ? (selected.dataset.name || '') : '';
                if (this.value) {
                    wrapper.querySelector('.service-unit-price').value = selected.dataset.price || 0;
                }
            });
        }

        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            wrapper.remove();
        });
        wrapper.classList.add('line-row-enter');
        document.getElementById('invoiceServiceLines').appendChild(wrapper);
        return wrapper;
    }

    function addSparepartLine(branchId, locked) {
        const template = document.getElementById('invoiceSparepartLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.sparepart-line');
        const index = sparepartLineCount++;

        const hiddenWoLineId = document.createElement('input');
        hiddenWoLineId.type = 'hidden';
        hiddenWoLineId.className = 'sparepart-wo-line-id';
        hiddenWoLineId.name = `spareparts[${index}][work_order_sparepart_line_id]`;
        wrapper.appendChild(hiddenWoLineId);

        wrapper.querySelector('.sparepart-qty').name = `spareparts[${index}][qty]`;
        wrapper.querySelector('.sparepart-unit-price').name = `spareparts[${index}][unit_price]`;
        wrapper.querySelector('.sparepart-discount-percent').name = `spareparts[${index}][discount_percent]`;

        const select = wrapper.querySelector('.sparepart-select');

        if (locked) {
            wrapper.querySelector('.sparepart-item-locked').classList.remove('d-none');
            wrapper.querySelector('.sparepart-item-free').classList.add('d-none');

            const hiddenBranchId = document.createElement('input');
            hiddenBranchId.type = 'hidden';
            hiddenBranchId.className = 'sparepart-locked-branch-id';
            hiddenBranchId.name = `spareparts[${index}][sparepart_branch_id]`;
            wrapper.appendChild(hiddenBranchId);
        } else {
            select.name = `spareparts[${index}][sparepart_branch_id]`;
            initAjaxSelect(select, {
                endpoint: '{{ route('lookup.spareparts') }}',
                extraParams: function () { return { branch_id: branchId }; },
                placeholder: '-- Pilih Sparepart --',
                onSelect: function (item) {
                    wrapper.querySelector('.sparepart-unit-price').value = item.selling_price;
                },
            });
        }

        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            if ($(select).data('select2')) $(select).select2('destroy');
            wrapper.remove();
        });
        wrapper.classList.add('line-row-enter');
        document.getElementById('invoiceSparepartLines').appendChild(wrapper);
        return wrapper;
    }

    async function preselectFreeFormSparepartLine(row, sparepartBranchId, branchId) {
        const select = row.querySelector('.sparepart-select');
        const item = await preselectAjaxOption(select, {
            endpoint: '{{ route('lookup.spareparts') }}',
            id: sparepartBranchId,
            extraParams: function () { return { branch_id: branchId }; },
        });
        if (item && !row.querySelector('.sparepart-unit-price').value) {
            row.querySelector('.sparepart-unit-price').value = item.selling_price;
        }
        $(select).trigger('change');
    }

    document.getElementById('addInvoiceServiceLine').addEventListener('click', function () {
        addServiceLine(false);
    });
    document.getElementById('addInvoiceSparepartLine').addEventListener('click', function () {
        addSparepartLine(window.currentInvoiceBranchId || null, false);
    });

    window.InvoiceLineItems = {
        addServiceLine: addServiceLine,
        addSparepartLine: addSparepartLine,
        preselectFreeFormSparepartLine: preselectFreeFormSparepartLine,
    };
})();
</script>
@endpush
