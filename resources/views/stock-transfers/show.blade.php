@extends('layouts.app')
@section('title', 'Detail Transfer Stock')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-arrow-left-right me-2"></i>{{ $stockTransfer->number }}</h1>
        <div class="d-flex gap-2">
            @can('update', $stockTransfer)
                <a href="{{ route('stock-transfers.edit', $stockTransfer) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
            @can('approve', $stockTransfer)
                <form method="POST" action="{{ route('stock-transfers.approve', $stockTransfer) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Setujui</button>
                </form>
            @endcan
            @can('dispatch', $stockTransfer)
                <form method="POST" action="{{ route('stock-transfers.dispatch', $stockTransfer) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Kirim</button>
                </form>
            @endcan
            @can('receive', $stockTransfer)
                <form method="POST" action="{{ route('stock-transfers.receive', $stockTransfer) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Terima</button>
                </form>
            @endcan
            @can('cancel', $stockTransfer)
                <form method="POST" action="{{ route('stock-transfers.cancel', $stockTransfer) }}" class="d-inline">
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
                <div class="col-md-3"><strong>Cabang Asal</strong><div>{{ $stockTransfer->fromBranch->name }}</div></div>
                <div class="col-md-3"><strong>Cabang Tujuan</strong><div>{{ $stockTransfer->toBranch->name }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $stockTransfer->transfer_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>@include('stock-transfers._status_badge', ['status' => $stockTransfer->status])</div>
                </div>
                <div class="col-md-4">
                    <strong>Disetujui</strong>
                    <div>
                        @if ($stockTransfer->approved_at)
                            {{ $stockTransfer->approvedBy->name ?? '-' }} pada {{ $stockTransfer->approved_at->format('d/m/Y H:i') }}
                        @else
                            -
                        @endif
                    </div>
                </div>
                <div class="col-md-4">
                    <strong>Dikirim</strong>
                    <div>
                        @if ($stockTransfer->dispatched_at)
                            {{ $stockTransfer->dispatchedBy->name ?? '-' }} pada {{ $stockTransfer->dispatched_at->format('d/m/Y H:i') }}
                        @else
                            -
                        @endif
                    </div>
                </div>
                <div class="col-md-4">
                    <strong>Diterima</strong>
                    <div>
                        @if ($stockTransfer->received_at)
                            {{ $stockTransfer->receivedBy->name ?? '-' }} pada {{ $stockTransfer->received_at->format('d/m/Y H:i') }}
                        @else
                            -
                        @endif
                    </div>
                </div>
                <div class="col-md-12"><strong>Catatan</strong><div>{{ $stockTransfer->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Baris Sparepart</h2>
            <table class="table table-sm">
                <thead><tr><th>Kode</th><th>Nama</th><th>Qty</th></tr></thead>
                <tbody>
                    @forelse ($stockTransfer->lines as $line)
                        <tr>
                            <td><code>{{ $line->sparepart->code }}</code></td>
                            <td>{{ $line->sparepart->name }}</td>
                            <td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-muted">Tidak ada baris sparepart.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <a href="{{ route('stock-transfers.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
