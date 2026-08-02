# Foundation v3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) to implement this plan task-by-task — this touches the shared layout every screen inherits (widest blast radius yet) plus new real query logic, so per the project's process preference it runs through the full review loop rather than inline execution. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the design system's depth/interaction language (deeper card shadows + hover lift, gradient sidebar active state, glassmorphism navbar, pill badges) and introduce two reusable list-page partials (filter bar, empty state) plus a shared table-hover treatment, piloted end-to-end on the Customer module with real search + branch-filter query logic.

**Architecture:** Same token file (`design-tokens.blade.php`) and shared layout files (`layouts/app.blade.php`, `partials/sidebar.blade.php` unaffected — no sidebar *content* changes, only its CSS) already established in the v2 redesign — only values and a handful of component rules change. Two new small partials (`partials/list-filter-bar.blade.php`, `partials/empty-state.blade.php`) follow this project's existing `@include(..., [params])` convention (no Blade Components). The Customer pilot converts `CustomerController::index()` from a plain list into a real filtered/searched query, proving the new filter-bar pattern works before any other module copies it.

**Tech Stack:** Laravel 8 (`^8.75` — pinned, no `Request` helper methods added in later versions), Blade, Bootstrap 5.3 (CSS custom properties for hover-state overrides, e.g. `--bs-table-hover-bg`, rather than fighting specificity — this project already hit a real bug from a hardcoded utility class beating a custom rule, see Global Constraints), vanilla JS.

Design spec: `docs/superpowers/specs/2026-08-02-foundation-v3-design.md`.

## Global Constraints

