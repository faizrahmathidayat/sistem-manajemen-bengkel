@extends('layouts.app')
@section('title', 'Stock Adjustment')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-sliders me-2"></i>Stock Adjustment</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor atau alasan...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => auth()->user()->branchesWithPermission('stock_adjustment.create')->isNotEmpty()
            ? '<a href="' . route('stock-adjustments.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Adjustment Baru</a>'
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
                        <th>Alasan</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stockAdjustments as $stockAdjustment)
                        <tr>
                            <td><code>{{ $stockAdjustment->number }}</code></td>
                            <td>{{ $stockAdjustment->branch->name }}</td>
                            <td>{{ $stockAdjustment->adjustment_date->format('d/m/Y') }}</td>
                            <td>{{ $stockAdjustment->reason }}</td>
                            <td>@include('stock-adjustments._status_badge', ['status' => $stockAdjustment->status])</td>
                            <td class="text-end">
                                <a href="{{ route('stock-adjustments.show', $stockAdjustment) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-sliders',
                                    'title' => 'Belum ada stock adjustment',
                                    'description' => 'Mulai dengan membuat stock adjustment pertama.',
                                    'ctaRoute' => 'stock-adjustments.create',
                                    'ctaLabel' => '+ Buat Adjustment Pertama',
                                    'ctaVisible' => auth()->user()->branchesWithPermission('stock_adjustment.create')->isNotEmpty(),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $stockAdjustments->links() }}
    </div>
@endsection
