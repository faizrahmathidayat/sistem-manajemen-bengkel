@extends('layouts.app')
@section('title', 'Jasa Service')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-tools"></i></span>
            <div>
                <p class="eyebrow mb-1">Master Data</p>
                <h1 class="h3 mb-1">Jasa Service</h1>
                <p class="text-muted mb-0">Kelola katalog jasa service dan harga default.</p>
            </div>
        </div>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari kode atau nama jasa...',
        'searchValue' => $search,
        'branchFilterBranches' => null,
        'branchFilterSelected' => [],
        'actionsHtml' => auth()->user()->can('service.create')
            ? '<a href="' . route('service-catalogs.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah Jasa</a>'
            : '',
    ])

    <div class="card mt-3">
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
                        <tr>
                            <td colspan="5" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-tools',
                                    'title' => 'Belum ada jasa service',
                                    'description' => 'Mulai dengan menambahkan jasa service pertama.',
                                    'ctaRoute' => 'service-catalogs.create',
                                    'ctaLabel' => '+ Tambah Jasa Pertama',
                                    'ctaPermission' => 'service.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $serviceCatalogs->links() }}
    </div>
@endsection
