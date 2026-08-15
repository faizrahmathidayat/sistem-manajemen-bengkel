# Redesign UI Total (Dark Mode, Global Overlay, Component Polish) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **Do NOT use superpowers:subagent-driven-development or the Agent/subagent tool for this project — standing project rule is no subagents, ever.**

**Goal:** Redesign JMS MOTOR's UI to be modern/elegan/sleek with a persistent Light/Dark theme switcher and a global page-loading overlay, without adding any JS framework or build-step dependency.

**Architecture:** Pure extension of the existing single-source-of-truth styling file (`partials/design-tokens.blade.php`, inline `<style>` included by both layouts) using native Bootstrap 5.3 `data-bs-theme` + CSS custom-property redefinition for dark mode. All new JS is vanilla, IIFE-wrapped, added inline in `layouts/app.blade.php` — matching the codebase's existing zero-framework convention. No PHP business logic, no migrations, no new permissions.

**Tech Stack:** Laravel 8.75 / PHP 7.4, Blade, Bootstrap 5.3.3 (CDN), vanilla JS, PHPUnit Feature tests.

**Spec:** `docs/superpowers/specs/2026-08-15-ui-redesign-design.md`

## Global Constraints

- PHP 7.4 floor — no PHP 8-only syntax anywhere (no `?->`, no `match`, no string-keyed array spread, no `str_contains`/`str_starts_with`).
- No subagents — this plan is executed inline, one task at a time, in this session.
- No new npm/composer dependencies, no Mix/Vite build-step changes — all CSS/JS stays inline in the existing Blade partials/layouts.
- No changes to DB schema, models, controllers, routes, or permissions — this is presentation-layer only.
- No changes to `layouts/print.blade.php`.
- Default theme on first visit (no `localStorage` value yet): always **Light**.
- Sidebar follows the Light/Dark toggle (not permanently dark).
- Permission badges are removed from the navbar entirely (not moved to the dropdown).
- The old per-page `sendEmailOverlay` (`invoices/show.blade.php`, commit `940a539`) is deleted and replaced by the new global overlay mechanism.

---

## File Structure

| File | Responsibility |
|---|---|
| `resources/views/partials/design-tokens.blade.php` | Single source of all CSS: light tokens (`:root`), dark tokens (`html[data-bs-theme="dark"]`), every component style. Touched by Tasks 1, 3, 4. |
| `resources/views/layouts/app.blade.php` | Authenticated shell: anti-FOUC script, navbar, global overlay markup + script. Touched by Tasks 1, 2, 3. |
| `resources/views/layouts/guest.blade.php` | Login shell: anti-FOUC script only (no toggle button). Touched by Task 1. |
| `resources/views/invoices/show.blade.php` | Loses its page-specific overlay block (superseded by the global one). Touched by Task 3. |
| `resources/views/work-orders/_line_item_scripts.blade.php` | Dynamic PKB line-editor — gains row-enter animation class. Touched by Task 4. |
| `resources/views/invoices/_line_item_scripts.blade.php` | Dynamic Invoice line-editor — gains row-enter animation class. Touched by Task 4. |
| `tests/Feature/ThemeSwitcherTest.php` | New — Task 1 coverage. |
| `tests/Feature/NavbarRedesignTest.php` | New — Task 2 coverage. |
| `tests/Feature/GlobalLoadingOverlayTest.php` | New — Task 3 coverage. |
| `tests/Feature/ComponentPolishTest.php` | New — Task 4 coverage. |
| `tests/Feature/InvoicePrintEmailTest.php` | Loses its 2 tests that assert on the now-deleted per-page overlay markup. Touched by Task 5. |
| `tests/Feature/UiRedesignLayoutTest.php` | New — Task 5, cross-cutting regression test spanning Tasks 1–4 on real pages. |

