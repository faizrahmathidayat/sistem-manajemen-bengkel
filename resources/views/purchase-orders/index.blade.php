@extends('layouts.app')
@section('title', 'Pembelian Barang')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-cart-check"></i></span>
            <div>
                <p class="eyebrow mb-1">Pembelian</p>
                <h1 class="h3 mb-1">Pembelian Barang</h1>
                <p class="text-muted mb-0">Pembelian dibuat otomatis dari Permintaan yang telah disetujui.</p>
            </div>
        </div>
    </div>

    @php($statusFilterHtml = view('purchase-orders._filters', ['selectedStatus' => $selectedStatus, 'selectedSupplier' => $selectedSupplier])->render())
    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor pembelian...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'extraFilterHtml' => $statusFilterHtml,
        'actionsHtml' => '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Pembelian</th>
                        <th>Tanggal</th>
                        <th>Supplier</th>
                        <th>Cabang</th>
                        <th>Asal Permintaan</th>
                        <th class="text-end">Total (Rp)</th>
                        <th style="width: 130px;">Diterima</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($purchaseOrders as $order)
                        @php($ordered = (float) $order->qty_ordered_sum)
                        @php($percent = $ordered > 0 ? (int) round(((float) $order->qty_received_sum / $ordered) * 100) : 0)
                        <tr>
                            <td><code>{{ $order->number }}</code></td>
                            <td>{{ $order->order_date->format('d/m/Y') }}</td>
                            <td>{{ $order->supplier->name }}</td>
                            <td>{{ $order->branch->name }}</td>
                            <td><code>{{ $order->purchaseRequest->number }}</code></td>
                            <td class="text-end">{{ number_format($order->total_amount, 0, ',', '.') }}</td>
                            <td>
                                <div class="progress" style="height: 7px;"><div class="progress-bar {{ $percent >= 100 ? 'bg-success' : 'bg-warning' }}" style="width: {{ $percent }}%"></div></div>
                                <span class="small text-muted">{{ $percent }}%</span>
                            </td>
                            <td>@include('purchase-orders._status_badge', ['status' => $order->status])</td>
                            <td class="text-end">
                                <a href="{{ route('purchase-orders.show', $order) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye"></i> Lihat</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-cart-check',
                                    'title' => 'Belum ada pembelian',
                                    'description' => 'Pembelian muncul otomatis setelah permintaan barang disetujui.',
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
        {{ $purchaseOrders->links() }}
    </div>
@endsection
