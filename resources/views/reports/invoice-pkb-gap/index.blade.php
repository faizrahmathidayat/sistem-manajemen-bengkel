@extends('layouts.app')
@section('title', 'Laporan Gap Invoice vs PKB')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-bar-chart-steps"></i></span>
            <div>
                <p class="eyebrow mb-1">Reporting</p>
                <h1 class="h3 mb-1">PKB vs Invoice</h1>
                <p class="text-muted mb-0">Bandingkan selisih PKB selesai dengan invoice yang terbit.</p>
            </div>
        </div>
        <div class="heading-actions">
            @include('partials.report-export-buttons', [
                'excelRoute' => 'reports.invoice-pkb-gap.export-excel',
                'pdfPreviewRoute' => 'reports.invoice-pkb-gap.pdf-preview',
                'pdfDownloadRoute' => 'reports.invoice-pkb-gap.pdf-download',
            ])
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.invoice-pkb-gap.index') }}" id="invoicePkbGapFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Status Selisih</label>
                    <select name="gap_status" class="form-select form-select-sm">
                        <option value="ada_selisih" {{ $gapStatus === 'ada_selisih' ? 'selected' : '' }}>Ada Selisih</option>
                        <option value="invoice_gt_pkb" {{ $gapStatus === 'invoice_gt_pkb' ? 'selected' : '' }}>Invoice &gt; PKB</option>
                        <option value="invoice_lt_pkb" {{ $gapStatus === 'invoice_lt_pkb' ? 'selected' : '' }}>Invoice &lt; PKB</option>
                        <option value="sesuai" {{ $gapStatus === 'sesuai' ? 'selected' : '' }}>Sesuai</option>
                        <option value="semua" {{ $gapStatus === 'semua' ? 'selected' : '' }}>Semua</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Customer / No. PKB / No. Invoice</label>
                    <input type="text" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Cari...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tanggal Invoice Dari</label>
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
                    <div class="stat-value">{{ number_format($summary->total_transaksi, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Transaksi Terhubung</div>
                </div>
                <i class="bi bi-link-45deg stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_nilai_pkb, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Nilai PKB</div>
                </div>
                <i class="bi bi-clipboard-check stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_nilai_invoice, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Nilai Invoice</div>
                </div>
                <i class="bi bi-file-earmark-text stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_varian_netto, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Varian Netto</div>
                </div>
                <i class="bi bi-graph-up-arrow stat-icon"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
        @if ($mode === 'detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. PKB</th>
                        <th>No. Invoice</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Tipe Item</th>
                        <th>Nama Item</th>
                        <th>Qty PKB</th>
                        <th>Harga PKB</th>
                        <th>Qty Invoice</th>
                        <th>Harga Invoice</th>
                        <th>Kategori</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        @forelse ($invoice->comparisonLines as $line)
                            <tr>
                                <td><a href="{{ route('work-orders.show', $invoice->work_order_id) }}"><code>{{ $invoice->workOrder->number }}</code></a></td>
                                <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                                <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td>{{ $invoice->customer->name }}</td>
                                <td>{{ $line['item_type'] }}</td>
                                <td>{{ $line['item_name'] }}</td>
                                <td>{{ $line['pkb_qty'] !== null ? number_format($line['pkb_qty'], 0, ',', '.') : '—' }}</td>
                                <td>{{ $line['pkb_price'] !== null ? number_format($line['pkb_price'], 0, ',', '.') : '—' }}</td>
                                <td>{{ $line['invoice_qty'] !== null ? number_format($line['invoice_qty'], 0, ',', '.') : '—' }}</td>
                                <td>{{ $line['invoice_price'] !== null ? number_format($line['invoice_price'], 0, ',', '.') : '—' }}</td>
                                <td>
                                    @if ($line['category'] === 'sesuai')
                                        <span class="status-dot status-active">Sesuai</span>
                                    @elseif ($line['category'] === 'changed')
                                        <span class="status-dot status-warning">Berubah</span>
                                    @elseif ($line['category'] === 'removed')
                                        <span class="status-dot status-danger">Dihapus</span>
                                    @else
                                        <span class="status-dot status-warning">Ditambahkan</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td><a href="{{ route('work-orders.show', $invoice->work_order_id) }}"><code>{{ $invoice->workOrder->number }}</code></a></td>
                                <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                                <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td>{{ $invoice->customer->name }}</td>
                                <td colspan="7">&mdash;</td>
                            </tr>
                        @endforelse
                    @empty
                        <tr>
                            <td colspan="11" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-bar-chart-steps',
                                    'title' => 'Belum ada data transaksi',
                                    'description' => 'Tidak ada transaksi yang cocok dengan filter saat ini.',
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
                        <th>No. PKB</th>
                        <th>No. Invoice</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Total PKB</th>
                        <th>Total Invoice</th>
                        <th>Selisih (Rp)</th>
                        <th>Selisih (%)</th>
                        <th>Status Gap</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        @php
                            $pkbTotal = (float) $invoice->pkb_total;
                            $grandTotal = (float) $invoice->grand_total;
                            $selisihAmount = $grandTotal - $pkbTotal;
                            $selisihPercent = $pkbTotal != 0.0 ? ($selisihAmount / $pkbTotal) * 100 : null;

                            if ($selisihAmount == 0.0) {
                                $gapBadge = '<span class="status-dot status-active">Sesuai</span>';
                            } elseif ($selisihAmount > 0.0) {
                                $gapBadge = '<span class="status-dot status-warning">Invoice &gt; PKB</span>';
                            } else {
                                $gapBadge = '<span class="status-dot status-danger">Invoice &lt; PKB</span>';
                            }
                        @endphp
                        <tr>
                            <td><a href="{{ route('work-orders.show', $invoice->work_order_id) }}"><code>{{ $invoice->workOrder->number }}</code></a></td>
                            <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                            <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ number_format($pkbTotal, 0, ',', '.') }}</td>
                            <td>{{ number_format($grandTotal, 0, ',', '.') }}</td>
                            <td>{{ ($selisihAmount >= 0 ? '+' : '') . number_format($selisihAmount, 0, ',', '.') }}</td>
                            <td>{{ $selisihPercent !== null ? (($selisihPercent >= 0 ? '+' : '') . number_format($selisihPercent, 1, ',', '.') . '%') : '—' }}</td>
                            <td>{!! $gapBadge !!}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-bar-chart-steps',
                                    'title' => 'Belum ada data transaksi',
                                    'description' => 'Tidak ada transaksi yang cocok dengan filter saat ini.',
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
        const form = document.getElementById('invoicePkbGapFilterForm');
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
