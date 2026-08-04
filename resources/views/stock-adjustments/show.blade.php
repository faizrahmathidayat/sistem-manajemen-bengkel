@extends('layouts.app')
@section('title', 'Detail Stock Adjustment')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-sliders me-2"></i>{{ $stockAdjustment->number }}</h1>
        <div class="d-flex gap-2">
            @can('update', $stockAdjustment)
                <a href="{{ route('stock-adjustments.edit', $stockAdjustment) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
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

    <a href="{{ route('stock-adjustments.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
