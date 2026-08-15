@php($user = auth()->user())

@can('dashboard.view')
    <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
        <span class="nav-icon"><i class="bi bi-speedometer2"></i></span>
        <span class="nav-text">Dashboard</span>
    </a>
@endcan

@if ($user && ($user->branchesWithPermission('pkb.view')->isNotEmpty() || $user->branchesWithPermission('invoice.view')->isNotEmpty() || $user->branchesWithPermission('payment.view')->isNotEmpty()))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Operasional</div>
    @if ($user->branchesWithPermission('pkb.view')->isNotEmpty())
        <a href="{{ route('work-orders.index') }}" class="nav-link {{ request()->routeIs('work-orders.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-clipboard-check"></i></span>
            <span class="nav-text">Perintah Kerja Bengkel</span>
        </a>
    @endif
    @if ($user->branchesWithPermission('invoice.view')->isNotEmpty())
        <a href="{{ route('invoices.index') }}" class="nav-link {{ request()->routeIs('invoices.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-receipt"></i></span>
            <span class="nav-text">Invoice</span>
        </a>
    @endif
    @if ($user->branchesWithPermission('payment.view')->isNotEmpty())
        <a href="{{ route('payment-receipts.index') }}" class="nav-link {{ request()->routeIs('payment-receipts.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-cash-coin"></i></span>
            <span class="nav-text">Penerimaan Pembayaran</span>
        </a>
    @endif
@endif

@if ($user && ($user->branchesWithPermission('sparepart.view')->isNotEmpty() || $user->branchesWithPermission('receipt.view')->isNotEmpty() || $user->branchesWithPermission('stock_adjustment.view')->isNotEmpty() || $user->branchesWithPermission('stock_transfer.view')->isNotEmpty()))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Persediaan</div>
    @if ($user->branchesWithPermission('sparepart.view')->isNotEmpty())
        <a href="{{ route('sparepart-branches.index') }}" class="nav-link {{ request()->routeIs('sparepart-branches.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-box-seam"></i></span>
            <span class="nav-text">Master Sparepart</span>
        </a>
        <a href="{{ route('stock-card.index') }}" class="nav-link {{ request()->routeIs('stock-card.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-card-list"></i></span>
            <span class="nav-text">Kartu Stok</span>
        </a>
    @endif
    @if ($user->branchesWithPermission('receipt.view')->isNotEmpty())
        <a href="{{ route('goods-receipts.index') }}" class="nav-link {{ request()->routeIs('goods-receipts.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-truck"></i></span>
            <span class="nav-text">Penerimaan Barang</span>
        </a>
    @endif
    @if ($user->branchesWithPermission('stock_adjustment.view')->isNotEmpty())
        <a href="{{ route('stock-adjustments.index') }}" class="nav-link {{ request()->routeIs('stock-adjustments.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-sliders"></i></span>
            <span class="nav-text">Stock Adjustment</span>
        </a>
    @endif
    @if ($user->branchesWithPermission('stock_transfer.view')->isNotEmpty())
        <a href="{{ route('stock-transfers.index') }}" class="nav-link {{ request()->routeIs('stock-transfers.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-arrow-left-right"></i></span>
            <span class="nav-text">Transfer Stock</span>
        </a>
    @endif
@endif

