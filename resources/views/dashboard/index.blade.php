@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Dashboard</h1>
            <p class="mb-0" style="color: var(--color-ink-muted);">Selamat datang kembali, {{ auth()->user()->name }}.</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            @include('partials.branch-multiselect-filter')
            @if (auth()->user()->branchesWithPermission('sparepart.view')->isNotEmpty())
                <a href="{{ route('sparepart-branches.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> Sparepart Baru
                </a>
            @endif
            <span class="btn btn-outline-secondary btn-sm disabled" style="cursor: not-allowed;" aria-disabled="true">
                <i class="bi bi-clipboard-plus"></i> Buat PKB Baru
                <span class="badge-soon">Segera Hadir</span>
            </span>
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
                        <div class="small mt-1" style="color: var(--color-ink-muted);">On-hand {{ number_format($stockOverview['onHand'], 0, ',', '.') }} &middot; Reservasi {{ number_format($stockOverview['reserved'], 0, ',', '.') }}</div>
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
                        <div class="stat-value" id="kpiPkbTotal">{{ $pkbStatus['open'] + $pkbStatus['shortage'] + $pkbStatus['completed'] }}</div>
                        <div class="stat-label">Status PKB Hari Ini</div>
                        <div class="small mt-1" style="color: var(--color-ink-muted);">Open {{ $pkbStatus['open'] }} &middot; Shortage {{ $pkbStatus['shortage'] }} &middot; Selesai {{ $pkbStatus['completed'] }}</div>
                    </div>
                    <i class="bi bi-clipboard-check stat-icon"></i>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" id="kpiRevenue">{{ number_format($receivables['revenue'], 0, ',', '.') }}</div>
                        <div class="stat-label">Pendapatan & Piutang</div>
                        <div class="small mt-1" style="color: var(--color-ink-muted);">Piutang belum lunas {{ number_format($receivables['unpaid'], 0, ',', '.') }}</div>
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
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 mb-3">Tren PKB vs Invoice Posted Mingguan</h2>
                        <canvas id="trendChart" height="220"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 mb-3">Komposisi Status Piutang</h2>
                        <canvas id="receivablesChart" height="220"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-loading-parent" id="tabsSection">
        <div class="dashboard-loading-overlay d-none"><div class="spinner-border text-primary" role="status"></div></div>
        <div class="card shadow-sm">
            <div class="card-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pkb-invoice" type="button" role="tab">Status PKB & Invoice</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-kartu-stok" type="button" role="tab">Kartu Stok</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-audit-log" type="button" role="tab">Audit Log</button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tab-pkb-invoice" role="tabpanel">
                        @include('dashboard._tab_pkb_invoice')
                    </div>
                    <div class="tab-pane fade" id="tab-kartu-stok" role="tabpanel">
                        @include('dashboard._tab_kartu_stok')
                    </div>
                    <div class="tab-pane fade" id="tab-audit-log" role="tabpanel">
                        @include('dashboard._tab_audit_log')
                    </div>
                </div>
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

    function fetchDashboard(params) {
        showOverlays();
        const url = '{{ route('dashboard') }}?' + params.toString();

        return Promise.all([
            fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }),
            minDelay(400),
        ]).then(function (results) {
            applyPayload(results[0]);
            hideOverlays();
        });
    }

    function applyPayload(data) {
        document.getElementById('kpiStockAvailable').textContent = Math.round(data.stockOverview.available).toLocaleString('id-ID');
        document.getElementById('kpiCriticalStock').textContent = data.criticalStockCount;
        document.getElementById('kpiPkbTotal').textContent = data.pkbStatus.open + data.pkbStatus.shortage + data.pkbStatus.completed;
        document.getElementById('kpiRevenue').textContent = Math.round(data.receivables.revenue).toLocaleString('id-ID');

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
})();
</script>
@endpush
