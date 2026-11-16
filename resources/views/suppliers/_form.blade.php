@csrf
@isset($method)
    @method($method)
@endisset

<div class="row g-3">
    <div class="col-md-4">
        <label for="code" class="form-label">Kode</label>
        <input type="text" name="code" id="code" value="{{ old('code', $supplier->code) }}" class="form-control @error('code') is-invalid @enderror" maxlength="30" required>
        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-8">
        <label for="name" class="form-label">Nama Supplier</label>
        <input type="text" name="name" id="name" value="{{ old('name', $supplier->name) }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" placeholder="Nama perusahaan / toko" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label for="contact_person" class="form-label">Kontak Person</label>
        <input type="text" name="contact_person" id="contact_person" value="{{ old('contact_person', $supplier->contact_person) }}" class="form-control @error('contact_person') is-invalid @enderror" maxlength="100">
        @error('contact_person')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label for="phone" class="form-label">Telepon</label>
        <input type="text" name="phone" id="phone" value="{{ old('phone', $supplier->phone) }}" class="form-control @error('phone') is-invalid @enderror" maxlength="50">
        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <label for="address" class="form-label">Alamat</label>
        <textarea name="address" id="address" rows="2" class="form-control @error('address') is-invalid @enderror" maxlength="1000">{{ old('address', $supplier->address) }}</textarea>
        @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label for="account_number" class="form-label">No. Rekening</label>
        <input type="text" name="account_number" id="account_number" value="{{ old('account_number', $supplier->account_number) }}" class="form-control @error('account_number') is-invalid @enderror" maxlength="60" placeholder="Contoh: BCA 123-456-7890 a.n. PT Sumber">
        @error('account_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <div class="form-check form-switch">
            <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $supplier->exists ? $supplier->is_active : true) ? 'checked' : '' }}>
            <label for="is_active" class="form-check-label">Aktif (bisa dipilih saat menentukan supplier)</label>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
    <a href="{{ route('suppliers.index') }}" class="btn btn-outline-secondary">Batal</a>
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
</div>
