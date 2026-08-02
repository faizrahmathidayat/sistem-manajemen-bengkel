@csrf
@isset($method)
    @method($method)
@endisset

<div class="mb-3">
    <label for="code" class="form-label">Kode Jasa</label>
    <input type="text" name="code" id="code" value="{{ old('code', $serviceCatalog->code) }}" class="form-control @error('code') is-invalid @enderror" maxlength="30" required>
    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="name" class="form-label">Nama Jasa</label>
    <input type="text" name="name" id="name" value="{{ old('name', $serviceCatalog->name) }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="default_price" class="form-label">Harga Default</label>
    <input type="number" step="0.01" min="0" name="default_price" id="default_price" value="{{ old('default_price', $serviceCatalog->default_price) }}" class="form-control @error('default_price') is-invalid @enderror" required>
    @error('default_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="form-check form-switch mb-4">
    <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $serviceCatalog->exists ? $serviceCatalog->is_active : true) ? 'checked' : '' }}>
    <label for="is_active" class="form-check-label">Aktif</label>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
<a href="{{ route('service-catalogs.index') }}" class="btn btn-outline-secondary">Batal</a>
