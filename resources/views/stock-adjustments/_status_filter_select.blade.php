<select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
    <option value="">-- Semua Status --</option>
    <option value="{{ \App\Support\StockAdjustmentStatus::DRAFT }}" {{ $selectedStatus === \App\Support\StockAdjustmentStatus::DRAFT ? 'selected' : '' }}>Draft</option>
    <option value="{{ \App\Support\StockAdjustmentStatus::PENDING_APPROVAL }}" {{ $selectedStatus === \App\Support\StockAdjustmentStatus::PENDING_APPROVAL ? 'selected' : '' }}>Diajukan</option>
    <option value="{{ \App\Support\StockAdjustmentStatus::APPROVED }}" {{ $selectedStatus === \App\Support\StockAdjustmentStatus::APPROVED ? 'selected' : '' }}>Disetujui</option>
    <option value="{{ \App\Support\StockAdjustmentStatus::POSTED }}" {{ $selectedStatus === \App\Support\StockAdjustmentStatus::POSTED ? 'selected' : '' }}>Diposting</option>
    <option value="{{ \App\Support\StockAdjustmentStatus::CANCELLED }}" {{ $selectedStatus === \App\Support\StockAdjustmentStatus::CANCELLED ? 'selected' : '' }}>Dibatalkan</option>
</select>
