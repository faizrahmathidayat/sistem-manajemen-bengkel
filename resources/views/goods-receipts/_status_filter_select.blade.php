<select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
    <option value="">-- Semua Status --</option>
    <option value="{{ \App\Support\GoodsReceiptStatus::DRAFT }}" {{ $selectedStatus === \App\Support\GoodsReceiptStatus::DRAFT ? 'selected' : '' }}>Draft</option>
    <option value="{{ \App\Support\GoodsReceiptStatus::POSTED }}" {{ $selectedStatus === \App\Support\GoodsReceiptStatus::POSTED ? 'selected' : '' }}>Diposting</option>
    <option value="{{ \App\Support\GoodsReceiptStatus::CANCELLED }}" {{ $selectedStatus === \App\Support\GoodsReceiptStatus::CANCELLED ? 'selected' : '' }}>Dibatalkan</option>
</select>
