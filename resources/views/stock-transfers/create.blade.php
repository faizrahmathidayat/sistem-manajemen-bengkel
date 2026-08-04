@extends('layouts.app')
@section('title', 'Transfer Stock Baru')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-arrow-left-right me-2"></i>Transfer Stock Baru</h1>
    </div>
    <form method="POST" action="{{ route('stock-transfers.store') }}" id="stockTransferForm">
        @csrf
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Cabang Asal</label>
                        <select name="from_branch_id" id="fromBranchSelect" class="form-select @error('from_branch_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Cabang Asal --</option>
                            @foreach ($fromBranches as $branch)
                                <option value="{{ $branch->id }}" {{ (int) old('from_branch_id') === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('from_branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Cabang Tujuan</label>
                        <select name="to_branch_id" id="toBranchSelect" class="form-select @error('to_branch_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Cabang Tujuan --</option>
                            @foreach ($allBranches as $branch)
                                <option value="{{ $branch->id }}" {{ (int) old('to_branch_id') === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('to_branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tanggal Transfer</label>
                        <input type="date" name="transfer_date" value="{{ old('transfer_date', now()->format('Y-m-d')) }}" class="form-control @error('transfer_date') is-invalid @enderror" required>
                        @error('transfer_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addStockTransferLine" disabled>+ Tambah Sparepart</button>
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
        <a href="{{ route('stock-transfers.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('stock-transfers._line_item_scripts')

    @php($oldLines = old('lines', []))
    @push('scripts')
    <script>
    (function () {
        const fromBranchSelect = document.getElementById('fromBranchSelect');
        const addButton = document.getElementById('addStockTransferLine');

        async function handleFromBranchChange(branchId) {
            addButton.disabled = true;
            if (!branchId) {
                return;
            }
            const spareparts = await StockTransferLineItems.fetchJson(`/stock-transfers/lookup/spareparts/${branchId}`);
            StockTransferLineItems.setSparepartOptions(spareparts);
            addButton.disabled = false;
        }

        fromBranchSelect.addEventListener('change', function () {
            handleFromBranchChange(this.value);
        });

        // Validation-error round-trip: replay the line rows submitted before the
        // failed validation. Built in from the start — this exact gap was an
        // Important finding in the sibling Goods Receipt module's final review.
        function replayOldLines() {
            const oldLines = @json($oldLines);
            oldLines.forEach(function (line) {
                StockTransferLineItems.addLine();
                const rows = document.querySelectorAll('#stockTransferLines .stock-transfer-line');
                const row = rows[rows.length - 1];
                if (line.sparepart_id) {
                    const select = row.querySelector('.stock-transfer-sparepart-select');
                    select.value = line.sparepart_id;
                    select.dispatchEvent(new Event('change'));
                }
                row.querySelector('.stock-transfer-qty').value = line.qty || '';
            });
        }

        // Validation-error round-trip: old('from_branch_id') re-selects the branch
        // option but does not fire a native `change` event, so the sparepart
        // cascade and add-line button would otherwise stay empty/disabled.
        if (fromBranchSelect.value) {
            handleFromBranchChange(fromBranchSelect.value).then(replayOldLines);
        } else {
            replayOldLines();
        }
    })();
    </script>
    @endpush
@endsection