**Note on test scope per task:** each task's own "run tests" step runs only that task's new test file (fast, focused feedback — matches this plan's TDD granularity). The two pre-existing `InvoicePrintEmailTest` tests that reference the deleted `sendEmailOverlay` markup will fail starting after Task 3 — that is expected and is fixed in Task 5, which is also where the full suite is run.

---

### Task 1: Foundation Theme Switcher & Design Tokens

**Files:**
- Modify: `resources/views/partials/design-tokens.blade.php:21-22`
- Modify: `resources/views/layouts/app.blade.php:1-11` and `:74-78`
- Modify: `resources/views/layouts/guest.blade.php:1-10`
- Test: `tests/Feature/ThemeSwitcherTest.php`

**Interfaces:**
- Produces: CSS custom properties `--color-bg`, `--color-surface`, `--color-ink`, `--color-ink-muted`, `--color-border`, `--color-accent`, `--color-accent-dark` redefined inside a `html[data-bs-theme="dark"] { ... }` block — Task 4 appends more properties into this same block. Produces DOM attribute `<html data-bs-theme="light|dark">` and `localStorage.theme`, which Task 2's toggle button reads/writes via `#themeToggleBtn` (looked up defensively — `if (toggleBtn) { ... }` — since the button doesn't exist until Task 2).

- [x] **Step 1: Write the failing test**

Create `tests/Feature/ThemeSwitcherTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_layout_includes_anti_fouc_script_before_design_tokens(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSeeInOrder([
            "localStorage.getItem('theme')",
            'data-bs-theme',
            '--color-bg: #F8FAFC;',
        ], false);
    }

    public function test_guest_layout_includes_anti_fouc_script(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee("localStorage.getItem('theme')", false);
    }

    public function test_authenticated_layout_includes_dark_mode_token_overrides(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('html[data-bs-theme="dark"]', false);
        $response->assertSee('--color-bg: #0B0F17;', false);
    }

    public function test_authenticated_layout_includes_theme_toggle_handler_script(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee("getElementById('themeToggleBtn')", false);
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ThemeSwitcherTest`
Expected: FAIL — none of the new markup/CSS exists yet.

- [x] **Step 3: Add dark-mode CSS tokens to `design-tokens.blade.php`**

Find (end of the existing `:root { ... }` block, right before `body {`):

```css
        --color-warning: #F59E0B;
    }

    body {
```

Replace with:

```css
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
    }

    html[data-bs-theme="dark"] .card {
        box-shadow: 0 4px 20px -2px rgba(0, 0, 0, .35);
    }

    html[data-bs-theme="dark"] .card:hover:not(:focus-within) {
        box-shadow: 0 8px 30px -4px rgba(0, 0, 0, .5);
    }

    body {
```

- [x] **Step 4: Add the anti-FOUC script to `layouts/app.blade.php`**

Find:

```blade
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sistem Manajemen Bengkel')</title>
```

Replace with:

```blade
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
    (function () {
        var theme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', theme);
    })();
    </script>
    <title>@yield('title', 'Sistem Manajemen Bengkel')</title>
```

- [x] **Step 5: Add the same anti-FOUC script to `layouts/guest.blade.php`**

Find:

```blade
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sistem Manajemen Bengkel')</title>
```

Replace with:

```blade
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
    (function () {
        var theme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', theme);
    })();
    </script>
    <title>@yield('title', 'Sistem Manajemen Bengkel')</title>
```

- [x] **Step 6: Add the theme-toggle handler script to `layouts/app.blade.php`**

Find:

```blade
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
```

Replace with:

```blade
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
```

