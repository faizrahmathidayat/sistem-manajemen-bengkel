@extends('layouts.app')
@section('title', 'Laporan Pembelian Barang')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-cart-check"></i></span>
            <div>
                <p class="eyebrow mb-1">Reporting</p>
                <h1 class="h3 mb-1">Laporan Pembelian Barang</h1>
                <p class="text-muted mb-0">Nilai pembelian, penerimaan, dan outstanding per PO, per barang, dan per supplier — semua cabang.</p>
            </div>
        </div>
        <div class="heading-actions">
            @include('partials.report-export-buttons', [
                'excelRoute' => 'reports.purchase-orders.export-excel',
                'pdfPreviewRoute' => 'reports.purchase-orders.pdf-preview',
                'pdfDownloadRoute' => 'reports.purchase-orders.pdf-download',
            ])
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.purchase-orders.index') }}" id="purchaseOrderReportFilterForm" class="row g-2 align-items-end">
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
                        @foreach (\App\Support\PurchaseOrderStatus::LABELS as $value => $label)
                            <option value="{{ $value }}" {{ $status === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Tampilan</label>
                    <select name="mode" id="purchaseOrderReportMode" class="form-select form-select-sm">
                        <option value="rekap" {{ $mode === 'rekap' ? 'selected' : '' }}>Rekap (per PO)</option>
                        <option value="detail" {{ $mode === 'detail' ? 'selected' : '' }}>Detail (per barang)</option>
                        <option value="supplier" {{ $mode === 'supplier' ? 'selected' : '' }}>Per Supplier</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Supplier</label>
                    <select name="supplier_id" id="purchaseOrderReportSupplier" class="form-select form-select-sm">
                        @if ($supplier)
                            <option value="{{ $supplier->id }}" selected>{{ $supplier->label }}</option>
                        @endif
                    </select>
                </div>
                <div class="col-md-4" id="purchaseOrderReportSparepartGroup" {!! $mode === 'detail' ? '' : 'style="display:none"' !!}>
                    <label class="form-label small">Sparepart</label>
                    <select name="sparepart_id" id="purchaseOrderReportSparepart" class="form-select form-select-sm" {{ $mode === 'detail' ? '' : 'disabled' }}>
                        <option value=""></option>
                    </select>
                </div>
                <div class="col-md-4">
                    <div class="form-check form-switch mb-1">
                        <input type="checkbox" name="outstanding" id="purchaseOrderReportOutstanding" value="1" class="form-check-input" {{ $outstanding ? 'checked' : '' }}>
                        <label for="purchaseOrderReportOutstanding" class="form-check-label small">Belum selesai diterima (outstanding)</label>
                    </div>
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
                <div><div class="stat-value">{{ number_format($summary['jumlah_po'], 0, ',', '.') }}</div><div class="stat-label">Jumlah PO</div></div>
                <i class="bi bi-files stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div><div class="stat-value">{{ number_format($summary['nilai_dipesan'], 0, ',', '.') }}</div><div class="stat-label">Nilai Dipesan</div></div>
                <i class="bi bi-cash-stack stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div><div class="stat-value">{{ number_format($summary['nilai_diterima'], 0, ',', '.') }}</div><div class="stat-label">Nilai Sudah Diterima</div></div>
                <i class="bi bi-box-arrow-in-down stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div><div class="stat-value">{{ number_format($summary['nilai_outstanding'], 0, ',', '.') }}</div><div class="stat-label">Nilai Outstanding</div></div>
                <i class="bi bi-hourglass-split stat-icon"></i>
            </div>
        </div>
    </div>
    <p class="text-muted small mb-2">Pembelian berstatus Dibatalkan ditampilkan di daftar, tetapi tidak dihitung dalam total. Nilai diterima dihitung dari qty yang sudah diposting.</p>

    <div class="card">
        <div class="table-responsive">
        @if ($mode === 'detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Pembelian</th>
                        <th>Tanggal</th>
                        <th>Supplier</th>
                        <th>Cabang</th>
                        <th>Status</th>
                        <th>Kode</th>
                        <th>Sparepart</th>
                        <th class="text-end">Qty Pesan</th>
                        <th class="text-end">Qty Terima</th>
                        <th class="text-end">Sisa</th>
                        <th class="text-end">Harga</th>
                        <th class="text-end">Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $line)
                        @php($order = $line->purchaseOrder)
                        <tr>
                            <td><code>{{ $order->number }}</code></td>
                            <td>{{ $order->order_date->format('d/m/Y') }}</td>
                            <td>{{ $order->supplier->name }}</td>
                            <td>{{ $order->branch->name }}</td>
                            <td>@include('purchase-orders._status_badge', ['status' => $order->status])</td>
                            <td><code>{{ $line->sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $line->sparepartBranch->sparepart->name }}</td>
                            <td class="text-end">{{ number_format((float) $line->qty_ordered, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $line->qty_received, 0, ',', '.') }}</td>
                            <td class="text-end fw-bold">{{ number_format($line->remainingQty(), 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $line->unit_price, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $line->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="12" class="p-0">@include('reports.purchase-order._empty')</td></tr>
                    @endforelse
                </tbody>
            </table>
        @elseif ($mode === 'supplier')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Supplier</th>
                        <th class="text-end">Jumlah PO</th>
                        <th class="text-end">Nilai Dipesan (Rp)</th>
                        <th class="text-end">Nilai Diterima (Rp)</th>
                        <th class="text-end">Outstanding (Rp)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->supplier_name }}</td>
                            <td class="text-end">{{ number_format((int) $row->po_count, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $row->ordered_value, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $row->received_value, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $row->ordered_value - (float) $row->received_value, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-0">@include('reports.purchase-order._empty')</td></tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Pembelian</th>
                        <th>Tanggal</th>
                        <th>Supplier</th>
                        <th>Cabang</th>
                        <th class="text-end">Nilai PO (Rp)</th>
                        <th class="text-end">Diterima (Rp)</th>
                        <th class="text-end">Outstanding (Rp)</th>
                        <th class="text-end">% Terima</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $order)
                        @php($ordered = (float) $order->qty_ordered_sum)
                        @php($percent = $ordered > 0 ? (int) round(((float) $order->qty_received_sum / $ordered) * 100) : 0)
                        <tr>
                            <td><code>{{ $order->number }}</code></td>
                            <td>{{ $order->order_date->format('d/m/Y') }}</td>
                            <td>{{ $order->supplier->name }}</td>
                            <td>{{ $order->branch->name }}</td>
                            <td class="text-end">{{ number_format((float) $order->total_amount, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $order->received_value, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format((float) $order->total_amount - (float) $order->received_value, 0, ',', '.') }}</td>
                            <td class="text-end">{{ $percent }}%</td>
                            <td>@include('purchase-orders._status_badge', ['status' => $order->status])</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="p-0">@include('reports.purchase-order._empty')</td></tr>
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
        const form = document.getElementById('purchaseOrderReportFilterForm');
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

        initAjaxSelect(document.getElementById('purchaseOrderReportSupplier'), {
            endpoint: @json(route('lookup.suppliers')),
            placeholder: '-- Semua Supplier --',
        });

        const sparepartEndpoint = @json(route('lookup.report-spareparts'));
        const modeSelect = document.getElementById('purchaseOrderReportMode');
        const group = document.getElementById('purchaseOrderReportSparepartGroup');
        const sparepartSelect = document.getElementById('purchaseOrderReportSparepart');

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
