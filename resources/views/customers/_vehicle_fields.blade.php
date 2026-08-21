<div class="panel mb-3">
    <div class="panel-header">
        <div>
            <h2 class="h5 mb-1 section-title"><i class="bi bi-car-front"></i><span>Kendaraan</span></h2>
            <p class="text-muted mb-0">Opsional &mdash; isi jika ingin langsung mendaftarkan kendaraan milik customer ini.</p>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <label for="vehicle_category_id" class="form-label">Kategori</label>
            <select name="vehicle_category_id" id="vehicle_category_id" class="form-select @error('vehicle_category_id') is-invalid @enderror">
                <option value="">-- Pilih Kategori --</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" {{ (int) old('vehicle_category_id') === $category->id ? 'selected' : '' }}>
                        {{ $category->name }}
                    </option>
                @endforeach
            </select>
            @error('vehicle_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label for="vehicle_brand_id" class="form-label">Merk</label>
            <select name="vehicle_brand_id" id="vehicle_brand_id" class="form-select @error('vehicle_brand_id') is-invalid @enderror">
                <option value="">-- Pilih Kategori Dulu --</option>
            </select>
            @error('vehicle_brand_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label for="vehicle_type_id" class="form-label">Tipe</label>
            <select name="vehicle_type_id" id="vehicle_type_id" class="form-select @error('vehicle_type_id') is-invalid @enderror">
                <option value="">-- Pilih Merk Dulu --</option>
            </select>
            @error('vehicle_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-md-3">
            <label for="vehicle_plate_number" class="form-label">No. Polisi</label>
            <input type="text" name="vehicle_plate_number" id="vehicle_plate_number" value="{{ old('vehicle_plate_number') }}" class="form-control @error('vehicle_plate_number') is-invalid @enderror" maxlength="30">
            @error('vehicle_plate_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label for="vehicle_frame_number" class="form-label">No. Rangka</label>
            <input type="text" name="vehicle_frame_number" id="vehicle_frame_number" value="{{ old('vehicle_frame_number') }}" class="form-control @error('vehicle_frame_number') is-invalid @enderror" maxlength="100">
            @error('vehicle_frame_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label for="vehicle_engine_number" class="form-label">No. Mesin</label>
            <input type="text" name="vehicle_engine_number" id="vehicle_engine_number" value="{{ old('vehicle_engine_number') }}" class="form-control @error('vehicle_engine_number') is-invalid @enderror" maxlength="100">
            @error('vehicle_engine_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label for="vehicle_year" class="form-label">Tahun Kendaraan</label>
            <input type="number" name="vehicle_year" id="vehicle_year" value="{{ old('vehicle_year') }}" class="form-control @error('vehicle_year') is-invalid @enderror" min="1900" max="{{ now()->year + 1 }}">
            @error('vehicle_year')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const categorySelect = document.getElementById('vehicle_category_id');
    const brandSelect = document.getElementById('vehicle_brand_id');
    const typeSelect = document.getElementById('vehicle_type_id');

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
        fillSelect(typeSelect, [], '-- Pilih Merk Dulu --');
        if (!this.value) {
            fillSelect(brandSelect, [], '-- Pilih Kategori Dulu --');
            return;
        }
        const brands = await fetchOptions(`/vehicles/lookup/brands/${this.value}`);
        fillSelect(brandSelect, brands, '-- Pilih Merk --');
    });

    brandSelect.addEventListener('change', async function () {
        if (!this.value) {
            fillSelect(typeSelect, [], '-- Pilih Merk Dulu --');
            return;
        }
        const types = await fetchOptions(`/vehicles/lookup/types/${this.value}`);
        fillSelect(typeSelect, types, '-- Pilih Tipe --');
    });
})();
</script>
@endpush