- [x] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=ThemeSwitcherTest`
Expected: PASS (4/4)

- [x] **Step 8: Commit**

```bash
git add resources/views/partials/design-tokens.blade.php resources/views/layouts/app.blade.php resources/views/layouts/guest.blade.php tests/Feature/ThemeSwitcherTest.php
git commit -m "feat: add dark mode design tokens and theme toggle foundation"
```

---

### Task 2: Navbar Redesign & Profile Dropdown

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (navbar right-side block)
- Modify: `resources/views/partials/design-tokens.blade.php` (`.topbar-permission-badge*` removed, `.topbar-avatar` added)
- Test: `tests/Feature/NavbarRedesignTest.php`

**Interfaces:**
- Consumes: `#themeToggleBtn` handler script from Task 1 (button markup added here activates it — no JS changes needed in this task).
- Produces: `#themeToggleBtn` button element, `#profileDropdownToggle` dropdown trigger — no later task depends on new names beyond what's already used here.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/NavbarRedesignTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavbarRedesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_navbar_no_longer_displays_permission_badges(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('topbar-permission-badge', false);
    }

    public function test_navbar_displays_todays_date_in_indonesian(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(now()->locale('id')->translatedFormat('l, d F Y'));
    }

    public function test_navbar_displays_theme_toggle_button(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('id="themeToggleBtn"', false);
    }

    public function test_navbar_displays_profile_dropdown_with_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('id="profileDropdownToggle"', false);
        $response->assertSee(route('logout'), false);
        $response->assertSee(strtoupper(mb_substr($user->name, 0, 1)), false);
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=NavbarRedesignTest`
Expected: FAIL — old badge markup still present, new elements missing.

- [x] **Step 3: Restructure the navbar in `layouts/app.blade.php`**

Find:

```blade
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
```

Replace with:

```blade
        <div class="ms-auto d-flex align-items-center gap-3">
            @auth
                <span class="small d-none d-lg-inline" id="topbarDate" style="color: var(--color-ink-muted);">{{ now()->locale('id')->translatedFormat('l, d F Y') }}</span>

                <button type="button" class="btn btn-outline-light btn-sm" id="themeToggleBtn" title="Mode Gelap">
                    <i class="bi bi-moon-stars"></i>
                </button>

                <div class="dropdown">
                    <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2" type="button" id="profileDropdownToggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="topbar-avatar">{{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                        <span class="d-none d-sm-inline">{{ auth()->user()->name }}</span>
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
```

- [x] **Step 4: Replace the now-dead permission-badge CSS with `.topbar-avatar` in `design-tokens.blade.php`**

Find:

```css
    .topbar-permission-badge {
        font-size: .75rem;
        color: #2563EB;
        background: #EFF6FF;
        border: 1px solid #DBEAFE;
        border-radius: 999px;
        padding: .25rem .625rem;
    }
    .topbar-permission-badge-more {
        color: var(--color-ink-muted);
        background: var(--color-bg);
        border-color: var(--color-border);
    }
```

Replace with:

```css
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
```

- [x] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=NavbarRedesignTest`
Expected: PASS (4/4)

- [x] **Step 6: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/views/partials/design-tokens.blade.php tests/Feature/NavbarRedesignTest.php
git commit -m "feat: redesign navbar with profile dropdown, remove permission badges"
```

---

### Task 3: Global Loading Overlay

**Files:**
- Modify: `resources/views/partials/design-tokens.blade.php` (`.page-loading-overlay` block)
- Modify: `resources/views/layouts/app.blade.php` (overlay markup + delegation script)
- Modify: `resources/views/invoices/show.blade.php` (delete page-specific overlay block)
- Test: `tests/Feature/GlobalLoadingOverlayTest.php`

**Interfaces:**
- Consumes: `.app-body` wrapper element (already exists in `layouts/app.blade.php`, unchanged).
- Produces: `#globalLoadingOverlay` element and `data-no-loading` escape-hatch attribute — no later task depends on these.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/GlobalLoadingOverlayTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalLoadingOverlayTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_includes_global_loading_overlay_markup(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('id="globalLoadingOverlay"', false);
        $response->assertSee('page-loading-overlay d-none', false);
    }

    public function test_layout_includes_navigation_and_submit_delegation_script(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee("getElementById('globalLoadingOverlay')", false);
        $response->assertSeeInOrder([
            "appBody.addEventListener('click'",
            "appBody.addEventListener('submit'",
        ], false);
    }

    public function test_design_tokens_include_blurred_backdrop_and_neon_spinner_styles(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('backdrop-filter: blur(5px);', false);
        $response->assertSee('loadingSpin', false);
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GlobalLoadingOverlayTest`
Expected: FAIL — global overlay markup/script doesn't exist yet.

- [x] **Step 3: Update `.page-loading-overlay` styles in `design-tokens.blade.php`**

Find:

```css
    .page-loading-overlay {
        position: fixed;
        inset: 0;
        background: rgba(255, 255, 255, .85);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: .75rem;
        z-index: 2000;
    }
```

Replace with:

```css
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
    @keyframes loadingSpin {
        to { transform: rotate(360deg); }
    }
    .page-loading-overlay .loading-text {
        font-family: var(--font-mono);
        font-size: .8rem;
        color: var(--color-ink-muted);
        letter-spacing: .04em;
    }
```

- [x] **Step 4: Add the overlay markup and delegation script to `layouts/app.blade.php`**

Find:

```blade
<body class="app-shell">
    <nav class="navbar topbar px-3 d-flex align-items-center gap-2">
```

Replace with:

```blade
<body class="app-shell">
    <div class="page-loading-overlay d-none" id="globalLoadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">Memuat...</div>
    </div>

    <nav class="navbar topbar px-3 d-flex align-items-center gap-2">
```

Find (the closing of the theme-toggle script added in Task 1):

```blade
            toggleBtn.addEventListener('click', function () {
                var current = document.documentElement.getAttribute('data-bs-theme') || 'light';
                applyTheme(current === 'dark' ? 'light' : 'dark');
            });
        }
    })();
    </script>
    @stack('scripts')
