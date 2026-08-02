@php($user = auth()->user())

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

@if ($user && $user->branchesWithPermission('sparepart.view')->isNotEmpty())
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Persediaan</div>
    <ul class="nav flex-column mb-3">
        <li class="nav-item">
            <a href="{{ route('sparepart-branches.index') }}" class="nav-link {{ request()->routeIs('sparepart-branches.*') ? 'active' : '' }}">
                <i class="bi bi-box-seam me-2"></i> Master Sparepart
            </a>
        </li>
    </ul>
@endif

@if ($user && $user->can('user.view'))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Administrasi</div>
    <ul class="nav flex-column mb-3">
        <li class="nav-item">
            <a href="{{ route('users.index') }}" class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                <i class="bi bi-people me-2"></i> Users
            </a>
        </li>
    </ul>
@endif
