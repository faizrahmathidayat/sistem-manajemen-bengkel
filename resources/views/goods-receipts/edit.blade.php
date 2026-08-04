@extends('layouts.app')
@section('title', 'Ubah Penerimaan Barang')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-truck me-2"></i>Ubah {{ $goodsReceipt->number }} — {{ $goodsReceipt->branch->name }}</h1>
    </div>
    <form method="POST" action="{{ route('goods-receipts.update', $goodsReceipt) }}" id="goodsReceiptForm">
        @csrf
        @method('PUT')
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Tanggal Penerimaan</label>
                        <input type="date" name="receipt_date" value="{{ old('receipt_date', $goodsReceipt->receipt_date->format('Y-m-d')) }}" class="form-control @error('receipt_date') is-invalid @enderror" required>
                        @error('receipt_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">No. Referensi</label>
                        <input type="text" name="reference_number" value="{{ old('reference_number', $goodsReceipt->reference_number) }}" class="form-control @error('reference_number') is-invalid @enderror" maxlength="100">
                        @error('reference_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes', $goodsReceipt->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Sparepart</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addGoodsReceiptLine">+ Tambah Sparepart</button>
                </div>
                <div id="goodsReceiptLines"></div>
                @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('goods-receipts.show', $goodsReceipt) }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('goods-receipts._line_item_scripts')

    @push('scripts')
    <script>
    (function () {
        const existingSparepartOptions = @json($sparepartOptions);
        GoodsReceiptLineItems.setSparepartOptions(existingSparepartOptions);

        const existingLines = @json($existingLines);
        existingLines.forEach(function (line) {
            GoodsReceiptLineItems.addLine();
            const rows = document.querySelectorAll('#goodsReceiptLines .goods-receipt-line');
            const row = rows[rows.length - 1];
            row.querySelector('.goods-receipt-sparepart-select').value = line.sparepart_branch_id;
            row.querySelector('.goods-receipt-qty').value = line.qty;
            row.querySelector('.goods-receipt-purchase-price').value = line.purchase_price;
        });
    })();
    </script>
    @endpush
@endsection
