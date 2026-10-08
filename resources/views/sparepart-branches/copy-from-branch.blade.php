@extends('layouts.app')
@section('title', 'Salin Sparepart dari Cabang Lain')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-files"></i></span>
            <div>
                <p class="eyebrow mb-1">Sparepart</p>
                <h1 class="h3 mb-1">Salin Sparepart ke {{ $branch->name }}</h1>
                <p class="text-muted mb-0">Daftarkan semua sparepart aktif dari cabang lain ke cabang ini sekaligus.</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('sparepart-branches.copyFromBranch.store') }}" class="panel">
        @csrf
        <input type="hidden" name="branch_id" value="{{ $branch->id }}">
        <div class="panel-header">
            <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-files"></i><span>Cabang Asal</span></h2>
                <p class="text-muted mb-0">Pilih cabang yang sparepart-nya akan disalin.</p>
            </div>
        </div>

        @if ($sourceBranches->isEmpty())
            <div class="alert alert-info mb-0">Anda belum memiliki akses ke cabang lain untuk disalin.</div>
        @else
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="source_branch_id" class="form-label">Salin dari</label>
                    <select name="source_branch_id" id="source_branch_id" class="form-select @error('source_branch_id') is-invalid @enderror" required>
                        <option value="">-- Pilih Cabang --</option>
                        @foreach ($sourceBranches as $source)
                            <option value="{{ $source->id }}" {{ (int) old('source_branch_id') === $source->id ? 'selected' : '' }}>
                                {{ $source->name }} ({{ $copyableCounts[$source->id] }} sparepart baru)
                            </option>
                        @endforeach
                    </select>
                    @error('source_branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    @error('branch_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="alert alert-secondary mt-4 mb-0">
                <ul class="mb-0">
                    <li>Yang disalin: sparepart aktif di cabang asal beserta <strong>Harga Jual</strong> dan <strong>Stok Minimum</strong>-nya.</li>
                    <li><strong>Rak</strong> tidak disalin (kosong), atur per sparepart lewat tombol Ubah.</li>
                    <li>Stok awal di {{ $branch->name }} adalah 0. Isi stok lewat Penerimaan Barang atau Stock Adjustment.</li>
                    <li>Sparepart yang sudah terdaftar di {{ $branch->name }} dilewati, tidak ditimpa.</li>
                </ul>
            </div>

            <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
                <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary">Batal</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-files"></i> Salin Sparepart</button>
            </div>
        @endif
    </form>
@endsection
