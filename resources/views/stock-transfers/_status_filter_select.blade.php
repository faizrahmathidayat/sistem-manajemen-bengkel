<select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
    <option value="">-- Semua Status --</option>
    <option value="{{ \App\Support\TransferStatus::DRAFT }}" {{ $selectedStatus === \App\Support\TransferStatus::DRAFT ? 'selected' : '' }}>Draft</option>
    <option value="{{ \App\Support\TransferStatus::APPROVED }}" {{ $selectedStatus === \App\Support\TransferStatus::APPROVED ? 'selected' : '' }}>Disetujui</option>
    <option value="{{ \App\Support\TransferStatus::DISPATCHED }}" {{ $selectedStatus === \App\Support\TransferStatus::DISPATCHED ? 'selected' : '' }}>Dikirim</option>
    <option value="{{ \App\Support\TransferStatus::RECEIVED }}" {{ $selectedStatus === \App\Support\TransferStatus::RECEIVED ? 'selected' : '' }}>Diterima</option>
    <option value="{{ \App\Support\TransferStatus::CANCELLED }}" {{ $selectedStatus === \App\Support\TransferStatus::CANCELLED ? 'selected' : '' }}>Dibatalkan</option>
</select>
