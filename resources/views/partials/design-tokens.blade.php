<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    /* Design tokens ported precisely from the adminHMD template (docs/adminhmd) —
       variable names keep the --color-* convention already used across this file
       and asserted by feature tests; values are copied from docs/adminhmd/assets/css/style.css. */
    :root {
        --sidebar-width: 280px;
        --sidebar-mini-width: 88px;
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

    /* App shell (authenticated layout only, adminHMD .admin-shell) */
    body.app-shell { min-height: 100vh; }
    .admin-shell { min-height: 100vh; }
    .sidebar-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1030;
        display: none;
        background: rgba(15, 23, 42, .5);
    }
    .admin-main {
        width: auto;
        min-width: 0;
        min-height: 100vh;
        margin-left: var(--sidebar-width);
        transition: margin-left .2s ease;
    }
    .dashboard-content { min-height: calc(100vh - 72px); }
    .admin-footer {
        padding: 1.1rem 0 1.35rem;
        color: var(--color-ink-muted);
        font-size: .9rem;
    }
    .admin-footer .container-fluid {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }

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
        flex: 0 0 auto;
    }

    /* Navbar controls (adminHMD .sidebar-toggle / .icon-button / .profile-button) */
    .navbar-actions { display: flex; align-items: center; gap: .75rem; }
    .sidebar-toggle {
        width: 42px;
        height: 42px;
        display: inline-grid;
        place-items: center;
        gap: 4px;
        padding: 9px;
        border: 1px solid var(--color-border);
        border-radius: 8px;
        background: var(--color-surface);
        box-shadow: 0 8px 18px rgba(15, 23, 42, .05);
        transition: border-color .16s ease, background .16s ease, box-shadow .16s ease;
    }
    .sidebar-toggle:hover,
    .sidebar-toggle:focus {
        border-color: #93C5FD;
        background: var(--color-sidebar-icon-bg);
        box-shadow: var(--color-ring);
    }
    .sidebar-toggle span {
        width: 18px;
        height: 2px;
        display: block;
        background: var(--color-ink);
        border-radius: 999px;
    }
    .icon-button,
    .profile-button {
        border: 1px solid var(--color-border);
        background: var(--color-surface);
        color: var(--color-ink);
        box-shadow: 0 8px 18px rgba(15, 23, 42, .05);
        transition: border-color .16s ease, box-shadow .16s ease;
    }
    .icon-button:hover,
    .icon-button:focus,
    .profile-button:hover,
    .profile-button:focus {
        border-color: #93C5FD;
        box-shadow: var(--color-ring);
    }
    .icon-button {
        position: relative;
        width: 42px;
        height: 42px;
        border-radius: 8px;
        font-weight: 800;
        display: inline-grid;
        place-items: center;
    }
    .profile-button {
        display: flex;
        align-items: center;
        gap: .55rem;
        min-height: 42px;
        padding: .35rem .65rem;
        border-radius: 8px;
        font-weight: 700;
    }

    /* Sidebar (adminHMD .admin-sidebar / .sidebar-nav) */
    #sidebar {
        position: fixed;
        inset: 0 auto 0 0;
        z-index: 1040;
        width: var(--sidebar-width);
        display: flex;
        flex-direction: column;
        overflow-x: hidden;
        overflow-y: auto;
        scrollbar-width: thin;
        background-color: var(--color-sidebar) !important;
        border-right: 1px solid var(--color-sidebar-border);
        box-shadow: var(--color-sidebar-shadow);
        transform: translateX(0);
        transition: width .2s ease, transform .2s ease;
    }
    #sidebar::-webkit-scrollbar { width: 6px; }
    #sidebar::-webkit-scrollbar-thumb { background: var(--color-sidebar-border); border-radius: 999px; }
    #sidebar::-webkit-scrollbar-track { background: transparent; }
    .sidebar-header {
        padding: 1.35rem 1.25rem 1.15rem;
        border-bottom: 1px solid var(--color-sidebar-border);
    }
    .brand-mark {
        display: flex;
        align-items: center;
        gap: .75rem;
        min-width: 0;
        color: var(--color-sidebar-ink-active) !important;
        text-decoration: none;
    }
    .brand-icon {
        display: inline-grid;
        place-items: center;
        flex: 0 0 auto;
        width: 42px;
        height: 42px;
        border-radius: 8px;
        background: linear-gradient(135deg, var(--color-accent), var(--color-success));
        overflow: hidden;
    }
    .brand-copy,
    .nav-text,
    .sidebar-footer-text {
        min-width: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: opacity .16s ease, width .16s ease;
    }
    .brand-title { display: block; font-size: 1.05rem; font-weight: 800; line-height: 1.2; }
    .brand-subtitle { display: block; font-size: .78rem; line-height: 1.2; color: var(--color-sidebar-heading); }

    #sidebar .sidebar-nav {
        display: grid;
        gap: .35rem;
        padding: 1.1rem .75rem;
        min-width: 0;
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
        gap: .6rem;
        min-height: 48px;
        min-width: 0;
        overflow: hidden;
        color: var(--color-sidebar-ink);
        border-left: 3px solid transparent;
        border-radius: 8px;
        padding: .6rem .65rem;
        font-weight: 700;
        font-size: .92rem;
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
    .nav-icon {
        display: inline-grid;
        place-items: center;
        flex: 0 0 auto;
        width: 30px;
        height: 30px;
        border-radius: 8px;
        background: var(--color-sidebar-icon-bg);
        color: var(--color-sidebar-icon);
        font-size: .8rem;
    }
    #sidebar .nav-link.active .nav-icon {
        background: rgba(255, 255, 255, .18);
        color: #fff;
    }

    .sidebar-user {
        margin: auto 1rem 1rem;
        padding: .85rem;
        display: grid;
        justify-items: center;
        gap: .22rem;
        border: 1px solid var(--color-sidebar-border);
        border-radius: 8px;
        background: var(--color-sidebar-soft);
        text-align: center;
    }
    .sidebar-user-avatar {
        width: 48px;
        height: 48px;
        font-size: 1.1rem;
        border-radius: 50%;
        box-shadow: 0 0 0 3px var(--color-success);
    }
    .sidebar-user strong { color: var(--color-sidebar-ink-active); font-size: 1rem; line-height: 1.1; }
    .sidebar-user small { color: var(--color-sidebar-ink); font-size: .84rem; }

    .sidebar-footer {
        display: flex;
        align-items: center;
        gap: .65rem;
        margin-inline: 1.25rem;
        padding: 1rem 0;
        color: var(--color-sidebar-ink);
        border-top: 1px solid var(--color-sidebar-border);
        font-size: .9rem;
    }
    .app-status-dot {
        width: 9px;
        height: 9px;
        flex: 0 0 auto;
        border-radius: 50%;
        background: var(--color-success);
    }

    @media (min-width: 992px) {
        body.sidebar-mini #sidebar { width: var(--sidebar-mini-width); }
        body.sidebar-mini .admin-main { margin-left: var(--sidebar-mini-width); }
        body.sidebar-mini .brand-copy,
        body.sidebar-mini .nav-text,
        body.sidebar-mini .sidebar-footer-text {
            width: 0;
            opacity: 0;
            overflow: hidden;
        }
        body.sidebar-mini .sidebar-header,
        body.sidebar-mini .sidebar-footer { margin-inline: .75rem; padding-inline: 0; }
        body.sidebar-mini #sidebar .sidebar-nav { padding-inline: .5rem; }
        body.sidebar-mini #sidebar .nav-link { justify-content: center; gap: 0; padding: .65rem .5rem; }
        body.sidebar-mini #sidebar .sidebar-heading { padding-inline: 0; text-align: center; font-size: 0; }
        body.sidebar-mini .sidebar-user { display: none; }
    }

    @media (max-width: 991.98px) {
        #sidebar { width: min(var(--sidebar-width), calc(100vw - 48px)); transform: translateX(-100%); }
        .admin-main { margin-left: 0; }
        body.sidebar-open { overflow: hidden; }
        body.sidebar-open #sidebar { transform: translateX(0); }
        body.sidebar-open .sidebar-backdrop { display: block; }
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

    /* Auth pages (adminHMD .auth-body / .auth-card / .auth-brand) */
    .auth-body {
        min-height: 100vh;
        background: linear-gradient(135deg, #F8FBFF 0%, #EAF2FF 44%, #EEF8F6 100%);
    }
    html[data-bs-theme="dark"] .auth-body {
        background: linear-gradient(135deg, #0B1120 0%, #111827 48%, #10201F 100%);
    }
    .auth-page {
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 1.5rem;
    }
    .auth-card {
        width: min(100%, 420px);
        border: 1px solid var(--color-border);
        border-radius: 8px;
        background: var(--color-surface);
        box-shadow: var(--color-shadow-lg);
        padding: 1.5rem;
    }
    .auth-brand {
        display: flex;
        align-items: center;
        gap: .75rem;
        margin-bottom: 1.5rem;
        color: var(--color-ink) !important;
        text-decoration: none;
    }
    .auth-brand span:last-child { display: grid; line-height: 1.2; }
    .auth-brand small { color: var(--color-ink-muted); }
    .auth-theme-toggle {
        position: fixed;
        top: 1rem;
        right: 1rem;
        z-index: 10;
    }
</style>
