@extends('layouts.app')
@section('title', 'Mekanik')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-gear me-2"></i>Mekanik</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nama, telepon, atau NIP...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => auth()->user()->can('mechanic.create')
            ? '<a href="' . route('mechanics.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah Mekanik</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nama</th>
                        <th>NIP</th>
                        <th>Tanggal Bergabung</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mechanics as $mechanic)
                        <tr>
                            <td>{{ $mechanic->name }}</td>
                            <td>{{ $mechanic->nip ?? '-' }}</td>
                            <td>{{ $mechanic->join_date ? $mechanic->join_date->format('d/m/Y') : '-' }}</td>
                            <td>{{ $mechanic->phone ?? '-' }}</td>
                            <td>
                                @if ($mechanic->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('mechanics.show', $mechanic) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-gear"></i> Kelola
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-person-gear',
                                    'title' => 'Belum ada mekanik',
                                    'description' => 'Mulai dengan menambahkan mekanik pertama.',
                                    'ctaRoute' => 'mechanics.create',
                                    'ctaLabel' => '+ Tambah Mekanik Pertama',
                                    'ctaPermission' => 'mechanic.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $mechanics->links() }}
    </div>
@endsection
