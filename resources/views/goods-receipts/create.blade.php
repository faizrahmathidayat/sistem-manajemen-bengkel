@extends('layouts.app')
@section('title', 'Penerimaan Barang Baru')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-truck me-2"></i>Penerimaan Barang Baru</h1>
    </div>
    <form method="POST" action="{{ route('goods-receipts.store') }}" id="goodsReceiptForm">
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
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Sparepart</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addGoodsReceiptLine" disabled>+ Tambah Sparepart</button>
                </div>
                <div id="goodsReceiptLines"></div>
                @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('goods-receipts.index') }}" class="btn btn-outline-secondary">Batal</a>
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
        let currentBranchId = branchSelect.value || null;
        window.currentGoodsReceiptBranchId = currentBranchId;

        function handleBranchChange(branchId) {
            currentBranchId = branchId || null;
            window.currentGoodsReceiptBranchId = currentBranchId;
            addButton.disabled = !currentBranchId;
        }

        branchSelect.addEventListener('change', function () {
            handleBranchChange(this.value);
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