- Base palette, font, and overall layout structure (sidebar-left/navbar-top/card-content) are **unchanged** — this plan only raises shadow/radius/gradient/blur depth on the same token names already in place (`--color-accent`, `--color-sidebar`, etc.).
- **Never hardcode a Bootstrap utility class alongside a custom component class when the utility might override the custom rule with `!important`** (e.g. `class="card shadow-sm"` silently defeated the v2 redesign's `.card` shadow across 31 files, fixed in commit `26ff744`, immediately before this plan). Prefer Bootstrap's own CSS-variable override mechanism (e.g. `.table-hover { --bs-table-hover-bg: ... }`) over adding a new class that might collide the same way.
- New reusable partials follow the `@include('partials.x', [params])` convention already used throughout this codebase (`branch-multiselect-filter.blade.php`, `_tab_profil.blade.php`, etc.) — no Blade Components (`<x-...>`) introduced.
- `branch-multiselect-filter.blade.php` is reused **completely unmodified** — its checkboxes have no `name` attribute (built for the dashboard's AJAX-only consumer, which reads `.checked` via JS, never natively submits a form). The Customer pilot's plain `GET` form therefore injects hidden `branch_ids[]` inputs via JS at submit time rather than adding `name` attributes to the shared partial — this keeps the partial's "same component, zero changes" property from the spec literally true.
- Laravel 8 pinned — do not use `Request::integer()` or other post-8.x `Request` helpers (see project memory).
- Full TDD: write the failing test first, confirm the failure reason, implement, confirm green.
- **This project has twice shipped cross-task defects that only a final whole-branch review caught — a CSS specificity collision (`.nav-link:hover` vs `.nav-link-disabled` in the design-system-foundation branch) and an `assertSee` text collision between two unrelated elements sharing the same string (three separate instances, across two different branches).** This plan's tasks touch a lot of shared CSS and a page whose new empty-state/filter-bar text could plausibly collide with existing sidebar or navbar assertions. **The final whole-branch review dispatch must explicitly instruct the reviewer to check for both defect classes** — grep for any new class name against existing CSS rules for specificity fights, and cross-reference every new user-visible string this plan introduces (`badge-soon` pill text, empty-state title/description, "Terapkan" button label) against `AppShellTest`'s existing `assertSee`/`assertDontSee` calls.

---

### Task 1: Design tokens v3 — card depth, sidebar gradient, pill badges, navbar glassmorphism

**Files:**
- Modify: `resources/views/partials/design-tokens.blade.php`
- Modify: `resources/views/layouts/app.blade.php`

**Interfaces:**
- Produces: no new PHP/Blade variables or routes — pure CSS + one markup tag change (`<code>` → `<span>` for the permission badge, since it's no longer styled as code).

- [ ] **Step 1: Confirm the baseline suite is green**

Run: `php artisan test`
Expected: PASS, all tests (this task changes no server-side logic, so this is the "before" snapshot to diff against after the CSS change).

- [ ] **Step 2: Update `.card` in design-tokens.blade.php**

In `resources/views/partials/design-tokens.blade.php`, replace the `/* Cards */` block:

```css
/* Cards */
.card {
    border: 1px solid rgba(226, 232, 240, .8);
    border-radius: 1rem;
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, .05);
    transition: transform .15s ease, box-shadow .15s ease;
}
.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 30px -4px rgba(0, 0, 0, .08);
}
```

- [ ] **Step 3: Update the sidebar active-link rule**

In the same file, inside the `/* Sidebar */` block, replace:

```css
    #sidebar .nav-link.active {
        color: var(--color-sidebar-ink-active);
        background-color: color-mix(in srgb, var(--color-accent) 14%, transparent);
        border-left-color: var(--color-accent);
        font-weight: 500;
    }
```

with:

```css
    #sidebar .nav-link.active {
        color: var(--color-sidebar-ink-active);
        background: linear-gradient(135deg, #3B82F6, #2563EB);
        font-weight: 600;
        box-shadow: 0 0 12px rgba(59, 130, 246, .35);
    }
```

(The `border-left-color` line is dropped entirely — the base `.nav-link` rule already sets `border-left: 3px solid transparent`, so the active item's left edge stays transparent now that the gradient fill itself is the active indicator, per the design spec.)

- [ ] **Step 4: Update `.badge-soon`**

Replace:

```css
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

with:

```css
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
```

- [ ] **Step 5: Update the permission badges to pill style**

Replace:

```css
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
```

with:

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

- [ ] **Step 6: Update `.topbar` for glassmorphism + sticky positioning**

Replace:

```css
    /* Topbar */
    .topbar {
        background-color: var(--color-surface) !important;
        border-bottom: 1px solid var(--color-border);
    }
```

with:

```css
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
```

(`z-index: 1020` matches Bootstrap 5's own `.navbar` stacking tier, so the sticky topbar layers correctly above page content without needing a custom z-index scale. `position: sticky` is what makes the blur visually meaningful — without it the navbar scrolls away with the page and nothing is ever visible blurred behind it.)

- [ ] **Step 7: Add the table-hover color override**

In the `/* Tables */` block, change:

```css
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
```

to:

```css
    /* Tables */
    .table thead th {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: var(--color-ink-muted);
        font-weight: 600;
        border-bottom: none;
    }
    .table td { vertical-align: middle; }
    .table-hover { --bs-table-hover-bg: #F8FAFC; }
```

(Using Bootstrap's own `--bs-table-hover-bg` CSS variable — not a new class — is the direct fix for the exact class of bug `shadow-sm` caused: a plain custom class competing with a Bootstrap utility. Bootstrap's table hover color already reads from this variable internally, so setting it here always wins with zero specificity fighting.)

- [ ] **Step 8: Change the permission badge tag from `<code>` to `<span>`**

In `resources/views/layouts/app.blade.php`, change:

```blade
                        @foreach (array_slice($permissionCodes, 0, 3) as $code)
                            <code class="topbar-permission-badge">{{ $code }}</code>
                        @endforeach
```

to:

```blade
                        @foreach (array_slice($permissionCodes, 0, 3) as $code)
                            <span class="topbar-permission-badge">{{ $code }}</span>
                        @endforeach
```

(`<code>` implied a monospace/code-like presentation that no longer matches the new pill style — this is a pure presentation tag swap, the CSS class and its content are unchanged, so nothing that inspects the badge's text or class needs to change.)

- [ ] **Step 9: Run the full suite to confirm no regression**

Run: `php artisan test`
Expected: PASS, same test count as Step 1 — this task changed no server-side behavior, only CSS and one tag name, so every existing assertion must still hold. If `AppShellTest` fails, check whether the failing assertion depends on the exact `<code>` tag (it shouldn't — existing assertions check for text/route/class presence, not tag name) before assuming this task broke something unrelated.

- [ ] **Step 10: Commit**

```bash
git add resources/views/partials/design-tokens.blade.php resources/views/layouts/app.blade.php
git commit -m "feat: upgrade card/sidebar/badge/navbar depth for foundation v3"
```

---

### Task 2: Empty-state partial + Customer empty-state retrofit

**Files:**
- Create: `resources/views/partials/empty-state.blade.php`
- Modify: `resources/views/customers/index.blade.php`
- Modify: `tests/Feature/CustomerManagementTest.php`

**Interfaces:**
- Produces: `@include('partials.empty-state', ['icon' => string, 'title' => string, 'description' => string, 'ctaRoute' => string, 'ctaLabel' => string, 'ctaPermission' => string])` — a centered icon/title/description/CTA block, CTA gated by `@can($ctaPermission)`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/CustomerManagementTest.php` (new methods, inside the existing class):

```php
    public function test_index_shows_empty_state_when_no_customers_match(): void
    {
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get('/customers');

        $response->assertOk();
        $response->assertSee('Belum ada customer');
        $response->assertSee('Mulai dengan menambahkan customer pertama Anda.');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['customer.view', 'customer.create']);

        $response = $this->actingAs($user)->get('/customers');

        $response->assertOk();
        $response->assertSee('Tambah Customer Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get('/customers');

        $response->assertOk();
        $response->assertDontSee('Tambah Customer Pertama');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CustomerManagementTest`
Expected: the three new methods FAIL (partial doesn't exist, current empty row says "Belum ada customer." with a period and no CTA text).

- [ ] **Step 3: Write the partial**

Create `resources/views/partials/empty-state.blade.php`:

```blade
<div class="text-center py-5">
    <i class="bi {{ $icon }}" style="font-size: 3rem; color: var(--color-ink-muted); opacity: .4;"></i>
    <h5 class="mt-3 mb-1">{{ $title }}</h5>
    <p class="text-muted small mb-3">{{ $description }}</p>
    @can($ctaPermission)
        <a href="{{ route($ctaRoute) }}" class="btn btn-primary btn-sm">{{ $ctaLabel }}</a>
    @endcan
</div>
```

- [ ] **Step 4: Wire it into the Customer index view**

In `resources/views/customers/index.blade.php`, replace:

```blade
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Belum ada customer.</td></tr>
                    @endforelse
```

with:

```blade
                    @empty
                        <tr>
                            <td colspan="5" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-person-badge',
                                    'title' => 'Belum ada customer',
                                    'description' => 'Mulai dengan menambahkan customer pertama Anda.',
                                    'ctaRoute' => 'customers.create',
                                    'ctaLabel' => '+ Tambah Customer Pertama',
                                    'ctaPermission' => 'customer.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=CustomerManagementTest`
Expected: PASS, all methods including the 3 new ones (existing methods in this file must be unaffected — the "Belum ada customer" *text* is preserved exactly, just no longer wrapped in a plain `<td>` sentence with a trailing period, so no existing assertion should break).

- [ ] **Step 6: Commit**

```bash
git add resources/views/partials/empty-state.blade.php resources/views/customers/index.blade.php tests/Feature/CustomerManagementTest.php
git commit -m "feat: add reusable empty-state partial, wire into Customer list"
```

---

### Task 3: List-filter-bar partial + Customer search/branch-filter

**Files:**
- Create: `resources/views/partials/list-filter-bar.blade.php`
- Modify: `app/Http/Controllers/CustomerController.php`
- Modify: `resources/views/customers/index.blade.php`
- Modify: `tests/Feature/CustomerManagementTest.php`

**Interfaces:**
- Consumes: `partials.branch-multiselect-filter` (existing, unmodified — expects `$allowedBranches` collection and `$selectedBranchIds` array), `Customer::customerBranches()` relation (existing, from migration 003), `User::branches` relation (existing).
- Produces: `@include('partials.list-filter-bar', ['searchPlaceholder' => string, 'searchValue' => ?string, 'branchFilterBranches' => ?Collection, 'branchFilterSelected' => array, 'actionsHtml' => string])`. `CustomerController::index()` now accepts `?q=` and `?branch_ids[]=` query params.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/CustomerManagementTest.php` (new methods; add `use App\Models\Branch;`, `use App\Models\CustomerBranch;`, and `use App\Services\UserBranchService;` to the file's imports if not already present — check the top of the file first, this file already imports `Customer`, `Permission`, `User`, `UserPermission`):

```php
    public function test_index_search_by_name_filters_results(): void
    {
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get('/customers?q=Budi');

        $response->assertOk();
        $response->assertSee('Budi Santoso');
        $response->assertDontSee('Siti Aminah');
    }

    public function test_index_search_by_phone_filters_results(): void
    {
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso', 'phone' => '081111111111']);
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah', 'phone' => '082222222222']);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get('/customers?q=081111');

        $response->assertOk();
        $response->assertSee('Budi Santoso');
        $response->assertDontSee('Siti Aminah');
    }

    public function test_index_branch_filter_scopes_to_selected_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        CustomerBranch::create(['customer_id' => $customerA->id, 'branch_id' => $branchA->id]);
        CustomerBranch::create(['customer_id' => $customerB->id, 'branch_id' => $branchB->id]);
        $user = $this->userWithPermissions(['customer.view']);
        (new UserBranchService())->assign($user, $branchA);
        (new UserBranchService())->assign($user, $branchB);

        $response = $this->actingAs(User::find($user->id))->get("/customers?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee('Budi Santoso');
        $response->assertDontSee('Siti Aminah');
    }

    public function test_index_branch_filter_drops_branch_ids_the_user_is_not_assigned_to(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        CustomerBranch::create(['customer_id' => $customerB->id, 'branch_id' => $branchB->id]);
        $user = $this->userWithPermissions(['customer.view']);
        (new UserBranchService())->assign($user, $branchA);

        $response = $this->actingAs(User::find($user->id))->get("/customers?branch_ids[]={$branchB->id}");

        $response->assertOk();
        $response->assertSee('Siti Aminah');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CustomerManagementTest`
Expected: the 4 new methods FAIL — `CustomerController::index()` doesn't read `q`/`branch_ids` yet.

- [ ] **Step 3: Write the `list-filter-bar` partial**

Create `resources/views/partials/list-filter-bar.blade.php`:

```blade
<div class="card" style="background: rgba(255, 255, 255, .72); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);">
    <div class="card-body">
        <form method="GET" action="{{ url()->current() }}" id="listFilterBarForm" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-transparent border-0"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="{{ $searchValue }}" class="form-control border-0" placeholder="{{ $searchPlaceholder }}">
                </div>
            </div>
            @if ($branchFilterBranches !== null)
            <div class="col-md-3">
                @include('partials.branch-multiselect-filter', ['allowedBranches' => $branchFilterBranches, 'selectedBranchIds' => $branchFilterSelected])
            </div>
            @endif
            <div class="col-md-4 text-md-end">
                <button type="submit" class="btn btn-outline-primary btn-sm">Terapkan</button>
                {!! $actionsHtml !!}
            </div>
        </form>
    </div>
</div>

@if ($branchFilterBranches !== null)
@push('scripts')
<script>
(function () {
    const menu = document.getElementById('branchFilterMenu');
    const form = document.getElementById('listFilterBarForm');
    if (!menu || !form) return;

    menu.addEventListener('click', function (event) { event.stopPropagation(); });

    const selectAll = document.getElementById('branchFilterSelectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
        });
    }

    form.addEventListener('submit', function () {
        form.querySelectorAll('input[data-branch-hidden]').forEach(function (el) { el.remove(); });
        document.querySelectorAll('.branch-filter-checkbox:checked').forEach(function (checkbox) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'branch_ids[]';
            hidden.value = checkbox.value;
            hidden.setAttribute('data-branch-hidden', '1');
            form.appendChild(hidden);
        });
    });
})();
</script>
@endpush
@endif
```

(`branch-multiselect-filter.blade.php`'s checkboxes have no `name` attribute — they were built for the dashboard's AJAX consumer, which reads `.checked` state via JS rather than relying on native form submission. Rather than modifying that shared partial, this submit listener injects hidden `branch_ids[]` inputs matching the checked boxes right before the form actually submits, so the partial itself stays completely untouched per the Global Constraints.)

- [ ] **Step 4: Update the controller**

In `app/Http/Controllers/CustomerController.php`, replace the `index()` method:

```php
    public function index()
    {
        $this->authorize('customer.view');

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect(auth()->user()->branches->pluck('id'))
            ->values()->all();

        $customers = Customer::orderBy('name')
            ->when(request('q'), function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%");
                });
            })
            ->when($branchIds, fn ($query) => $query->whereHas('customerBranches', fn ($q) => $q->whereIn('branch_id', $branchIds)->where('is_active', true)))
            ->simplePaginate(15)
            ->withQueryString();

        $branches = auth()->user()->branches;

        return view('customers.index', compact('customers', 'branches'))->with('selectedBranchIds', $branchIds);
    }
```

- [ ] **Step 5: Wire the filter bar into the Customer index view**

In `resources/views/customers/index.blade.php`, replace the whole file with:

```blade
@extends('layouts.app')
@section('title', 'Customer')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-badge me-2"></i>Customer</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nama atau telepon...',
        'searchValue' => request('q'),
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => auth()->user()->can('customer.create')
            ? '<a href="' . route('customers.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah Customer</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nama</th>
                        <th>Tipe</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr>
                            <td>{{ $customer->name }}</td>
                            <td>{{ $customer->customer_type === 'COMPANY' ? 'Perusahaan' : 'Perorangan' }}</td>
                            <td>{{ $customer->phone ?? '-' }}</td>
                            <td>
                                @if ($customer->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('customers.show', $customer) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-gear"></i> Kelola
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-person-badge',
                                    'title' => 'Belum ada customer',
                                    'description' => 'Mulai dengan menambahkan customer pertama Anda.',
                                    'ctaRoute' => 'customers.create',
                                    'ctaLabel' => '+ Tambah Customer Pertama',
                                    'ctaPermission' => 'customer.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $customers->links() }}
    </div>
@endsection
```

(The "Tambah Customer" button moves from the page header into the filter bar's action slot per the design spec's "caller-supplied action area, visually unified in one card" — the header above now only holds the page title. The empty-state block is Task 2's, kept as-is.)

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=CustomerManagementTest`
Expected: PASS, all methods (existing + Task 2's 3 + this task's 4). Also run `php artisan test --filter=AppShellTest` to confirm the header button's relocation didn't break any sidebar/navbar assertion.

- [ ] **Step 7: Commit**

```bash
git add resources/views/partials/list-filter-bar.blade.php app/Http/Controllers/CustomerController.php resources/views/customers/index.blade.php tests/Feature/CustomerManagementTest.php
git commit -m "feat: add list-filter-bar partial, wire real search/branch-filter into Customer list"
```

---

### Task 4: Full-suite verification

**Files:** none (verification only).

**Interfaces:** none.

- [ ] **Step 1: Run the full suite**

Run: `php artisan test`
Expected: PASS, all tests green (baseline + Task 2's 3 + Task 3's 4 = 7 new).

- [ ] **Step 2: Grep for any other hardcoded `shadow-sm` or similar utility/custom-class collisions introduced by this plan**

Run: `grep -rn "shadow-sm" resources/views/`
Expected: no output (this plan doesn't reintroduce the pattern this project already fixed once).

- [ ] **Step 3: Commit (only if Steps 1-2 required a fix; otherwise this task has nothing to commit and is done)**

If a fix was needed, stage exactly the files touched and commit with a message describing what was found and fixed. If nothing needed fixing, mark this task complete with no commit.

## Manual verification checklist (after all tasks complete)

1. Load `/dashboard` as `faiz_rahmat`, scroll the page, confirm the navbar visibly blurs the content scrolling beneath it and stays pinned to the top.
2. Confirm the sidebar's active menu item shows the blue gradient fill (not the old flat left-accent-bar), and that a "Segera Hadir" placeholder item shows the new transparent pill badge.
3. Load `/customers`, confirm the glassmorphism filter bar renders above the table; type a search term and click "Terapkan", confirm results narrow; toggle branch-filter checkboxes and click "Terapkan" again, confirm results narrow to the selected branch(es); search for something matching nothing, confirm the new empty-state (icon + title + description + CTA) renders instead of a bare table row.
4. Hover over any card anywhere in the app (e.g. the Branches list), confirm the lift/shadow-deepen hover effect fires.
