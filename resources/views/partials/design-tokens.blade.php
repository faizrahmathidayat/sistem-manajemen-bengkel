<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    /* Design tokens ported precisely from the adminHMD template (docs/adminhmd) —
       variable names keep the --color-* convention already used across this file
       and asserted by feature tests; values are copied from docs/adminhmd/assets/css/style.css. */
    :root {
        --font-sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        --font-mono: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
        --color-bg: #F5F7FB;
        --color-surface: #FFFFFF;
        --color-surface-soft: #F8FAFC;
        --color-ink: #1F2937;
        --color-ink-muted: #6B7280;
        --color-border: #DBE4EF;
        --color-sidebar: #FFFFFF;
        --color-sidebar-border: #DBE4EF;
        --color-sidebar-soft: #EEF4FF;
        --color-sidebar-ink: #475569;
        --color-sidebar-ink-active: #0F172A;
        --color-sidebar-heading: rgba(15, 23, 42, .45);
        --color-sidebar-ink-disabled: rgba(15, 23, 42, .35);
        --color-sidebar-icon-bg: #EAF2FF;
        --color-sidebar-icon: #2563EB;
        --color-accent: #2563EB;
        --color-accent-dark: #1D4ED8;
        --color-success: #0F766E;
        --color-danger: #DC2626;
        --color-warning: #D97706;
        --color-shadow-sm: 0 10px 24px rgba(15, 23, 42, .06);
        --color-shadow: 0 18px 46px rgba(15, 23, 42, .09);
        --color-shadow-lg: 0 26px 70px rgba(15, 23, 42, .12);
        --color-sidebar-shadow: 18px 0 42px rgba(15, 23, 42, .08);
        --color-ring: 0 0 0 4px rgba(37, 99, 235, .12);
    }

    html[data-bs-theme="dark"] {
        --color-bg: #0F172A;
        --color-surface: #182235;
        --color-surface-soft: #111827;
        --color-ink: #E5EDF7;
        --color-ink-muted: #9AA8BD;
        --color-border: #2F3B52;
        --color-sidebar: #090F1D;
        --color-sidebar-border: rgba(255, 255, 255, .08);
        --color-sidebar-soft: #172033;
        --color-sidebar-ink: #D1D5DB;
        --color-sidebar-ink-active: #FFFFFF;
        --color-sidebar-heading: rgba(241, 245, 249, .4);
        --color-sidebar-ink-disabled: rgba(241, 245, 249, .35);
        --color-sidebar-icon-bg: rgba(255, 255, 255, .08);
        --color-sidebar-icon: #BFDBFE;
        --color-accent: #60A5FA;
        --color-accent-dark: #3B82F6;
        --color-success: #2DD4BF;
        --color-danger: #F87171;
        --color-warning: #FBBF24;
        --color-shadow-sm: 0 10px 24px rgba(0, 0, 0, .24);
        --color-shadow: 0 18px 46px rgba(0, 0, 0, .32);
        --color-shadow-lg: 0 26px 70px rgba(0, 0, 0, .42);
        --color-sidebar-shadow: 18px 0 42px rgba(0, 0, 0, .34);
        --color-ring: 0 0 0 4px rgba(96, 165, 250, .18);
    }

    body {
        font-family: var(--font-sans);
        background: linear-gradient(180deg, #F8FBFF 0%, var(--color-bg) 42%, #EEF4FA 100%);
        color: var(--color-ink);
    }
    html[data-bs-theme="dark"] body {
        background: linear-gradient(180deg, #111827 0%, var(--color-bg) 48%, #0B1120 100%);
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

    /* Topbar (adminHMD .admin-navbar) */
    .topbar {
        background-color: rgba(255, 255, 255, .94) !important;
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border-bottom: 1px solid var(--color-border);
        box-shadow: var(--color-shadow-sm);
        position: sticky;
        top: 0;
        z-index: 1020;
    }
    html[data-bs-theme="dark"] .topbar {
        background-color: rgba(24, 34, 53, .92) !important;
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


    /* Sidebar (adminHMD .admin-sidebar / .sidebar-nav) */
    #sidebar {
        width: 260px;
        flex-shrink: 0;
        background-color: var(--color-sidebar) !important;
        border-right: 1px solid var(--color-sidebar-border);
        box-shadow: var(--color-sidebar-shadow);
    }
    #sidebar .sidebar-heading {
        color: var(--color-sidebar-heading);
        font-size: .7rem;
        letter-spacing: .08em;
        font-weight: 600;
    }
    #sidebar .nav-link {
        display: flex;
        align-items: center;
        gap: .75rem;
        color: var(--color-sidebar-ink);
        border-left: 3px solid transparent;
        border-radius: 8px;
        padding: .6rem .75rem;
        font-weight: 700;
        transition: background .16s ease, color .16s ease, transform .16s ease;
    }
    #sidebar .nav-link:hover,
    #sidebar .nav-link:focus {
        color: var(--color-sidebar-ink-active);
        background-color: var(--color-sidebar-soft);
        transform: translateX(2px);
    }
    #sidebar .nav-link.active {
        color: var(--color-sidebar-ink-active);
        background: linear-gradient(135deg, var(--color-accent), var(--color-accent-dark));
        font-weight: 700;
        box-shadow: 0 0 12px rgba(37, 99, 235, .35);
    }
    #sidebar .nav-link.nav-link-disabled {
        cursor: not-allowed;
        color: var(--color-sidebar-ink-disabled);
    }
    #sidebar .nav-link.nav-link-disabled:hover {
        background-color: transparent;
        color: var(--color-sidebar-ink-disabled);
        transform: none;
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

    /* Buttons (adminHMD .btn) */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: .4rem;
        border-radius: .5rem;
        font-weight: 700;
        box-shadow: 0 8px 18px rgba(15, 23, 42, .05);
        transition: border-color .16s ease, background .16s ease, box-shadow .16s ease, transform .16s ease;
    }
    html[data-bs-theme="dark"] .btn { box-shadow: 0 8px 18px rgba(0, 0, 0, .2); }
    .btn:hover, .btn:focus { transform: translateY(-1px); }
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
    .btn-light {
        border-color: var(--color-border);
        background: var(--color-surface-soft);
        color: var(--color-ink);
    }
    .btn-outline-secondary {
        border-color: var(--color-border);
        color: var(--color-ink);
    }
    .btn-outline-secondary:hover,
    .btn-outline-secondary:focus {
        border-color: var(--color-accent);
        background: var(--color-sidebar-icon-bg);
        color: var(--color-accent-dark);
    }
    html[data-bs-theme="dark"] .btn-outline-secondary:hover,
    html[data-bs-theme="dark"] .btn-outline-secondary:focus {
        background: #1E3A5F;
        color: #BFDBFE;
    }

    /* Form controls (adminHMD .form-control / .form-select) */
    .form-control,
    .form-select {
        border-color: var(--color-border);
        background-color: var(--color-surface);
        color: var(--color-ink);
        transition: background-color .18s ease, border-color .18s ease, color .18s ease, box-shadow .18s ease;
    }
    .form-control:focus,
    .form-select:focus {
        border-color: #93C5FD;
        background-color: var(--color-surface);
        color: var(--color-ink);
        box-shadow: var(--color-ring);
    }
    .form-control::placeholder { color: var(--color-ink-muted); }

    /* Page heading (adminHMD .page-heading) */
    .page-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.35rem;
    }
    .page-heading h1 { font-weight: 800; letter-spacing: 0; }
    .page-heading-copy {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 1rem;
        min-width: 0;
    }
    .eyebrow {
        color: var(--color-accent);
        font-size: .78rem;
        font-weight: 800;
        text-transform: uppercase;
    }
    .heading-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: .65rem;
    }
    .page-icon,
    .section-title i {
        display: inline-grid;
        place-items: center;
        flex: 0 0 auto;
        border-radius: 8px;
        background: var(--color-sidebar-icon-bg);
        color: var(--color-accent-dark);
    }
    .page-icon { width: 48px; height: 48px; font-size: 1.25rem; }
    .section-title { display: inline-flex; align-items: center; gap: .55rem; }
    .section-title i { width: 34px; height: 34px; font-size: .95rem; }

    /* Panel / elevated surface (adminHMD .panel, replaces bare .card for
       form and content surfaces) */
    .panel,
    .card {
        border: 1px solid var(--color-border);
        border-radius: 8px;
        background: var(--color-surface);
        box-shadow: var(--color-shadow);
        transition: border-color .18s ease, box-shadow .18s ease;
    }
    .panel { padding: 1.35rem; }
    .panel:hover,
    .card:hover:not(:focus-within) {
        border-color: #C6D5E8;
        box-shadow: var(--color-shadow-lg);
    }
    html[data-bs-theme="dark"] .panel:hover,
    html[data-bs-theme="dark"] .card:hover:not(:focus-within) {
        border-color: #35435E;
    }
    .panel-header { margin-bottom: 1.25rem; }
    .panel-header p { font-size: .92rem; }

    /* Tables (adminHMD .table) */
    .table {
        --bs-table-bg: transparent;
        --bs-table-color: var(--color-ink);
        --bs-table-border-color: var(--color-border);
        color: var(--color-ink);
    }
    .table thead th {
        color: var(--color-ink-muted);
        font-size: .78rem;
        text-transform: uppercase;
        white-space: nowrap;
        border-bottom: none;
    }
    .table tbody td { vertical-align: middle; padding-block: 1rem; }
    .table tbody tr {
        border-bottom: 1px solid var(--color-border);
        transition: background .16s ease;
    }
    .table tbody tr:hover { background: #F8FBFF; }
    html[data-bs-theme="dark"] .table tbody tr:hover { background: #111827; }

    /* Table action buttons (soft pill, kept for edit/delete color semantics
       not present in adminHMD's sample tables) */
    .table .btn-outline-primary,
    .table .btn-outline-danger {
        border: none;
        border-radius: 8px;
        padding: .35rem .65rem;
        font-weight: 500;
        box-shadow: none;
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
