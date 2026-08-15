@extends('layouts.app')
@section('title', 'Detail Penerimaan Barang')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-truck"></i></span>
            <div>
                <p class="eyebrow mb-1">Penerimaan Barang</p>
                <h1 class="h3 mb-1">{{ $goodsReceipt->number }}</h1>
            </div>
        </div>
        <div class="heading-actions">
            @can('update', $goodsReceipt)
                <a href="{{ route('goods-receipts.edit', $goodsReceipt) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
            @can('post', $goodsReceipt)
                <form method="POST" action="{{ route('goods-receipts.post', $goodsReceipt) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Posting</button>
                </form>
            @endcan
            @can('cancel', $goodsReceipt)
                <form method="POST" action="{{ route('goods-receipts.cancel', $goodsReceipt) }}" class="d-inline">
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
                <div class="col-md-3"><strong>Cabang</strong><div>{{ $goodsReceipt->branch->name }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $goodsReceipt->receipt_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3"><strong>No. Referensi</strong><div>{{ $goodsReceipt->reference_number ?? '-' }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>
                        @if ($goodsReceipt->status === \App\Support\GoodsReceiptStatus::DRAFT)
                            <span class="status-dot status-active">Draft</span>
                        @elseif ($goodsReceipt->status === \App\Support\GoodsReceiptStatus::POSTED)
                            <span class="status-dot status-active">Diposting</span>
                        @else
                            <span class="status-dot status-inactive">Dibatalkan</span>
                        @endif
                    </div>
                </div>
                <div class="col-md-12"><strong>Catatan</strong><div>{{ $goodsReceipt->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Baris Sparepart</h2>
            <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Kode</th><th>Nama</th><th>Qty</th><th>Harga Beli</th><th>Total</th></tr></thead>
                <tbody>
                    @forelse ($goodsReceipt->lines as $line)
                        <tr>
                            <td><code>{{ $line->sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $line->sparepartBranch->sparepart->name }}</td>
                            <td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->purchase_price, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">Tidak ada baris sparepart.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <a href="{{ route('goods-receipts.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
