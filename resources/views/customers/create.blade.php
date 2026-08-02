@extends('layouts.app')
@section('title', 'Tambah Customer')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-badge me-2"></i>Tambah Customer</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('customers.store') }}">
                @csrf

                <div class="mb-3">
                    <label for="customer_type" class="form-label">Tipe Customer</label>
                    <select name="customer_type" id="customer_type" class="form-select @error('customer_type') is-invalid @enderror" required>
                        <option value="">-- Pilih --</option>
                        <option value="INDIVIDUAL" {{ old('customer_type') === 'INDIVIDUAL' ? 'selected' : '' }}>Perorangan</option>
                        <option value="COMPANY" {{ old('customer_type') === 'COMPANY' ? 'selected' : '' }}>Perusahaan</option>
                    </select>
                    @error('customer_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="name" class="form-label">Nama Customer</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="stnk_name" class="form-label">Nama Sesuai STNK</label>
                    <input type="text" name="stnk_name" id="stnk_name" value="{{ old('stnk_name') }}" class="form-control @error('stnk_name') is-invalid @enderror" maxlength="150" required>
                    @error('stnk_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="address" class="form-label">Alamat</label>
                    <textarea name="address" id="address" class="form-control @error('address') is-invalid @enderror" rows="2">{{ old('address') }}</textarea>
                    @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="phone" class="form-label">Telepon</label>
                        <input type="text" name="phone" id="phone" value="{{ old('phone') }}" class="form-control @error('phone') is-invalid @enderror" maxlength="50">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" maxlength="255">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="form-check form-switch mb-4">
                    <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" checked>
                    <label for="is_active" class="form-check-label">Aktif</label>
                </div>

                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
                <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">Batal</a>
            </form>
        </div>
    </div>
@endsection
