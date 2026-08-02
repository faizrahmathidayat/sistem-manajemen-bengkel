@extends('layouts.app')
@section('title', 'Jasa Service')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-tools me-2"></i>Jasa Service</h1>
        @can('service.create')
            <a href="{{ route('service-catalogs.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Tambah Jasa
            </a>
        @endcan
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Harga Default</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($serviceCatalogs as $serviceCatalog)
                        <tr>
                            <td><code>{{ $serviceCatalog->code }}</code></td>
                            <td>{{ $serviceCatalog->name }}</td>
                            <td>{{ number_format($serviceCatalog->default_price, 0, ',', '.') }}</td>
                            <td>
                                @if ($serviceCatalog->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('service.edit')
                                    <a href="{{ route('service-catalogs.edit', $serviceCatalog) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Belum ada jasa service.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $serviceCatalogs->links() }}
    </div>
@endsection
