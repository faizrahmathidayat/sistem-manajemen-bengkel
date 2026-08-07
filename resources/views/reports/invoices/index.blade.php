@extends('layouts.app')
@section('title', 'Laporan Invoice')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-text me-2"></i>Laporan Invoice</h1>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.invoices.index') }}" id="invoiceReportFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Status Invoice</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Semua Status</option>
                        <option value="{{ \App\Support\InvoiceStatus::DRAFT }}" {{ $status === \App\Support\InvoiceStatus::DRAFT ? 'selected' : '' }}>Draft</option>
                        <option value="{{ \App\Support\InvoiceStatus::POSTED }}" {{ $status === \App\Support\InvoiceStatus::POSTED ? 'selected' : '' }}>Diposting</option>
                        <option value="{{ \App\Support\InvoiceStatus::PARTIALLY_PAID }}" {{ $status === \App\Support\InvoiceStatus::PARTIALLY_PAID ? 'selected' : '' }}>Dibayar Sebagian</option>
                        <option value="{{ \App\Support\InvoiceStatus::PAID }}" {{ $status === \App\Support\InvoiceStatus::PAID ? 'selected' : '' }}>Lunas</option>
                        <option value="{{ \App\Support\InvoiceStatus::CANCELLED }}" {{ $status === \App\Support\InvoiceStatus::CANCELLED ? 'selected' : '' }}>Dibatalkan</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Customer / No. Invoice</label>
                    <input type="text" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Cari customer atau No. Invoice...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tanggal Dari</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sampai</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tampilan</label>
                    <select name="mode" class="form-select form-select-sm">
                        <option value="rekap" {{ $mode === 'rekap' ? 'selected' : '' }}>Rekap</option>
                        <option value="detail" {{ $mode === 'detail' ? 'selected' : '' }}>Detail</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Terapkan Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_invoice, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Invoice</div>
                </div>
                <i class="bi bi-file-earmark-text stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_nominal, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Nominal Invoice</div>
                </div>
                <i class="bi bi-cash-stack stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_paid, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Terbayar</div>
                </div>
                <i class="bi bi-check-circle stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_remaining, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Sisa Piutang</div>
                </div>
                <i class="bi bi-file-earmark-minus stat-icon"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
        @if ($mode === 'detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Invoice</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Tipe Item</th>
                        <th>Nama Item</th>
                        <th>Qty</th>
                        <th>Harga Satuan</th>
                        <th>Subtotal Line</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        @php
                            switch ($invoice->status) {
                                case \App\Support\InvoiceStatus::DRAFT:
                                    $statusBadge = '<span class="status-dot status-inactive">Draft</span>';
                                    break;
                                case \App\Support\InvoiceStatus::POSTED:
                                    $statusBadge = '<span class="status-dot status-active">Diposting</span>';
                                    break;
                                case \App\Support\InvoiceStatus::PARTIALLY_PAID:
                                    $statusBadge = '<span class="status-dot status-warning">Dibayar Sebagian</span>';
                                    break;
                                case \App\Support\InvoiceStatus::PAID:
                                    $statusBadge = '<span class="status-dot status-active">Lunas</span>';
                                    break;
                                default:
                                    $statusBadge = '<span class="status-dot status-danger">Dibatalkan</span>';
                            }
                        @endphp
                        @forelse ($invoice->details as $detail)
                            <tr>
                                <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                                <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td>{{ $invoice->customer->name }}</td>
                                <td>{{ $detail->item_type === \App\Support\InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart' }}</td>
                                <td>{{ $detail->description }}</td>
                                <td>{{ number_format($detail->qty, 0, ',', '.') }}</td>
                                <td>{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                                <td>{{ number_format($detail->line_total, 0, ',', '.') }}</td>
                                <td>{!! $statusBadge !!}</td>
                            </tr>
                        @empty
                            <tr>
                                <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                                <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td>{{ $invoice->customer->name }}</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>{!! $statusBadge !!}</td>
                            </tr>
                        @endforelse
                    @empty
                        <tr>
                            <td colspan="9" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-text',
                                    'title' => 'Belum ada data invoice',
                                    'description' => 'Tidak ada invoice yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Invoice</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Subtotal Jasa</th>
                        <th>Subtotal Sparepart</th>
                        <th>Discount</th>
                        <th>Grand Total</th>
                        <th>Terbayar</th>
                        <th>Sisa Piutang</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr>
                            <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                            <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ number_format($invoice->subtotal_service, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->discount_amount, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->paid_amount, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</td>
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
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-text',
                                    'title' => 'Belum ada data invoice',
                                    'description' => 'Tidak ada invoice yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @endif
        </div>
    </div>

    <div class="mt-3">
        {{ $invoices->links() }}
    </div>

    @push('scripts')
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('invoiceReportFilterForm');
        if (!menu || !form) return;

        menu.addEventListener('click', function (event) { event.stopPropagation(); });

        const selectAll = document.getElementById('branchFilterSelectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
        }

        form.addEventListener('submit', function () {
            form.querySelectorAll('input[data-branch-hidden]').forEach(function (el) { el.remove(); });
            document.querySelectorAll('.branch-filter-checkbox:checked').forEach(function (checkbox) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'branch_ids[]';
                hidden.value = checkbox.value;
                hidden.setAttribute('data-branch-hidden', '1');
                form.appendChild(hidden);
            });
        });
    })();
    </script>
    @endpush
@endsection
