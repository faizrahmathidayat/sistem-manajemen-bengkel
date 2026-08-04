@extends('layouts.app')
@section('title', 'Detail PKB')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-clipboard-check me-2"></i>{{ $workOrder->number }}</h1>
        <div class="d-flex gap-2">
            @can('confirm', $workOrder)
                <form method="POST" action="{{ route('work-orders.confirm', $workOrder) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Konfirmasi</button>
                </form>
            @endcan
            @can('update', $workOrder)
                <a href="{{ route('work-orders.edit', $workOrder) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
            @can('cancel', $workOrder)
                <form method="POST" action="{{ route('work-orders.cancel', $workOrder) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-outline-danger btn-sm">Batalkan</button>
                </form>
            @endcan
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><strong>Cabang</strong><div>{{ $workOrder->branch->name }}</div></div>
                <div class="col-md-3"><strong>Customer</strong><div>{{ $workOrder->customer->name }}</div></div>
                <div class="col-md-3"><strong>Kendaraan</strong><div>{{ $workOrder->vehicle->plate_number ?? '-' }}</div></div>
                <div class="col-md-3"><strong>Mekanik</strong><div>{{ $workOrder->mechanic->name }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $workOrder->work_order_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3"><strong>Kilometer</strong><div>{{ $workOrder->odometer_km ?? '-' }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>
                        @if ($workOrder->status === \App\Support\WorkOrderStatus::DRAFT)
                            <span class="status-dot status-active">Draft</span>
                        @elseif ($workOrder->status === \App\Support\WorkOrderStatus::OPEN)
                            <span class="status-dot status-active">Dikonfirmasi</span>
                        @elseif ($workOrder->status === \App\Support\WorkOrderStatus::SHORTAGE)
                            <span class="status-dot status-inactive">Kurang Stok</span>
                        @else
                            <span class="status-dot status-inactive">Dibatalkan</span>
                        @endif
                    </div>
                </div>
                <div class="col-md-6"><strong>Catatan</strong><div>{{ $workOrder->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Baris Jasa</h2>
            <table class="table table-sm">
                <thead><tr><th>Deskripsi</th><th>Qty</th><th>Harga</th><th>Total</th></tr></thead>
                <tbody>
                    @forelse ($workOrder->serviceLines as $line)
                        <tr>
                            <td>{{ $line->description }}</td>
                            <td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->unit_price, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">Tidak ada baris jasa.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Baris Sparepart</h2>
            <table class="table table-sm">
                <thead><tr><th>Kode</th><th>Nama</th><th>Qty</th><th>Direservasi</th><th>Harga</th><th>Total</th></tr></thead>
                <tbody>
                    @forelse ($workOrder->sparepartLines as $line)
                        <tr>
                            <td><code>{{ $line->item_code_snapshot }}</code></td>
                            <td>{{ $line->item_name_snapshot }}</td>
                            <td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->reservations->where('status', 'active')->sum('qty'), 0, ',', '.') }}</td>
                            <td>{{ number_format($line->unit_price, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">Tidak ada baris sparepart.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($workOrder->status === \App\Support\WorkOrderStatus::SHORTAGE)
        <div class="card mb-3">
            <div class="card-body">
                @if ($workOrder->shortage_overridden_at)
                    <p class="mb-0">
                        <strong>Kekurangan stok disetujui</strong> oleh {{ optional($workOrder->shortageOverriddenBy)->name ?? '-' }}
                        pada {{ $workOrder->shortage_overridden_at->format('d/m/Y H:i') }}:
                        {{ $workOrder->shortage_override_reason }}
                    </p>
                @else
                    @can('overrideShortage', $workOrder)
                        <form method="POST" action="{{ route('work-orders.overrideShortage', $workOrder) }}">
                            @csrf
                            @method('PATCH')
                            <label for="reason" class="form-label"><strong>Override Kekurangan Stok</strong></label>
                            <textarea name="reason" id="reason" class="form-control @error('reason') is-invalid @enderror" rows="2" required></textarea>
                            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <button type="submit" class="btn btn-outline-warning btn-sm mt-2">Kirim Override</button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>
    @endif

    <a href="{{ route('work-orders.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
