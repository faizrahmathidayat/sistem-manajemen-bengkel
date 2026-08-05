@csrf
@isset($method)
    @method($method)
@endisset

<div class="mb-3">
    <label for="customer_id" class="form-label">Customer</label>
    <select name="customer_id" id="customer_id" class="form-select @error('customer_id') is-invalid @enderror" required></select>
    @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="row">
    <div class="col-md-4 mb-3">
        <label for="category_id" class="form-label">Kategori</label>
        <select name="category_id" id="category_id" class="form-select @error('category_id') is-invalid @enderror" required>
            <option value="">-- Pilih Kategori --</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" {{ (int) old('category_id', $vehicle->category_id) === $category->id ? 'selected' : '' }}>
                    {{ $category->name }}
                </option>
            @endforeach
        </select>
        @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label for="brand_id" class="form-label">Merk</label>
        <select name="brand_id" id="brand_id" class="form-select @error('brand_id') is-invalid @enderror" required>
            <option value="">-- Pilih Merk --</option>
            @foreach ($brands as $brand)
                <option value="{{ $brand->id }}" {{ (int) old('brand_id', $vehicle->brand_id) === $brand->id ? 'selected' : '' }}>
                    {{ $brand->name }}
                </option>
            @endforeach
        </select>
        @error('brand_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label for="type_id" class="form-label">Tipe</label>
        <select name="type_id" id="type_id" class="form-select @error('type_id') is-invalid @enderror" required>
            <option value="">-- Pilih Tipe --</option>
            @foreach ($types as $type)
                <option value="{{ $type->id }}" {{ (int) old('type_id', $vehicle->type_id) === $type->id ? 'selected' : '' }}>
                    {{ $type->name }}
                </option>
            @endforeach
        </select>
        @error('type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="row">
    <div class="col-md-4 mb-3">
        <label for="plate_number" class="form-label">No. Polisi</label>
        <input type="text" name="plate_number" id="plate_number" value="{{ old('plate_number', $vehicle->plate_number) }}" class="form-control @error('plate_number') is-invalid @enderror" maxlength="30">
        @error('plate_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label for="frame_number" class="form-label">No. Rangka</label>
        <input type="text" name="frame_number" id="frame_number" value="{{ old('frame_number', $vehicle->frame_number) }}" class="form-control @error('frame_number') is-invalid @enderror" maxlength="100">
        @error('frame_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label for="engine_number" class="form-label">No. Mesin</label>
        <input type="text" name="engine_number" id="engine_number" value="{{ old('engine_number', $vehicle->engine_number) }}" class="form-control @error('engine_number') is-invalid @enderror" maxlength="100">
        @error('engine_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="form-check form-switch mb-4">
    <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $vehicle->exists ? $vehicle->is_active : true) ? 'checked' : '' }}>
    <label for="is_active" class="form-check-label">Aktif</label>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
<a href="{{ route('vehicles.index') }}" class="btn btn-outline-secondary">Batal</a>

@push('scripts')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
<script>
(function () {
    const customerSelect = document.getElementById('customer_id');
    initAjaxSelect(customerSelect, {
        endpoint: '{{ route('lookup.customers') }}',
        placeholder: '-- Pilih Customer --',
    });
    const existingCustomerId = {{ (int) old('customer_id', $vehicle->customer_id ?? $selectedCustomerId ?? 0) }};
    if (existingCustomerId) {
        preselectAjaxOption(customerSelect, { endpoint: '{{ route('lookup.customers') }}', id: existingCustomerId })
            .then(function () { $(customerSelect).trigger('change'); });
    }
})();
</script>
@endpush

@push('scripts')
<script>
(function () {
    const categorySelect = document.getElementById('category_id');
    const brandSelect = document.getElementById('brand_id');
    const typeSelect = document.getElementById('type_id');

    async function fetchOptions(url) {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        return response.json();
    }

    function fillSelect(select, items, placeholder) {
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        items.forEach(function (item) {
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.name;
            select.appendChild(option);
        });
    }

    categorySelect.addEventListener('change', async function () {
        fillSelect(typeSelect, [], '-- Pilih Tipe --');
        if (!this.value) {
            fillSelect(brandSelect, [], '-- Pilih Merk --');
            return;
        }
        const brands = await fetchOptions(`/vehicles/lookup/brands/${this.value}`);
        fillSelect(brandSelect, brands, '-- Pilih Merk --');
    });

    brandSelect.addEventListener('change', async function () {
        if (!this.value) {
            fillSelect(typeSelect, [], '-- Pilih Tipe --');
            return;
        }
        const types = await fetchOptions(`/vehicles/lookup/types/${this.value}`);
        fillSelect(typeSelect, types, '-- Pilih Tipe --');
    });
})();
</script>
@endpush
