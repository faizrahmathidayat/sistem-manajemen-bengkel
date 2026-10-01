{{-- Buttons + error box for importing adjustment lines from Excel. Wired up in _line_item_scripts. --}}
<div class="d-flex flex-wrap gap-2">
    <a href="{{ route('stock-adjustments.import-template') }}" download class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-download"></i> Download Template
    </a>
    <button type="button" class="btn btn-outline-primary btn-sm" id="importStockAdjustmentButton" {{ $importDisabled ?? false ? 'disabled' : '' }}>
        <span class="spinner-border spinner-border-sm d-none" id="importStockAdjustmentSpinner" role="status" aria-hidden="true"></span>
        <i class="bi bi-upload" id="importStockAdjustmentIcon"></i> Import Baris
    </button>
    <input type="file" id="importStockAdjustmentFile" accept=".xlsx,.xls" class="d-none">
    <button type="button" class="btn btn-outline-primary btn-sm" id="addStockAdjustmentLine" {{ $importDisabled ?? false ? 'disabled' : '' }}>+ Tambah Sparepart</button>
</div>
