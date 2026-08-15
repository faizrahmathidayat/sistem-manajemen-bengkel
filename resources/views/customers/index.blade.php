@extends('layouts.app')
@section('title', 'Customer')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-person-badge"></i></span>
            <div>
                <p class="eyebrow mb-1">Master Data</p>
                <h1 class="h3 mb-1">Customer</h1>
                <p class="text-muted mb-0">Kelola data customer.</p>
            </div>
        </div>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nama atau telepon...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => auth()->user()->can('customer.create')
            ? '<a href="' . route('customers.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah Customer</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nama</th>
                        <th>Tipe</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr>
                            <td>{{ $customer->name }}</td>
                            <td>{{ $customer->customer_type === 'COMPANY' ? 'Perusahaan' : 'Perorangan' }}</td>
                            <td>{{ $customer->phone ?? '-' }}</td>
                            <td>
                                @if ($customer->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('customers.show', $customer) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-gear"></i> Kelola
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-0">
                                {{-- bi-person-badge also renders in the page header above, so
                                     assertSee('bi-person-badge') alone can't prove this empty
                                     state specifically rendered — pair it with an assertion on
                                     the empty-state text/CTA instead. --}}
                                @include('partials.empty-state', [
                                    'icon' => 'bi-person-badge',
                                    'title' => 'Belum ada customer',
                                    'description' => 'Mulai dengan menambahkan customer pertama Anda.',
                                    'ctaRoute' => 'customers.create',
                                    'ctaLabel' => '+ Tambah Customer Pertama',
                                    'ctaPermission' => 'customer.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $customers->links() }}
    </div>
@endsection
