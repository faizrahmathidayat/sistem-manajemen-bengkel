<select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
    <option value="">-- Semua Status --</option>
    <option value="{{ \App\Support\InvoiceStatus::DRAFT }}" {{ $selectedStatus === \App\Support\InvoiceStatus::DRAFT ? 'selected' : '' }}>Draft</option>
    <option value="{{ \App\Support\InvoiceStatus::POSTED }}" {{ $selectedStatus === \App\Support\InvoiceStatus::POSTED ? 'selected' : '' }}>Diposting</option>
    <option value="{{ \App\Support\InvoiceStatus::PARTIALLY_PAID }}" {{ $selectedStatus === \App\Support\InvoiceStatus::PARTIALLY_PAID ? 'selected' : '' }}>Dibayar Sebagian</option>
    <option value="{{ \App\Support\InvoiceStatus::PAID }}" {{ $selectedStatus === \App\Support\InvoiceStatus::PAID ? 'selected' : '' }}>Lunas</option>
    <option value="{{ \App\Support\InvoiceStatus::CANCELLED }}" {{ $selectedStatus === \App\Support\InvoiceStatus::CANCELLED ? 'selected' : '' }}>Dibatalkan</option>
</select>
