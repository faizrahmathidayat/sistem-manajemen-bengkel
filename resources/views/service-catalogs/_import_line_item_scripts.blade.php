<template id="serviceImportLineTemplate">
    <div class="row g-2 align-items-start mb-2 service-import-line">
        <div class="col-md-4">
            <input type="text" required maxlength="30" class="form-control service-import-code" placeholder="Kode">
        </div>
        <div class="col-md-5">
            <input type="text" required maxlength="150" class="form-control service-import-name" placeholder="Nama Jasa">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" min="0" required class="form-control service-import-price" placeholder="Harga Default">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-service-import-line">&times;</button>
        </div>
    </div>
</template>

@push('scripts')
<script>
(function () {
    let lineCount = 0;

    function addLine() {
        const template = document.getElementById('serviceImportLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.service-import-line');
        const index = lineCount++;
        wrapper.querySelector('.service-import-code').name = `lines[${index}][code]`;
        wrapper.querySelector('.service-import-name').name = `lines[${index}][name]`;
        wrapper.querySelector('.service-import-price').name = `lines[${index}][default_price]`;
        wrapper.querySelector('.remove-service-import-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('serviceImportLines').appendChild(wrapper);

        return wrapper;
    }

    document.getElementById('addServiceImportLine').addEventListener('click', function () {
        addLine();
    });

    window.ServiceImportLineItems = {
        addLine: addLine,
    };
})();
</script>
@endpush
