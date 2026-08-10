@extends('layouts.app')
@section('title', 'Tambah Sparepart dari Cabang Lain')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-link-45deg me-2"></i>Tambah Sparepart ke {{ $branch->name }}</h1>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('sparepart-branches.storeExisting') }}">
                @csrf
                <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                <div class="mb-3">
                    <label for="sparepart_id" class="form-label">Sparepart</label>
                    <select name="sparepart_id" id="sparepart_id" class="form-select @error('sparepart_id') is-invalid @enderror" required></select>
                    @error('sparepart_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="rack_id" class="form-label">Rak</label>
                        <select name="rack_id" id="rack_id" class="form-select @error('rack_id') is-invalid @enderror">
                            <option value="">-- Tanpa Rak --</option>
                            @foreach ($racks as $rack)
                                <option value="{{ $rack->id }}" {{ (int) old('rack_id') === $rack->id ? 'selected' : '' }}>{{ $rack->code }}</option>
                            @endforeach
                        </select>
                        @error('rack_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="selling_price" class="form-label">Harga Jual</label>
                        <input type="number" step="0.01" min="0" name="selling_price" id="selling_price" value="{{ old('selling_price') }}" class="form-control @error('selling_price') is-invalid @enderror" required>
                        @error('selling_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="minimum_stock" class="form-label">Stok Minimum</label>
                        <input type="number" step="0.001" min="0" name="minimum_stock" id="minimum_stock" value="{{ old('minimum_stock', 0) }}" class="form-control @error('minimum_stock') is-invalid @enderror">
                        @error('minimum_stock')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
                <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary">Batal</a>
            </form>
        </div>
    </div>

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
