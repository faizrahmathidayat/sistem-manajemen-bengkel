# Design System Foundation + Navbar/Sidebar Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the app's color/typography design tokens (burnt-orange/IBM Plex Sans → electric-blue/emerald/Plus Jakarta Sans, slate palette) and redesign the shared Navbar and Sidebar, so every existing screen inherits the new visual language and the Sidebar previews the system's full target menu structure with permission-gated placeholders for modules not yet built.

**Architecture:** Pure presentation-layer change — CSS custom properties in one shared partial (`design-tokens.blade.php`), plus HTML/Blade changes to the two shared layout partials (`layouts/app.blade.php` navbar, `partials/sidebar.blade.php`). No database changes, no new backend logic beyond reading permission data that already exists.

**Tech Stack:** Blade, Bootstrap 5 (CDN), Bootstrap Icons, vanilla CSS custom properties — no new dependencies.

## Global Constraints

- Every index/list endpoint uses `->simplePaginate()`, never `->paginate()` — not touched by this plan (no controllers change), noted for completeness.
- No hard deletes anywhere — not applicable, no data operations in this plan.
- Reuse existing component classes; the only new CSS added is for genuinely new elements (topbar search/permission-badge/notification-bell, sidebar placeholder items) — do not hand-roll new one-off inline styles when a token or existing class already covers it.
- Real sidebar/navbar links keep their exact current permission gating (`@can(...)` for global codes, `branchesWithPermission(...)` for branch-scoped codes) — this plan only changes visual grouping/styling of real items and adds gated placeholder items, never changes what a real item requires to appear.
- Placeholder items must never call `route(...)` for a route that doesn't exist — always a `<span>`, never an `<a href>`.

---

### Task 1: Design tokens — palette, typography, component-rule updates

**Files:**
- Modify: `resources/views/partials/design-tokens.blade.php`

**Interfaces:**
- Produces: updated CSS custom properties (`--color-bg`, `--color-surface`, `--color-ink`, `--color-ink-muted`, `--color-border`, `--color-sidebar`, `--color-sidebar-ink`, `--color-sidebar-ink-active`, `--color-accent`, `--color-accent-dark`, `--color-success`, `--color-danger`, and the new `--color-warning`) and `--font-sans` — every later task and every existing screen consumes these by name, unchanged.

- [ ] **Step 1: Replace the file**

Replace the full contents of `resources/views/partials/design-tokens.blade.php` with:

```blade
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --font-sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        --font-mono: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
        --color-bg: #F4F6F9;
        --color-surface: #FFFFFF;
        --color-ink: #0F172A;
        --color-ink-muted: #64748B;
        --color-border: #E2E8F0;
        --color-sidebar: #0F172A;
        --color-sidebar-ink: rgba(241, 245, 249, .68);
        --color-sidebar-ink-active: #FFFFFF;
        --color-accent: #2563EB;
        --color-accent-dark: #1D4ED8;
        --color-success: #10B981;
        --color-danger: #DC2626;
        --color-warning: #F59E0B;
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
        background-color: var(--color-surface) !important;
        border-bottom: 1px solid var(--color-border);
    }
    .topbar .navbar-brand { color: var(--color-ink) !important; font-weight: 700; }
    .topbar .navbar-brand i { color: var(--color-accent); }
    .topbar .btn-outline-light {
        --bs-btn-color: var(--color-ink);
        --bs-btn-border-color: var(--color-border);
        --bs-btn-hover-bg: var(--color-ink);
        --bs-btn-hover-border-color: var(--color-ink);
    }

    /* Sidebar */
    #sidebar { width: 260px; flex-shrink: 0; background-color: var(--color-sidebar) !important; }
    #sidebar .sidebar-heading {
        color: rgba(241, 245, 249, .4);
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
    #sidebar .nav-link:hover { color: var(--color-sidebar-ink-active); background-color: rgba(255, 255, 255, .04); }
    #sidebar .nav-link.active {
        color: var(--color-sidebar-ink-active);
        background-color: color-mix(in srgb, var(--color-accent) 14%, transparent);
        border-left-color: var(--color-accent);
        font-weight: 500;
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

    /* Cards */
    .card {
        border: 1px solid var(--color-border);
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 4px 12px rgba(15, 23, 42, .04);
        border-radius: .75rem;
    }

    /* Tables */
    .table thead th {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: var(--color-ink-muted);
        font-weight: 600;
        border-bottom-width: 1px;
    }
    .table td { vertical-align: middle; }

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
    .stat-card .stat-icon { color: var(--color-accent); font-size: 1.4rem; }

    /* Tabs (user detail) */
    .nav-tabs { border-bottom: 1px solid var(--color-border); }
    .nav-tabs .nav-link { color: var(--color-ink-muted); border: none; border-bottom: 2px solid transparent; font-weight: 500; margin-bottom: -1px; }
    .nav-tabs .nav-link.active { color: var(--color-ink); border-bottom-color: var(--color-accent); background: transparent; }

    /* Accordion (permission tab) */
    .accordion-button:not(.collapsed) { background-color: color-mix(in srgb, var(--color-accent) 6%, transparent); color: var(--color-ink); box-shadow: none; }
    .accordion-button:focus { box-shadow: none; border-color: var(--color-border); }
</style>
```

