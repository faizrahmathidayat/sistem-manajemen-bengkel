@extends('layouts.app')
@section('title', 'Detail Permintaan Barang')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-clipboard-plus"></i></span>
            <div>
                <p class="eyebrow mb-1">Permintaan Barang</p>
                <h1 class="h3 mb-1">{{ $purchaseRequest->number }}</h1>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('purchase-requests.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
            @can('update', $purchaseRequest)
                <a href="{{ route('purchase-requests.edit', $purchaseRequest) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
            @can('cancel', $purchaseRequest)
                <form method="POST" action="{{ route('purchase-requests.cancel', $purchaseRequest) }}" class="d-inline" onsubmit="return confirm('Batalkan permintaan ini?');">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-outline-danger btn-sm">Batalkan</button>
                </form>
            @endcan
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3 small">
                <div class="col-md-2"><strong>Cabang</strong><div>{{ $purchaseRequest->branch->name }}</div></div>
                <div class="col-md-2"><strong>Tanggal</strong><div>{{ $purchaseRequest->request_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3"><strong>Pemohon</strong><div>{{ optional($purchaseRequest->creator)->name ?? '-' }}</div></div>
                <div class="col-md-2"><strong>Status</strong><div>@include('purchase-requests._status_badge', ['status' => $purchaseRequest->status])</div></div>
                <div class="col-md-3"><strong>Catatan</strong><div>{{ $purchaseRequest->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2 class="section-title mb-2"><i class="bi bi-list-check"></i><span>Rincian Barang</span></h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Sparepart</th>
                        <th class="text-end">Qty</th>
                        <th>Supplier</th>
                        <th class="text-end">Harga Beli</th>
                        <th class="text-end">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($purchaseRequest->lines as $line)
                        <tr>
                            <td><code>{{ $line->sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $line->sparepartBranch->sparepart->name }}</td>
                            <td class="text-end">{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ optional($line->supplier)->name ?? '-' }}</td>
                            <td class="text-end">{{ $line->unit_price !== null ? number_format($line->unit_price, 0, ',', '.') : '-' }}</td>
                            <td class="text-end">{{ $line->line_total !== null ? number_format($line->line_total, 0, ',', '.') : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                @php($pricedTotal = (float) $purchaseRequest->lines->sum('line_total'))
                @if ($pricedTotal > 0)
                    <tfoot>
                        <tr>
                            <td colspan="5" class="text-end fw-bold">Total</td>
                            <td class="text-end fw-bold">{{ number_format($pricedTotal, 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
@endsection
