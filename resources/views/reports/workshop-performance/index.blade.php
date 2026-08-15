@extends('layouts.app')
@section('title', 'Laporan Performance Bengkel')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-speedometer2"></i></span>
            <div>
                <p class="eyebrow mb-1">Reporting</p>
                <h1 class="h3 mb-1">Laporan Performance Bengkel</h1>
                <p class="text-muted mb-0">Pantau produktivitas mekanik dan bengkel.</p>
            </div>
        </div>
        <div class="heading-actions">
            @include('partials.report-export-buttons', [
                'excelRoute' => 'reports.workshop-performance.export-excel',
                'pdfPreviewRoute' => 'reports.workshop-performance.pdf-preview',
                'pdfDownloadRoute' => 'reports.workshop-performance.pdf-download',
            ])
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.workshop-performance.index') }}" id="workshopPerformanceFilterForm" class="row g-2 align-items-end">
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
                    <label class="form-label small">Tanggal Dari</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sampai</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tampilan</label>
                    <select name="view_type" class="form-select form-select-sm">
                        <option value="mechanic" {{ $viewType === 'mechanic' ? 'selected' : '' }}>Mekanik</option>
                        <option value="invoice_detail" {{ $viewType === 'invoice_detail' ? 'selected' : '' }}>Invoice Detail</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Terapkan Filter</button>
                </div>
            </form>
        </div>
    </div>

    @if ($viewType === 'mechanic')
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Mekanik</th>
                            <th>Total Customer</th>
                            <th>Total Qty Jasa</th>
                            <th>Total Discount Jasa (Rp)</th>
                            <th>Subtotal Jasa</th>
                            <th>Total Qty Sparepart</th>
                            <th>Total Discount Sparepart (Rp)</th>
                            <th>Subtotal Sparepart</th>
                            <th>Grand Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($mechanicRows as $row)
                            @php
                                $mechanicLabel = $row->mechanic_nip ? "{$row->mechanic_nip} - {$row->mechanic_name}" : $row->mechanic_name;
                                $grandTotal = (float) $row->subtotal_jasa + (float) $row->subtotal_sparepart;
                            @endphp
                            <tr>
                                <td>{{ $mechanicLabel }}</td>
                                <td>{{ number_format($row->total_customer, 0, ',', '.') }}</td>
                                <td>{{ number_format($row->total_qty_jasa, 0, ',', '.') }}</td>
                                <td>{{ number_format($row->total_discount_jasa, 0, ',', '.') }}</td>
                                <td>{{ number_format($row->subtotal_jasa, 0, ',', '.') }}</td>
                                <td>{{ number_format($row->total_qty_sparepart, 0, ',', '.') }}</td>
                                <td>{{ number_format($row->total_discount_sparepart, 0, ',', '.') }}</td>
                                <td>{{ number_format($row->subtotal_sparepart, 0, ',', '.') }}</td>
                                <td>{{ number_format($grandTotal, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="p-0">
                                    @include('partials.empty-state', [
                                        'icon' => 'bi-speedometer2',
                                        'title' => 'Belum ada data performance mekanik',
                                        'description' => 'Tidak ada mekanik dengan aktivitas invoice yang cocok dengan filter saat ini.',
                                        'ctaVisible' => false,
                                        'ctaRoute' => '',
                                        'ctaLabel' => '',
                                    ])
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">
            {{ $mechanicRows->links() }}
        </div>
    @else
        @forelse ($invoices as $invoice)
            @php
                $mechanicLabel = optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-';
                $pairs = \App\Support\WorkshopPerformanceLinePairer::build($invoice);
                $totalJasa = collect($pairs)->sum('jasa_subtotal');
                $totalSparepart = collect($pairs)->sum('sparepart_subtotal');
                $totalLine = $totalJasa + $totalSparepart;
            @endphp
            <div class="card mb-3">
                <div class="card-header d-flex flex-wrap gap-3 small">
                    <span><strong>No. Invoice:</strong> {{ $invoice->number }}</span>
                    <span><strong>Tanggal:</strong> {{ $invoice->invoice_date->format('d/m/Y') }}</span>
                    <span><strong>Status:</strong> {{ $invoice->status }}</span>
                    <span><strong>Customer:</strong> {{ $invoice->customer->name }}</span>
                    <span><strong>Mekanik:</strong> {{ $mechanicLabel }}</span>
                    <span><strong>Cabang:</strong> {{ $invoice->branch->name }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th colspan="5" class="text-center">Jasa</th>
                                <th colspan="5" class="text-center">Sparepart</th>
                                <th rowspan="2" class="align-middle">Subtotal Line</th>
                            </tr>
                            <tr>
                                <th>Deskripsi</th><th>Harga</th><th>Qty</th><th>Diskon %</th><th>Subtotal</th>
                                <th>Deskripsi</th><th>Harga</th><th>Qty</th><th>Diskon %</th><th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pairs as $pair)
                                <tr>
                                    <td>{{ $pair['jasa_desc'] }}</td>
                                    <td>{{ number_format($pair['jasa_price'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['jasa_qty'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['jasa_discount_percent'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['jasa_subtotal'], 0, ',', '.') }}</td>
                                    <td>{{ $pair['sparepart_desc'] }}</td>
                                    <td>{{ number_format($pair['sparepart_price'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['sparepart_qty'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['sparepart_discount_percent'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['sparepart_subtotal'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['subtotal_line'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="11" class="text-muted">&mdash;</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="4">Total</td>
                                <td>{{ number_format($totalJasa, 0, ',', '.') }}</td>
                                <td colspan="4"></td>
                                <td>{{ number_format($totalSparepart, 0, ',', '.') }}</td>
                                <td>{{ number_format($totalLine, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @empty
            @include('partials.empty-state', [
                'icon' => 'bi-speedometer2',
                'title' => 'Belum ada data invoice',
                'description' => 'Tidak ada invoice yang cocok dengan filter saat ini.',
                'ctaVisible' => false,
                'ctaRoute' => '',
                'ctaLabel' => '',
            ])
        @endforelse
        <div class="mt-3">
            {{ $invoices->links() }}
        </div>
    @endif

    @push('scripts')
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('workshopPerformanceFilterForm');
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
