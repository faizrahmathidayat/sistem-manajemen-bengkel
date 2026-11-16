@extends('layouts.app')
@section('title', 'Approval Permintaan')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-patch-check"></i></span>
            <div>
                <p class="eyebrow mb-1">Pembelian</p>
                <h1 class="h3 mb-1">Approval Permintaan</h1>
                <p class="text-muted mb-0">Permintaan barang yang menunggu keputusan Anda, dan keputusan yang sudah Anda buat.</p>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'pending' ? 'active' : '' }}" href="{{ route('approvals.index') }}">
                Menunggu Saya
                @if ($pendingCount > 0)<span class="badge bg-danger">{{ $pendingCount }}</span>@endif
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'history' ? 'active' : '' }}" href="{{ route('approvals.index', ['tab' => 'history']) }}">Riwayat Saya</a>
        </li>
    </ul>

    @if ($tab === 'history')
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nomor</th>
                            <th>Cabang</th>
                            <th class="text-end">Total (Rp)</th>
                            <th>Level</th>
                            <th>Keputusan</th>
                            <th>Catatan</th>
                            <th>Waktu</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($decisions as $decision)
                            <tr>
                                <td><code>{{ $decision->purchaseRequest->number }}</code> @include('purchase-requests._urgent_badge', ['purchaseRequest' => $decision->purchaseRequest])</td>
                                <td>{{ $decision->purchaseRequest->branch->name }}</td>
                                <td class="text-end">{{ number_format($decision->purchaseRequest->total_amount, 0, ',', '.') }}</td>
                                <td>{{ $decision->level_name }}</td>
                                <td>
                                    <span class="status-dot {{ $decision->status === 'approved' ? 'status-active' : 'status-danger' }}">
                                        {{ \App\Support\PurchaseRequestApprovalStatus::LABELS[$decision->status] }}
                                    </span>
                                </td>
                                <td class="small">{{ $decision->notes ?? '-' }}</td>
                                <td class="small">{{ optional($decision->acted_at)->format('d/m/Y H:i') }}</td>
                                <td class="text-end">
                                    <a href="{{ route('purchase-requests.show', $decision->purchaseRequest) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye"></i> Lihat</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">Belum ada keputusan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">{{ $decisions->links() }}</div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nomor</th>
                            <th>Cabang</th>
                            <th>Pemohon</th>
                            <th class="text-end">Total (Rp)</th>
                            <th>Level Saya</th>
                            <th>Menunggu</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($waiting as $purchaseRequest)
                            <tr>
                                <td><code>{{ $purchaseRequest->number }}</code> @include('purchase-requests._urgent_badge', ['purchaseRequest' => $purchaseRequest])</td>
                                <td>{{ $purchaseRequest->branch->name }}</td>
                                <td>{{ optional($purchaseRequest->creator)->name ?? '-' }}</td>
                                <td class="text-end">{{ number_format($purchaseRequest->total_amount, 0, ',', '.') }}</td>
                                <td><span class="tag">{{ optional($purchaseRequest->approvals->firstWhere('status', 'pending'))->level_name }}</span></td>
                                <td class="small">{{ optional($purchaseRequest->submitted_at)->diffForHumans() }}</td>
                                <td class="text-end">
                                    <a href="{{ route('purchase-requests.show', $purchaseRequest) }}" class="btn btn-outline-primary btn-sm">Tinjau</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada permintaan yang menunggu keputusan Anda.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">{{ $waiting->links() }}</div>
    @endif
@endsection
