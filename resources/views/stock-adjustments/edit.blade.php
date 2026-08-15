@extends('layouts.app')
@section('title', 'Ubah Stock Adjustment')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-sliders"></i></span>
            <div>
                <p class="eyebrow mb-1">Stock Adjustment</p>
                <h1 class="h3 mb-1">Ubah {{ $stockAdjustment->number }}</h1>
                <p class="text-muted mb-0">{{ $stockAdjustment->branch->name }}</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('stock-adjustments.show', $stockAdjustment) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('stock-adjustments.update', $stockAdjustment) }}" id="stockAdjustmentForm">
        @csrf
        @method('PUT')
        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-info-circle"></i><span>Informasi Penyesuaian</span></h2>
                </div>
            </div>
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

        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-nut"></i><span>Baris Penyesuaian</span></h2>
                </div>
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

        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('stock-adjustments.show', $stockAdjustment) }}" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </div>
    </form>

    @include('stock-adjustments._line_item_scripts')

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    @endpush

    @push('scripts')
    <script>
    (function () {
        const branchId = {{ $stockAdjustment->branch_id }};
        window.currentStockAdjustmentBranchId = branchId;

        const existingLines = @json($existingLines);
        existingLines.forEach(function (line) {
            const row = StockAdjustmentLineItems.addLine(branchId);
            row.querySelector('.stock-adjustment-physical-qty').value = line.physical_qty;
            row.querySelector('.stock-adjustment-reason').value = line.reason;
            StockAdjustmentLineItems.preselectLine(row, line.sparepart_branch_id, branchId);
        });
    })();
    </script>
    @endpush
@endsection
