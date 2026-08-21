@extends('layouts.app')
@section('title', 'Detail Invoice')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-receipt"></i></span>
            <div>
                <p class="eyebrow mb-1">Invoice</p>
                <h1 class="h3 mb-1">{{ $invoice->number }}</h1>
            </div>
        </div>
        <div class="heading-actions">
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
                <form method="POST" action="{{ route('invoices.send-email', $invoice) }}" class="d-inline" id="sendEmailForm">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm" id="sendEmailButton">
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
            @can('pay', $invoice)
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#payInvoiceModal">
                    <i class="bi bi-cash-coin"></i> Bayar
                </button>
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
            <div class="table-responsive">
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
            <div class="table-responsive">
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

    @can('pay', $invoice)
        <div class="modal fade" id="payInvoiceModal" tabindex="-1" aria-labelledby="payInvoiceModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form id="payInvoiceForm">
                        <div class="modal-header">
                            <h5 class="modal-title" id="payInvoiceModalLabel">Bayar Invoice {{ $invoice->number }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-danger d-none" id="payInvoiceGeneralError"></div>

                            <input type="hidden" name="branch_id" value="{{ $invoice->branch_id }}">
                            <input type="hidden" name="customer_id" value="{{ $invoice->customer_id }}">
                            <input type="hidden" name="allocations[0][invoice_id]" value="{{ $invoice->id }}">
                            <input type="hidden" name="allocations[0][allocated_amount]" id="payInvoiceAllocatedAmount" value="{{ $invoice->outstanding_amount }}">

                            <div class="mb-3">
                                <label class="form-label">Sisa Piutang</label>
                                <input type="text" class="form-control" value="{{ number_format($invoice->outstanding_amount, 0, ',', '.') }}" disabled>
                            </div>
                            <div class="mb-3">
                                <label for="payInvoiceDate" class="form-label">Tanggal Bayar</label>
                                <input type="date" name="payment_date" id="payInvoiceDate" class="form-control" value="{{ now()->format('Y-m-d') }}" required>
                                <div class="invalid-feedback" data-error-for="payment_date"></div>
                            </div>
                            <div class="mb-3">
                                <label for="payInvoiceMethod" class="form-label">Metode</label>
                                <select name="payment_method" id="payInvoiceMethod" class="form-select" required>
                                    @foreach ($paymentMethods as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback" data-error-for="payment_method"></div>
                            </div>
                            <div class="mb-3">
                                <label for="payInvoiceReference" class="form-label">No. Referensi</label>
                                <input type="text" name="reference_number" id="payInvoiceReference" class="form-control">
                                <div class="invalid-feedback" data-error-for="reference_number"></div>
                            </div>
                            <div class="mb-3">
                                <label for="payInvoiceAmount" class="form-label">Nominal Dibayar</label>
                                <input type="number" step="0.01" min="0.01" max="{{ $invoice->outstanding_amount }}" name="amount" id="payInvoiceAmount" class="form-control" value="{{ $invoice->outstanding_amount }}" required>
                                <div class="invalid-feedback" data-error-for="amount"></div>
                            </div>
                            <div class="mb-3">
                                <label for="payInvoiceNotes" class="form-label">Catatan</label>
                                <textarea name="notes" id="payInvoiceNotes" class="form-control" rows="2"></textarea>
                                <div class="invalid-feedback" data-error-for="notes"></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary" id="payInvoiceSubmit">
                                <span class="spinner-border spinner-border-sm d-none" id="payInvoiceSpinner" role="status" aria-hidden="true"></span>
                                Simpan Pembayaran
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        @push('scripts')
        <script>
        (function () {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            const modalEl = document.getElementById('payInvoiceModal');
            const form = document.getElementById('payInvoiceForm');
            const generalError = document.getElementById('payInvoiceGeneralError');
            const amountInput = document.getElementById('payInvoiceAmount');
            const allocatedAmountInput = document.getElementById('payInvoiceAllocatedAmount');
            const submitButton = document.getElementById('payInvoiceSubmit');
            const spinner = document.getElementById('payInvoiceSpinner');

            amountInput.addEventListener('input', function () {
                allocatedAmountInput.value = amountInput.value;
            });

            function clearErrors() {
                generalError.classList.add('d-none');
                generalError.textContent = '';
                form.querySelectorAll('.is-invalid').forEach(function (el) {
                    el.classList.remove('is-invalid');
                });
                form.querySelectorAll('[data-error-for]').forEach(function (el) {
                    el.textContent = '';
                });
            }

            function showErrors(errors) {
                Object.keys(errors).forEach(function (key) {
                    const message = errors[key][0];
                    const target = form.querySelector(`[data-error-for="${key}"]`);
                    if (target) {
                        target.textContent = message;
                        const input = form.querySelector(`[name="${key}"]`) || document.getElementById('payInvoiceAmount');
                        if (input) input.classList.add('is-invalid');
                    } else {
                        generalError.textContent = generalError.textContent ? generalError.textContent + ' ' + message : message;
                        generalError.classList.remove('d-none');
                    }
                });
            }

            modalEl.addEventListener('show.bs.modal', clearErrors);

            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                clearErrors();
                submitButton.disabled = true;
                spinner.classList.remove('d-none');

                try {
                    const response = await fetch(@json(route('payment-receipts.store')), {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                        },
                        body: new FormData(form),
                    });
                    const data = await response.json();

                    if (response.status === 422 && data.errors) {
                        showErrors(data.errors);
                        return;
                    }
                    if (!response.ok) {
                        generalError.textContent = data.message || 'Terjadi kesalahan.';
                        generalError.classList.remove('d-none');
                        return;
                    }

                    window.location.reload();
                } catch (error) {
                    generalError.textContent = 'Gagal menghubungi server. Silakan coba lagi.';
                    generalError.classList.remove('d-none');
                } finally {
                    submitButton.disabled = false;
                    spinner.classList.add('d-none');
                }
            });
        })();
        </script>
        @endpush
    @endcan

@endsection
