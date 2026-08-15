@extends('layouts.app')
@section('title', 'Ubah Konfigurasi Sparepart')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-box-seam"></i></span>
            <div>
                <p class="eyebrow mb-1">Sparepart</p>
                <h1 class="h3 mb-1">Ubah {{ $sparepartBranch->sparepart->name }}</h1>
                <p class="text-muted mb-0">Perbarui rak, harga jual, dan stok minimum sparepart ini.</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('sparepart-branches.update', $sparepartBranch) }}" class="panel">
        @csrf
        @method('PUT')
        <div class="panel-header">
            <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-box-seam"></i><span>Detail Sparepart</span></h2>
                <p class="text-muted mb-0">Lengkapi rak, harga jual, dan stok minimum di bawah ini.</p>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Kode Sparepart</label>
                <input type="text" value="{{ $sparepartBranch->sparepart->code }}" class="form-control" disabled>
            </div>
            <div class="col-md-4">
                <label for="rack_id" class="form-label">Rak</label>
                <select name="rack_id" id="rack_id" class="form-select @error('rack_id') is-invalid @enderror">
                    <option value="">-- Tanpa Rak --</option>
                    @foreach ($racks as $rack)
                        <option value="{{ $rack->id }}" {{ (int) old('rack_id', $sparepartBranch->rack_id) === $rack->id ? 'selected' : '' }}>{{ $rack->code }}</option>
                    @endforeach
                </select>
                @error('rack_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label for="selling_price" class="form-label">Harga Jual</label>
                <input type="number" step="0.01" min="0" name="selling_price" id="selling_price" value="{{ old('selling_price', $sparepartBranch->selling_price) }}" class="form-control @error('selling_price') is-invalid @enderror" required>
                @error('selling_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label for="minimum_stock" class="form-label">Stok Minimum</label>
                <input type="number" step="0.001" min="0" name="minimum_stock" id="minimum_stock" value="{{ old('minimum_stock', $sparepartBranch->minimum_stock) }}" class="form-control @error('minimum_stock') is-invalid @enderror">
                @error('minimum_stock')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </div>
    </form>
@endsection
