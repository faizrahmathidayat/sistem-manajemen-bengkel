@extends('layouts.app')
@section('title', 'Ubah Transfer Stock')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-arrow-left-right"></i></span>
            <div>
                <p class="eyebrow mb-1">Transfer Stock</p>
                <h1 class="h3 mb-1">Ubah {{ $stockTransfer->number }}</h1>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('stock-transfers.show', $stockTransfer) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('stock-transfers.update', $stockTransfer) }}" id="stockTransferForm">
        @csrf
        @method('PUT')
        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-info-circle"></i><span>Informasi Transfer</span></h2>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Cabang Asal</label>
                    <input type="text" class="form-control" value="{{ $stockTransfer->fromBranch->name }}" disabled>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cabang Tujuan</label>
                    <select name="to_branch_id" class="form-select @error('to_branch_id') is-invalid @enderror" required>
                        @foreach ($allBranches as $branch)
                            <option value="{{ $branch->id }}" {{ (int) old('to_branch_id', $stockTransfer->to_branch_id) === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('to_branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tanggal Transfer</label>
                    <input type="date" name="transfer_date" value="{{ old('transfer_date', $stockTransfer->transfer_date->format('Y-m-d')) }}" class="form-control @error('transfer_date') is-invalid @enderror" required>
                    @error('transfer_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-12">
                    <label class="form-label">Catatan</label>
                    <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes', $stockTransfer->notes) }}</textarea>
                    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="panel mb-3">
            <div class="panel-header">
                <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-nut"></i><span>Baris Sparepart</span></h2>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm" id="addStockTransferLine">+ Tambah Sparepart</button>
            </div>
            <div class="row g-2 mb-1 text-muted small">
                <div class="col-md-6">Sparepart</div>
                <div class="col-md-2">Stok Tersedia</div>
                <div class="col-md-3">Qty</div>
            </div>
            <div id="stockTransferLines"></div>
            @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>

        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('stock-transfers.show', $stockTransfer) }}" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </div>
    </form>

    @include('stock-transfers._line_item_scripts')

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    @endpush

    @push('scripts')
    <script>
    (function () {
        const fromBranchId = {{ $stockTransfer->from_branch_id }};
        window.currentStockTransferFromBranchId = fromBranchId;

        const existingLines = @json($existingLines);
        existingLines.forEach(function (line) {
            const row = StockTransferLineItems.addLine(fromBranchId);
            row.querySelector('.stock-transfer-qty').value = line.qty;
            StockTransferLineItems.preselectLine(row, line.sparepart_id, fromBranchId);
        });
    })();
    </script>
    @endpush
@endsection
