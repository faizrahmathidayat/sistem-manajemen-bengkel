@extends('layouts.app')
@section('title', 'Tambah Sparepart dari Cabang Lain')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-link-45deg"></i></span>
            <div>
                <p class="eyebrow mb-1">Sparepart</p>
                <h1 class="h3 mb-1">Tambah Sparepart ke {{ $branch->name }}</h1>
                <p class="text-muted mb-0">Hubungkan satu atau banyak sparepart yang sudah ada di master ke cabang ini.</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('sparepart-branches.storeExisting') }}" id="sparepartExistingForm">
        @csrf
        <input type="hidden" name="branch_id" value="{{ $branch->id }}">

        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-box-seam"></i><span>Baris Sparepart</span></h2>
                    <p class="text-muted mb-0 small">Cari sparepart dengan mengetik minimal 3 huruf. Maksimal 100 baris per penyimpanan.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('sparepart-branches.existing-import-template') }}" download class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-download"></i> Download Template
                    </a>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="importExistingButton">
                        <span class="spinner-border spinner-border-sm d-none" id="importExistingSpinner" role="status" aria-hidden="true"></span>
                        <i class="bi bi-upload" id="importExistingIcon"></i> Import Baris
                    </button>
                    <input type="file" id="importExistingFile" accept=".xlsx,.xls" class="d-none">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addExistingLine">+ Tambah Baris</button>
                </div>
            </div>
            <div class="alert alert-danger d-none" id="importExistingErrors"></div>
            <div class="row g-2 small text-muted mb-1">
                <div class="col-md-5">Sparepart</div>
                <div class="col-md-2">Rak</div>
                <div class="col-md-2">Harga Jual</div>
                <div class="col-md-2">Stok Min.</div>
                <div class="col-md-1"></div>
            </div>
            <div id="existingLines"></div>
        </div>

        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan Semua</button>
        </div>
    </form>

    <template id="existingLineTemplate">
        <div class="row g-2 align-items-start mb-2 existing-line">
            <div class="col-md-5">
                <select class="form-select existing-sparepart"></select>
            </div>
            <div class="col-md-2">
                <select class="form-select existing-rack">
                    <option value="">-- Tanpa Rak --</option>
                    @foreach ($racks as $rack)
                        <option value="{{ $rack->id }}">{{ $rack->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <input type="number" step="1" min="0" data-integer required class="form-control existing-price" placeholder="Harga Jual">
            </div>
            <div class="col-md-2">
                <input type="number" step="1" min="0" data-integer class="form-control existing-stock" placeholder="Stok Min." value="0">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-outline-danger btn-sm remove-existing-line">&times;</button>
            </div>
        </div>
    </template>

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    <script>
    (function () {
        const branchId = {{ $branch->id }};
        const linesContainer = document.getElementById('existingLines');
        let lineCount = 0;

        function addLine(prefill) {
            const clone = document.getElementById('existingLineTemplate').content.cloneNode(true);
            const wrapper = clone.querySelector('.existing-line');
            const index = lineCount++;
            const select = wrapper.querySelector('.existing-sparepart');
            select.name = `lines[${index}][sparepart_id]`;
            wrapper.querySelector('.existing-rack').name = `lines[${index}][rack_id]`;
            wrapper.querySelector('.existing-price').name = `lines[${index}][selling_price]`;
            wrapper.querySelector('.existing-stock').name = `lines[${index}][minimum_stock]`;
            wrapper.querySelector('.remove-existing-line').addEventListener('click', function () {
                if ($(select).data('select2')) $(select).select2('destroy');
                wrapper.remove();
            });
            linesContainer.appendChild(wrapper);

            if (prefill && prefill.sparepart_id) {
                select.appendChild(new Option(prefill.text, prefill.sparepart_id, true, true));
            }
            initAjaxSelect(select, {
                endpoint: @json(route('sparepart-branches.lookup.unconfigured')),
                extraParams: function () { return { branch_id: branchId }; },
                placeholder: '-- Pilih Sparepart --',
            });

            if (prefill) {
                if (prefill.rack_id) wrapper.querySelector('.existing-rack').value = prefill.rack_id;
                wrapper.querySelector('.existing-price').value = prefill.selling_price ?? '';
                wrapper.querySelector('.existing-stock').value = prefill.minimum_stock ?? 0;
            }

            return wrapper;
        }

        document.getElementById('addExistingLine').addEventListener('click', function () {
            addLine();
        });

        // Excel import
        const importButton = document.getElementById('importExistingButton');
        const importFile = document.getElementById('importExistingFile');
        const importSpinner = document.getElementById('importExistingSpinner');
        const importIcon = document.getElementById('importExistingIcon');
        const importErrors = document.getElementById('importExistingErrors');

        function showErrors(messages) {
            importErrors.innerHTML = '<ul class="mb-0">' + messages.map(function (m) {
                const li = document.createElement('li');
                li.textContent = m;
                return li.outerHTML;
            }).join('') + '</ul>';
            importErrors.classList.remove('d-none');
        }

        importButton.addEventListener('click', function () { importFile.click(); });

        importFile.addEventListener('change', async function () {
            const file = importFile.files[0];
            importFile.value = '';
            if (!file) return;

            importErrors.classList.add('d-none');
            importErrors.innerHTML = '';
            importButton.disabled = true;
            importIcon.classList.add('d-none');
            importSpinner.classList.remove('d-none');

            try {
                const formData = new FormData();
                formData.append('branch_id', branchId);
                formData.append('file', file);

                const response = await fetch(@json(route('sparepart-branches.existing-import-lines')), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                    body: formData,
                });
                const data = await response.json();

                if (!response.ok) {
                    showErrors(data.errors && Array.isArray(data.errors) ? data.errors : [data.message || 'Import gagal.']);
                    return;
                }

                // Drop untouched blank rows so imported rows don't land below an empty one.
                linesContainer.querySelectorAll('.existing-line').forEach(function (row) {
                    const select = row.querySelector('.existing-sparepart');
                    if (!select.value && !row.querySelector('.existing-price').value) {
                        if ($(select).data('select2')) $(select).select2('destroy');
                        row.remove();
                    }
                });

                data.lines.forEach(function (line) {
                    addLine({
                        sparepart_id: line.sparepart_id,
                        text: line.code + ' — ' + line.name,
                        rack_id: line.rack_id,
                        selling_price: line.selling_price,
                        minimum_stock: line.minimum_stock,
                    });
                });
            } catch (error) {
                showErrors(['Gagal menghubungi server. Silakan coba lagi.']);
            } finally {
                importButton.disabled = false;
                importIcon.classList.remove('d-none');
                importSpinner.classList.add('d-none');
            }
        });

        // Validation-error round-trip: these rows only exist in JS-managed DOM state, so
        // replay what was submitted before the failed validation.
        const oldLines = @json(old('lines', []));
        const oldLabels = @json($oldSparepartLabels);
        if (oldLines.length) {
            oldLines.forEach(function (line) {
                addLine({
                    sparepart_id: line.sparepart_id,
                    text: oldLabels[line.sparepart_id] || ('#' + line.sparepart_id),
                    rack_id: line.rack_id,
                    selling_price: line.selling_price,
                    minimum_stock: line.minimum_stock,
                });
            });
        } else {
            addLine();
        }
    })();
    </script>
    @endpush
@endsection
