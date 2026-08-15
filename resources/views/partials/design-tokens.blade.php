<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --font-sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        --font-mono: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
        --color-bg: #F8FAFC;
        --color-surface: #FFFFFF;
        --color-ink: #0F172A;
        --color-ink-muted: #64748B;
        --color-border: #E2E8F0;
        --color-sidebar: #FFFFFF;
        --color-sidebar-border: #E2E8F0;
        --color-sidebar-ink: #334155;
        --color-sidebar-ink-active: #0F172A;
        --color-sidebar-heading: rgba(15, 23, 42, .45);
        --color-sidebar-ink-disabled: rgba(15, 23, 42, .35);
        --color-accent: #2563EB;
        --color-accent-dark: #1D4ED8;
        --color-success: #10B981;
        --color-danger: #DC2626;
        --color-warning: #F59E0B;
    }

    html[data-bs-theme="dark"] {
        --color-bg: #0B0F17;
        --color-surface: #1E293B;
        --color-ink: #F1F5F9;
        --color-ink-muted: #94A3B8;
        --color-border: rgba(51, 65, 85, .6);
        --color-accent: #3B82F6;
        --color-accent-dark: #2563EB;
        --color-sidebar: #1E293B;
        --color-sidebar-border: rgba(51, 65, 85, .6);
        --color-sidebar-ink: rgba(241, 245, 249, .68);
        --color-sidebar-ink-active: #FFFFFF;
        --color-sidebar-heading: rgba(241, 245, 249, .4);
        --color-sidebar-ink-disabled: rgba(241, 245, 249, .35);
    }

    html[data-bs-theme="dark"] .card {
        box-shadow: 0 4px 20px -2px rgba(0, 0, 0, .35);
    }

    html[data-bs-theme="dark"] .card:hover:not(:focus-within) {
        box-shadow: 0 8px 30px -4px rgba(0, 0, 0, .5);
    }

    body {
        font-family: var(--font-sans);
        background-color: var(--color-bg);
        color: var(--color-ink);
    }

    /* App shell (authenticated layout only) — keeps the sidebar's dark
       background reaching the bottom of the viewport even when page
       content is shorter than the screen, while still growing taller
       than the viewport for long pages (see .app-body below). */
    body.app-shell {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
    .app-body { flex: 1 1 auto; }

    code, .font-mono { font-family: var(--font-mono); }

    h1, h2, h3, h4, h5, .navbar-brand {
        font-family: var(--font-sans);
        font-weight: 600;
        letter-spacing: -.01em;
    }

    /* Topbar */
    .topbar {
        background-color: rgba(255, 255, 255, .72) !important;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border-bottom: 1px solid rgba(226, 232, 240, .6);
        position: sticky;
        top: 0;
        z-index: 1020;
    }
    html[data-bs-theme="dark"] .topbar {
        background-color: rgba(11, 15, 23, .72) !important;
        border-bottom: 1px solid rgba(255, 255, 255, .05);
    }
    .topbar .navbar-brand { color: var(--color-ink) !important; font-weight: 700; }
    .topbar .navbar-brand i { color: var(--color-accent); }
    .topbar .btn-outline-light {
        --bs-btn-color: var(--color-ink);
        --bs-btn-border-color: var(--color-border);
        --bs-btn-hover-color: var(--color-ink);
        --bs-btn-hover-bg: rgba(15, 23, 42, .06);
        --bs-btn-hover-border-color: var(--color-border);
    }

    .topbar-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: var(--color-accent);
        color: #fff;
        font-weight: 600;
        font-size: .8rem;
    }


    /* Sidebar */
    #sidebar { width: 260px; flex-shrink: 0; background-color: var(--color-sidebar) !important; border-right: 1px solid var(--color-sidebar-border); }
    #sidebar .sidebar-heading {
        color: var(--color-sidebar-heading);
        font-size: .7rem;
        letter-spacing: .08em;
        font-weight: 600;
    }
    #sidebar .nav-link {
        display: flex;
        align-items: center;
        color: var(--color-sidebar-ink);
        border-left: 3px solid transparent;
        border-radius: 0;
        padding: .5rem .75rem;
    }
    #sidebar .nav-link:hover { color: var(--color-sidebar-ink-active); background-color: color-mix(in srgb, var(--color-sidebar-ink-active) 8%, transparent); }
    #sidebar .nav-link.active {
        color: var(--color-sidebar-ink-active);
        background: linear-gradient(135deg, #3B82F6, #2563EB);
        font-weight: 600;
        box-shadow: 0 0 12px rgba(59, 130, 246, .35);
    }
    #sidebar .nav-link.nav-link-disabled {
        cursor: not-allowed;
        color: var(--color-sidebar-ink-disabled);
    }
    #sidebar .nav-link.nav-link-disabled:hover {
        background-color: transparent;
        color: var(--color-sidebar-ink-disabled);
    }
    .badge-soon {
        font-family: var(--font-mono);
        font-size: .6rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        background: rgba(51, 65, 85, .5);
        color: #94A3B8;
        border: 1px solid rgba(71, 85, 105, .3);
        padding: .1rem .5rem;
        border-radius: 999px;
        margin-left: auto;
    }
    .dashboard-loading-overlay {
        position: absolute;
        inset: 0;
        background: rgba(255, 255, 255, .7);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 5;
    }
    .dashboard-loading-parent { position: relative; min-height: 80px; }

    .page-loading-overlay {
        position: fixed;
        inset: 0;
        background: rgba(248, 250, 252, .72);
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: .85rem;
        z-index: 2000;
    }
    html[data-bs-theme="dark"] .page-loading-overlay {
        background: rgba(11, 15, 23, .72);
    }
    .page-loading-overlay .loading-spinner {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        border: 3px solid rgba(37, 99, 235, .15);
        border-top-color: var(--color-accent);
        box-shadow: 0 0 16px rgba(37, 99, 235, .45);
        animation: loadingSpin .7s linear infinite;
    }
    @@keyframes loadingSpin {
        to { transform: rotate(360deg); }
    }
    .page-loading-overlay .loading-text {
        font-family: var(--font-mono);
        font-size: .8rem;
        color: var(--color-ink-muted);
        letter-spacing: .04em;
    }

    @@keyframes rowFadeSlideIn {
        from { opacity: 0; transform: translateY(-8px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .line-row-enter {
        animation: rowFadeSlideIn .2s ease-out;
    }

    .app-body { align-items: stretch; }

    /* Buttons */
    .btn-primary {
        --bs-btn-bg: var(--color-accent);
        --bs-btn-border-color: var(--color-accent);
        --bs-btn-hover-bg: var(--color-accent-dark);
        --bs-btn-hover-border-color: var(--color-accent-dark);
        --bs-btn-active-bg: var(--color-accent-dark);
        --bs-btn-active-border-color: var(--color-accent-dark);
    }
    .btn-outline-primary {
        --bs-btn-color: var(--color-accent);
        --bs-btn-border-color: var(--color-accent);
        --bs-btn-hover-bg: var(--color-accent);
        --bs-btn-hover-border-color: var(--color-accent);
    }
    .btn { border-radius: .5rem; font-weight: 500; }

    /* Form controls (soft rounded, AdminHMD style) */
    .form-control,
    .form-select {
        border-radius: 10px;
        background-color: var(--color-surface);
        border: 1px solid var(--color-border);
        color: var(--color-ink);
    }
    .form-control:focus,
    .form-select:focus {
        background-color: var(--color-surface);
        color: var(--color-ink);
        border-color: var(--color-accent);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, .2);
    }

    /* Cards */
    .card {
        border: 1px solid var(--color-border);
        border-radius: 14px;
        box-shadow: 0 4px 20px -2px rgba(0, 0, 0, .05);
        transition: transform .15s ease, box-shadow .15s ease;
    }
    .card:hover:not(:focus-within) {
        transform: translateY(-2px);
        box-shadow: 0 8px 30px -4px rgba(0, 0, 0, .08);
    }

    /* Tables */
    .table thead th {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: var(--color-ink-muted);
        font-weight: 600;
        border-bottom: none;
        background: var(--color-bg);
    }
    .table td { vertical-align: middle; }
    .table tbody tr { border-bottom: 1px solid var(--color-border); }

    .table-hover > tbody > tr:hover > * {
        background-color: rgba(0, 0, 0, .015);
        color: var(--color-ink);
    }
    html[data-bs-theme="dark"] .table-hover > tbody > tr:hover > * {
        background-color: rgba(255, 255, 255, .02);
        color: #F1F5F9;
    }

    /* Table action buttons (soft pill, AdminHMD style) */
    .table .btn-outline-primary,
    .table .btn-outline-danger {
        border: none;
        border-radius: 8px;
        padding: .35rem .65rem;
        font-weight: 500;
    }
    .table .btn-outline-primary {
        background-color: rgba(37, 99, 235, .12);
        color: #2563EB;
    }
    .table .btn-outline-primary:hover,
    .table .btn-outline-primary:focus {
        background-color: rgba(37, 99, 235, .2);
        color: #2563EB;
    }
    html[data-bs-theme="dark"] .table .btn-outline-primary,
    html[data-bs-theme="dark"] .table .btn-outline-primary:hover,
    html[data-bs-theme="dark"] .table .btn-outline-primary:focus {
        color: #3B82F6;
    }
    .table .btn-outline-danger,
    .table .btn-outline-danger:hover,
    .table .btn-outline-danger:focus {
        background-color: rgba(239, 68, 68, .12);
        color: #EF4444;
    }
    .table .btn-outline-danger:hover,
    .table .btn-outline-danger:focus {
        background-color: rgba(239, 68, 68, .2);
    }

    /* Status indicator */
    .status-dot {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        font-family: var(--font-mono);
        font-size: .76rem;
        text-transform: uppercase;
        letter-spacing: .03em;
    }
    .status-dot::before {
        content: '';
        width: .5rem;
        height: .5rem;
        border-radius: 50%;
        background-color: currentColor;
        flex: none;
    }
    .status-dot.status-active { color: var(--color-success); }
    .status-dot.status-inactive { color: var(--color-ink-muted); }
    .status-dot.status-warning { color: var(--color-warning); }
    .status-dot.status-danger { color: var(--color-danger); }

    /* Stat cards */
    .stat-card {
        border: 1px solid var(--color-border);
        border-radius: .5rem;
        background: var(--color-surface);
        padding: 1.25rem 1.5rem;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
    }
    .stat-card .stat-value { font-family: var(--font-mono); font-size: 2rem; font-weight: 600; line-height: 1; color: var(--color-ink); }
    .stat-card .stat-label { font-size: .76rem; color: var(--color-ink-muted); text-transform: uppercase; letter-spacing: .05em; margin-top: .4rem; }
    .stat-card .stat-icon {
        color: var(--color-accent);
        font-size: 1.1rem;
        width: 44px;
        height: 44px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: color-mix(in srgb, var(--color-accent) 12%, transparent);
        flex: none;
    }

    /* Tabs (user detail) */
    .nav-tabs { border-bottom: 1px solid var(--color-border); }
    .nav-tabs .nav-link { color: var(--color-ink-muted); border: none; border-bottom: 2px solid transparent; font-weight: 500; margin-bottom: -1px; }
    .nav-tabs .nav-link.active { color: var(--color-ink); border-bottom-color: var(--color-accent); background: transparent; }

    /* Accordion (permission tab) */
    .accordion-button:not(.collapsed) { background-color: color-mix(in srgb, var(--color-accent) 6%, transparent); color: var(--color-ink); box-shadow: none; }
    .accordion-button:focus { box-shadow: none; border-color: var(--color-border); }
</style>
