@extends('layouts.app')
@section('title', 'Stock Adjustment Baru')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-sliders me-2"></i>Stock Adjustment Baru</h1>
    </div>
    <form method="POST" action="{{ route('stock-adjustments.store') }}" id="stockAdjustmentForm">
        @csrf
        <div class="card mb-3">
            <div class="card-body">
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
                        <label class="form-label">Tanggal Penyesuaian</label>
                        <input type="date" name="adjustment_date" value="{{ old('adjustment_date', now()->format('Y-m-d')) }}" class="form-control @error('adjustment_date') is-invalid @enderror" required>
                        @error('adjustment_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Alasan</label>
                        <input type="text" name="reason" value="{{ old('reason') }}" class="form-control @error('reason') is-invalid @enderror" maxlength="255" required>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes') }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Penyesuaian</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addStockAdjustmentLine" disabled>+ Tambah Sparepart</button>
                </div>
                <div class="row g-2 mb-1 text-muted small">
                    <div class="col-md-4">Sparepart</div>
                    <div class="col-md-2">Qty Sistem</div>
                    <div class="col-md-2">Qty Fisik</div>
                    <div class="col-md-3">Alasan Baris</div>
                </div>
                <div id="stockAdjustmentLines"></div>
                @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('stock-adjustments.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('stock-adjustments._line_item_scripts')

    @php($oldLines = old('lines', []))
    @push('scripts')
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const addButton = document.getElementById('addStockAdjustmentLine');

        async function handleBranchChange(branchId) {
            addButton.disabled = true;
            if (!branchId) {
                return;
            }
            const spareparts = await StockAdjustmentLineItems.fetchJson(`/stock-adjustments/lookup/spareparts/${branchId}`);
            StockAdjustmentLineItems.setSparepartOptions(spareparts);
            addButton.disabled = false;
        }

        branchSelect.addEventListener('change', function () {
            handleBranchChange(this.value);
        });

        // Validation-error round-trip: replay the line rows submitted before the
        // failed validation. These rows only exist in JS-managed DOM state (added
        // via <template> cloning), so without this the user would have to retype
        // every line from scratch after any validation error. Built in from the
        // start here — this exact gap was an Important finding in the sibling
        // Goods Receipt module's final review.
        function replayOldLines() {
            const oldLines = @json($oldLines);
            oldLines.forEach(function (line) {
                StockAdjustmentLineItems.addLine();
                const rows = document.querySelectorAll('#stockAdjustmentLines .stock-adjustment-line');
                const row = rows[rows.length - 1];
                if (line.sparepart_branch_id) {
                    const select = row.querySelector('.stock-adjustment-sparepart-select');
                    select.value = line.sparepart_branch_id;
                    select.dispatchEvent(new Event('change'));
                }
                row.querySelector('.stock-adjustment-physical-qty').value = line.physical_qty || '';
                row.querySelector('.stock-adjustment-reason').value = line.reason || '';
            });
        }

        // Validation-error round-trip: old('branch_id') re-selects the branch
        // option but does not fire a native `change` event, so the sparepart
        // cascade and add-line button would otherwise stay empty/disabled.
        // handleBranchChange is async, so replayOldLines is chained onto its
        // promise rather than called eagerly — replayed rows need the sparepart
        // options to already be populated before they can select a value.
        if (branchSelect.value) {
            handleBranchChange(branchSelect.value).then(replayOldLines);
        } else {
            replayOldLines();
        }
    })();
    </script>
    @endpush
@endsection
