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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    @endpush

    @push('scripts')
    <script>
    (function () {
        const fromBranchSelect = document.getElementById('fromBranchSelect');
        const addButton = document.getElementById('addStockTransferLine');
        let currentFromBranchId = fromBranchSelect.value || null;
        window.currentStockTransferFromBranchId = currentFromBranchId;

        function handleFromBranchChange(branchId) {
            currentFromBranchId = branchId || null;
            window.currentStockTransferFromBranchId = currentFromBranchId;
            addButton.disabled = !currentFromBranchId;
        }

        fromBranchSelect.addEventListener('change', function () {
            handleFromBranchChange(this.value);
        });

        // Validation-error round-trip: replay the line rows submitted before the
        // failed validation. Built in from the start — this exact gap was an
        // Important finding in the sibling Goods Receipt module's final review.
        async function replayOldLines() {
            const oldLines = @json($oldLines);
            for (const line of oldLines) {
                const row = StockTransferLineItems.addLine(currentFromBranchId);
                row.querySelector('.stock-transfer-qty').value = line.qty || '';
                if (line.sparepart_id) {
                    await StockTransferLineItems.preselectLine(row, line.sparepart_id, currentFromBranchId);
                }
            }
        }

        handleFromBranchChange(fromBranchSelect.value);
        replayOldLines();
    })();
    </script>
    @endpush
@endsection
