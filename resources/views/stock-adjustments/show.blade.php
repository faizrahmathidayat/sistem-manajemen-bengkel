@extends('layouts.app')
@section('title', 'Detail Stock Adjustment')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-sliders"></i></span>
            <div>
                <p class="eyebrow mb-1">Stock Adjustment</p>
                <h1 class="h3 mb-1">{{ $stockAdjustment->number }}</h1>
            </div>
        </div>
        <div class="heading-actions">
            @can('update', $stockAdjustment)
                <a href="{{ route('stock-adjustments.edit', $stockAdjustment) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
            @can('submit', $stockAdjustment)
                <form method="POST" action="{{ route('stock-adjustments.submit', $stockAdjustment) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-outline-primary btn-sm">Ajukan</button>
                </form>
            @endcan
            @can('approve', $stockAdjustment)
                <form method="POST" action="{{ route('stock-adjustments.approve', $stockAdjustment) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Setujui</button>
                </form>
            @endcan
            @can('post', $stockAdjustment)
                <form method="POST" action="{{ route('stock-adjustments.post', $stockAdjustment) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Posting</button>
                </form>
            @endcan
            @can('cancel', $stockAdjustment)
                <form method="POST" action="{{ route('stock-adjustments.cancel', $stockAdjustment) }}" class="d-inline">
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
                <div class="col-md-3"><strong>Cabang</strong><div>{{ $stockAdjustment->branch->name }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $stockAdjustment->adjustment_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>@include('stock-adjustments._status_badge', ['status' => $stockAdjustment->status])</div>
                </div>
                <div class="col-md-3">
                    <strong>Disetujui</strong>
                    <div>
                        @if ($stockAdjustment->approved_at)
                            {{ $stockAdjustment->approvedBy->name ?? '-' }} pada {{ $stockAdjustment->approved_at->format('d/m/Y H:i') }}
                        @else
                            -
                        @endif
                    </div>
                </div>
                <div class="col-md-12"><strong>Alasan</strong><div>{{ $stockAdjustment->reason }}</div></div>
                <div class="col-md-12"><strong>Catatan</strong><div>{{ $stockAdjustment->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Baris Penyesuaian</h2>
            <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Kode</th><th>Nama</th><th>Qty Sistem</th><th>Qty Fisik</th><th>Selisih</th><th>Alasan</th></tr></thead>
                <tbody>
                    @forelse ($stockAdjustment->lines as $line)
                        <tr>
                            <td><code>{{ $line->sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $line->sparepartBranch->sparepart->name }}</td>
                            <td>{{ number_format($line->system_qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->physical_qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->adjustment_qty, 0, ',', '.') }}</td>
                            <td>{{ $line->reason }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">Tidak ada baris penyesuaian.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <a href="{{ route('stock-adjustments.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
