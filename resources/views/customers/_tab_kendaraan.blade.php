<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="text-muted small mb-0">Kendaraan milik customer ini.</p>
            @can('vehicle.create')
                <a href="{{ route('vehicles.create', ['customer_id' => $customer->id]) }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> Tambah Kendaraan
                </a>
            @endcan
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Polisi</th>
                        <th>Kategori / Merk / Tipe</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customer->vehicles as $vehicle)
                        <tr>
                            <td><code>{{ $vehicle->plate_number ?? '-' }}</code></td>
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
                        <tr><td colspan="4" class="text-center text-muted py-4">Belum ada kendaraan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
