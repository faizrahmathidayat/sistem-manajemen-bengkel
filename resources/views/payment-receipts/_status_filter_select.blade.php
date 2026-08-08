<select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
    <option value="">-- Semua Status --</option>
    <option value="{{ \App\Support\PaymentReceiptStatus::POSTED }}" {{ $selectedStatus === \App\Support\PaymentReceiptStatus::POSTED ? 'selected' : '' }}>Posted</option>
    <option value="{{ \App\Support\PaymentReceiptStatus::VOID }}" {{ $selectedStatus === \App\Support\PaymentReceiptStatus::VOID ? 'selected' : '' }}>Void</option>
</select>
