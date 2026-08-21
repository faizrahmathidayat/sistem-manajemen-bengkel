<template id="sparepartImportLineTemplate">
    <div class="row g-2 align-items-start mb-2 sparepart-import-line">
        <div class="col-md-3">
            <input type="text" required maxlength="30" class="form-control sparepart-import-code" placeholder="Kode">
        </div>
        <div class="col-md-3">
            <input type="text" required maxlength="150" class="form-control sparepart-import-name" placeholder="Nama Sparepart">
        </div>
        <div class="col-md-2">
            <select class="form-select sparepart-import-rack">
                <option value="">-- Tanpa Rak --</option>
                @foreach ($racks as $rack)
                    <option value="{{ $rack->id }}">{{ $rack->code }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" min="0" required class="form-control sparepart-import-price" placeholder="Harga Jual">
        </div>
        <div class="col-md-1">
            <input type="number" step="0.001" min="0" class="form-control sparepart-import-stock" placeholder="Stok Min." value="0">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-sparepart-import-line">&times;</button>
        </div>
    </div>
</template>

@push('scripts')
<script>
(function () {
    let lineCount = 0;

    function addLine() {
        const template = document.getElementById('sparepartImportLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.sparepart-import-line');
        const index = lineCount++;
        wrapper.querySelector('.sparepart-import-code').name = `lines[${index}][code]`;
        wrapper.querySelector('.sparepart-import-name').name = `lines[${index}][name]`;
        wrapper.querySelector('.sparepart-import-rack').name = `lines[${index}][rack_id]`;
        wrapper.querySelector('.sparepart-import-price').name = `lines[${index}][selling_price]`;
        wrapper.querySelector('.sparepart-import-stock').name = `lines[${index}][minimum_stock]`;
        wrapper.querySelector('.remove-sparepart-import-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('sparepartImportLines').appendChild(wrapper);

        return wrapper;
    }

    document.getElementById('addSparepartImportLine').addEventListener('click', function () {
        addLine();
    });

    window.SparepartImportLineItems = {
        addLine: addLine,
    };
})();
</script>
@endpush
