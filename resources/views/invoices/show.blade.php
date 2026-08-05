@extends('layouts.app')
@section('title', 'Detail Invoice')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-receipt me-2"></i>{{ $invoice->number }}</h1>
        <div class="d-flex gap-2">
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><strong>Cabang</strong><div>{{ $invoice->branch->name }}</div></div>
                <div class="col-md-3"><strong>Customer</strong><div>{{ $invoice->customer->name }}</div></div>
                <div class="col-md-3"><strong>PKB</strong><div>{{ $invoice->workOrder->number }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $invoice->invoice_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>
                        @if ($invoice->status === \App\Support\InvoiceStatus::DRAFT)
                            <span class="status-dot status-active">Draft</span>
                        @elseif ($invoice->status === \App\Support\InvoiceStatus::POSTED)
                            <span class="status-dot status-active">Diposting</span>
                        @else
                            <span class="status-dot status-inactive">Dibatalkan</span>
                        @endif
                    </div>
                </div>
                <div class="col-md-12"><strong>Catatan</strong><div>{{ $invoice->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Baris Invoice</h2>
            <table class="table table-sm">
                <thead><tr><th>Tipe</th><th>Kode</th><th>Deskripsi</th><th>Qty</th><th>Harga</th><th>Total</th></tr></thead>
                <tbody>
                    @forelse ($invoice->details as $detail)
                        <tr>
                            <td>{{ $detail->item_type === \App\Support\InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart' }}</td>
                            <td><code>{{ $detail->item_code_snapshot ?? '-' }}</code></td>
                            <td>{{ $detail->description }}</td>
                            <td>{{ number_format($detail->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                            <td>{{ number_format($detail->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">Tidak ada baris invoice.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Ringkasan</h2>
            <div class="row g-2">
                <div class="col-md-3"><strong>Subtotal Jasa</strong><div>{{ number_format($invoice->subtotal_service, 0, ',', '.') }}</div></div>
                <div class="col-md-3"><strong>Subtotal Sparepart</strong><div>{{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}</div></div>
                <div class="col-md-3"><strong>Diskon ({{ number_format($invoice->discount_percent, 2, ',', '.') }}%)</strong><div>{{ number_format($invoice->discount_amount, 0, ',', '.') }}</div></div>
                <div class="col-md-3"><strong>PPN ({{ number_format($invoice->tax_percent, 2, ',', '.') }}%)</strong><div>{{ number_format($invoice->tax_amount, 0, ',', '.') }}</div></div>
                <div class="col-md-3"><strong>Grand Total</strong><div>{{ number_format($invoice->grand_total, 0, ',', '.') }}</div></div>
            </div>
        </div>
    </div>

    <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
