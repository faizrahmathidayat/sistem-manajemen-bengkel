@php($user = auth()->user())

@if ($user && $user->can('branch.view'))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Master Data</div>
    <ul class="nav nav-pills flex-column mb-3">
        <li class="nav-item">
            <a href="{{ route('branches.index') }}" class="nav-link {{ request()->routeIs('branches.*') ? 'active' : '' }}">
                <i class="bi bi-shop me-2"></i> Cabang
            </a>
        </li>
    </ul>
@endif

@if ($user && $user->can('user.view'))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Administrasi</div>
    <ul class="nav nav-pills flex-column mb-3">
        <li class="nav-item">
            <a href="{{ route('users.index') }}" class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                <i class="bi bi-people me-2"></i> Users
            </a>
        </li>
    </ul>
@endif