```

Replace with:

```blade
            toggleBtn.addEventListener('click', function () {
                var current = document.documentElement.getAttribute('data-bs-theme') || 'light';
                applyTheme(current === 'dark' ? 'light' : 'dark');
            });
        }
    })();
    </script>
    <script>
    (function () {
        var overlay = document.getElementById('globalLoadingOverlay');
        var appBody = document.querySelector('.app-body');
        if (!overlay || !appBody) return;

        function showOverlay() {
            overlay.classList.remove('d-none');
        }

        appBody.addEventListener('click', function (event) {
            var link = event.target.closest('a[href]');
            if (!link || !appBody.contains(link)) return;
            if (link.target === '_blank') return;
            if (link.hasAttribute('download')) return;
            if (link.hasAttribute('data-no-loading')) return;
            var href = link.getAttribute('href');
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
            showOverlay();
        });

        appBody.addEventListener('submit', function (event) {
            if (event.target.hasAttribute('data-no-loading')) return;
            showOverlay();
        });
    })();
    </script>
    @stack('scripts')
```

- [x] **Step 5: Delete the page-specific overlay block from `invoices/show.blade.php`**

Find:

```blade
    @can('sendEmail', $invoice)
        <div class="page-loading-overlay d-none" id="sendEmailOverlay">
            <div class="spinner-border text-primary" role="status"></div>
            <div>Mengirim email...</div>
        </div>

        @push('scripts')
        <script>
        document.getElementById('sendEmailForm').addEventListener('submit', function () {
            document.getElementById('sendEmailButton').disabled = true;
            document.getElementById('sendEmailOverlay').classList.remove('d-none');
        });
        </script>
        @endpush
    @endcan
@endsection
```

Replace with:

```blade
@endsection
```

- [x] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=GlobalLoadingOverlayTest`
Expected: PASS (3/3)

Note: `php artisan test --filter=InvoicePrintEmailTest` will now show 2 failures (`test_show_page_includes_send_email_loading_overlay_and_script_when_permitted`, `test_show_page_hides_send_email_loading_overlay_without_email_permission`) — expected, fixed in Task 5.

