<template id="stockAdjustmentLineTemplate">
    <div class="row g-2 align-items-start mb-2 stock-adjustment-line">
        <div class="col-md-4">
            <select class="form-select stock-adjustment-sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" step="1" class="form-control stock-adjustment-system-qty" readonly tabindex="-1">
        </div>
        <div class="col-md-2">
            <input type="number" step="1" min="0" required class="form-control stock-adjustment-physical-qty">
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

    // Excel import: server validates/parses the file and returns the lines as JSON;
    // nothing is persisted until the form itself is saved. A sparepart that is already
    // in the form (the form requires distinct spareparts) has its qty/reason updated
    // instead of getting a duplicate row.
    function findRowBySparepartBranchId(sparepartBranchId) {
        return Array.from(document.querySelectorAll('.stock-adjustment-line')).find(function (row) {
            return String($(row.querySelector('.stock-adjustment-sparepart-select')).val() || '') === String(sparepartBranchId);
        });
    }

    async function applyImportedLine(line, branchId) {
        let row = findRowBySparepartBranchId(line.sparepart_branch_id);
        const isNew = !row;
        if (isNew) row = addLine(branchId);
        row.querySelector('.stock-adjustment-physical-qty').value = line.physical_qty;
        row.querySelector('.stock-adjustment-reason').value = line.reason;
        if (isNew) await preselectLine(row, line.sparepart_branch_id, branchId);
    }

    const importButton = document.getElementById('importStockAdjustmentButton');
    const importFile = document.getElementById('importStockAdjustmentFile');
    const importSpinner = document.getElementById('importStockAdjustmentSpinner');
    const importIcon = document.getElementById('importStockAdjustmentIcon');
    const importErrors = document.getElementById('importStockAdjustmentErrors');

    function showImportErrors(messages) {
        importErrors.innerHTML = '';
        const list = document.createElement('ul');
        list.className = 'mb-0';
        messages.forEach(function (message) {
            const item = document.createElement('li');
            item.textContent = message;
            list.appendChild(item);
        });
        importErrors.appendChild(list);
        importErrors.classList.remove('d-none');
    }

    importButton.addEventListener('click', function () {
        importFile.click();
    });

    importFile.addEventListener('change', async function () {
        const file = importFile.files[0];
        importFile.value = '';
        const branchId = window.currentStockAdjustmentBranchId;
        if (!file || !branchId) return;

        importErrors.classList.add('d-none');
        importButton.disabled = true;
        importIcon.classList.add('d-none');
        importSpinner.classList.remove('d-none');

        try {
            const formData = new FormData();
            formData.append('branch_id', branchId);
            formData.append('file', file);

            const response = await fetch(@json(route('stock-adjustments.import-lines')), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                body: formData,
            });
            const data = await response.json();

            if (!response.ok) {
                const messages = data.errors && Array.isArray(data.errors) ? data.errors : [data.message || 'Import gagal.'];
                showImportErrors(messages);
                return;
            }

            for (const line of data.lines) {
                await applyImportedLine(line, branchId);
            }
        } catch (error) {
            showImportErrors(['Gagal menghubungi server. Silakan coba lagi.']);
        } finally {
            importButton.disabled = !window.currentStockAdjustmentBranchId;
            importIcon.classList.remove('d-none');
            importSpinner.classList.add('d-none');
        }
    });

    window.StockAdjustmentLineItems = {
        addLine: addLine,
        preselectLine: preselectLine,
    };
})();
</script>
@endpush
