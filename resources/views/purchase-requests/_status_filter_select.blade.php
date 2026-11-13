<select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
    <option value="">-- Semua Status --</option>
    @foreach (\App\Support\PurchaseRequestStatus::LABELS as $value => $label)
        <option value="{{ $value }}" {{ $selectedStatus === $value ? 'selected' : '' }}>{{ $label }}</option>
    @endforeach
</select>
