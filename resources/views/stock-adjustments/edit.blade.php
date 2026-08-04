@extends('layouts.app')
@section('title', 'Ubah Stock Adjustment')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-sliders me-2"></i>Ubah {{ $stockAdjustment->number }} — {{ $stockAdjustment->branch->name }}</h1>
    </div>
    <form method="POST" action="{{ route('stock-adjustments.update', $stockAdjustment) }}" id="stockAdjustmentForm">
        @csrf
        @method('PUT')
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Tanggal Penyesuaian</label>
                        <input type="date" name="adjustment_date" value="{{ old('adjustment_date', $stockAdjustment->adjustment_date->format('Y-m-d')) }}" class="form-control @error('adjustment_date') is-invalid @enderror" required>
                        @error('adjustment_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Alasan</label>
                        <input type="text" name="reason" value="{{ old('reason', $stockAdjustment->reason) }}" class="form-control @error('reason') is-invalid @enderror" maxlength="255" required>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes', $stockAdjustment->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Penyesuaian</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addStockAdjustmentLine">+ Tambah Sparepart</button>
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
        <a href="{{ route('stock-adjustments.show', $stockAdjustment) }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('stock-adjustments._line_item_scripts')

    @push('scripts')
    <script>
    (function () {
        const existingSparepartOptions = @json($sparepartOptions);
        StockAdjustmentLineItems.setSparepartOptions(existingSparepartOptions);

        const existingLines = @json($existingLines);
        existingLines.forEach(function (line) {
            StockAdjustmentLineItems.addLine();
            const rows = document.querySelectorAll('#stockAdjustmentLines .stock-adjustment-line');
            const row = rows[rows.length - 1];
            const select = row.querySelector('.stock-adjustment-sparepart-select');
            select.value = line.sparepart_branch_id;
            select.dispatchEvent(new Event('change'));
            row.querySelector('.stock-adjustment-physical-qty').value = line.physical_qty;
            row.querySelector('.stock-adjustment-reason').value = line.reason;
        });
    })();
    </script>
    @endpush
@endsection
