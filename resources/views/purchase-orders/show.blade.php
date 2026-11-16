@extends('layouts.app')
@section('title', 'Detail Pembelian Barang')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-cart-check"></i></span>
            <div>
                <p class="eyebrow mb-1">Pembelian Barang</p>
                <h1 class="h3 mb-1">{{ $purchaseOrder->number }}</h1>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('purchase-orders.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
            @can('print', $purchaseOrder)
                <a href="{{ route('purchase-orders.print', $purchaseOrder) }}" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Cetak PO</a>
            @endcan
            @if (in_array($purchaseOrder->status, \App\Support\PurchaseOrderStatus::RECEIVABLE, true) && auth()->user()->hasPermissionToInBranch('receipt.create', $purchaseOrder->branch_id))
                <a href="{{ route('goods-receipts.create', ['purchase_order_id' => $purchaseOrder->id]) }}" class="btn btn-primary btn-sm"><i class="bi bi-truck"></i> Terima Barang</a>
            @endif
            @can('cancel', $purchaseOrder)
                <form method="POST" action="{{ route('purchase-orders.cancel', $purchaseOrder) }}" class="d-inline" onsubmit="return confirm('Batalkan pembelian ini? Tindakan ini tidak dapat dibatalkan.');">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-outline-danger btn-sm">Batalkan</button>
                </form>
            @endcan
            @can('issue', $purchaseOrder)
                <form method="POST" action="{{ route('purchase-orders.issue', $purchaseOrder) }}" class="d-inline" onsubmit="this.querySelector('button').disabled = true;">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Terbitkan</button>
                </form>
            @endcan
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row g-3 small">
                        <div class="col-md-3"><strong>Supplier</strong><div>{{ $purchaseOrder->supplier->name }}</div><div class="text-muted">No. Rekening: {{ $purchaseOrder->supplier_account_number ?? '-' }}</div></div>
                        <div class="col-md-3"><strong>Cabang Tujuan</strong><div>{{ $purchaseOrder->branch->name }}</div></div>
                        <div class="col-md-3"><strong>Tanggal PO</strong><div>{{ $purchaseOrder->order_date->format('d/m/Y') }}</div></div>
                        <div class="col-md-3"><strong>Status</strong><div>@include('purchase-orders._status_badge', ['status' => $purchaseOrder->status])</div></div>
                        <div class="col-md-3">
                            <strong>Sumber Permintaan</strong>
                            <div>
                                @can('view', $purchaseOrder->purchaseRequest)
                                    <a href="{{ route('purchase-requests.show', $purchaseOrder->purchaseRequest) }}"><code>{{ $purchaseOrder->purchaseRequest->number }}</code></a>
                                @else
                                    <code>{{ $purchaseOrder->purchaseRequest->number }}</code>
                                @endcan
                            </div>
                        </div>
                        <div class="col-md-3">
                            <strong>Diterbitkan oleh</strong>
                            <div>{{ $purchaseOrder->issued_at ? optional($purchaseOrder->issuer)->name . ' · ' . $purchaseOrder->issued_at->format('d/m/Y H:i') : '-' }}</div>
                        </div>
                        <div class="col-md-3"><strong>Total</strong><div class="fw-bold">Rp {{ number_format($purchaseOrder->total_amount, 0, ',', '.') }}</div></div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <h2 class="section-title mb-2"><i class="bi bi-list-check"></i><span>Rincian &amp; Progres Penerimaan</span></h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Sparepart</th>
                                <th class="text-end">Dipesan</th>
                                <th class="text-end">Diterima</th>
                                <th class="text-end">Sisa</th>
                                <th style="width: 120px;">Progres</th>
                                <th class="text-end">Harga</th>
                                <th class="text-end">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($purchaseOrder->lines as $line)
                                @php($percent = (float) $line->qty_ordered > 0 ? (int) round(((float) $line->qty_received / (float) $line->qty_ordered) * 100) : 0)
                                <tr>
                                    <td><code>{{ $line->sparepartBranch->sparepart->code }}</code></td>
                                    <td>{{ $line->sparepartBranch->sparepart->name }}</td>
                                    <td class="text-end">{{ number_format($line->qty_ordered, 0, ',', '.') }}</td>
                                    <td class="text-end">{{ number_format($line->qty_received, 0, ',', '.') }}</td>
                                    <td class="text-end {{ $line->remainingQty() > 0 ? 'fw-bold' : '' }}">{{ number_format($line->remainingQty(), 0, ',', '.') }}</td>
                                    <td><div class="progress" style="height: 8px;"><div class="progress-bar {{ $percent >= 100 ? 'bg-success' : 'bg-warning' }}" style="width: {{ $percent }}%"></div></div></td>
                                    <td class="text-end">{{ number_format($line->unit_price, 0, ',', '.') }}</td>
                                    <td class="text-end">{{ number_format($line->line_total, 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="panel">
                <h2 class="section-title mb-2"><i class="bi bi-truck"></i><span>Penerimaan</span></h2>
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                        @forelse ($purchaseOrder->goodsReceipts as $receipt)
                            <tr>
                                <td>
                                    @can('view', $receipt)
                                        <a href="{{ route('goods-receipts.show', $receipt) }}"><code>{{ $receipt->number }}</code></a>
                                    @else
                                        <code>{{ $receipt->number }}</code>
                                    @endcan
                                    <div class="text-muted small">{{ $receipt->receipt_date->format('d/m/Y') }}{{ $receipt->reference_number ? ' · ' . $receipt->reference_number : '' }}</div>
                                </td>
                                <td class="text-end">
                                    @if ($receipt->status === \App\Support\GoodsReceiptStatus::POSTED)
                                        <span class="status-dot status-active">Diposting</span>
                                    @elseif ($receipt->status === \App\Support\GoodsReceiptStatus::CANCELLED)
                                        <span class="status-dot status-inactive">Dibatalkan</span>
                                    @else
                                        <span class="status-dot status-inactive">Draft</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="text-muted">Belum ada penerimaan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                @php($orderedTotal = (float) $purchaseOrder->lines->sum('qty_ordered'))
                @if ($orderedTotal > 0)
                    <div class="small text-muted mt-2">
                        Diterima {{ number_format((float) $purchaseOrder->lines->sum('qty_received'), 0, ',', '.') }} / {{ number_format($orderedTotal, 0, ',', '.') }} unit
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
