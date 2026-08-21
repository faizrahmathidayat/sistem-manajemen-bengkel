@extends('layouts.app')
@section('title', 'Import Jasa Service')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-file-earmark-arrow-up"></i></span>
            <div>
                <p class="eyebrow mb-1">Master Data</p>
                <h1 class="h3 mb-1">Import Jasa Service</h1>
                <p class="text-muted mb-0">Tambahkan banyak jasa service baru sekaligus.</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('service-catalogs.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
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

    <form method="POST" action="{{ route('service-catalogs.import-store') }}" id="serviceImportForm">
        @csrf
        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-tools"></i><span>Baris Jasa Service</span></h2>
                    <p class="text-muted mb-0 small">Maksimal 100 baris per import.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('service-catalogs.import-template') }}" download class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-download"></i> Download Template
                    </a>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="importServiceButton">
                        <span class="spinner-border spinner-border-sm d-none" id="importServiceSpinner" role="status" aria-hidden="true"></span>
                        <i class="bi bi-upload" id="importServiceIcon"></i> Import Baris
                    </button>
                    <input type="file" id="importServiceFile" accept=".xlsx,.xls" class="d-none">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addServiceImportLine">+ Tambah Baris</button>
                </div>
            </div>
            <div class="alert alert-danger d-none" id="importServiceErrors"></div>
            <div class="row g-2 small text-muted mb-1">
                <div class="col-md-4">Kode</div>
                <div class="col-md-5">Nama</div>
                <div class="col-md-2">Harga Default</div>
                <div class="col-md-1"></div>
            </div>
            <div id="serviceImportLines"></div>
            @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>

        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('service-catalogs.index') }}" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan Semua</button>
        </div>
    </form>

    @include('service-catalogs._import_line_item_scripts')

    @php($oldLines = old('lines', []))
    @push('scripts')
    <script>
    (function () {
        const importButton = document.getElementById('importServiceButton');
        const importFile = document.getElementById('importServiceFile');
        const importSpinner = document.getElementById('importServiceSpinner');
        const importIcon = document.getElementById('importServiceIcon');
        const importErrors = document.getElementById('importServiceErrors');

        function showErrors(messages) {
            importErrors.innerHTML = '<ul class="mb-0">' + messages.map(function (m) { return '<li>' + m + '</li>'; }).join('') + '</ul>';
            importErrors.classList.remove('d-none');
        }

        importButton.addEventListener('click', function () {
            importFile.click();
        });

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
                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                const formData = new FormData();
                formData.append('file', file);

                const response = await fetch(@json(route('service-catalogs.import-lines')), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                    body: formData,
                });
                const data = await response.json();

                if (!response.ok) {
                    const messages = data.errors && Array.isArray(data.errors) ? data.errors : [data.message || 'Import gagal.'];
                    showErrors(messages);
                    return;
                }

                data.lines.forEach(function (line) {
                    const row = ServiceImportLineItems.addLine();
                    row.querySelector('.service-import-code').value = line.code;
                    row.querySelector('.service-import-name').value = line.name;
                    row.querySelector('.service-import-price').value = line.default_price;
                });
            } catch (error) {
                showErrors(['Gagal menghubungi server. Silakan coba lagi.']);
            } finally {
                importButton.disabled = false;
                importIcon.classList.remove('d-none');
                importSpinner.classList.add('d-none');
            }
        });

        const oldLines = @json($oldLines);
        oldLines.forEach(function (line) {
            const row = ServiceImportLineItems.addLine();
            row.querySelector('.service-import-code').value = line.code || '';
            row.querySelector('.service-import-name').value = line.name || '';
            row.querySelector('.service-import-price').value = line.default_price || '';
        });
    })();
    </script>
    @endpush
@endsection
