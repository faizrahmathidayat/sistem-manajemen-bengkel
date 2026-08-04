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
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const addButton = document.getElementById('addGoodsReceiptLine');

        async function handleBranchChange(branchId) {
            addButton.disabled = true;
            if (!branchId) {
                return;
            }
            const spareparts = await GoodsReceiptLineItems.fetchJson(`/goods-receipts/lookup/spareparts/${branchId}`);
            GoodsReceiptLineItems.setSparepartOptions(spareparts);
            addButton.disabled = false;
        }

        branchSelect.addEventListener('change', function () {
            handleBranchChange(this.value);
        });

        // Validation-error round-trip: replay the sparepart line rows submitted
        // before the failed validation. These rows only exist in JS-managed DOM
        // state (added via <template> cloning), so without this the user would
        // have to retype every line from scratch after any validation error.
        function replayOldLines() {
            const oldLines = @json($oldLines);
            oldLines.forEach(function (line) {
                GoodsReceiptLineItems.addLine();
                const rows = document.querySelectorAll('#goodsReceiptLines .goods-receipt-line');
                const row = rows[rows.length - 1];
                if (line.sparepart_branch_id) row.querySelector('.goods-receipt-sparepart-select').value = line.sparepart_branch_id;
                row.querySelector('.goods-receipt-qty').value = line.qty || '';
                row.querySelector('.goods-receipt-purchase-price').value = line.purchase_price || '';
            });
        }

        // Validation-error round-trip: old('branch_id') re-selects the branch option
        // but does not fire a native `change` event, so the sparepart cascade and
        // add-line button would otherwise stay empty and disabled. Re-run it once
        // on load.
        //
        // The sparepart <select> options are populated dynamically from the
        // branch's AJAX response (via GoodsReceiptLineItems.setSparepartOptions
        // inside handleBranchChange), so line-replay MUST run after that AJAX call
        // resolves — otherwise replayed rows would be added with no options to
        // select from yet. handleBranchChange() is async and returns a promise, so
        // we chain replayOldLines() onto it here instead of calling it eagerly.
        if (branchSelect.value) {
            handleBranchChange(branchSelect.value).then(replayOldLines);
        } else {
            replayOldLines();
        }
    })();
    </script>
    @endpush
@endsection
