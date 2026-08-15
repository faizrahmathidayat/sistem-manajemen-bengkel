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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    @include('partials.design-tokens')
</head>
<body class="auth-body">
    <button class="icon-button theme-toggle auth-theme-toggle" type="button" id="themeToggleBtn" title="Mode Gelap">
        <i class="bi bi-moon-stars"></i>
    </button>

    @yield('content')

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
</body>
</html>
