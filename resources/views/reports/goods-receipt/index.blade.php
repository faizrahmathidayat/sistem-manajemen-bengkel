@extends('layouts.app')
@section('title', 'Laporan Penerimaan Barang')
@section('content')
    @php
        $statusLabels = ['draft' => 'Draft', 'posted' => 'Diposting', 'cancelled' => 'Dibatalkan'];
        $statusClasses = ['draft' => 'status-active', 'posted' => 'status-active', 'cancelled' => 'status-inactive'];
    @endphp
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-box-arrow-in-down"></i></span>
            <div>
                <p class="eyebrow mb-1">Reporting</p>
                <h1 class="h3 mb-1">Laporan Penerimaan Barang</h1>
                <p class="text-muted mb-0">Rekap dan detail penerimaan barang dari supplier per cabang.</p>
            </div>
        </div>
        <div class="heading-actions">
            @include('partials.report-export-buttons', [
                'excelRoute' => 'reports.goods-receipts.export-excel',
                'pdfPreviewRoute' => 'reports.goods-receipts.pdf-preview',
                'pdfDownloadRoute' => 'reports.goods-receipts.pdf-download',
            ])
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.goods-receipts.index') }}" id="goodsReceiptReportFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
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
                    <label class="form-label small">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        @foreach ($statusLabels as $value => $label)
                            <option value="{{ $value }}" {{ $status === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Tampilan</label>
                    <select name="mode" id="goodsReceiptReportMode" class="form-select form-select-sm">
                        <option value="rekap" {{ $mode === 'rekap' ? 'selected' : '' }}>Rekap (per dokumen)</option>
                        <option value="detail" {{ $mode === 'detail' ? 'selected' : '' }}>Detail (per item)</option>
                    </select>
                </div>
                <div class="col-md-4" id="goodsReceiptReportSparepartGroup" {!! $mode === 'detail' ? '' : 'style="display:none"' !!}>
                    <label class="form-label small">Sparepart</label>
                    <select name="sparepart_id" id="goodsReceiptReportSparepart" class="form-select form-select-sm" {{ $mode === 'detail' ? '' : 'disabled' }}>
                        <option value=""></option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Terapkan Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_dokumen, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Dokumen</div>
                </div>
                <i class="bi bi-file-earmark-text stat-icon"></i>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_qty, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Qty Diterima</div>
                </div>
                <i class="bi bi-boxes stat-icon"></i>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_nilai, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Nilai Penerimaan</div>
                </div>
                <i class="bi bi-cash-stack stat-icon"></i>
            </div>
        </div>
    </div>
    <p class="text-muted small mb-2">Dokumen berstatus Dibatalkan ditampilkan di daftar, tetapi tidak dihitung dalam total.</p>

    <div class="card">
        <div class="table-responsive">
        @if ($mode === 'detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Dokumen</th>
                        <th>Tanggal</th>
                        <th>Cabang</th>
                        <th>Status</th>
                        <th>Kode</th>
                        <th>Nama Sparepart</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Harga Beli</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $line)
                        @php $receipt = $line->goodsReceipt; @endphp
                        <tr>
                            <td>{{ $receipt->number }}</td>
                            <td>{{ $receipt->receipt_date->format('d/m/Y') }}</td>
                            <td>{{ $receipt->branch->name }}</td>
                            <td><span class="status-dot {{ $statusClasses[$receipt->status] ?? 'status-active' }}">{{ $statusLabels[$receipt->status] ?? $receipt->status }}</span></td>
                            <td><code>{{ $line->sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $line->sparepartBranch->sparepart->name }}</td>
                            <td class="text-end">{{ number_format((float) $line->qty, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $line->purchase_price, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $line->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-box-arrow-in-down',
                                    'title' => 'Belum ada data penerimaan',
                                    'description' => 'Tidak ada penerimaan barang yang cocok dengan filter saat ini.',
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
                        <th>No. Dokumen</th>
                        <th>Tanggal</th>
                        <th>Cabang</th>
                        <th>No. Referensi</th>
                        <th>Status</th>
                        <th class="text-end">Jumlah Item</th>
                        <th class="text-end">Total Qty</th>
                        <th class="text-end">Total Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $receipt)
                        <tr>
                            <td>{{ $receipt->number }}</td>
                            <td>{{ $receipt->receipt_date->format('d/m/Y') }}</td>
                            <td>{{ $receipt->branch->name }}</td>
                            <td>{{ $receipt->reference_number ?? '-' }}</td>
                            <td><span class="status-dot {{ $statusClasses[$receipt->status] ?? 'status-active' }}">{{ $statusLabels[$receipt->status] ?? $receipt->status }}</span></td>
                            <td class="text-end">{{ number_format($receipt->lines_count, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $receipt->total_qty, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $receipt->total_nilai, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-box-arrow-in-down',
                                    'title' => 'Belum ada data penerimaan',
                                    'description' => 'Tidak ada penerimaan barang yang cocok dengan filter saat ini.',
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
        {{ $rows->links() }}
    </div>

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('goodsReceiptReportFilterForm');
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

        // Sparepart filter: AJAX Select2, shown only in detail mode.
        const endpoint = '{{ route('lookup.report-spareparts') }}';
        const modeSelect = document.getElementById('goodsReceiptReportMode');
        const group = document.getElementById('goodsReceiptReportSparepartGroup');
        const sparepartSelect = document.getElementById('goodsReceiptReportSparepart');

        initAjaxSelect(sparepartSelect, { endpoint: endpoint, placeholder: '-- Semua Sparepart --' });

        @if ($sparepartId)
        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm ms-2';
        group.querySelector('label').appendChild(spinner);
        preselectAjaxOption(sparepartSelect, { endpoint: endpoint, id: {{ (int) $sparepartId }} })
            .then(function () { $(sparepartSelect).trigger('change'); })
            .finally(function () { spinner.remove(); });
        @endif

        modeSelect.addEventListener('change', function () {
            const isDetail = modeSelect.value === 'detail';
            group.style.display = isDetail ? '' : 'none';
            sparepartSelect.disabled = !isDetail;
        });
    })();
    </script>
    @endpush
@endsection
