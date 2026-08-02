<div class="row g-2 mb-3">
    <div class="col-md-5">
        <select class="form-select form-select-sm" id="kartuStokSparepartSelect">
            @forelse ($kartuStok['spareparts'] as $sparepart)
                <option value="{{ $sparepart['id'] }}" {{ $sparepart['id'] === $kartuStok['selected']['id'] ? 'selected' : '' }}>
                    {{ $sparepart['code'] }} &mdash; {{ $sparepart['name'] }}
                </option>
            @empty
                <option value="">Belum ada sparepart di cabang terpilih</option>
            @endforelse
        </select>
    </div>
    <div class="col-md-4">
        <select class="form-select form-select-sm" disabled>
            <option>Semua Jenis Mutasi</option>
        </select>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-4">
        <div class="stat-card">
            <div>
                <div class="stat-value" id="kartuStokOnHand">{{ number_format($kartuStok['selected']['onHand'], 0, ',', '.') }}</div>
                <div class="stat-label">Stok Fisik</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card">
            <div>
                <div class="stat-value" id="kartuStokReserved">{{ number_format($kartuStok['selected']['reserved'], 0, ',', '.') }}</div>
                <div class="stat-label">Stok Reservasi</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card">
            <div>
                <div class="stat-value" id="kartuStokAvailable" style="color: var(--color-success);">{{ number_format($kartuStok['selected']['available'], 0, ',', '.') }}</div>
                <div class="stat-label">Stok Tersedia</div>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>Tanggal</th>
                <th>Tipe Mutasi</th>
                <th>Referensi</th>
                <th class="text-end">Masuk</th>
                <th class="text-end">Keluar</th>
                <th class="text-end">Reservasi</th>
                <th class="text-end">Saldo Akhir</th>
            </tr>
        </thead>
        <tbody id="kartuStokMutationsBody">
            @foreach ($kartuStok['mutations'] as $row)
                <tr>
                    <td>{{ $row['date'] }}</td>
                    <td><span class="status-dot status-active">{{ $row['type'] }}</span></td>
                    <td><code>{{ $row['reference'] }}</code></td>
                    <td class="text-end">{{ $row['in'] }}</td>
                    <td class="text-end">{{ $row['out'] }}</td>
                    <td class="text-end">{{ $row['reserved'] }}</td>
                    <td class="text-end">{{ $row['balance'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
