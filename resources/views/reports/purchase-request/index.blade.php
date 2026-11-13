@extends('layouts.app')
@section('title', 'Laporan Permintaan Barang')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-clipboard-data"></i></span>
            <div>
                <p class="eyebrow mb-1">Reporting</p>
                <h1 class="h3 mb-1">Laporan Permintaan Barang</h1>
                <p class="text-muted mb-0">Rekap dan detail permintaan barang beserta posisi approval, semua cabang.</p>
            </div>
        </div>
        <div class="heading-actions">
            @include('partials.report-export-buttons', [
                'excelRoute' => 'reports.purchase-requests.export-excel',
                'pdfPreviewRoute' => 'reports.purchase-requests.pdf-preview',
                'pdfDownloadRoute' => 'reports.purchase-requests.pdf-download',
            ])
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.purchase-requests.index') }}" id="purchaseRequestReportFilterForm" class="row g-2 align-items-end">
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
                        @foreach (\App\Support\PurchaseRequestStatus::LABELS as $value => $label)
                            <option value="{{ $value }}" {{ $status === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Tampilan</label>
                    <select name="mode" id="purchaseRequestReportMode" class="form-select form-select-sm">
                        <option value="rekap" {{ $mode === 'rekap' ? 'selected' : '' }}>Rekap (per dokumen)</option>
                        <option value="detail" {{ $mode === 'detail' ? 'selected' : '' }}>Detail (per barang)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Supplier</label>
                    <select name="supplier_id" id="purchaseRequestReportSupplier" class="form-select form-select-sm">
                        @if ($supplier)
                            <option value="{{ $supplier->id }}" selected>{{ $supplier->label }}</option>
                        @endif
                    </select>
                </div>
                <div class="col-md-4" id="purchaseRequestReportSparepartGroup" {!! $mode === 'detail' ? '' : 'style="display:none"' !!}>
                    <label class="form-label small">Sparepart</label>
                    <select name="sparepart_id" id="purchaseRequestReportSparepart" class="form-select form-select-sm" {{ $mode === 'detail' ? '' : 'disabled' }}>
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
        <div class="col-md-3">
            <div class="stat-card">
                <div><div class="stat-value">{{ number_format($summary['total_dokumen'], 0, ',', '.') }}</div><div class="stat-label">Total Permintaan</div></div>
                <i class="bi bi-files stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div><div class="stat-value">{{ number_format($summary['total_nilai'], 0, ',', '.') }}</div><div class="stat-label">Nilai Total (tanpa batal)</div></div>
                <i class="bi bi-cash-stack stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div><div class="stat-value">{{ number_format($summary['menunggu_approval'], 0, ',', '.') }}</div><div class="stat-label">Menunggu Approval</div></div>
                <i class="bi bi-hourglass-split stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div><div class="stat-value">{{ number_format($summary['ditolak'], 0, ',', '.') }}</div><div class="stat-label">Ditolak</div></div>
                <i class="bi bi-x-circle stat-icon"></i>
            </div>
        </div>
    </div>
    <p class="text-muted small mb-2">Permintaan berstatus Dibatalkan ditampilkan di daftar, tetapi nilainya tidak dihitung dalam total.</p>

    <div class="card">
        <div class="table-responsive">
        @if ($mode === 'detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Permintaan</th>
                        <th>Tanggal</th>
                        <th>Cabang</th>
                        <th>Status</th>
                        <th>Kode</th>
                        <th>Sparepart</th>
                        <th class="text-end">Qty</th>
                        <th>Supplier</th>
                        <th class="text-end">Harga</th>
                        <th class="text-end">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $line)
                        @php($request = $line->purchaseRequest)
                        <tr>
                            <td><code>{{ $request->number }}</code></td>
                            <td>{{ $request->request_date->format('d/m/Y') }}</td>
                            <td>{{ $request->branch->name }}</td>
                            <td>@include('purchase-requests._status_badge', ['status' => $request->status])</td>
                            <td><code>{{ $line->sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $line->sparepartBranch->sparepart->name }}</td>
                            <td class="text-end">{{ number_format((float) $line->qty, 0, ',', '.') }}</td>
                            <td>{{ $line->supplier ? $line->supplier->name : '-' }}</td>
                            <td class="text-end">{{ $line->unit_price !== null ? number_format((float) $line->unit_price, 0, ',', '.') : '-' }}</td>
                            <td class="text-end">{{ $line->line_total !== null ? number_format((float) $line->line_total, 0, ',', '.') : '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="p-0">@include('reports.purchase-request._empty')</td></tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Permintaan</th>
                        <th>Tanggal</th>
                        <th>Cabang</th>
                        <th>Pemohon</th>
                        <th class="text-end">Item</th>
                        <th class="text-end">Total (Rp)</th>
                        <th>Status</th>
                        <th>Posisi / Keputusan</th>
                        <th class="text-end">Lama Proses</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $request)
                        @php($total = $request->total_amount !== null ? (float) $request->total_amount : (float) $request->lines_amount)
                        @php($days = $request->processDays())
                        <tr>
                            <td><code>{{ $request->number }}</code></td>
                            <td>{{ $request->request_date->format('d/m/Y') }}</td>
                            <td>{{ $request->branch->name }}</td>
                            <td>{{ optional($request->creator)->name ?? '-' }}</td>
                            <td class="text-end">{{ number_format($request->lines_count, 0, ',', '.') }}</td>
                            <td class="text-end">{{ $total > 0 ? number_format($total, 0, ',', '.') : '—' }}</td>
                            <td>@include('purchase-requests._status_badge', ['status' => $request->status])</td>
                            <td class="small">{{ $request->positionLabel() }}</td>
                            <td class="text-end">{{ $days === null ? '-' : $days . ' hari' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="p-0">@include('reports.purchase-request._empty')</td></tr>
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
        const form = document.getElementById('purchaseRequestReportFilterForm');
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

        initAjaxSelect(document.getElementById('purchaseRequestReportSupplier'), {
            endpoint: @json(route('lookup.suppliers')),
            placeholder: '-- Semua Supplier --',
        });

        const sparepartEndpoint = @json(route('lookup.report-spareparts'));
        const modeSelect = document.getElementById('purchaseRequestReportMode');
        const group = document.getElementById('purchaseRequestReportSparepartGroup');
        const sparepartSelect = document.getElementById('purchaseRequestReportSparepart');

        initAjaxSelect(sparepartSelect, { endpoint: sparepartEndpoint, placeholder: '-- Semua Sparepart --' });

        @if ($sparepartId)
        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm ms-2';
        group.querySelector('label').appendChild(spinner);
        preselectAjaxOption(sparepartSelect, { endpoint: sparepartEndpoint, id: {{ (int) $sparepartId }} })
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
