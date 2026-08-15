@csrf
@isset($method)
    @method($method)
@endisset

<div class="row g-3">
    <div class="col-md-6">
        <label for="code" class="form-label">Kode Rak</label>
        <input type="text" name="code" id="code" value="{{ old('code', $rack->code) }}" class="form-control @error('code') is-invalid @enderror" maxlength="30" required>
        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <div class="form-check form-switch">
            <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $rack->exists ? $rack->is_active : true) ? 'checked' : '' }}>
            <label for="is_active" class="form-check-label">Aktif</label>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
    <a href="{{ route('racks.index') }}" class="btn btn-outline-secondary">Batal</a>
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
</div>
