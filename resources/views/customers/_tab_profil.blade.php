<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ route('customers.update', $customer) }}">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="customer_type" class="form-label">Tipe Customer</label>
                <select name="customer_type" id="customer_type" class="form-select @error('customer_type') is-invalid @enderror" required>
                    <option value="INDIVIDUAL" {{ old('customer_type', $customer->customer_type) === 'INDIVIDUAL' ? 'selected' : '' }}>Perorangan</option>
                    <option value="COMPANY" {{ old('customer_type', $customer->customer_type) === 'COMPANY' ? 'selected' : '' }}>Perusahaan</option>
                </select>
                @error('customer_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="name" class="form-label">Nama Customer</label>
                <input type="text" name="name" id="name" value="{{ old('name', $customer->name) }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="stnk_name" class="form-label">Nama Sesuai STNK</label>
                <input type="text" name="stnk_name" id="stnk_name" value="{{ old('stnk_name', $customer->stnk_name) }}" class="form-control @error('stnk_name') is-invalid @enderror" maxlength="150" required>
                @error('stnk_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="address" class="form-label">Alamat</label>
                <textarea name="address" id="address" class="form-control @error('address') is-invalid @enderror" rows="2">{{ old('address', $customer->address) }}</textarea>
                @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="phone" class="form-label">Telepon</label>
                    <input type="text" name="phone" id="phone" value="{{ old('phone', $customer->phone) }}" class="form-control @error('phone') is-invalid @enderror" maxlength="50">
                    @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6 mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" name="email" id="email" value="{{ old('email', $customer->email) }}" class="form-control @error('email') is-invalid @enderror" maxlength="255">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-check form-switch mb-4">
                <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $customer->is_active) ? 'checked' : '' }}>
                <label for="is_active" class="form-check-label">Aktif</label>
            </div>

            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </form>
    </div>
</div>
