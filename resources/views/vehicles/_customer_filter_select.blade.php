<select name="customer_id" class="form-select form-select-sm" onchange="this.form.submit()">
    <option value="">-- Semua Customer --</option>
    @foreach ($customers as $customer)
        <option value="{{ $customer->id }}" {{ $selectedCustomerId === $customer->id ? 'selected' : '' }}>{{ $customer->name }}</option>
    @endforeach
</select>
