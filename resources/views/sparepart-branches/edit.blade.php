@extends('layouts.app')
@section('title', 'Ubah Konfigurasi Sparepart')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i>Ubah {{ $sparepartBranch->sparepart->name }}</h1>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('sparepart-branches.update', $sparepartBranch) }}">
                @csrf
                @method('PUT')
                <div class="mb-3">
                    <label class="form-label">Kode Sparepart</label>
                    <input type="text" value="{{ $sparepartBranch->sparepart->code }}" class="form-control" disabled>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="rack_id" class="form-label">Rak</label>
                        <select name="rack_id" id="rack_id" class="form-select @error('rack_id') is-invalid @enderror">
                            <option value="">-- Tanpa Rak --</option>
                            @foreach ($racks as $rack)
                                <option value="{{ $rack->id }}" {{ (int) old('rack_id', $sparepartBranch->rack_id) === $rack->id ? 'selected' : '' }}>{{ $rack->code }}</option>
                            @endforeach
                        </select>
                        @error('rack_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="selling_price" class="form-label">Harga Jual</label>
                        <input type="number" step="0.01" min="0" name="selling_price" id="selling_price" value="{{ old('selling_price', $sparepartBranch->selling_price) }}" class="form-control @error('selling_price') is-invalid @enderror" required>
                        @error('selling_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="minimum_stock" class="form-label">Stok Minimum</label>
                        <input type="number" step="0.001" min="0" name="minimum_stock" id="minimum_stock" value="{{ old('minimum_stock', $sparepartBranch->minimum_stock) }}" class="form-control @error('minimum_stock') is-invalid @enderror">
                        @error('minimum_stock')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
                <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary">Batal</a>
            </form>
        </div>
    </div>
@endsection