- [x] **Step 7: Commit**

```bash
git add resources/views/partials/design-tokens.blade.php resources/views/layouts/app.blade.php resources/views/invoices/show.blade.php tests/Feature/GlobalLoadingOverlayTest.php
git commit -m "feat: consolidate loading feedback into a global page overlay"
```

---

### Task 4: Component Polish & Dynamic Table Animations

**Files:**
- Modify: `resources/views/partials/design-tokens.blade.php` (sidebar tokens, stat-icon badge, table header, row-enter animation)
- Modify: `resources/views/work-orders/_line_item_scripts.blade.php`
- Modify: `resources/views/invoices/_line_item_scripts.blade.php`
- Test: `tests/Feature/ComponentPolishTest.php`

**Interfaces:**
- Consumes: the `html[data-bs-theme="dark"] { ... }` block from Task 1 (appends 5 more custom properties into it) and the `:root { ... }` block's existing `--color-sidebar*` declarations (redefines their values).
- Produces: `.line-row-enter` class, consumed by both `_line_item_scripts.blade.php` files' `appendChild` call sites.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/ComponentPolishTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComponentPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function grantBranchPermission(User $user, Branch $branch, string $code): void
    {
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    public function test_design_tokens_include_theme_aware_sidebar_variables(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('--color-sidebar: #FFFFFF;', false);
        $response->assertSee('--color-sidebar-border: #E2E8F0;', false);
        $response->assertSee('--color-sidebar: #1E293B;', false);
    }

    public function test_design_tokens_include_stat_icon_badge_and_row_animation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('color-mix(in srgb, var(--color-accent) 12%, transparent)', false);
        $response->assertSee('@keyframes rowFadeSlideIn', false);
        $response->assertSee('.line-row-enter', false);
    }

    public function test_work_order_create_page_includes_row_enter_animation_class(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs($user)->get(route('work-orders.create'));

        $response->assertOk();
        $response->assertSee("classList.add('line-row-enter')", false);
    }

    public function test_invoice_create_direct_page_includes_row_enter_animation_class(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->get(route('invoices.createDirect'));

        $response->assertOk();
        $response->assertSee("classList.add('line-row-enter')", false);
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ComponentPolishTest`
Expected: FAIL — sidebar still permanently dark, no stat-icon badge, no row animation.

- [x] **Step 3: Make the sidebar theme-aware — light tokens in `:root`**

Find:

```css
        --color-sidebar: #0F172A;
        --color-sidebar-ink: rgba(241, 245, 249, .68);
        --color-sidebar-ink-active: #FFFFFF;
```

Replace with:

```css
        --color-sidebar: #FFFFFF;
        --color-sidebar-border: #E2E8F0;
        --color-sidebar-ink: #334155;
        --color-sidebar-ink-active: #0F172A;
        --color-sidebar-heading: rgba(15, 23, 42, .45);
        --color-sidebar-ink-disabled: rgba(15, 23, 42, .35);
```

- [x] **Step 4: Add dark-mode sidebar tokens (append into Task 1's dark block)**

Find:

```css
        --color-accent-dark: #2563EB;
    }
```

Replace with:

```css
        --color-accent-dark: #2563EB;
        --color-sidebar: #1E293B;
        --color-sidebar-border: rgba(51, 65, 85, .6);
        --color-sidebar-ink: rgba(241, 245, 249, .68);
        --color-sidebar-ink-active: #FFFFFF;
        --color-sidebar-heading: rgba(241, 245, 249, .4);
        --color-sidebar-ink-disabled: rgba(241, 245, 249, .35);
    }
```

- [x] **Step 5: Use the new tokens in the sidebar component rules**

Find:

```css
    #sidebar { width: 260px; flex-shrink: 0; background-color: var(--color-sidebar) !important; }
    #sidebar .sidebar-heading {
        color: rgba(241, 245, 249, .4);
        font-size: .7rem;
        letter-spacing: .08em;
        font-weight: 600;
    }
