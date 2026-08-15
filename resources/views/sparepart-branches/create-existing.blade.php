@extends('layouts.app')
@section('title', 'Tambah Sparepart dari Cabang Lain')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-link-45deg"></i></span>
            <div>
                <p class="eyebrow mb-1">Sparepart</p>
                <h1 class="h3 mb-1">Tambah Sparepart ke {{ $branch->name }}</h1>
                <p class="text-muted mb-0">Hubungkan sparepart dari cabang lain ke cabang ini.</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('sparepart-branches.storeExisting') }}" class="panel">
        @csrf
        <input type="hidden" name="branch_id" value="{{ $branch->id }}">
        <div class="panel-header">
            <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-link-45deg"></i><span>Detail Sparepart</span></h2>
                <p class="text-muted mb-0">Pilih sparepart yang sudah terdaftar di cabang lain.</p>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-12">
                <label for="sparepart_id" class="form-label">Sparepart</label>
                <select name="sparepart_id" id="sparepart_id" class="form-select @error('sparepart_id') is-invalid @enderror" required></select>
                @error('sparepart_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label for="rack_id" class="form-label">Rak</label>
                <select name="rack_id" id="rack_id" class="form-select @error('rack_id') is-invalid @enderror">
                    <option value="">-- Tanpa Rak --</option>
                    @foreach ($racks as $rack)
                        <option value="{{ $rack->id }}" {{ (int) old('rack_id') === $rack->id ? 'selected' : '' }}>{{ $rack->code }}</option>
                    @endforeach
                </select>
                @error('rack_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label for="selling_price" class="form-label">Harga Jual</label>
                <input type="number" step="0.01" min="0" name="selling_price" id="selling_price" value="{{ old('selling_price') }}" class="form-control @error('selling_price') is-invalid @enderror" required>
                @error('selling_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label for="minimum_stock" class="form-label">Stok Minimum</label>
                <input type="number" step="0.001" min="0" name="minimum_stock" id="minimum_stock" value="{{ old('minimum_stock', 0) }}" class="form-control @error('minimum_stock') is-invalid @enderror">
                @error('minimum_stock')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </div>
    </form>

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    <script>
    (function () {
        initAjaxSelect(document.getElementById('sparepart_id'), {
            endpoint: '{{ route('sparepart-branches.lookup.unconfigured') }}',
            extraParams: function () { return { branch_id: {{ $branch->id }} }; },
            placeholder: '-- Pilih Sparepart --',
        });
    })();
    </script>
    @endpush
@endsection
