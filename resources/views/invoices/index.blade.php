@extends('layouts.app')
@section('title', 'Invoice')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-receipt"></i></span>
            <div>
                <p class="eyebrow mb-1">Operasional</p>
                <h1 class="h3 mb-1">Invoice</h1>
                <p class="text-muted mb-0">Kelola invoice dan penagihan customer.</p>
            </div>
        </div>
        @if (auth()->user()->branchesWithPermission('invoice.create')->isNotEmpty())
            <div class="heading-actions">
                <a href="{{ route('invoices.createDirect') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> Invoice Langsung (DS)
                </a>
            </div>
        @endif
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor invoice...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'extraFilterHtml' => view('invoices._status_filter_select', ['selectedStatus' => $selectedStatus])->render(),
        'actionsHtml' => '',
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
                        <th>Total</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr>
                            <td><code>{{ $invoice->number }}</code></td>
                            <td>{{ $invoice->branch->name }}</td>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                            <td>{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                            <td>
                                @if ($invoice->status === \App\Support\InvoiceStatus::DRAFT)
                                    <span class="status-dot status-inactive">Draft</span>
                                @elseif ($invoice->status === \App\Support\InvoiceStatus::POSTED)
                                    <span class="status-dot status-active">Diposting</span>
                                @elseif ($invoice->status === \App\Support\InvoiceStatus::PARTIALLY_PAID)
                                    <span class="status-dot status-warning">Dibayar Sebagian</span>
                                @elseif ($invoice->status === \App\Support\InvoiceStatus::PAID)
                                    <span class="status-dot status-active">Lunas</span>
                                @else
                                    <span class="status-dot status-danger">Dibatalkan</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-receipt',
                                    'title' => 'Belum ada invoice',
                                    'description' => 'Invoice dibuat dari PKB yang sudah ditandai selesai.',
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
        {{ $invoices->links() }}
    </div>
@endsection
