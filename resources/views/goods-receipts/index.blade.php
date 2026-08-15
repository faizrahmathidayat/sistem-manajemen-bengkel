@extends('layouts.app')
@section('title', 'Penerimaan Barang')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-truck"></i></span>
            <div>
                <p class="eyebrow mb-1">Persediaan</p>
                <h1 class="h3 mb-1">Penerimaan Barang</h1>
                <p class="text-muted mb-0">Kelola penerimaan barang dari supplier.</p>
            </div>
        </div>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor penerimaan...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'extraFilterHtml' => view('goods-receipts._status_filter_select', ['selectedStatus' => $selectedStatus])->render(),
        'actionsHtml' => auth()->user()->branchesWithPermission('receipt.create')->isNotEmpty()
            ? '<a href="' . route('goods-receipts.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Penerimaan Baru</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nomor</th>
                        <th>Cabang</th>
                        <th>Tanggal</th>
                        <th>No. Referensi</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($goodsReceipts as $goodsReceipt)
                        <tr>
                            <td><code>{{ $goodsReceipt->number }}</code></td>
                            <td>{{ $goodsReceipt->branch->name }}</td>
                            <td>{{ $goodsReceipt->receipt_date->format('d/m/Y') }}</td>
                            <td>{{ $goodsReceipt->reference_number ?? '-' }}</td>
                            <td>
                                @if ($goodsReceipt->status === \App\Support\GoodsReceiptStatus::DRAFT)
                                    <span class="status-dot status-active">Draft</span>
                                @elseif ($goodsReceipt->status === \App\Support\GoodsReceiptStatus::POSTED)
                                    <span class="status-dot status-active">Diposting</span>
                                @else
                                    <span class="status-dot status-inactive">Dibatalkan</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('goods-receipts.show', $goodsReceipt) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-truck',
                                    'title' => 'Belum ada penerimaan barang',
                                    'description' => 'Mulai dengan membuat penerimaan barang pertama.',
                                    'ctaRoute' => 'goods-receipts.create',
                                    'ctaLabel' => '+ Buat Penerimaan Pertama',
                                    'ctaVisible' => auth()->user()->branchesWithPermission('receipt.create')->isNotEmpty(),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $goodsReceipts->links() }}
    </div>
@endsection
