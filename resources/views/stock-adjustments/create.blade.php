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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    @endpush

    @push('scripts')
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const addButton = document.getElementById('addStockAdjustmentLine');
        let currentBranchId = branchSelect.value || null;
        window.currentStockAdjustmentBranchId = currentBranchId;

        function handleBranchChange(branchId) {
            currentBranchId = branchId || null;
            window.currentStockAdjustmentBranchId = currentBranchId;
            addButton.disabled = !currentBranchId;
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
        async function replayOldLines() {
            const oldLines = @json($oldLines);
            for (const line of oldLines) {
                const row = StockAdjustmentLineItems.addLine(currentBranchId);
                row.querySelector('.stock-adjustment-physical-qty').value = line.physical_qty || '';
                row.querySelector('.stock-adjustment-reason').value = line.reason || '';
                if (line.sparepart_branch_id) {
                    await StockAdjustmentLineItems.preselectLine(row, line.sparepart_branch_id, currentBranchId);
                }
            }
        }

        handleBranchChange(branchSelect.value);
        replayOldLines();
    })();
    </script>
    @endpush
@endsection
