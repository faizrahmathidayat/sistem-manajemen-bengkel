@extends('layouts.app')
@section('title', 'Rak')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-grid-3x3-gap"></i></span>
            <div>
                <p class="eyebrow mb-1">Master Data</p>
                <h1 class="h3 mb-1">Rak</h1>
                <p class="text-muted mb-0">Kelola rak penyimpanan sparepart.</p>
            </div>
        </div>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari kode rak...',
        'searchValue' => $search,
        'branchFilterBranches' => null,
        'branchFilterSelected' => [],
        'actionsHtml' => auth()->user()->can('rack.create')
            ? '<a href="' . route('racks.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah Rak</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($racks as $rack)
                        <tr>
                            <td><code>{{ $rack->code }}</code></td>
                            <td>
                                @if ($rack->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('rack.edit')
                                    <a href="{{ route('racks.edit', $rack) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-grid-3x3-gap',
                                    'title' => 'Belum ada rak',
                                    'description' => 'Mulai dengan menambahkan rak pertama.',
                                    'ctaRoute' => 'racks.create',
                                    'ctaLabel' => '+ Tambah Rak Pertama',
                                    'ctaPermission' => 'rack.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $racks->links() }}
    </div>
@endsection
