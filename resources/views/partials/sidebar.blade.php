@php($user = auth()->user())

@if ($user && ($user->branchesWithPermission('pkb.view')->isNotEmpty() || $user->branchesWithPermission('invoice.view')->isNotEmpty() || $user->branchesWithPermission('payment.view')->isNotEmpty()))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Operasional</div>
    <ul class="nav flex-column mb-3">
        @if ($user->branchesWithPermission('pkb.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('work-orders.index') }}" class="nav-link {{ request()->routeIs('work-orders.*') ? 'active' : '' }}">
                <i class="bi bi-clipboard-check me-2"></i> Perintah Kerja Bengkel
            </a>
        </li>
        @endif
        @if ($user->branchesWithPermission('invoice.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('invoices.index') }}" class="nav-link {{ request()->routeIs('invoices.*') ? 'active' : '' }}">
                <i class="bi bi-receipt me-2"></i> Invoice
            </a>
        </li>
        @endif
        @if ($user->branchesWithPermission('payment.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-cash-coin me-2"></i> Penerimaan Pembayaran
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
    </ul>
@endif

@if ($user && ($user->branchesWithPermission('sparepart.view')->isNotEmpty() || $user->branchesWithPermission('receipt.view')->isNotEmpty() || $user->branchesWithPermission('stock_adjustment.view')->isNotEmpty() || $user->branchesWithPermission('stock_transfer.view')->isNotEmpty()))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Persediaan</div>
    <ul class="nav flex-column mb-3">
        @if ($user->branchesWithPermission('sparepart.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('sparepart-branches.index') }}" class="nav-link {{ request()->routeIs('sparepart-branches.*') ? 'active' : '' }}">
                <i class="bi bi-box-seam me-2"></i> Master Sparepart
            </a>
        </li>
        <li class="nav-item">
            <a href="{{ route('stock-card.index') }}" class="nav-link {{ request()->routeIs('stock-card.*') ? 'active' : '' }}">
                <i class="bi bi-card-list me-2"></i> Kartu Stok
            </a>
        </li>
        @endif
        @if ($user->branchesWithPermission('receipt.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('goods-receipts.index') }}" class="nav-link {{ request()->routeIs('goods-receipts.*') ? 'active' : '' }}">
                <i class="bi bi-truck me-2"></i> Penerimaan Barang
            </a>
        </li>
        @endif
        @if ($user->branchesWithPermission('stock_adjustment.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('stock-adjustments.index') }}" class="nav-link {{ request()->routeIs('stock-adjustments.*') ? 'active' : '' }}">
                <i class="bi bi-sliders me-2"></i> Stock Adjustment
            </a>
        </li>
        @endif
        @if ($user->branchesWithPermission('stock_transfer.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('stock-transfers.index') }}" class="nav-link {{ request()->routeIs('stock-transfers.*') ? 'active' : '' }}">
                <i class="bi bi-arrow-left-right me-2"></i> Transfer Stock
            </a>
        </li>
        @endif
    </ul>
@endif

@if ($user && ($user->can('branch.view') || $user->can('customer.view') || $user->can('vehicle.view') || $user->can('vehicle_reference.view') || $user->can('mechanic.view') || $user->can('service.view')))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Master Data</div>
    <ul class="nav flex-column mb-3">
        @can('branch.view')
        <li class="nav-item">
            <a href="{{ route('branches.index') }}" class="nav-link {{ request()->routeIs('branches.*') ? 'active' : '' }}">
                <i class="bi bi-shop me-2"></i> Cabang
            </a>
        </li>
        @endcan
        @can('customer.view')
        <li class="nav-item">
            <a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.*') ? 'active' : '' }}">
                <i class="bi bi-person-badge me-2"></i> Customer
            </a>
        </li>
        @endcan
        @can('vehicle.view')
        <li class="nav-item">
            <a href="{{ route('vehicles.index') }}" class="nav-link {{ request()->routeIs('vehicles.*') ? 'active' : '' }}">
                <i class="bi bi-car-front me-2"></i> Kendaraan
            </a>
        </li>
        @endcan
        @can('vehicle_reference.view')
        <li class="nav-item">
            <a href="{{ route('vehicle-references.index') }}" class="nav-link {{ request()->routeIs('vehicle-references.*') ? 'active' : '' }}">
                <i class="bi bi-diagram-3 me-2"></i> Referensi Kendaraan
            </a>
        </li>
        @endcan
        @can('mechanic.view')
        <li class="nav-item">
            <a href="{{ route('mechanics.index') }}" class="nav-link {{ request()->routeIs('mechanics.*') ? 'active' : '' }}">
                <i class="bi bi-person-gear me-2"></i> Mekanik
            </a>
        </li>
        @endcan
        @can('service.view')
        <li class="nav-item">
            <a href="{{ route('service-catalogs.index') }}" class="nav-link {{ request()->routeIs('service-catalogs.*') ? 'active' : '' }}">
                <i class="bi bi-tools me-2"></i> Jasa Service
            </a>
        </li>
        @endcan
    </ul>
@endif

@if ($user && ($user->can('user.view') || $user->can('audit_log.view')))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Administrasi</div>
    <ul class="nav flex-column mb-3">
        @can('user.view')
        <li class="nav-item">
            <a href="{{ route('users.index') }}" class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                <i class="bi bi-people me-2"></i> Users
            </a>
        </li>
        @endcan
        @can('audit_log.view')
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-journal-text me-2"></i> Audit Log
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endcan
    </ul>
@endif

@if ($user && ($user->branchesWithPermission('report.pkb.view')->isNotEmpty() || $user->branchesWithPermission('report.invoice.view')->isNotEmpty() || $user->branchesWithPermission('report.receivable.view')->isNotEmpty() || $user->branchesWithPermission('report.invoice_pkb_gap.view')->isNotEmpty() || $user->branchesWithPermission('report.sparepart.view')->isNotEmpty()))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Reporting</div>
    <ul class="nav flex-column mb-3">
        @if ($user->branchesWithPermission('report.pkb.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-file-earmark-bar-graph me-2"></i> Laporan PKB
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
        @if ($user->branchesWithPermission('report.invoice.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-file-earmark-text me-2"></i> Laporan Invoice
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
        @if ($user->branchesWithPermission('report.receivable.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-file-earmark-minus me-2"></i> Laporan Piutang
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
        @if ($user->branchesWithPermission('report.invoice_pkb_gap.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-bar-chart-steps me-2"></i> PKB vs Invoice
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
        @if ($user->branchesWithPermission('report.sparepart.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i> Laporan Sparepart
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
    </ul>
@endif
