@extends('layouts.app')
@section('title', 'Kendaraan')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-car-front me-2"></i>Kendaraan</h1>
        @can('vehicle.create')
            <a href="{{ route('vehicles.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Tambah Kendaraan
            </a>
        @endcan
    </div>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-4">
            <select name="customer_id" class="form-select form-select-sm">
                <option value="">-- Semua Customer --</option>
                @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}" {{ (int) request('customer_id') === $customer->id ? 'selected' : '' }}>{{ $customer->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <input type="text" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Cari no. polisi/rangka/mesin">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Cari</button>
        </div>
    </form>

    <div class="card">
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
                        <tr><td colspan="5" class="text-center text-muted py-4">Belum ada kendaraan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $vehicles->links() }}
    </div>
@endsection