```

Replace with:

```css
    #sidebar { width: 260px; flex-shrink: 0; background-color: var(--color-sidebar) !important; border-right: 1px solid var(--color-sidebar-border); }
    #sidebar .sidebar-heading {
        color: var(--color-sidebar-heading);
        font-size: .7rem;
        letter-spacing: .08em;
        font-weight: 600;
    }
```

Find:

```css
    #sidebar .nav-link:hover { color: var(--color-sidebar-ink-active); background-color: rgba(255, 255, 255, .04); }
    #sidebar .nav-link.active {
```

Replace with:

```css
    #sidebar .nav-link:hover { color: var(--color-sidebar-ink-active); background-color: color-mix(in srgb, var(--color-sidebar-ink-active) 8%, transparent); }
    #sidebar .nav-link.active {
```

Find:

```css
    #sidebar .nav-link.nav-link-disabled {
        cursor: not-allowed;
        color: rgba(241, 245, 249, .35);
    }
    #sidebar .nav-link.nav-link-disabled:hover {
        background-color: transparent;
        color: rgba(241, 245, 249, .35);
    }
```

Replace with:

```css
    #sidebar .nav-link.nav-link-disabled {
        cursor: not-allowed;
        color: var(--color-sidebar-ink-disabled);
    }
    #sidebar .nav-link.nav-link-disabled:hover {
        background-color: transparent;
        color: var(--color-sidebar-ink-disabled);
    }
```

(The disabled-link fix is a direct consequence of making the sidebar theme-aware: the old hardcoded near-white text would be almost invisible on the new white light-mode sidebar.)

- [x] **Step 6: Add the pastel stat-icon badge**

Find:

```css
    .stat-card .stat-icon { color: var(--color-accent); font-size: 1.4rem; }
```

Replace with:

```css
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
```

- [x] **Step 7: Make the table header dark-mode aware**

Find:

```css
    .table thead th {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: var(--color-ink-muted);
        font-weight: 600;
        border-bottom: none;
        background: #F8FAFC;
    }
```

Replace with:

```css
    .table thead th {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: var(--color-ink-muted);
        font-weight: 600;
        border-bottom: none;
        background: var(--color-bg);
    }
```

- [x] **Step 8: Add the row-enter animation**

Find:

```css
    .app-body { align-items: stretch; }
```

Replace with:

```css
    @keyframes rowFadeSlideIn {
        from { opacity: 0; transform: translateY(-8px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .line-row-enter {
        animation: rowFadeSlideIn .2s ease-out;
    }

    .app-body { align-items: stretch; }
```

- [x] **Step 9: Apply `.line-row-enter` in `work-orders/_line_item_scripts.blade.php`**

Find:

```js
        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('serviceLines').appendChild(wrapper);
    }
```

Replace with:

```js
        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            wrapper.remove();
        });
        wrapper.classList.add('line-row-enter');
        document.getElementById('serviceLines').appendChild(wrapper);
    }
```

Find:

```js
        document.getElementById('sparepartLines').appendChild(wrapper);

        initAjaxSelect(select, {
```

Replace with:

```js
        wrapper.classList.add('line-row-enter');
        document.getElementById('sparepartLines').appendChild(wrapper);

        initAjaxSelect(select, {
```

- [x] **Step 10: Apply `.line-row-enter` in `invoices/_line_item_scripts.blade.php`**

Find:

```js
        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('invoiceServiceLines').appendChild(wrapper);
        return wrapper;
    }
```

Replace with:

```js
        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            wrapper.remove();
        });
        wrapper.classList.add('line-row-enter');
        document.getElementById('invoiceServiceLines').appendChild(wrapper);
        return wrapper;
    }
```

Find:

```js
        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            if ($(select).data('select2')) $(select).select2('destroy');
            wrapper.remove();
        });
        document.getElementById('invoiceSparepartLines').appendChild(wrapper);
        return wrapper;
    }
