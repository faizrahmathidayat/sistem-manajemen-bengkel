@extends('layouts.app')
@section('title', 'Detail Pembayaran')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-cash-coin me-2"></i>{{ $paymentReceipt->number }}</h1>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><strong>Cabang</strong><div>{{ $paymentReceipt->branch->name }}</div></div>
                <div class="col-md-3"><strong>Customer</strong><div>{{ $paymentReceipt->customer->name }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $paymentReceipt->payment_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>
                        @if ($paymentReceipt->status === \App\Support\PaymentReceiptStatus::POSTED)
                            <span class="status-dot status-active">Posted</span>
                        @else
                            <span class="status-dot status-danger">Void</span>
                        @endif
                    </div>
                </div>
                <div class="col-md-3"><strong>Metode</strong><div>{{ \App\Support\PaymentMethod::LABELS[$paymentReceipt->payment_method] ?? $paymentReceipt->payment_method }}</div></div>
                <div class="col-md-3"><strong>No. Referensi</strong><div>{{ $paymentReceipt->reference_number ?? '-' }}</div></div>
                <div class="col-md-3"><strong>Total Nominal</strong><div>{{ number_format($paymentReceipt->amount, 0, ',', '.') }}</div></div>
                <div class="col-md-12"><strong>Catatan</strong><div>{{ $paymentReceipt->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Alokasi Invoice</h2>
            <table class="table table-sm">
                <thead><tr><th>No. Invoice</th><th>Nominal Dialokasikan</th></tr></thead>
                <tbody>
                    @foreach ($paymentReceipt->allocations as $allocation)
                        <tr>
                            <td><a href="{{ route('invoices.show', $allocation->invoice) }}">{{ $allocation->invoice->number }}</a></td>
                            <td>{{ number_format($allocation->allocated_amount, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($paymentReceipt->voided_at)
        <div class="card mb-3">
            <div class="card-body">
                <p class="mb-0">
                    <strong>Pembayaran di-void</strong> oleh {{ optional($paymentReceipt->voidedBy)->name ?? '-' }}
                    pada {{ $paymentReceipt->voided_at->format('d/m/Y H:i') }}: {{ $paymentReceipt->void_reason }}
                </p>
            </div>
        </div>
    @elseif ($paymentReceipt->status === \App\Support\PaymentReceiptStatus::POSTED)
        @can('void', $paymentReceipt)
            <div class="card mb-3">
                <div class="card-body">
                    <form method="POST" action="{{ route('payment-receipts.void', $paymentReceipt) }}">
                        @csrf
                        @method('PATCH')
                        <label for="reason" class="form-label"><strong>Void Pembayaran</strong></label>
                        <textarea name="reason" id="reason" class="form-control @error('reason') is-invalid @enderror" rows="2" required></textarea>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-outline-danger btn-sm mt-2">Kirim Void</button>
                    </form>
                </div>
            </div>
        @endcan
    @endif

    <a href="{{ route('payment-receipts.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
