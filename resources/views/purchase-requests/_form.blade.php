@csrf
@isset($method)
    @method($method)
@endisset

@php
    $isEdit = isset($purchaseRequest);
    $initialLines = old('lines', $existingLines ?? []);
    $initialBranchId = $isEdit ? $purchaseRequest->branch_id : (old('branch_id') ?: ($branches->count() === 1 ? $branches->first()->id : null));
@endphp

<div class="panel mb-3">
    <div class="panel-header">
        <h2 class="h5 mb-1 section-title"><i class="bi bi-info-circle"></i><span>Informasi Permintaan</span></h2>
    </div>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Cabang</label>
            @if ($isEdit)
                <input type="text" class="form-control" value="{{ $purchaseRequest->branch->name }}" disabled>
            @else
                <select name="branch_id" id="prBranchSelect" class="form-select @error('branch_id') is-invalid @enderror" required>
                    <option value="">-- Pilih Cabang --</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" {{ (int) $initialBranchId === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            @endif
        </div>
        <div class="col-md-3">
            <label class="form-label">Tanggal Permintaan</label>
            <input type="date" name="request_date" value="{{ old('request_date', $isEdit ? $purchaseRequest->request_date->format('Y-m-d') : now()->format('Y-m-d')) }}" class="form-control @error('request_date') is-invalid @enderror" required>
            @error('request_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label">Catatan / Alasan Kebutuhan</label>
            <input type="text" name="notes" value="{{ old('notes', $isEdit ? $purchaseRequest->notes : '') }}" class="form-control @error('notes') is-invalid @enderror" maxlength="1000">
            @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>

<div class="panel mb-3">
    <div class="panel-header d-flex justify-content-between align-items-start">
        <div>
            <h2 class="h5 mb-1 section-title"><i class="bi bi-nut"></i><span>Barang yang Diminta</span></h2>
            <p class="text-muted mb-0 small">Supplier dan harga ditentukan oleh Kepala Gudang pada tahap berikutnya.</p>
            <p class="text-muted mb-0 small"><i class="bi bi-info-circle"></i> Hanya barang dengan stok di bawah stok minimal yang dapat diminta (stok tersedia = stok fisik dikurangi yang sudah dipesan PKB).</p>
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm" id="addPrLine" disabled>+ Tambah Sparepart</button>
    </div>
    <div class="row g-2 small text-muted mb-1 fw-bold">
        <div class="col-md-6">Sparepart</div>
        <div class="col-md-2">Stok Tersedia / Minimal</div>
        <div class="col-md-2">Qty Diminta</div>
        <div class="col-md-2"></div>
    </div>
    <div id="prLinesLoading" class="small text-muted d-none mb-2">
        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Memuat sparepart...
    </div>
    <div id="prLines"></div>
    @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    @foreach ($errors->get('lines.*') as $messages)
        @foreach ($messages as $message)
            <div class="text-danger small mt-1">{{ $message }}</div>
        @endforeach
    @endforeach
</div>

<div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
    <a href="{{ $isEdit ? route('purchase-requests.show', $purchaseRequest) : route('purchase-requests.index') }}" class="btn btn-outline-secondary">Batal</a>
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan Permintaan</button>
</div>

<template id="prLineTemplate">
    <div class="row g-2 align-items-center mb-2 pr-line">
        <div class="col-md-6">
            <select class="form-select pr-sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
        </div>
        <div class="col-md-2 small text-muted pr-stock">—</div>
        <div class="col-md-2">
            <input type="number" data-integer step="1" min="1" required class="form-control pr-qty" value="1">
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-outline-danger btn-sm pr-remove" aria-label="Hapus baris">&times;</button>
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
    const endpoint = @json(route('purchase-requests.lookup.spareparts'));
    const initialLines = @json($initialLines);
    const initialBranchId = @json($initialBranchId);
    const branchSelect = document.getElementById('prBranchSelect');
    const addButton = document.getElementById('addPrLine');
    const linesEl = document.getElementById('prLines');
    const loadingEl = document.getElementById('prLinesLoading');
    let currentBranchId = initialBranchId ? String(initialBranchId) : null;
    let lineCount = 0;

    function formatStock(item) {
        return Number(item.available_qty).toLocaleString('id-ID') + ' / ' + Number(item.minimum_stock).toLocaleString('id-ID');
    }

    function clearLines() {
        linesEl.querySelectorAll('.pr-sparepart-select').forEach(function (select) {
            if ($(select).data('select2')) $(select).select2('destroy');
        });
        linesEl.innerHTML = '';
    }

    function addLine() {
        const clone = document.getElementById('prLineTemplate').content.cloneNode(true);
        const row = clone.querySelector('.pr-line');
        const index = lineCount++;
        const select = row.querySelector('.pr-sparepart-select');
        const stock = row.querySelector('.pr-stock');
        select.name = 'lines[' + index + '][sparepart_branch_id]';
        row.querySelector('.pr-qty').name = 'lines[' + index + '][qty]';
        row.querySelector('.pr-remove').addEventListener('click', function () {
            if ($(select).data('select2')) $(select).select2('destroy');
            row.remove();
        });
        linesEl.appendChild(row);

        initAjaxSelect(select, {
            endpoint: endpoint,
            extraParams: function () { return { branch_id: currentBranchId }; },
            placeholder: '-- Pilih Sparepart --',
            onSelect: function (item) { stock.textContent = formatStock(item); },
        });
        $(select).on('select2:clear', function () { stock.textContent = '—'; });

        return row;
    }

    async function replayLines(lines) {
        if (!currentBranchId || !lines.length) return;
        loadingEl.classList.remove('d-none');
        try {
            for (const line of lines) {
                const row = addLine();
                row.querySelector('.pr-qty').value = line.qty;
                const item = await preselectAjaxOption(row.querySelector('.pr-sparepart-select'), {
                    endpoint: endpoint,
                    id: line.sparepart_branch_id,
                    extraParams: function () { return { branch_id: currentBranchId }; },
                });
                if (item) row.querySelector('.pr-stock').textContent = formatStock(item);
            }
        } finally {
            loadingEl.classList.add('d-none');
        }
    }

    function applyBranch(branchId) {
        currentBranchId = branchId || null;
        addButton.disabled = !currentBranchId;
    }

    addButton.addEventListener('click', function () { addLine(); });

    if (branchSelect) {
        branchSelect.addEventListener('change', function () {
            clearLines();
            applyBranch(this.value);
        });
    }

    applyBranch(currentBranchId);
    replayLines(initialLines);
})();
</script>
@endpush
