@extends('layouts.app')
@section('title', 'Tambah User')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-person-plus"></i></span>
            <div>
                <p class="eyebrow mb-1">User</p>
                <h1 class="h3 mb-1">Tambah User</h1>
                <p class="text-muted mb-0">Buat akun user baru untuk mengakses sistem.</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('users.store') }}" class="panel">
        @csrf
        <div class="panel-header">
            <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-person-plus"></i><span>Detail User</span></h2>
                <p class="text-muted mb-0">Lengkapi data akun di bawah ini.</p>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label for="name" class="form-label">Nama Lengkap</label>
                <input type="text" name="name" id="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label for="username" class="form-label">Username</label>
                <input type="text" name="username" id="username" value="{{ old('username') }}" class="form-control @error('username') is-invalid @enderror" maxlength="100" required>
                @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label for="password" class="form-label">Password</label>
                <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" required>
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12">
                <div class="form-check form-switch">
                    <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" checked>
                    <label for="is_active" class="form-check-label">Aktif</label>
                </div>
            </div>
        </div>
        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </div>
    </form>
@endsection
