@extends('layouts.app')
@section('title', 'Detail Permintaan Barang')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-clipboard-plus"></i></span>
            <div>
                <p class="eyebrow mb-1">Permintaan Barang</p>
                <h1 class="h3 mb-1">{{ $purchaseRequest->number }}</h1>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('purchase-requests.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
            @can('update', $purchaseRequest)
                <a href="{{ route('purchase-requests.edit', $purchaseRequest) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
            @can('cancel', $purchaseRequest)
                <form method="POST" action="{{ route('purchase-requests.cancel', $purchaseRequest) }}" class="d-inline" onsubmit="return confirm('Batalkan permintaan ini?');">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-outline-danger btn-sm">Batalkan</button>
                </form>
            @endcan
            @can('submit', $purchaseRequest)
                <form method="POST" action="{{ route('purchase-requests.submit', $purchaseRequest) }}" class="d-inline" onsubmit="this.querySelector('button').disabled = true;">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm" {{ isset($submitPreview['steps']) ? '' : 'disabled' }}>
                        <i class="bi bi-send-check"></i> Ajukan Approval
                    </button>
                </form>
            @endcan
            @can('reject', $purchaseRequest)
                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal">
                    <i class="bi bi-x-circle"></i> Tolak
                </button>
            @endcan
            @can('approve', $purchaseRequest)
                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#approveModal">
                    <i class="bi bi-check-circle"></i> Setuju
                </button>
            @endcan
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3 small">
                <div class="col-md-2"><strong>Cabang</strong><div>{{ $purchaseRequest->branch->name }}</div></div>
                <div class="col-md-2"><strong>Tanggal</strong><div>{{ $purchaseRequest->request_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3"><strong>Pemohon</strong><div>{{ optional($purchaseRequest->creator)->name ?? '-' }}</div></div>
                <div class="col-md-2"><strong>Status</strong><div>@include('purchase-requests._status_badge', ['status' => $purchaseRequest->status])</div></div>
                <div class="col-md-3"><strong>Catatan</strong><div>{{ $purchaseRequest->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    @if ($purchaseRequest->status === \App\Support\PurchaseRequestStatus::REJECTED)
        <div class="alert alert-danger">
            <strong><i class="bi bi-x-octagon"></i> Permintaan ditolak.</strong>
            <div>Alasan: {{ $purchaseRequest->rejected_reason }}</div>
            <div class="small">Permintaan ini sudah tidak berlaku. Silakan buat permintaan baru.</div>
        </div>
    @endif

    @if ($purchaseRequest->approvals->isNotEmpty())
        @include('purchase-requests._approval_progress')
    @endif

    @if ($purchaseRequest->status === \App\Support\PurchaseRequestStatus::APPROVED)
        <div class="panel mb-3">
            <h2 class="section-title mb-2"><i class="bi bi-cart-check"></i><span>Pembelian yang Terbentuk</span></h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>No. Pembelian</th><th>Supplier</th><th class="text-end">Total</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($purchaseRequest->purchaseOrders as $order)
                            <tr>
                                <td><code>{{ $order->number }}</code></td>
                                <td>{{ $order->supplier->name }}</td>
                                <td class="text-end">{{ number_format($order->total_amount, 0, ',', '.') }}</td>
                                <td>{{ \App\Support\PurchaseOrderStatus::LABELS[$order->status] ?? $order->status }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">Belum ada pembelian.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @can('assignSupplier', $purchaseRequest)
        @include('purchase-requests._assign_form')
    @else
    <div class="panel">
        <h2 class="section-title mb-2"><i class="bi bi-list-check"></i><span>Rincian Barang</span></h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Sparepart</th>
                        <th class="text-end">Qty</th>
                        <th>Supplier</th>
                        <th class="text-end">Harga Beli</th>
                        <th class="text-end">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($purchaseRequest->lines as $line)
                        <tr>
                            <td><code>{{ $line->sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $line->sparepartBranch->sparepart->name }}</td>
                            <td class="text-end">{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ optional($line->supplier)->name ?? '-' }}</td>
                            <td class="text-end">{{ $line->unit_price !== null ? number_format($line->unit_price, 0, ',', '.') : '-' }}</td>
                            <td class="text-end">{{ $line->line_total !== null ? number_format($line->line_total, 0, ',', '.') : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                @php($pricedTotal = (float) $purchaseRequest->lines->sum('line_total'))
                @if ($pricedTotal > 0)
                    <tfoot>
                        <tr>
                            <td colspan="5" class="text-end fw-bold">Total</td>
                            <td class="text-end fw-bold">{{ number_format($pricedTotal, 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
    @endcan

    @can('approve', $purchaseRequest)
        <div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('purchase-requests.approve', $purchaseRequest) }}" class="modal-content" onsubmit="this.querySelector('[type=submit]').disabled = true;">
                    @csrf
                    @method('PATCH')
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-check-circle text-success"></i> Setujui Permintaan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small mb-2">Anda menyetujui <b>{{ $purchaseRequest->number }}</b> (Rp {{ number_format($purchaseRequest->total_amount, 0, ',', '.') }}).</p>
                        <label class="form-label" for="approveNote">Catatan (opsional)</label>
                        <textarea name="note" id="approveNote" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-circle"></i> Ya, Setujui</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    @can('reject', $purchaseRequest)
        <div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('purchase-requests.reject', $purchaseRequest) }}" class="modal-content" onsubmit="this.querySelector('[type=submit]').disabled = true;">
                    @csrf
                    @method('PATCH')
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-x-circle text-danger"></i> Tolak Permintaan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small mb-2">Anda menolak <b>{{ $purchaseRequest->number }}</b>. Permintaan yang ditolak <b>tidak dapat dilanjutkan</b>; pemohon harus membuat permintaan baru dari awal.</p>
                        <label class="form-label" for="rejectReason">Alasan Penolakan <span class="text-danger">*</span></label>
                        <textarea name="reason" id="rejectReason" class="form-control @error('reason') is-invalid @enderror" rows="3" maxlength="1000" required>{{ old('reason') }}</textarea>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-x-circle"></i> Ya, Tolak</button>
                    </div>
                </form>
            </div>
        </div>
        @if ($errors->has('reason'))
            @push('scripts')
            <script>document.addEventListener('DOMContentLoaded', function () { new bootstrap.Modal(document.getElementById('rejectModal')).show(); });</script>
            @endpush
        @endif
    @endcan
@endsection
