@extends('layouts.app')
@section('title', 'Penerimaan Barang Baru')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-truck"></i></span>
            <div>
                <p class="eyebrow mb-1">Penerimaan Barang</p>
                <h1 class="h3 mb-1">Penerimaan Barang Baru</h1>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('goods-receipts.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('goods-receipts.store') }}" id="goodsReceiptForm">
        @csrf
        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-info-circle"></i><span>Informasi Penerimaan</span></h2>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Cabang</label>
                    <select name="branch_id" id="branchSelect" class="form-select @error('branch_id') is-invalid @enderror" required>
                        <option value="">-- Pilih Cabang --</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" {{ (int) old('branch_id') === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tanggal Penerimaan</label>
                    <input type="date" name="receipt_date" value="{{ old('receipt_date', now()->format('Y-m-d')) }}" class="form-control @error('receipt_date') is-invalid @enderror" required>
                    @error('receipt_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">No. Referensi</label>
                    <input type="text" name="reference_number" value="{{ old('reference_number') }}" class="form-control @error('reference_number') is-invalid @enderror" maxlength="100">
                    @error('reference_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-12">
                    <label class="form-label">Catatan</label>
                    <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes') }}</textarea>
                    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-nut"></i><span>Baris Sparepart</span></h2>
                    <p class="text-muted mb-0 small">Maksimal 100 baris per import.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('goods-receipts.import-template') }}" download class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-download"></i> Download Template
                    </a>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="importGoodsReceiptButton" disabled>
                        <span class="spinner-border spinner-border-sm d-none" id="importGoodsReceiptSpinner" role="status" aria-hidden="true"></span>
                        <i class="bi bi-upload" id="importGoodsReceiptIcon"></i> Import Baris
                    </button>
                    <input type="file" id="importGoodsReceiptFile" accept=".xlsx,.xls" class="d-none">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addGoodsReceiptLine" disabled>+ Tambah Sparepart</button>
                </div>
            </div>
            <div class="alert alert-danger d-none" id="importGoodsReceiptErrors"></div>
            <div class="row g-2 small text-muted mb-1">
                <div class="col-md-5">Sparepart</div>
                <div class="col-md-3">Qty</div>
                <div class="col-md-3">Harga Satuan</div>
                <div class="col-md-1"></div>
            </div>
            <div id="goodsReceiptLines"></div>
            @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>

        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('goods-receipts.index') }}" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </div>
    </form>

    @include('goods-receipts._line_item_scripts')

    @php($oldLines = old('lines', []))
    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    @endpush

    @push('scripts')
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const addButton = document.getElementById('addGoodsReceiptLine');
        const importButton = document.getElementById('importGoodsReceiptButton');
        const importFile = document.getElementById('importGoodsReceiptFile');
        const importSpinner = document.getElementById('importGoodsReceiptSpinner');
        const importIcon = document.getElementById('importGoodsReceiptIcon');
        const importErrors = document.getElementById('importGoodsReceiptErrors');
        let currentBranchId = branchSelect.value || null;
        window.currentGoodsReceiptBranchId = currentBranchId;

        function handleBranchChange(branchId) {
            currentBranchId = branchId || null;
            window.currentGoodsReceiptBranchId = currentBranchId;
            addButton.disabled = !currentBranchId;
            importButton.disabled = !currentBranchId;
        }

        branchSelect.addEventListener('change', function () {
            handleBranchChange(this.value);
        });

        importButton.addEventListener('click', function () {
            importFile.click();
        });

        importFile.addEventListener('change', async function () {
            const file = importFile.files[0];
            importFile.value = '';
            if (!file || !currentBranchId) return;

            importErrors.classList.add('d-none');
            importErrors.innerHTML = '';
            importButton.disabled = true;
            importIcon.classList.add('d-none');
            importSpinner.classList.remove('d-none');

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                const formData = new FormData();
                formData.append('branch_id', currentBranchId);
                formData.append('file', file);

                const response = await fetch(@json(route('goods-receipts.import-lines')), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                    body: formData,
                });
                const data = await response.json();

                if (!response.ok) {
                    const messages = data.errors && Array.isArray(data.errors) ? data.errors : [data.message || 'Import gagal.'];
                    importErrors.innerHTML = '<ul class="mb-0">' + messages.map(function (m) { return '<li>' + m + '</li>'; }).join('') + '</ul>';
                    importErrors.classList.remove('d-none');
                    return;
                }

                for (const line of data.lines) {
                    const row = GoodsReceiptLineItems.addLine(currentBranchId);
                    row.querySelector('.goods-receipt-qty').value = line.qty;
                    row.querySelector('.goods-receipt-purchase-price').value = line.purchase_price;
                    await GoodsReceiptLineItems.preselectLine(row, line.sparepart_branch_id, currentBranchId);
                }
            } catch (error) {
                importErrors.innerHTML = '<ul class="mb-0"><li>Gagal menghubungi server. Silakan coba lagi.</li></ul>';
                importErrors.classList.remove('d-none');
            } finally {
                importButton.disabled = !currentBranchId;
                importIcon.classList.remove('d-none');
                importSpinner.classList.add('d-none');
            }
        });

        // Validation-error round-trip: replay the sparepart line rows submitted
        // before the failed validation. These rows only exist in JS-managed DOM
        // state (added via <template> cloning), so without this the user would
        // have to retype every line from scratch after any validation error.
        async function replayOldLines() {
            const oldLines = @json($oldLines);
            for (const line of oldLines) {
                const row = GoodsReceiptLineItems.addLine(currentBranchId);
                row.querySelector('.goods-receipt-qty').value = line.qty || '';
                row.querySelector('.goods-receipt-purchase-price').value = line.purchase_price || '';
                if (line.sparepart_branch_id) {
                    await GoodsReceiptLineItems.preselectLine(row, line.sparepart_branch_id, currentBranchId);
                }
            }
        }

        handleBranchChange(branchSelect.value);
        replayOldLines();
    })();
    </script>
    @endpush
@endsection
