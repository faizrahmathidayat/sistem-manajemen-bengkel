<select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
    <option value="">-- Semua Status --</option>
    <option value="{{ \App\Support\WorkOrderStatus::DRAFT }}" {{ $selectedStatus === \App\Support\WorkOrderStatus::DRAFT ? 'selected' : '' }}>Draft</option>
    <option value="{{ \App\Support\WorkOrderStatus::OPEN }}" {{ $selectedStatus === \App\Support\WorkOrderStatus::OPEN ? 'selected' : '' }}>Dikonfirmasi</option>
    <option value="{{ \App\Support\WorkOrderStatus::SHORTAGE }}" {{ $selectedStatus === \App\Support\WorkOrderStatus::SHORTAGE ? 'selected' : '' }}>Kurang Stok</option>
    <option value="{{ \App\Support\WorkOrderStatus::COMPLETED }}" {{ $selectedStatus === \App\Support\WorkOrderStatus::COMPLETED ? 'selected' : '' }}>Selesai</option>
    <option value="{{ \App\Support\WorkOrderStatus::CANCELLED }}" {{ $selectedStatus === \App\Support\WorkOrderStatus::CANCELLED ? 'selected' : '' }}>Dibatalkan</option>
</select>
