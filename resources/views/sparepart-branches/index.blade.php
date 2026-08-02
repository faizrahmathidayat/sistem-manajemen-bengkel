@extends('layouts.app')
@section('title', 'Master Sparepart')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i>Master Sparepart</h1>
        <div class="d-flex gap-2">
            @if (auth()->user()->hasPermissionToInBranch('sparepart.create', $currentBranch->id))
                <a href="{{ route('sparepart-branches.createExisting') }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-link-45deg"></i> Tambah dari Cabang Lain
                </a>
                <a href="{{ route('sparepart-branches.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> Sparepart Baru
                </a>
            @endif
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-4">
            <form method="GET" action="{{ route('sparepart-branches.index') }}">
                <label for="branch_id" class="form-label">Cabang</label>
                <select name="branch_id" id="branch_id" class="form-select" onchange="this.form.submit()">
                    @foreach ($allowedBranches as $branch)
                        <option value="{{ $branch->id }}" {{ $branch->id === $currentBranch->id ? 'selected' : '' }}>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="col-md-8">
            <form method="GET" action="{{ route('sparepart-branches.index') }}">
                <input type="hidden" name="branch_id" value="{{ $currentBranch->id }}">
                <label for="q" class="form-label">Cari</label>
                <input type="text" name="q" id="q" value="{{ request('q') }}" class="form-control" placeholder="Kode atau nama sparepart">
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Rak</th>
                        <th>Harga Jual</th>
                        <th>Stok Min</th>
                        <th>Stok Tersedia</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sparepartBranches as $sparepartBranch)
                        <tr>
                            <td><code>{{ $sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $sparepartBranch->sparepart->name }}</td>
                            <td>{{ $sparepartBranch->rack_number ?? '-' }}</td>
                            <td>{{ number_format($sparepartBranch->selling_price, 0, ',', '.') }}</td>
                            <td>{{ number_format($sparepartBranch->minimum_stock, 0, ',', '.') }}</td>
                            <td>{{ number_format($sparepartBranch->stock->available_qty, 0, ',', '.') }}</td>
                            <td>
                                @if ($sparepartBranch->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('update', $sparepartBranch)
                                    <a href="{{ route('sparepart-branches.edit', $sparepartBranch) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">Belum ada sparepart di cabang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $sparepartBranches->links() }}
    </div>
@endsection
