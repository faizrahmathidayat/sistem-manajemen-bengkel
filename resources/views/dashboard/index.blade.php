@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-grid-1x2-fill"></i></span>
            <div>
                <p class="eyebrow mb-1">Overview</p>
                <h1 class="h3 mb-1">Dashboard</h1>
                <p class="text-muted mb-0">Selamat datang kembali, {{ auth()->user()->name }}.</p>
            </div>
        </div>
        <div class="heading-actions">
            @include('partials.branch-multiselect-filter')
            @if (auth()->user()->branchesWithPermission('sparepart.create')->isNotEmpty())
                <a href="{{ route('sparepart-branches.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> Sparepart Baru
                </a>
            @endif
            @if (auth()->user()->branchesWithPermission('pkb.create')->isNotEmpty())
                <a href="{{ route('work-orders.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-clipboard-plus"></i> Buat PKB Baru
                </a>
            @endif
        </div>
    </div>

    <div class="dashboard-loading-parent" id="kpiSection">
        <div class="dashboard-loading-overlay d-none"><div class="spinner-border text-primary" role="status"></div></div>
        <div class="row g-3 mb-4" id="kpiCardsRow">
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" id="kpiStockAvailable">{{ number_format($stockOverview['available'], 0, ',', '.') }}</div>
                        <div class="stat-label">Stok Tersedia</div>
                        <div class="small mt-1" style="color: var(--color-ink-muted);">On-hand <span id="kpiStockOnHand">{{ number_format($stockOverview['onHand'], 0, ',', '.') }}</span> &middot; Reservasi <span id="kpiStockReserved">{{ number_format($stockOverview['reserved'], 0, ',', '.') }}</span></div>
                    </div>
                    <i class="bi bi-box-seam stat-icon"></i>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" id="kpiCriticalStock" style="{{ $criticalStockCount > 0 ? 'color: var(--color-warning);' : '' }}">{{ $criticalStockCount }}</div>
                        <div class="stat-label">Alert Stok Kritis</div>
                    </div>
                    <i class="bi bi-exclamation-triangle stat-icon"></i>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" id="kpiPkbTotal">{{ $pkbStatus['draft'] + $pkbStatus['open'] + $pkbStatus['shortage'] + $pkbStatus['completed'] }}</div>
                        <div class="stat-label">Status PKB Hari Ini</div>
                        <div class="small mt-1" style="color: var(--color-ink-muted);">Draft <span id="kpiPkbDraft">{{ $pkbStatus['draft'] }}</span> &middot; Open <span id="kpiPkbOpen">{{ $pkbStatus['open'] }}</span> &middot; Shortage <span id="kpiPkbShortage">{{ $pkbStatus['shortage'] }}</span> &middot; Selesai <span id="kpiPkbCompleted">{{ $pkbStatus['completed'] }}</span></div>
                    </div>
                    <i class="bi bi-clipboard-check stat-icon"></i>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" id="kpiRevenue">{{ number_format($receivables['revenue'], 0, ',', '.') }}</div>
                        <div class="stat-label">Pendapatan & Piutang</div>
                        <div class="small mt-1" style="color: var(--color-ink-muted);">Piutang belum lunas <span id="kpiUnpaid">{{ number_format($receivables['unpaid'], 0, ',', '.') }}</span></div>
                    </div>
                    <i class="bi bi-cash-coin stat-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-loading-parent" id="chartsSection">
        <div class="dashboard-loading-overlay d-none"><div class="spinner-border text-primary" role="status"></div></div>
        <div class="row g-3 mb-4">
            <div class="col-lg-7">
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h2 class="h5 mb-1 section-title"><i class="bi bi-graph-up-arrow"></i><span>Tren PKB vs Invoice Posted Mingguan</span></h2>
                        </div>
                    </div>
                    <canvas id="trendChart" height="220"></canvas>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h2 class="h5 mb-1 section-title"><i class="bi bi-pie-chart"></i><span>Komposisi Status Piutang</span></h2>
                        </div>
                    </div>
                    <canvas id="receivablesChart" height="220"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-loading-parent" id="tabsSection">
        <div class="dashboard-loading-overlay d-none"><div class="spinner-border text-primary" role="status"></div></div>
        <div class="panel">
            <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pkb-invoice" type="button" role="tab">Status PKB & Invoice</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-kartu-stok" type="button" role="tab">Kartu Stok</button>
                    </li>
                    @if ($canViewAuditLog)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-audit-log" type="button" role="tab">Audit Log</button>
                        </li>
                    @endif
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tab-pkb-invoice" role="tabpanel">
                        @include('dashboard._tab_pkb_invoice')
                    </div>
                    <div class="tab-pane fade" id="tab-kartu-stok" role="tabpanel">
                        @include('dashboard._tab_kartu_stok')
                    </div>
                    @if ($canViewAuditLog)
                        <div class="tab-pane fade" id="tab-audit-log" role="tabpanel">
                            @include('dashboard._tab_audit_log')
                        </div>
                    @endif
                </div>
        </div>
    </div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const trendChart = new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: @json($chartTrend['labels']),
        datasets: [
            { label: 'PKB', data: @json($chartTrend['pkb']), borderColor: '#2563EB', backgroundColor: 'rgba(37, 99, 235, .1)', tension: 0.3 },
            { label: 'Invoice', data: @json($chartTrend['invoice']), borderColor: '#10B981', backgroundColor: 'rgba(16, 185, 129, .1)', tension: 0.3 },
        ],
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } },
});

