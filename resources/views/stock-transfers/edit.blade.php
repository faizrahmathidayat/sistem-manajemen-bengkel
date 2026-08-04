@extends('layouts.app')
@section('title', 'Ubah Transfer Stock')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-arrow-left-right me-2"></i>Ubah {{ $stockTransfer->number }}</h1>
    </div>
    <form method="POST" action="{{ route('stock-transfers.update', $stockTransfer) }}" id="stockTransferForm">
        @csrf
        @method('PUT')
        <div class="card mb-3">
            <div class="card-body">
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
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Sparepart</h2>
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
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('stock-transfers.show', $stockTransfer) }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('stock-transfers._line_item_scripts')

    @push('scripts')
    <script>
    (function () {
        const existingSparepartOptions = @json($sparepartOptions);
        StockTransferLineItems.setSparepartOptions(existingSparepartOptions);

        const existingLines = @json($existingLines);
        existingLines.forEach(function (line) {
            StockTransferLineItems.addLine();
            const rows = document.querySelectorAll('#stockTransferLines .stock-transfer-line');
            const row = rows[rows.length - 1];
            const select = row.querySelector('.stock-transfer-sparepart-select');
            select.value = line.sparepart_id;
            select.dispatchEvent(new Event('change'));
            row.querySelector('.stock-transfer-qty').value = line.qty;
        });
    })();
    </script>
    @endpush
@endsection
