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
    <div class="page-loading-overlay d-none" id="globalLoadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">Memuat...</div>
    </div>

    <div class="admin-shell">
        <div class="sidebar-backdrop" data-sidebar-close></div>

        <aside class="admin-sidebar" id="sidebar" aria-label="Navigasi utama">
            <div class="sidebar-header">
                <a class="brand-mark" href="{{ route('dashboard') }}" aria-label="JMS MOTOR">
                    <span class="brand-icon"><img src="{{ asset('images/logo.png') }}" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:inherit;"></span>
                    <span class="brand-copy">
                        <span class="brand-title">JMS MOTOR</span>
                        <span class="brand-subtitle">Sistem Manajemen Bengkel</span>
                    </span>
                </a>
            </div>

            <nav class="sidebar-nav">
                @include('partials.sidebar')
            </nav>

            @auth
            <div class="sidebar-user">
                <span class="topbar-avatar sidebar-user-avatar">{{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                <strong>{{ auth()->user()->name }}</strong>
                <small>{{ now()->locale('id')->translatedFormat('l, d F Y') }}</small>
            </div>
            @endauth

            <div class="sidebar-footer">
                <span class="app-status-dot"></span>
                <span class="sidebar-footer-text">JMS MOTOR v1.0</span>
            </div>
        </aside>

        <div class="admin-main">
            <nav class="navbar admin-navbar topbar navbar-expand bg-white">
                <div class="container-fluid px-3 px-lg-4">
                    <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-controls="sidebar" aria-expanded="true" aria-label="Buka/tutup sidebar">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>

                    <div class="navbar-actions ms-auto">
                        @auth
                            <span class="small d-none d-lg-inline" id="topbarDate" style="color: var(--color-ink-muted);">{{ now()->locale('id')->translatedFormat('l, d F Y') }}</span>

                            <button type="button" class="icon-button theme-toggle" id="themeToggleBtn" title="Mode Gelap">
                                <i class="bi bi-moon-stars"></i>
                            </button>

                            <div class="dropdown">
                                <button class="profile-button dropdown-toggle" type="button" id="profileDropdownToggle" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="topbar-avatar">{{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                                    <span class="profile-name d-none d-sm-inline">{{ auth()->user()->name }}</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdownToggle">
                                    <li>
                                        <form method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <button type="submit" class="dropdown-item">
                                                <i class="bi bi-box-arrow-right me-2"></i> Logout
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        @endauth
                    </div>
                </div>
            </nav>

            <main class="dashboard-content app-body">
                <div class="container-fluid px-3 px-lg-4 py-4">
                    @if (session('status'))
                        <div class="alert alert-success">{{ session('status') }}</div>
                    @endif

                    @if (session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    @yield('content')
                </div>
            </main>

            <footer class="admin-footer">
                <div class="container-fluid px-3 px-lg-4">
                    <span>JMS MOTOR &mdash; Sistem Manajemen Bengkel</span>
                </div>
            </footer>
        </div>
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
    <script>
    (function () {
        var MINI_KEY = 'sidebarMini';
        var body = document.body;
        var sidebarToggle = document.querySelector('[data-sidebar-toggle]');
        var closeButtons = document.querySelectorAll('[data-sidebar-close]');
        var sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
        var desktopQuery = window.matchMedia('(min-width: 992px)');

        function isDesktop() {
            return desktopQuery.matches;
        }

        if (isDesktop() && localStorage.getItem(MINI_KEY) === 'true') {
            body.classList.add('sidebar-mini');
        }

        function closeMobileSidebar() {
            body.classList.remove('sidebar-open');
        }

        function toggleSidebar() {
            if (isDesktop()) {
                body.classList.toggle('sidebar-mini');
                localStorage.setItem(MINI_KEY, body.classList.contains('sidebar-mini'));
            } else {
                body.classList.toggle('sidebar-open');
            }
        }

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', toggleSidebar);
        }

        Array.prototype.forEach.call(closeButtons, function (el) {
            el.addEventListener('click', closeMobileSidebar);
        });

        Array.prototype.forEach.call(sidebarLinks, function (link) {
            link.addEventListener('click', function () {
                if (!isDesktop()) closeMobileSidebar();
            });
        });

        desktopQuery.addEventListener('change', function (event) {
            if (event.matches) {
                body.classList.remove('sidebar-open');
            } else {
                body.classList.remove('sidebar-mini');
            }
        });
    })();
    </script>
    <script>
    (function () {
        var overlay = document.getElementById('globalLoadingOverlay');
        var appBody = document.querySelector('.app-body');
        var sidebarNav = document.getElementById('sidebar');
        if (!overlay || !appBody) return;

        function showOverlay() {
            overlay.classList.remove('d-none');
        }

        function hideOverlay() {
            overlay.classList.add('d-none');
        }

        // Clicking any link shows the overlay right before the browser navigates away.
        // If the user later hits the back/forward button to return to *this* page, most
        // browsers restore it from bfcache instead of reloading it — which resumes the
        // exact in-memory DOM state from the moment it was left, overlay still visible
        // included, since bfcache restoration re-shows the page without rerunning any
        // load-time script. `pageshow` fires on every such restore (and harmlessly on a
        // normal fresh load too, where the overlay is already hidden), so hiding it here
        // is what makes back/forward navigation land on a page that isn't stuck loading.
        window.addEventListener('pageshow', hideOverlay);

        function handleLinkClick(event) {
            var container = event.currentTarget;
            var link = event.target.closest('a[href]');
            if (!link || !container.contains(link)) return;
            if (link.target === '_blank') return;
            if (link.hasAttribute('download')) return;
            if (link.hasAttribute('data-no-loading')) return;
            var href = link.getAttribute('href');
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
            showOverlay();
        }

        appBody.addEventListener('click', handleLinkClick);
        appBody.addEventListener('submit', function (event) {
            if (event.target.hasAttribute('data-no-loading')) return;
            showOverlay();
        });

        // The sidebar lives outside .app-body (it's a sibling of .admin-main,
        // not a descendant), so without this, navigating via the sidebar —
        // the only way to move between pages on mobile after opening the
        // hamburger menu — never triggered the loading spinner.
        if (sidebarNav) {
            sidebarNav.addEventListener('click', handleLinkClick);
        }
    })();
    </script>
    @stack('scripts')
</body>
</html>
