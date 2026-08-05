<template id="invoiceServiceLineTemplate">
    <div class="row g-2 align-items-start mb-2 service-line">
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

<template id="invoiceSparepartLineTemplate">
    <div class="row g-2 align-items-start mb-2 sparepart-line">
        <div class="col-md-5 sparepart-item-locked d-none">
            <input type="text" class="form-control-plaintext fw-bold sparepart-locked-label" readonly>
        </div>
        <div class="col-md-5 sparepart-item-free">
            <select class="form-select sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
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

        const description = wrapper.querySelector('.service-description');
        description.name = `services[${index}][description]`;
        wrapper.querySelector('.service-qty').name = `services[${index}][qty]`;
        wrapper.querySelector('.service-unit-price').name = `services[${index}][unit_price]`;

        if (locked) {
            description.readOnly = true;
            description.classList.add('bg-light');
        }

        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            wrapper.remove();
        });
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
