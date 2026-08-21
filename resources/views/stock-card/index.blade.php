@extends('layouts.app')
@section('title', 'Kartu Stok')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-card-list"></i></span>
            <div>
                <p class="eyebrow mb-1">Persediaan</p>
                <h1 class="h3 mb-1">Kartu Stok</h1>
                <p class="text-muted mb-0">Pantau riwayat pergerakan stok sparepart.</p>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('stock-card.index') }}" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Cabang</label>
                    <select name="branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        @foreach ($allowedBranches as $branch)
                            <option value="{{ $branch->id }}" {{ $branch->id === $currentBranch->id ? 'selected' : '' }}>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small mb-1">Sparepart</label>
                    <select name="sparepart_id" id="sparepartSelect" class="form-select form-select-sm" onchange="this.form.submit()">
                        @forelse ($spareparts as $sparepart)
                            <option value="{{ $sparepart->id }}" {{ $selectedSparepart && $sparepart->id === $selectedSparepart->id ? 'selected' : '' }}>
                                {{ $sparepart->code }} &mdash; {{ $sparepart->name }}
                            </option>
                        @empty
                            <option value="">Belum ada sparepart di cabang ini</option>
                        @endforelse
                    </select>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    (function () {
        const sparepartSelect = document.getElementById('sparepartSelect');
        $(sparepartSelect).select2({ placeholder: '-- Pilih Sparepart --', width: '100%' });

        // Select2 replaces the native <select>'s change semantics with its own jQuery
        // events — a Select2-driven selection never fires a native `change` event, so
        // the inline onchange="this.form.submit()" attribute on this element would
        // silently never run. Re-trigger it explicitly, matching the same pattern
        // already used in payment-receipts/create.blade.php for its own Select2 picker.
        $(sparepartSelect).on('select2:select', function () {
            sparepartSelect.dispatchEvent(new Event('change'));
        });
    })();
    </script>
    @endpush

    @if ($selectedSparepart)
        <div class="row g-3 mb-3">
            <div class="col-sm-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-value">{{ number_format($stat['onHand'], 0, ',', '.') }}</div>
                        <div class="stat-label">Stok Fisik</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-value">{{ number_format($stat['reserved'], 0, ',', '.') }}</div>
                        <div class="stat-label">Stok Reservasi</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" style="color: var(--color-success);">{{ number_format($stat['available'], 0, ',', '.') }}</div>
                        <div class="stat-label">Stok Tersedia</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <th>Tipe Mutasi</th>
                            <th>Referensi</th>
                            <th class="text-end">Masuk</th>
                            <th class="text-end">Keluar</th>
                            <th class="text-end">Saldo Akhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($movements as $row)
                            <tr>
                                <td>{{ $row['movement_at']->format('d/m/Y H:i') }}</td>
                                <td><span class="status-dot status-active">{{ $row['type_label'] }}</span></td>
                                <td>
                                    @if ($row['reference']['route'])
                                        <a href="{{ $row['reference']['route'] }}"><code>{{ $row['reference']['number'] }}</code></a>
                                    @else
                                        <code>{{ $row['reference']['number'] }}</code>
                                    @endif
                                </td>
                                <td class="text-end">{{ $row['qty_in'] > 0 ? number_format($row['qty_in'], 0, ',', '.') : '-' }}</td>
                                <td class="text-end">{{ $row['qty_out'] > 0 ? number_format($row['qty_out'], 0, ',', '.') : '-' }}</td>
                                <td class="text-end">{{ number_format($row['balance_after'], 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-0">
                                    @include('partials.empty-state', [
                                        'icon' => 'bi-card-list',
                                        'title' => 'Belum ada riwayat mutasi',
                                        'description' => 'Sparepart ini belum pernah mengalami mutasi stok di cabang ini.',
                                        'ctaVisible' => false,
                                    ])
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">
            {{ $movements->links() }}
        </div>
    @else
        @include('partials.empty-state', [
            'icon' => 'bi-box-seam',
            'title' => 'Belum ada sparepart di cabang ini',
            'description' => 'Tidak ada sparepart yang dikonfigurasi di cabang ini untuk ditampilkan kartu stoknya.',
            'ctaVisible' => false,
        ])
    @endif
@endsection