This is a like-for-like replacement of every existing rule (same selectors, same structure) with new token values, plus the new `--color-warning` variable and the `color-mix()` fix for the two rules that used to hardcode an orange-tinted rgba independently of `--color-accent`. No rule is removed or renamed.

- [ ] **Step 2: Run the full test suite**

Run: `php artisan test`
Expected: PASS, same count as before this change (this step touches only CSS, no HTML/text content) — confirms nothing broke and the Blade file has no syntax error.

- [ ] **Step 3: Commit**

```bash
git add resources/views/partials/design-tokens.blade.php
git commit -m "feat: replace design tokens with electric-blue/emerald palette and Plus Jakarta Sans"
```

---

### Task 2: Navbar redesign — quick search, permission badges, notification bell

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/partials/design-tokens.blade.php`
- Test: `tests/Feature/AppShellTest.php`

**Interfaces:**
- Consumes: `--color-*` tokens (Task 1), `auth()->user()->permissionCodes(): array` (pre-existing, returns the user's global permission codes).
- Produces: no new PHP interfaces — purely additive Blade/CSS.

- [ ] **Step 1: Write the failing tests**

Add these three test methods to `tests/Feature/AppShellTest.php` (inside the `AppShellTest` class, after the existing tests):

```php
    public function test_navbar_shows_quick_search_input_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Cari No. PKB, No. Polisi, Kode Sparepart, No. Invoice', false);
    }

    public function test_navbar_shows_up_to_three_permission_badges_and_overflow_count(): void
    {
        $user = User::factory()->create();
        $codes = ['branch.view', 'customer.view', 'vehicle.view', 'mechanic.view'];

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::create(['code' => $code, 'resource' => $resource, 'action' => $action, 'description' => $code]);
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('branch.view', false);
        $response->assertSee('customer.view', false);
        $response->assertSee('vehicle.view', false);
        $response->assertDontSee('mechanic.view', false);
        $response->assertSee('+1 lainnya', false);
    }

    public function test_navbar_shows_notification_bell(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('bi-bell', false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AppShellTest`
Expected: the three new tests FAIL (no quick-search markup, no permission badges, no bell icon exist yet); the pre-existing tests in this file still pass.

- [ ] **Step 3: Update the navbar**

In `resources/views/layouts/app.blade.php`, replace the `<nav class="navbar topbar ...">...</nav>` block (lines 13-31 of the current file) with:

```blade
    <nav class="navbar topbar px-3 d-flex align-items-center gap-2">
        <button class="btn btn-outline-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar">
            <i class="bi bi-list"></i>
        </button>
        <a class="navbar-brand mb-0" href="{{ route('dashboard') }}">
            <i class="bi bi-tools me-1"></i> Sistem Manajemen Bengkel
        </a>

        @auth
            <div class="topbar-search d-none d-md-flex align-items-center flex-grow-1 mx-2">
                <i class="bi bi-search topbar-search-icon"></i>
                <input type="text" class="form-control form-control-sm topbar-search-input" placeholder="Cari No. PKB, No. Polisi, Kode Sparepart, No. Invoice..." disabled>
            </div>
        @endauth

        <div class="ms-auto d-flex align-items-center gap-3">
            @auth
                @php($permissionCodes = auth()->user()->permissionCodes())
                @if (count($permissionCodes) > 0)
                    <div class="d-none d-lg-flex align-items-center gap-1">
                        @foreach (array_slice($permissionCodes, 0, 3) as $code)
                            <code class="topbar-permission-badge">{{ $code }}</code>
                        @endforeach
                        @if (count($permissionCodes) > 3)
                            <span class="topbar-permission-badge topbar-permission-badge-more">+{{ count($permissionCodes) - 3 }} lainnya</span>
                        @endif
                    </div>
                @endif

                <button type="button" class="btn btn-outline-light btn-sm position-relative" aria-label="Notifikasi">
                    <i class="bi bi-bell"></i>
                    <span class="topbar-notification-badge">3</span>
                </button>

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
```

- [ ] **Step 4: Add the new navbar component CSS**

In `resources/views/partials/design-tokens.blade.php`, add this block right after the existing `/* Topbar */` rule block (after the `.topbar .btn-outline-light { ... }` rule, before `/* Sidebar */`):

```css
    .topbar-search {
        position: relative;
        max-width: 420px;
    }
    .topbar-search-icon {
        position: absolute;
        left: .75rem;
        color: var(--color-ink-muted);
        font-size: .85rem;
        pointer-events: none;
    }
    .topbar-search-input {
        padding-left: 2.1rem;
        background-color: var(--color-bg);
        border-color: var(--color-border);
    }
    .topbar-search-input:disabled { color: var(--color-ink-muted); }

    .topbar-permission-badge {
        font-family: var(--font-mono);
        font-size: .68rem;
        color: var(--color-accent);
        background: color-mix(in srgb, var(--color-accent) 8%, transparent);
        border-radius: .3rem;
        padding: .15rem .45rem;
    }
    .topbar-permission-badge-more {
        color: var(--color-ink-muted);
        background: var(--color-bg);
    }

    .topbar-notification-badge {
        position: absolute;
        top: -.25rem;
        right: -.25rem;
        min-width: 1.1rem;
        height: 1.1rem;
        border-radius: 50%;
        background: var(--color-danger);
        color: #FFFFFF;
        font-size: .62rem;
        font-weight: 600;
        line-height: 1.1rem;
        text-align: center;
        padding: 0 .2rem;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=AppShellTest`
Expected: PASS (all tests in the file, including the three new ones).

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/views/partials/design-tokens.blade.php tests/Feature/AppShellTest.php
git commit -m "feat: redesign navbar with quick search, permission badges, and notification bell"
```

---

### Task 3: Sidebar redesign — full menu structure, permission-gated placeholders

**Files:**
- Modify: `resources/views/partials/sidebar.blade.php`
- Modify: `resources/views/partials/design-tokens.blade.php`
- Test: `tests/Feature/AppShellTest.php`

**Interfaces:**
- Consumes: `User::branchesWithPermission(string $code): Collection` (pre-existing, from the sparepart migration), `$user->can(string $code): bool` (pre-existing Gate/`Gate::before` mechanism).
- Produces: no new PHP interfaces — purely additive Blade/CSS. The permission catalog this task depends on (`pkb.view`, `invoice.view`, `payment.view`, `receipt.view`, `stock_adjustment.view`, `stock_transfer.view`, `audit_log.view`, `report.pkb.view`, `report.invoice.view`, `report.receivable.view`, `report.invoice_pkb_gap.view`, `report.sparepart.view`) already exists in the seeded `permissions` table (migration 002) — no seeder changes needed.

- [ ] **Step 1: Write the failing tests**

Add these six test methods to `tests/Feature/AppShellTest.php` (inside the `AppShellTest` class, after the tests added in Task 2):

```php
    public function test_sidebar_shows_pkb_placeholder_when_user_has_pkb_view_permission_in_a_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Perintah Kerja Bengkel', false);
        $response->assertSee('Segera Hadir', false);
    }

    public function test_sidebar_hides_pkb_placeholder_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Perintah Kerja Bengkel', false);
    }

    public function test_sidebar_shows_kartu_stok_placeholder_alongside_master_sparepart(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'sparepart.view', 'resource' => 'sparepart', 'action' => 'view', 'description' => 'Melihat sparepart']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('sparepart-branches.index'), false);
        $response->assertSee('Kartu Stok', false);
    }

    public function test_sidebar_shows_audit_log_placeholder_when_user_has_audit_log_view_permission(): void
    {
        $permission = Permission::create(['code' => 'audit_log.view', 'resource' => 'audit_log', 'action' => 'view', 'description' => 'Melihat audit log']);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Audit Log', false);
    }

    public function test_sidebar_shows_reporting_placeholder_when_user_has_report_pkb_view_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'report.pkb.view', 'resource' => 'report', 'action' => 'pkb.view', 'description' => 'Melihat laporan PKB']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Laporan PKB', false);
    }

    public function test_sidebar_hides_all_new_placeholder_headings_without_any_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Perintah Kerja Bengkel', false);
        $response->assertDontSee('Invoice', false);
        $response->assertDontSee('Penerimaan Pembayaran', false);
        $response->assertDontSee('Penerimaan Barang', false);
        $response->assertDontSee('Stock Adjustment', false);
        $response->assertDontSee('Transfer Stock', false);
        $response->assertDontSee('Audit Log', false);
        $response->assertDontSee('Laporan PKB', false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AppShellTest`
Expected: the six new tests FAIL (none of the placeholder markup or gating exists yet); all previously-passing tests in this file (including the Task 2 navbar tests) still pass.

- [ ] **Step 3: Replace the sidebar**

Replace the full contents of `resources/views/partials/sidebar.blade.php` with:

```blade
@php($user = auth()->user())

@if ($user && ($user->branchesWithPermission('pkb.view')->isNotEmpty() || $user->branchesWithPermission('invoice.view')->isNotEmpty() || $user->branchesWithPermission('payment.view')->isNotEmpty()))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Operasional</div>
    <ul class="nav flex-column mb-3">
        @if ($user->branchesWithPermission('pkb.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-clipboard-check me-2"></i> Perintah Kerja Bengkel
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
        @if ($user->branchesWithPermission('invoice.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-receipt me-2"></i> Invoice
                <span class="badge-soon">Segera Hadir</span>
            </span>
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
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-card-list me-2"></i> Kartu Stok
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
        @if ($user->branchesWithPermission('receipt.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-truck me-2"></i> Penerimaan Barang
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
        @if ($user->branchesWithPermission('stock_adjustment.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-sliders me-2"></i> Stock Adjustment
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
        @if ($user->branchesWithPermission('stock_transfer.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-arrow-left-right me-2"></i> Transfer Stock
                <span class="badge-soon">Segera Hadir</span>
            </span>
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
```

Note: `branchesWithPermission()` caches all of a branch's granted codes on first call for that branch (see `AuthorizesByPermission::branchPermissionCodes()`), so calling it repeatedly for different codes within this one render (as this file now does — up to 11 times for a user in one branch) costs one query per branch, not one query per call.

- [ ] **Step 4: Add the placeholder-item CSS**

In `resources/views/partials/design-tokens.blade.php`, add this block right after the `#sidebar .nav-link.active { ... }` rule (still inside the `/* Sidebar */` section, before `.app-body { align-items: stretch; }`):

```css
    #sidebar .nav-link.nav-link-disabled {
        cursor: not-allowed;
        color: rgba(241, 245, 249, .35);
    }
    .badge-soon {
        font-family: var(--font-mono);
        font-size: .6rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        background: rgba(241, 245, 249, .12);
        color: rgba(241, 245, 249, .5);
        padding: .1rem .4rem;
        border-radius: .25rem;
        margin-left: auto;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=AppShellTest`
Expected: PASS (all tests in the file — pre-existing, Task 2's, and Task 3's new ones).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS — every test in the project passes with no regressions (this is the widest-blast-radius change so far in this project; the full suite is the strongest signal that no other screen's tests assert on now-changed sidebar/navbar markup in a way that broke).

- [ ] **Step 7: Commit**

```bash
git add resources/views/partials/sidebar.blade.php resources/views/partials/design-tokens.blade.php tests/Feature/AppShellTest.php
git commit -m "feat: redesign sidebar with full menu structure and permission-gated placeholders"
```

---

## Manual verification checklist (after all tasks complete)

1. `php artisan serve` (or the project's `Browser` tool preview), log in as `faiz_rahmat` (all branches/all permissions per `DemoUsersSeeder`).
2. Confirm the new palette (slate background, electric-blue accents, emerald success states) and Plus Jakarta Sans font render across the Dashboard, a Master Data list (e.g. Cabang), and the Users detail page — spot-check no leftover orange (`#E8622C`) or old-font rendering anywhere.
3. Confirm the navbar shows the (disabled) quick-search box, a handful of permission-code badges with a "+N lainnya" overflow, and a notification bell with a static count.
4. Confirm the sidebar shows all five headings (Operasional, Persediaan, Master Data, Administrasi, Reporting) for `faiz_rahmat`, with real items (Master Sparepart, Cabang, Customer, etc.) clickable and placeholder items ("Perintah Kerja Bengkel", "Kartu Stok", "Audit Log", the 5 Laporan items, etc.) showing a muted "Segera Hadir" badge and not clickable.
5. Log in as `romi_ramdani` (BENGKEL1 only, `pkb.view`/`create` + all `laporan` view per `DemoUsersSeeder`) and confirm he sees: the Operasional heading with only "Perintah Kerja Bengkel" as a placeholder (not Invoice/Penerimaan Pembayaran, which he has no permission for), the Reporting heading with all 5 placeholders (he holds all 5 `report.*.view` codes), and no Persediaan/Master Data/Administrasi headings at all (he holds none of those permissions).
