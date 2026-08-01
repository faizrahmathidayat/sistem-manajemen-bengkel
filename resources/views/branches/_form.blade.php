@csrf
@isset($method)
    @method($method)
@endisset

<div class="mb-3">
    <label for="code" class="form-label">Kode Cabang</label>
    <input type="text" name="code" id="code" value="{{ old('code', $branch->code) }}" class="form-control @error('code') is-invalid @enderror" maxlength="30" required>
    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="name" class="form-label">Nama Cabang</label>
    <input type="text" name="name" id="name" value="{{ old('name', $branch->name) }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="address" class="form-label">Alamat</label>
    <textarea name="address" id="address" class="form-control @error('address') is-invalid @enderror" rows="2">{{ old('address', $branch->address) }}</textarea>
    @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="phone" class="form-label">Telepon</label>
        <input type="text" name="phone" id="phone" value="{{ old('phone', $branch->phone) }}" class="form-control @error('phone') is-invalid @enderror" maxlength="50">
        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label for="email" class="form-label">Email</label>
        <input type="email" name="email" id="email" value="{{ old('email', $branch->email) }}" class="form-control @error('email') is-invalid @enderror" maxlength="255">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="form-check form-switch mb-4">
    <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $branch->exists ? $branch->is_active : true) ? 'checked' : '' }}>
    <label for="is_active" class="form-check-label">Aktif</label>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
<a href="{{ route('branches.index') }}" class="btn btn-outline-secondary">Batal</a>