const receivablesChart = new Chart(document.getElementById('receivablesChart'), {
    type: 'doughnut',
    data: {
        labels: @json($chartReceivables['labels']),
        datasets: [{ data: @json($chartReceivables['values']), backgroundColor: ['#10B981', '#F59E0B', '#DC2626', '#64748B'] }],
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } },
});
</script>
<script>
(function () {
    const selectAll = document.getElementById('branchFilterSelectAll');
    const menu = document.getElementById('branchFilterMenu');
    const sections = ['kpiSection', 'chartsSection', 'tabsSection'];

    if (menu) {
        menu.addEventListener('click', function (event) { event.stopPropagation(); });
    }

    function showOverlays() {
        sections.forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.querySelector('.dashboard-loading-overlay').classList.remove('d-none');
        });
    }

    function hideOverlays() {
        sections.forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.querySelector('.dashboard-loading-overlay').classList.add('d-none');
        });
    }

    function minDelay(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function showFilterError(message) {
        const menu = document.getElementById('branchFilterMenu');
        if (!menu) return;
        let errorEl = document.getElementById('branchFilterError');
        if (!errorEl) {
            errorEl = document.createElement('p');
            errorEl.id = 'branchFilterError';
            errorEl.className = 'text-danger small mt-2 mb-0';
            menu.appendChild(errorEl);
        }
        errorEl.textContent = message;
    }

    function clearFilterError() {
        const errorEl = document.getElementById('branchFilterError');
        if (errorEl) {
            errorEl.remove();
        }
    }

    function fetchDashboard(params) {
        showOverlays();
        const url = '{{ route('dashboard') }}?' + params.toString();

        return Promise.all([
            fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) {
                if (!r.ok) {
                    throw new Error('Dashboard request failed with status ' + r.status);
                }
                return r.json();
            }),
            minDelay(400),
        ]).then(function (results) {
            applyPayload(results[0]);
            hideOverlays();
        }).catch(function (error) {
            console.error('Gagal memuat data dashboard:', error);
            hideOverlays();
            showFilterError('Gagal memuat data dashboard. Silakan coba lagi.');
        });
    }

    function applyPayload(data) {
        clearFilterError();
        document.getElementById('kpiStockAvailable').textContent = Math.round(data.stockOverview.available).toLocaleString('id-ID');
        document.getElementById('kpiStockOnHand').textContent = Math.round(data.stockOverview.onHand).toLocaleString('id-ID');
        document.getElementById('kpiStockReserved').textContent = Math.round(data.stockOverview.reserved).toLocaleString('id-ID');
        document.getElementById('kpiCriticalStock').textContent = data.criticalStockCount;

        document.getElementById('kpiPkbDraft').textContent = data.pkbStatus.draft;
        document.getElementById('kpiPkbOpen').textContent = data.pkbStatus.open;
        document.getElementById('kpiPkbShortage').textContent = data.pkbStatus.shortage;
        document.getElementById('kpiPkbCompleted').textContent = data.pkbStatus.completed;
        document.getElementById('kpiPkbTotal').textContent = data.pkbStatus.draft + data.pkbStatus.open + data.pkbStatus.shortage + data.pkbStatus.completed;

        document.getElementById('kpiRevenue').textContent = Math.round(data.receivables.revenue).toLocaleString('id-ID');
        document.getElementById('kpiUnpaid').textContent = Math.round(data.receivables.unpaid).toLocaleString('id-ID');

        trendChart.data.labels = data.chartTrend.labels;
        trendChart.data.datasets[0].data = data.chartTrend.pkb;
        trendChart.data.datasets[1].data = data.chartTrend.invoice;
        trendChart.update();

        receivablesChart.data.labels = data.chartReceivables.labels;
        receivablesChart.data.datasets[0].data = data.chartReceivables.values;
        receivablesChart.update();

        document.getElementById('kartuStokOnHand').textContent = Math.round(data.kartuStok.selected.onHand).toLocaleString('id-ID');
        document.getElementById('kartuStokReserved').textContent = Math.round(data.kartuStok.selected.reserved).toLocaleString('id-ID');
        document.getElementById('kartuStokAvailable').textContent = Math.round(data.kartuStok.selected.available).toLocaleString('id-ID');

        const sparepartSelect = document.getElementById('kartuStokSparepartSelect');
        if (sparepartSelect) {
            sparepartSelect.innerHTML = '';
            data.kartuStok.spareparts.forEach(function (sparepart) {
                const option = document.createElement('option');
                option.value = sparepart.id;
                option.textContent = sparepart.code + ' — ' + sparepart.name;
                option.selected = sparepart.id === data.kartuStok.selected.id;
                sparepartSelect.appendChild(option);
            });
        }

        renderPkbInvoiceRows(data.pkbInvoiceRows);
        renderAuditLogRows(data.auditLogRows);
        updateBranchFilterSummary(data.selectedBranchIds);
    }

    function renderPkbInvoiceRows(rows) {
        const body = document.getElementById('pkbInvoiceTabBody');
        if (!body) return;
        body.innerHTML = '';

        if (rows.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 5;
            td.className = 'text-muted text-center py-3';
            td.textContent = 'Tidak ada data PKB/Invoice yang cocok.';
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }

        rows.forEach(function (row) {
            const tr = document.createElement('tr');

            const tdNumber = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = 'badge me-1 ' + (row.type === 'pkb' ? 'bg-primary' : 'bg-success');
            badge.textContent = row.typeLabel;
            const code = document.createElement('code');
            code.textContent = row.number;
            tdNumber.appendChild(badge);
            tdNumber.appendChild(code);

            const tdCustomer = document.createElement('td');
            tdCustomer.textContent = row.customer + ' · ' + row.plate;

            const tdBranch = document.createElement('td');
            tdBranch.textContent = row.branch;

            const tdStatus = document.createElement('td');
            const statusBadge = document.createElement('span');
            statusBadge.className = 'status-dot status-active';
            statusBadge.textContent = row.statusLabel;
            tdStatus.appendChild(statusBadge);

            const tdAction = document.createElement('td');
            tdAction.className = 'text-end';
            const link = document.createElement('a');
            link.href = row.url;
            link.className = 'btn btn-outline-secondary btn-sm';
            link.textContent = 'Lihat';
            tdAction.appendChild(link);

            tr.appendChild(tdNumber);
            tr.appendChild(tdCustomer);
            tr.appendChild(tdBranch);
            tr.appendChild(tdStatus);
            tr.appendChild(tdAction);
            body.appendChild(tr);
        });
    }

    function renderAuditLogRows(rows) {
        const feed = document.getElementById('auditLogFeed');
        if (!feed) return;
        feed.innerHTML = '';

        if (rows.length === 0) {
            const li = document.createElement('li');
            li.className = 'list-group-item px-0 text-muted text-center py-3';
            li.textContent = 'Belum ada aktivitas untuk cabang terpilih.';
            feed.appendChild(li);
            return;
        }

        const severityClass = { LOW: 'status-active', MEDIUM: 'status-warning', HIGH: 'status-inactive' };
        rows.forEach(function (row) {
            const li = document.createElement('li');
            li.className = 'list-group-item px-0';

            const headerRow = document.createElement('div');
            headerRow.className = 'd-flex justify-content-between';
            const userSpan = document.createElement('span');
            userSpan.className = 'fw-semibold';
            userSpan.textContent = row.user;
            const timeSpan = document.createElement('span');
            timeSpan.className = 'small';
            timeSpan.style.color = 'var(--color-ink-muted)';
            timeSpan.textContent = row.timestamp;
            headerRow.appendChild(userSpan);
            headerRow.appendChild(timeSpan);

            const eventDiv = document.createElement('div');
            eventDiv.className = 'small mb-1';
            const eventCode = document.createElement('code');
            eventCode.textContent = row.event;
            eventDiv.appendChild(eventCode);

            const descDiv = document.createElement('div');
            descDiv.textContent = row.description;

            const severityBadge = document.createElement('span');
            severityBadge.className = 'status-dot ' + (severityClass[row.severity] || 'status-active');
            severityBadge.textContent = row.severity;

            li.appendChild(headerRow);
            li.appendChild(eventDiv);
            li.appendChild(descDiv);
            li.appendChild(severityBadge);
            feed.appendChild(li);
        });
    }

    function updateBranchFilterSummary(selectedBranchIds) {
        const label = document.getElementById('branchFilterLabel');
        const allCheckboxes = document.querySelectorAll('.branch-filter-checkbox');
        const total = allCheckboxes.length;
        const selectedCount = selectedBranchIds.length;

        if (label) {
            if (total > 0 && selectedCount === total) {
                label.textContent = 'Semua Cabang Saya';
            } else if (selectedCount === 1) {
                const checkbox = Array.from(allCheckboxes).find(function (cb) { return Number(cb.value) === Number(selectedBranchIds[0]); });
                const branchLabel = checkbox ? document.querySelector('label[for="' + checkbox.id + '"]') : null;
                label.textContent = branchLabel ? branchLabel.textContent : '1 Cabang Terpilih';
            } else {
                label.textContent = selectedCount + ' Cabang Terpilih';
            }
        }

        if (selectAll) {
            selectAll.checked = total > 0 && selectedCount === total;
        }
    }

    function currentBranchIds() {
        return Array.from(document.querySelectorAll('.branch-filter-checkbox:checked')).map(function (cb) { return cb.value; });
    }

    function applyBranchFilter() {
        const params = new URLSearchParams();
        currentBranchIds().forEach(function (id) { params.append('branch_ids[]', id); });
        fetchDashboard(params);
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
            applyBranchFilter();
        });
    }

    document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) {
        checkbox.addEventListener('change', applyBranchFilter);
    });

    document.addEventListener('change', function (event) {
        if (event.target && event.target.id === 'kartuStokSparepartSelect') {
            const params = new URLSearchParams();
            currentBranchIds().forEach(function (id) { params.append('branch_ids[]', id); });
            params.append('sparepart_id', event.target.value);
            fetchDashboard(params);
        }
    });

    const pkbInvoiceSearch = document.getElementById('pkbInvoiceSearch');
    const pkbInvoiceStatus = document.getElementById('pkbInvoiceStatus');
    const pkbInvoiceDateFrom = document.getElementById('pkbInvoiceDateFrom');
    const pkbInvoiceDateTo = document.getElementById('pkbInvoiceDateTo');
    let pkbInvoiceDebounceTimer = null;

    function applyPkbInvoiceFilter() {
        const params = new URLSearchParams();
        currentBranchIds().forEach(function (id) { params.append('branch_ids[]', id); });
        if (pkbInvoiceSearch && pkbInvoiceSearch.value) params.append('pkb_invoice_q', pkbInvoiceSearch.value);
        if (pkbInvoiceStatus && pkbInvoiceStatus.value) params.append('pkb_invoice_status', pkbInvoiceStatus.value);
        if (pkbInvoiceDateFrom && pkbInvoiceDateFrom.value) params.append('pkb_invoice_date_from', pkbInvoiceDateFrom.value);
        if (pkbInvoiceDateTo && pkbInvoiceDateTo.value) params.append('pkb_invoice_date_to', pkbInvoiceDateTo.value);
        fetchDashboard(params);
    }

    if (pkbInvoiceSearch) {
        pkbInvoiceSearch.addEventListener('input', function () {
            clearTimeout(pkbInvoiceDebounceTimer);
            pkbInvoiceDebounceTimer = setTimeout(applyPkbInvoiceFilter, 400);
        });
    }
    [pkbInvoiceStatus, pkbInvoiceDateFrom, pkbInvoiceDateTo].forEach(function (el) {
        if (el) el.addEventListener('change', applyPkbInvoiceFilter);
    });
})();
</script>
@endpush
