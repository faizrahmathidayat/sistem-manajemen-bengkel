@extends('layouts.app')
@section('title', 'Import Master Sparepart')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-file-earmark-arrow-up"></i></span>
            <div>
                <p class="eyebrow mb-1">Sparepart</p>
                <h1 class="h3 mb-1">Import Master Sparepart</h1>
                <p class="text-muted mb-0">Tambahkan banyak sparepart baru sekaligus untuk satu cabang.</p>
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

    <form method="POST" action="{{ route('sparepart-branches.import-store') }}" id="sparepartImportForm">
        @csrf
        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-info-circle"></i><span>Informasi</span></h2>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="branch_id" class="form-label">Cabang</label>
                    <select name="branch_id" id="branch_id" class="form-select @error('branch_id') is-invalid @enderror" required>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" {{ (int) old('branch_id', $selectedBranch->id) === $branch->id ? 'selected' : '' }}>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-box-seam"></i><span>Baris Sparepart</span></h2>
                    <p class="text-muted mb-0 small">Maksimal 100 baris per import.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('sparepart-branches.import-template') }}" download class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-download"></i> Download Template
                    </a>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="importSparepartButton">
                        <span class="spinner-border spinner-border-sm d-none" id="importSparepartSpinner" role="status" aria-hidden="true"></span>
                        <i class="bi bi-upload" id="importSparepartIcon"></i> Import Baris
                    </button>
                    <input type="file" id="importSparepartFile" accept=".xlsx,.xls" class="d-none">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addSparepartImportLine">+ Tambah Baris</button>
                </div>
            </div>
            <div class="alert alert-danger d-none" id="importSparepartErrors"></div>
            <div class="row g-2 small text-muted mb-1">
                <div class="col-md-3">Kode</div>
                <div class="col-md-3">Nama</div>
                <div class="col-md-2">Rak</div>
                <div class="col-md-2">Harga Jual</div>
                <div class="col-md-1">Stok Min.</div>
                <div class="col-md-1"></div>
            </div>
            <div id="sparepartImportLines"></div>
            @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>

        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan Semua</button>
        </div>
    </form>

    @include('sparepart-branches._import_line_item_scripts')

    @php($oldLines = old('lines', []))
    @push('scripts')
    <script>
    (function () {
        const branchSelect = document.getElementById('branch_id');
        const importButton = document.getElementById('importSparepartButton');
        const importFile = document.getElementById('importSparepartFile');
        const importSpinner = document.getElementById('importSparepartSpinner');
        const importIcon = document.getElementById('importSparepartIcon');
        const importErrors = document.getElementById('importSparepartErrors');

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
                formData.append('branch_id', branchSelect.value);
                formData.append('file', file);

                const response = await fetch(@json(route('sparepart-branches.import-lines')), {
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
                    const row = SparepartImportLineItems.addLine();
                    row.querySelector('.sparepart-import-code').value = line.code;
                    row.querySelector('.sparepart-import-name').value = line.name;
                    row.querySelector('.sparepart-import-price').value = line.selling_price;
                    row.querySelector('.sparepart-import-stock').value = line.minimum_stock;
                    if (line.rack_id) {
                        row.querySelector('.sparepart-import-rack').value = line.rack_id;
                    }
                });
            } catch (error) {
                showErrors(['Gagal menghubungi server. Silakan coba lagi.']);
            } finally {
                importButton.disabled = false;
                importIcon.classList.remove('d-none');
                importSpinner.classList.add('d-none');
            }
        });

        // Validation-error round-trip: replay the sparepart rows submitted before
        // the failed validation, same reasoning as goods-receipts create — these
        // rows only exist in JS-managed DOM state (added via <template> cloning).
        const oldLines = @json($oldLines);
        oldLines.forEach(function (line) {
            const row = SparepartImportLineItems.addLine();
            row.querySelector('.sparepart-import-code').value = line.code || '';
            row.querySelector('.sparepart-import-name').value = line.name || '';
            row.querySelector('.sparepart-import-price').value = line.selling_price || '';
            row.querySelector('.sparepart-import-stock').value = line.minimum_stock || '0';
            if (line.rack_id) {
                row.querySelector('.sparepart-import-rack').value = line.rack_id;
            }
        });
    })();
    </script>
    @endpush
@endsection
