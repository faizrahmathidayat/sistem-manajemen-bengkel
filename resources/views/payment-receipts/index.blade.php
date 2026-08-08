@extends('layouts.app')
@section('title', 'Penerimaan Pembayaran')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-cash-coin me-2"></i>Penerimaan Pembayaran</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor pembayaran...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'extraFilterHtml' => view('payment-receipts._status_filter_select', ['selectedStatus' => $selectedStatus])->render(),
        'actionsHtml' => auth()->user()->branchesWithPermission('payment.create')->isNotEmpty()
            ? '<a href="' . route('payment-receipts.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Catat Pembayaran</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nomor</th>
                        <th>Cabang</th>
                        <th>Customer</th>
                        <th>Tanggal</th>
                        <th>Nominal</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($paymentReceipts as $paymentReceipt)
                        <tr>
                            <td><code>{{ $paymentReceipt->number }}</code></td>
                            <td>{{ $paymentReceipt->branch->name }}</td>
                            <td>{{ $paymentReceipt->customer->name }}</td>
                            <td>{{ $paymentReceipt->payment_date->format('d/m/Y') }}</td>
                            <td>{{ number_format($paymentReceipt->amount, 0, ',', '.') }}</td>
                            <td>
                                @if ($paymentReceipt->status === \App\Support\PaymentReceiptStatus::POSTED)
                                    <span class="status-dot status-active">Posted</span>
                                @else
                                    <span class="status-dot status-danger">Void</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('payment-receipts.show', $paymentReceipt) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-cash-coin',
                                    'title' => 'Belum ada pembayaran',
                                    'description' => 'Mulai dengan mencatat pembayaran pertama.',
                                    'ctaRoute' => 'payment-receipts.create',
                                    'ctaLabel' => '+ Catat Pembayaran Pertama',
                                    'ctaVisible' => auth()->user()->branchesWithPermission('payment.create')->isNotEmpty(),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $paymentReceipts->links() }}
    </div>
@endsection
