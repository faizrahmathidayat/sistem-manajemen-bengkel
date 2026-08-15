<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
    (function () {
        var theme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', theme);
    })();
    </script>
    <title>@yield('title', 'Sistem Manajemen Bengkel')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    @include('partials.design-tokens')
</head>
<body class="app-shell">
    <nav class="navbar topbar px-3 d-flex align-items-center gap-2">
        <button class="btn btn-outline-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar">
            <i class="bi bi-list"></i>
        </button>
        <a class="navbar-brand mb-0 d-flex align-items-center gap-2" href="{{ route('dashboard') }}">
            <img src="{{ asset('images/logo.png') }}" alt="JMS MOTOR" style="width: 28px; height: 28px; object-fit: contain;">
            JMS MOTOR
        </a>

        <div class="ms-auto d-flex align-items-center gap-3">
            @auth
                @php($permissionCodes = auth()->user()->permissionCodes())
                @if (count($permissionCodes) > 0)
                    <div class="d-none d-lg-flex align-items-center gap-1">
                        {{-- Renders up to 3 of the user's global permission codes as visible page text.
                             Careful with assertDontSee('some.permission.code') in future tests — if the
                             acting user holds that code globally, this navbar block will make the
                             assertion fail regardless of what the test is actually checking. --}}
                        @foreach (array_slice($permissionCodes, 0, 3) as $code)
                            <span class="topbar-permission-badge">{{ $code }}</span>
                        @endforeach
                        @if (count($permissionCodes) > 3)
                            <span class="topbar-permission-badge topbar-permission-badge-more">+{{ count($permissionCodes) - 3 }} lainnya</span>
                        @endif
                    </div>
                @endif

                <span class="small d-none d-sm-inline" style="color: var(--color-ink-muted);">{{ auth()->user()->name }}</span>
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

            @if (session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            @yield('content')
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function () {
        var STORAGE_KEY = 'theme';

        function updateToggleUI(theme) {
            var btn = document.getElementById('themeToggleBtn');
            if (!btn) return;
            var icon = btn.querySelector('i');
            if (theme === 'dark') {
                icon.className = 'bi bi-brightness-high';
                btn.setAttribute('title', 'Mode Terang');
            } else {
                icon.className = 'bi bi-moon-stars';
                btn.setAttribute('title', 'Mode Gelap');
            }
        }

        function applyTheme(theme) {
            document.documentElement.setAttribute('data-bs-theme', theme);
            localStorage.setItem(STORAGE_KEY, theme);
            updateToggleUI(theme);
        }

        var toggleBtn = document.getElementById('themeToggleBtn');
        if (toggleBtn) {
            updateToggleUI(document.documentElement.getAttribute('data-bs-theme') || 'light');
            toggleBtn.addEventListener('click', function () {
                var current = document.documentElement.getAttribute('data-bs-theme') || 'light';
                applyTheme(current === 'dark' ? 'light' : 'dark');
            });
        }
    })();
    </script>
    @stack('scripts')
</body>
</html>
