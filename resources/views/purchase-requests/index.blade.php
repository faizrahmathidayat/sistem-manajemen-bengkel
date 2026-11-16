@extends('layouts.app')
@section('title', 'Permintaan Barang')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-clipboard-plus"></i></span>
            <div>
                <p class="eyebrow mb-1">Pembelian</p>
                <h1 class="h3 mb-1">Permintaan Barang</h1>
                <p class="text-muted mb-0">Permintaan sparepart dari cabang, penentuan supplier, dan approval.</p>
            </div>
        </div>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor permintaan...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'extraFilterHtml' => view('purchase-requests._status_filter_select', ['selectedStatus' => $selectedStatus])->render(),
        'actionsHtml' => auth()->user()->branchesWithPermission('purchase_request.create')->isNotEmpty()
            ? '<a href="' . route('purchase-requests.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Permintaan Baru</a>'
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
                        <th>Pemohon</th>
                        <th class="text-end">Item</th>
                        <th class="text-end">Total (Rp)</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($purchaseRequests as $purchaseRequest)
                        <tr>
                            <td><code>{{ $purchaseRequest->number }}</code> @include('purchase-requests._urgent_badge', ['purchaseRequest' => $purchaseRequest])</td>
                            <td>{{ $purchaseRequest->branch->name }}</td>
                            <td>{{ $purchaseRequest->request_date->format('d/m/Y') }}</td>
                            <td>{{ optional($purchaseRequest->creator)->name ?? '-' }}</td>
                            <td class="text-end">{{ $purchaseRequest->lines_count }}</td>
                            <td class="text-end">
                                @php($total = $purchaseRequest->total_amount !== null ? (float) $purchaseRequest->total_amount : (float) $purchaseRequest->lines_amount)
                                {{ $total > 0 ? number_format($total, 0, ',', '.') : '—' }}
                            </td>
                            <td>@include('purchase-requests._status_badge', ['status' => $purchaseRequest->status])</td>
                            <td class="text-end">
                                <a href="{{ route('purchase-requests.show', $purchaseRequest) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-clipboard-plus',
                                    'title' => 'Belum ada permintaan barang',
                                    'description' => 'Mulai dengan membuat permintaan barang pertama.',
                                    'ctaRoute' => 'purchase-requests.create',
                                    'ctaLabel' => '+ Buat Permintaan Pertama',
                                    'ctaVisible' => auth()->user()->branchesWithPermission('purchase_request.create')->isNotEmpty(),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $purchaseRequests->links() }}
    </div>
@endsection