```

Replace with:

```js
        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            if ($(select).data('select2')) $(select).select2('destroy');
            wrapper.remove();
        });
        wrapper.classList.add('line-row-enter');
        document.getElementById('invoiceSparepartLines').appendChild(wrapper);
        return wrapper;
    }
```

- [x] **Step 11: Run test to verify it passes**

Run: `php artisan test --filter=ComponentPolishTest`
Expected: PASS (4/4)

- [x] **Step 12: Commit**

```bash
git add resources/views/partials/design-tokens.blade.php resources/views/work-orders/_line_item_scripts.blade.php resources/views/invoices/_line_item_scripts.blade.php tests/Feature/ComponentPolishTest.php
git commit -m "feat: make sidebar theme-aware, add stat-icon badges and row-enter animation"
```

---

### Task 5: Regression Coverage, Cross-Cutting Test & Manual Verification

**Files:**
- Modify: `tests/Feature/InvoicePrintEmailTest.php`
- Test: `tests/Feature/UiRedesignLayoutTest.php`

**Interfaces:**
- Consumes: all markup/CSS produced by Tasks 1–4 (`#themeToggleBtn`, `#profileDropdownToggle`, `#globalLoadingOverlay`, `--color-sidebar-border`, anti-FOUC script, dark-mode token block).

- [x] **Step 1: Remove the two obsolete overlay tests from `InvoicePrintEmailTest.php`**

Find:

```php
    public function test_show_page_includes_send_email_loading_overlay_and_script_when_permitted(): void
    {
        // Regression guard: sendEmail became a synchronous request (no more queue), so a slow SMTP
        // server can leave the page looking frozen for a few seconds with no feedback. This overlay
        // is the fix — shown via a 'submit' listener on #sendEmailForm, so it can't be tested through
        // an HTTP-only Feature test's response, but we can assert the markup/script that makes it work
        // is actually present in the page source.
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'invoice.email');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee('id="sendEmailOverlay"', false);
        $response->assertSee("document.getElementById('sendEmailForm').addEventListener('submit'", false);
    }

    public function test_show_page_hides_send_email_loading_overlay_without_email_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertDontSee('id="sendEmailOverlay"', false);
    }
}
```

Replace with:

```php
}
```

(These asserted on the per-page `#sendEmailOverlay` markup deleted in Task 3. Global-overlay coverage now lives in `GlobalLoadingOverlayTest` and the new cross-cutting test below.)

- [x] **Step 2: Run it to confirm the suite is green again**

Run: `php artisan test --filter=InvoicePrintEmailTest`
Expected: PASS (all remaining tests — the 2 obsolete ones are gone, nothing else references the deleted markup)

- [x] **Step 3: Write the cross-cutting layout test**

Create `tests/Feature/UiRedesignLayoutTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\InvoiceService;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiRedesignLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function grantBranchPermission(User $user, Branch $branch, string $code): void
    {
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    protected function makeWorkOrder(Branch $branch): WorkOrder
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso', 'email' => 'budi@example.test']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => "B 1234 {$branch->code}",
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => "SVC-01-{$branch->code}", 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => "OLI-01-{$branch->code}", 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 50000],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000],
            ],
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        return $workOrder->fresh();
    }

    protected function makePostedInvoice(Branch $branch)
    {
        $invoice = (new InvoiceService())->createFromWorkOrder($this->makeWorkOrder($branch));
        (new InvoiceService())->postInvoice($invoice);

        return $invoice->fresh();
    }

    public function test_authenticated_layout_includes_all_redesigned_ui_elements_together(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee("localStorage.getItem('theme')", false);
        $response->assertSee('html[data-bs-theme="dark"]', false);
        $response->assertSee('id="themeToggleBtn"', false);
        $response->assertDontSee('topbar-permission-badge', false);
        $response->assertSee('id="profileDropdownToggle"', false);
        $response->assertSee('id="globalLoadingOverlay"', false);
        $response->assertSee('--color-sidebar-border', false);
    }

    public function test_guest_login_layout_includes_anti_fouc_script_without_theme_toggle_button(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee("localStorage.getItem('theme')", false);
        $response->assertDontSee('id="themeToggleBtn"', false);
    }

    public function test_invoice_show_page_no_longer_has_page_specific_overlay_markup(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'invoice.email');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertDontSee('id="sendEmailOverlay"', false);
        $response->assertSee('id="globalLoadingOverlay"', false);
    }
}
```

