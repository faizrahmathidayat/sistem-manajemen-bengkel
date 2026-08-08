@extends('layouts.app')
@section('title', 'Transfer Stock')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-arrow-left-right me-2"></i>Transfer Stock</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor transfer...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'extraFilterHtml' => view('stock-transfers._status_filter_select', ['selectedStatus' => $selectedStatus])->render(),
        'actionsHtml' => auth()->user()->branchesWithPermission('stock_transfer.create')->isNotEmpty()
            ? '<a href="' . route('stock-transfers.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Transfer Baru</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nomor</th>
                        <th>Cabang Asal</th>
                        <th>Cabang Tujuan</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stockTransfers as $stockTransfer)
                        <tr>
                            <td><code>{{ $stockTransfer->number }}</code></td>
                            <td>{{ $stockTransfer->fromBranch->name }}</td>
                            <td>{{ $stockTransfer->toBranch->name }}</td>
                            <td>{{ $stockTransfer->transfer_date->format('d/m/Y') }}</td>
                            <td>@include('stock-transfers._status_badge', ['status' => $stockTransfer->status])</td>
                            <td class="text-end">
                                <a href="{{ route('stock-transfers.show', $stockTransfer) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-arrow-left-right',
                                    'title' => 'Belum ada transfer stock',
                                    'description' => 'Mulai dengan membuat transfer stock pertama.',
                                    'ctaRoute' => 'stock-transfers.create',
                                    'ctaLabel' => '+ Buat Transfer Pertama',
                                    'ctaVisible' => auth()->user()->branchesWithPermission('stock_transfer.create')->isNotEmpty(),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $stockTransfers->links() }}
    </div>
@endsection
