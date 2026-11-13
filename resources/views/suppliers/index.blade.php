@extends('layouts.app')
@section('title', 'Supplier')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-shop-window"></i></span>
            <div>
                <p class="eyebrow mb-1">Master Data</p>
                <h1 class="h3 mb-1">Supplier</h1>
                <p class="text-muted mb-0">Kelola daftar supplier untuk pembelian sparepart.</p>
            </div>
        </div>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari kode / nama supplier...',
        'searchValue' => $search,
        'branchFilterBranches' => null,
        'branchFilterSelected' => [],
        'extraFilterHtml' => view('suppliers._status_filter_select', ['selectedStatus' => $selectedStatus])->render(),
        'actionsHtml' => auth()->user()->can('supplier.create')
            ? '<a href="' . route('suppliers.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Supplier Baru</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Kontak</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($suppliers as $supplier)
                        <tr>
                            <td><code>{{ $supplier->code }}</code></td>
                            <td>{{ $supplier->name }}</td>
                            <td>{{ $supplier->contact_person ?? '-' }}</td>
                            <td>{{ $supplier->phone ?? '-' }}</td>
                            <td>
                                @if ($supplier->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('supplier.edit')
                                    <a href="{{ route('suppliers.edit', $supplier) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-shop-window',
                                    'title' => 'Belum ada supplier',
                                    'description' => 'Mulai dengan menambahkan supplier pertama.',
                                    'ctaRoute' => 'suppliers.create',
                                    'ctaLabel' => '+ Tambah Supplier Pertama',
                                    'ctaPermission' => 'supplier.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $suppliers->links() }}
    </div>
@endsection