@if ($user && ($user->can('branch.view') || $user->can('customer.view') || $user->can('vehicle.view') || $user->can('vehicle_reference.view') || $user->can('mechanic.view') || $user->can('service.view') || $user->can('rack.view')))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Master Data</div>
    @can('branch.view')
        <a href="{{ route('branches.index') }}" class="nav-link {{ request()->routeIs('branches.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-shop"></i></span>
            <span class="nav-text">Cabang</span>
        </a>
    @endcan
    @can('customer.view')
        <a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-person-badge"></i></span>
            <span class="nav-text">Customer</span>
        </a>
    @endcan
    @can('vehicle.view')
        <a href="{{ route('vehicles.index') }}" class="nav-link {{ request()->routeIs('vehicles.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-car-front"></i></span>
            <span class="nav-text">Kendaraan</span>
        </a>
    @endcan
    @can('vehicle_reference.view')
        <a href="{{ route('vehicle-references.index') }}" class="nav-link {{ request()->routeIs('vehicle-references.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-diagram-3"></i></span>
            <span class="nav-text">Referensi Kendaraan</span>
        </a>
    @endcan
    @can('mechanic.view')
        <a href="{{ route('mechanics.index') }}" class="nav-link {{ request()->routeIs('mechanics.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-person-gear"></i></span>
            <span class="nav-text">Mekanik</span>
        </a>
    @endcan
    @can('service.view')
        <a href="{{ route('service-catalogs.index') }}" class="nav-link {{ request()->routeIs('service-catalogs.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-tools"></i></span>
            <span class="nav-text">Jasa Service</span>
        </a>
    @endcan
    @can('rack.view')
        <a href="{{ route('racks.index') }}" class="nav-link {{ request()->routeIs('racks.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-grid-3x3-gap"></i></span>
            <span class="nav-text">Rack</span>
        </a>
    @endcan
@endif

@if ($user && ($user->can('user.view') || $user->can('audit_log.view')))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Administrasi</div>
    @can('user.view')
        <a href="{{ route('users.index') }}" class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-people"></i></span>
            <span class="nav-text">Users</span>
        </a>
    @endcan
    @can('audit_log.view')
        <a href="{{ route('audit-logs.index') }}" class="nav-link {{ request()->routeIs('audit-logs.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-journal-text"></i></span>
            <span class="nav-text">Audit Log</span>
        </a>
    @endcan
@endif

@if ($user && ($user->branchesWithPermission('report.pkb.view')->isNotEmpty() || $user->branchesWithPermission('report.invoice.view')->isNotEmpty() || $user->branchesWithPermission('report.workshop_performance.view')->isNotEmpty() || $user->branchesWithPermission('report.receivable.view')->isNotEmpty() || $user->branchesWithPermission('report.invoice_pkb_gap.view')->isNotEmpty() || $user->branchesWithPermission('report.sparepart.view')->isNotEmpty()))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Reporting</div>
    @if ($user->branchesWithPermission('report.pkb.view')->isNotEmpty())
        <a href="{{ route('reports.pkb.index') }}" class="nav-link {{ request()->routeIs('reports.pkb.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-file-earmark-bar-graph"></i></span>
            <span class="nav-text">Laporan PKB</span>
        </a>
    @endif
    @if ($user->branchesWithPermission('report.invoice.view')->isNotEmpty())
        <a href="{{ route('reports.invoices.index') }}" class="nav-link {{ request()->routeIs('reports.invoices.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-file-earmark-text"></i></span>
            <span class="nav-text">Laporan Invoice</span>
        </a>
    @endif
    @if ($user->branchesWithPermission('report.workshop_performance.view')->isNotEmpty())
        <a href="{{ route('reports.workshop-performance.index') }}" class="nav-link {{ request()->routeIs('reports.workshop-performance.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-speedometer2"></i></span>
            <span class="nav-text">Laporan Performance Bengkel</span>
        </a>
    @endif
    @if ($user->branchesWithPermission('report.receivable.view')->isNotEmpty())
        <a href="{{ route('reports.receivables.index') }}" class="nav-link {{ request()->routeIs('reports.receivables.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-file-earmark-minus"></i></span>
            <span class="nav-text">Laporan Piutang</span>
        </a>
    @endif
    @if ($user->branchesWithPermission('report.invoice_pkb_gap.view')->isNotEmpty())
        <a href="{{ route('reports.invoice-pkb-gap.index') }}" class="nav-link {{ request()->routeIs('reports.invoice-pkb-gap.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-bar-chart-steps"></i></span>
            <span class="nav-text">PKB vs Invoice</span>
        </a>
    @endif
    @if ($user->branchesWithPermission('report.sparepart.view')->isNotEmpty())
        <a href="{{ route('reports.sparepart-stock.index') }}" class="nav-link {{ request()->routeIs('reports.sparepart-stock.*') ? 'active' : '' }}">
            <span class="nav-icon"><i class="bi bi-file-earmark-spreadsheet"></i></span>
            <span class="nav-text">Laporan Sparepart</span>
        </a>
    @endif
@endif
