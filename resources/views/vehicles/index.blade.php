@extends('layouts.app')
@section('title', 'Kendaraan')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-car-front me-2"></i>Kendaraan</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari no. polisi/rangka/mesin...',
        'searchValue' => $search,
        'branchFilterBranches' => null,
        'branchFilterSelected' => [],
        'extraFilterHtml' => view('vehicles._customer_filter_select', ['customers' => $customers, 'selectedCustomerId' => $selectedCustomerId])->render(),
        'actionsHtml' => auth()->user()->can('vehicle.create')
            ? '<a href="' . route('vehicles.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah Kendaraan</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Polisi</th>
                        <th>Customer</th>
                        <th>Kategori / Merk / Tipe</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vehicles as $vehicle)
                        <tr>
                            <td><code>{{ $vehicle->plate_number ?? '-' }}</code></td>
                            <td>{{ $vehicle->customer->name }}</td>
                            <td>{{ $vehicle->category->name }} / {{ $vehicle->brand->name }} / {{ $vehicle->type->name }}</td>
                            <td>
                                @if ($vehicle->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('vehicle.edit')
                                    <a href="{{ route('vehicles.edit', $vehicle) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-car-front',
                                    'title' => 'Belum ada kendaraan',
                                    'description' => 'Mulai dengan menambahkan kendaraan pertama.',
                                    'ctaRoute' => 'vehicles.create',
                                    'ctaLabel' => '+ Tambah Kendaraan Pertama',
                                    'ctaPermission' => 'vehicle.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $vehicles->links() }}
    </div>
@endsection
