@extends('layouts.app')
@section('title', 'Cabang')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-shop me-2"></i>Cabang</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari kode atau nama cabang...',
        'searchValue' => $search,
        'branchFilterBranches' => null,
        'branchFilterSelected' => [],
        'actionsHtml' => auth()->user()->can('branch.create')
            ? '<a href="' . route('branches.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah Cabang</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($branches as $branch)
                        <tr>
                            <td><code>{{ $branch->code }}</code></td>
                            <td>{{ $branch->name }}</td>
                            <td>{{ $branch->phone ?? '-' }}</td>
                            <td>
                                @if ($branch->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('branch.edit')
                                    <a href="{{ route('branches.edit', $branch) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-shop',
                                    'title' => 'Belum ada cabang',
                                    'description' => 'Mulai dengan menambahkan cabang pertama Anda.',
                                    'ctaRoute' => 'branches.create',
                                    'ctaLabel' => '+ Tambah Cabang Pertama',
                                    'ctaPermission' => 'branch.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $branches->links() }}
    </div>
@endsection