- [x] **Step 4: Run it to verify it passes**

Run: `php artisan test --filter=UiRedesignLayoutTest`
Expected: PASS (3/3)

- [x] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS — 0 failures across the whole suite.

- [x] **Step 6: Commit**

```bash
git add tests/Feature/InvoicePrintEmailTest.php tests/Feature/UiRedesignLayoutTest.php
git commit -m "test: fix overlay regression coverage and add cross-cutting UI redesign test"
```

- [ ] **Step 7: Manual Browser Verification Checklist**

Run the dev server, log in, and manually verify each item (all via the actual browser, not automated):

- [ ] Toggle theme to Dark → page repaints instantly, sidebar turns dark, cards/tables/text all readable, icon switches to sun (`bi-brightness-high`), tooltip/title shows "Mode Terang".
- [ ] Reload the page → theme stays Dark (persisted via `localStorage`), no flash of light theme before dark paints (anti-FOUC working).
- [ ] Toggle back to Light → sidebar turns white with a visible right border separating it from content, disabled sidebar items (if any visible for this user) are legible, not near-invisible.
- [ ] Open a new private/incognito window (no `localStorage` yet) → app loads in Light by default.
- [ ] Click any sidebar/content navigation link → global overlay (blurred backdrop + neon spinner + "Memuat...") appears immediately, then disappears once the new page has rendered.
- [ ] Submit a real form (e.g. cancel a draft invoice, or PKB "Batalkan") → overlay appears during the request.
- [ ] Click a `target="_blank"` link (e.g. "Cetak Invoice") → overlay does NOT appear (opens in new tab, current page stays interactive).
- [ ] Open Invoice show page for a posted invoice with email permission → click "Kirim Email" → global overlay appears (replacing the old page-specific one), page redirects back with the success/error flash.
- [ ] Go to PKB "Buat PKB Baru" and Invoice "Direct Sales" create pages → click "+ Tambah Baris Jasa" / "+ Tambah Baris Sparepart" → new row visibly fades/slides in.
- [ ] Resize to mobile width → sidebar becomes an offcanvas as before, profile dropdown + theme toggle + date remain usable and don't overflow/wrap awkwardly.
- [ ] Dashboard KPI branch filter (AJAX) still works and still shows its own section-level spinner — confirm the new global overlay does NOT also appear for that AJAX filter action.

---

## Self-Review Notes

- **Spec coverage:** §3.1 (theme + tokens + sidebar) → Tasks 1 & 4. §3.2 (global overlay) → Task 3. §3.3 (navbar) → Task 2. §3.4 (cards/tables/stat-icon) → Task 4. §3.5 (row animation) → Task 4. §6 (testing strategy incl. manual checklist) → Task 5. All four confirmed §7 decisions are reflected in Global Constraints and their respective tasks.
- **Placeholder scan:** no TBD/TODO — every step has literal, exact code.
- **Type/name consistency verified:** `#themeToggleBtn` (Task 1 script → Task 2 markup), `#globalLoadingOverlay` (Task 3 markup → Task 3 script → Task 5 tests), `.line-row-enter` (Task 4 CSS → Task 4 JS, both files), `html[data-bs-theme="dark"]` block (Task 1 creates → Task 4 appends into the same block, verified anchor text `--color-accent-dark: #2563EB;` is unique in the file).
