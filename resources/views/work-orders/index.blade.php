@extends('layouts.app')
@section('title', 'Perintah Kerja Bengkel')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-clipboard-check me-2"></i>Perintah Kerja Bengkel</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor PKB...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => auth()->user()->branchesWithPermission('pkb.create')->isNotEmpty()
            ? '<a href="' . route('work-orders.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> PKB Baru</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nomor PKB</th>
                        <th>Cabang</th>
                        <th>Customer</th>
                        <th>Kendaraan</th>
                        <th>Mekanik</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($workOrders as $workOrder)
                        <tr>
                            <td><code>{{ $workOrder->number }}</code></td>
                            <td>{{ $workOrder->branch->name }}</td>
                            <td>{{ $workOrder->customer->name }}</td>
                            <td>{{ $workOrder->vehicle->plate_number ?? '-' }}</td>
                            <td>{{ $workOrder->mechanic->name }}</td>
                            <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                            <td>
                                @if ($workOrder->status === \App\Support\WorkOrderStatus::DRAFT)
                                    <span class="status-dot status-inactive">Draft</span>
                                @elseif ($workOrder->status === \App\Support\WorkOrderStatus::OPEN)
                                    <span class="status-dot status-active">Dikonfirmasi</span>
                                @elseif ($workOrder->status === \App\Support\WorkOrderStatus::SHORTAGE)
                                    <span class="status-dot status-warning">Kurang Stok</span>
                                @elseif ($workOrder->status === \App\Support\WorkOrderStatus::COMPLETED)
                                    <span class="status-dot status-active">Selesai</span>
                                @else
                                    <span class="status-dot status-danger">Dibatalkan</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('work-orders.show', $workOrder) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-clipboard-check',
                                    'title' => 'Belum ada PKB',
                                    'description' => 'Mulai dengan membuat PKB pertama.',
                                    'ctaRoute' => 'work-orders.create',
                                    'ctaLabel' => '+ Buat PKB Pertama',
                                    'ctaVisible' => auth()->user()->branchesWithPermission('pkb.create')->isNotEmpty(),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $workOrders->links() }}
    </div>
@endsection
