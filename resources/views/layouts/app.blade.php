<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sistem Manajemen Bengkel')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    @include('partials.design-tokens')
</head>
<body>
    <nav class="navbar topbar px-3 d-flex align-items-center">
        <button class="btn btn-outline-light d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar">
            <i class="bi bi-list"></i>
        </button>
        <a class="navbar-brand" href="{{ route('dashboard') }}">
            <i class="bi bi-tools me-1"></i> Sistem Manajemen Bengkel
        </a>
        <div class="ms-auto d-flex align-items-center">
            @auth
                <span class="small me-3 d-none d-sm-inline" style="color: var(--color-ink-muted);">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </form>
            @endauth
        </div>
    </nav>

    <div class="app-body d-flex">
        <div class="offcanvas-lg offcanvas-start bg-dark text-white" tabindex="-1" id="sidebar">
            <div class="offcanvas-header d-lg-none">
                <h5 class="offcanvas-title">Menu</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebar"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column p-3">
                @include('partials.sidebar')
            </div>
        </div>

        <main class="app-main flex-grow-1 py-4 px-3 px-lg-4">
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            @yield('content')
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
