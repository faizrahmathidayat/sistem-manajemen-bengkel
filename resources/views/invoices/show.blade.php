@extends('layouts.app')
@section('title', 'Detail Invoice')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-receipt me-2"></i>{{ $invoice->number }}</h1>
        <div class="d-flex gap-2">
            @can('update', $invoice)
                <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
            @can('post', $invoice)
                <form method="POST" action="{{ route('invoices.post', $invoice) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Posting</button>
                </form>
            @endcan
            @can('print', $invoice)
                <a href="{{ route('invoices.print', $invoice) }}" target="_blank" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-printer"></i> Cetak Invoice
                </a>
            @endcan
            @can('sendEmail', $invoice)
                <form method="POST" action="{{ route('invoices.send-email', $invoice) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-envelope"></i> Kirim Email
                    </button>
                </form>
            @endcan
            @can('shareWhatsapp', $invoice)
                @if ($waLink = \App\Support\WhatsAppInvoiceLinkBuilder::build($invoice))
                    <a href="{{ $waLink }}" target="_blank" class="btn btn-success btn-sm">
                        <i class="bi bi-whatsapp"></i> Kirim via WhatsApp
                    </a>
                @endif
            @endcan
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><strong>Cabang</strong><div>{{ $invoice->branch->name }}</div></div>
                <div class="col-md-3"><strong>Customer</strong><div>{{ $invoice->customer->name }}</div></div>
                <div class="col-md-3"><strong>PKB</strong><div>{{ optional($invoice->workOrder)->number ?? 'Direct Sales' }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $invoice->invoice_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3"><strong>Jatuh Tempo</strong><div>{{ optional($invoice->due_date)->format('d/m/Y') ?? '-' }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>
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
                <thead><tr><th>Tipe</th><th>Kode</th><th>Deskripsi</th><th>Qty</th><th>Harga</th><th>Diskon</th><th>Total</th></tr></thead>
                <tbody>
                    @forelse ($invoice->details as $detail)
                        <tr>
                            <td>{{ $detail->item_type === \App\Support\InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart' }}</td>
                            <td><code>{{ $detail->item_code_snapshot ?? '-' }}</code></td>
                            <td>{{ $detail->description }}</td>
                            <td>{{ number_format($detail->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                            <td>{{ $detail->discount_amount > 0 ? number_format($detail->discount_amount, 0, ',', '.') : '-' }}</td>
                            <td>{{ number_format($detail->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">Tidak ada baris invoice.</td></tr>
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
                <div class="col-md-3"><strong>Sudah Dibayar</strong><div>{{ number_format($invoice->paid_amount, 0, ',', '.') }}</div></div>
                <div class="col-md-3"><strong>Sisa Piutang</strong><div>{{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Riwayat Pembayaran</h2>
            <table class="table table-sm">
                <thead><tr><th>No. Pembayaran</th><th>Tanggal</th><th>Nominal Dialokasikan</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($invoice->allocations()->with('paymentReceipt')->get() as $allocation)
                        <tr>
                            <td><a href="{{ route('payment-receipts.show', $allocation->paymentReceipt) }}">{{ $allocation->paymentReceipt->number }}</a></td>
                            <td>{{ $allocation->paymentReceipt->payment_date->format('d/m/Y') }}</td>
                            <td>{{ number_format($allocation->allocated_amount, 0, ',', '.') }}</td>
                            <td>
                                @if ($allocation->paymentReceipt->status === \App\Support\PaymentReceiptStatus::VOID)
                                    <span class="status-dot status-danger">Void</span>
                                @else
                                    <span class="status-dot status-active">Posted</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">Belum ada pembayaran.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($invoice->cancelled_at)
        <div class="card mb-3">
            <div class="card-body">
                <p class="mb-0">
                    <strong>Invoice dibatalkan</strong> oleh {{ optional($invoice->cancelledBy)->name ?? '-' }}
                    pada {{ $invoice->cancelled_at->format('d/m/Y H:i') }}: {{ $invoice->cancel_reason }}
                </p>
            </div>
        </div>
    @elseif ($invoice->status === \App\Support\InvoiceStatus::DRAFT)
        @can('cancel', $invoice)
            <div class="card mb-3">
                <div class="card-body">
                    <form method="POST" action="{{ route('invoices.cancel', $invoice) }}">
                        @csrf
                        @method('PATCH')
                        <label for="reason" class="form-label"><strong>Batalkan Invoice</strong></label>
                        <textarea name="reason" id="reason" class="form-control @error('reason') is-invalid @enderror" rows="2" required></textarea>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-outline-danger btn-sm mt-2">Kirim Pembatalan</button>
                    </form>
                </div>
            </div>
        @endcan
    @endif

    <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
