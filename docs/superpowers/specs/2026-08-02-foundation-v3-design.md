# Foundation v3 — Visual Overhaul + Shared List-Page Components — Design

Status: approved by user, ready for implementation plan

## Purpose

The design-system-foundation + dashboard-redesign sub-projects (both merged) introduced a new palette (slate/electric-blue/emerald, Plus Jakarta Sans) but the result still read as "too plain/flat/generic-template" to the user — partly a real bug (31 views hardcoded Bootstrap's `.shadow-sm !important`, silently overriding the new `.card` shadow site-wide, fixed separately in commit `26ff744`), partly because the shipped depth/interaction language (flat shadow, thin sidebar accent bar, opaque navbar, `<code>`-styled badges) was conservative compared to what the user actually wanted: a high-end SaaS/enterprise look with depth, gradients, glassmorphism, and hover interactivity.

This is **Foundation v3**: upgrades the same design-token file and shared layout (navbar/sidebar) already established, plus introduces three reusable list-page component patterns (filter bar, table styling, empty state) that every future module screen will reuse — piloted on one real screen (Customer) rather than rolled out everywhere blind.

This does **not** discard the existing design system — it raises the depth/interaction level of the same token names and component structure already in place (see project memory `bengkel-foundation-decisions`, "Design system v2"). Base colors, font, and overall layout structure (sidebar-left/navbar-top/card-content) are unchanged.

## Scope

**In scope:**
- Token upgrades in `resources/views/partials/design-tokens.blade.php`: deeper card shadow + larger radius + hover lift, sidebar active-item gradient + glow, "Segera Hadir" badge restyled as a transparent pill, navbar glassmorphism, permission badges restyled as pills.
- Three new reusable partials: `resources/views/partials/list-filter-bar.blade.php`, `resources/views/partials/empty-state.blade.php`, and a new `.table` CSS treatment (no new partial needed — table markup varies per page, only the class/rules are shared).
- Piloted on the **Customer** module: retrofit `customers/index.blade.php` to use all three new patterns, and add **functional** search (name/phone) and multi-branch filtering to `CustomerController::index()` — real behavior, not decoration, so the filter bar pattern is proven working before any other module copies it.
- Full-suite regression verification (existing `CustomerManagementTest`, `AppShellTest`, and all other Feature tests must keep passing unchanged in behavior, only visual/markup changes).

**Explicitly out of scope / deferred:**
- Rolling the new list-filter-bar/table/empty-state patterns out to any other module (Branch, Vehicle, Mechanic, Service Catalog, Sparepart, Users) — that's the next sub-project(s) in the UI redesign track, informed by whatever this pilot's final review surfaces.
- The sparepart-specific 3-tier stock pill (On-Hand/Reserved/Available visual breakdown) — belongs to the Sparepart module rollout (UI redesign track sub-project "Master Data group 2"), not this foundation pass.
- Navbar quick search (still decorative), notification bell (still static), dashboard's conditional KPI coloring — all three were explicitly revisited and re-deferred earlier this session (real data behind them is still 0/nonexistent until migrations 006-009), unaffected by this visual pass.
- Skeleton-loader *shapes* (animated gray placeholder blocks mimicking content). The spinner-overlay pattern already established in the dashboard (`dashboard-loading-overlay` + `minDelay(200)`) is reused as-is for the Customer pilot's search/filter — building true content-shaped skeletons is a separate, larger undertaking not justified for one pilot screen.

## Design tokens (`resources/views/partials/design-tokens.blade.php`)

Same variable names as the current v2 system — only values and a few component rules change:

```text
.card:
  border: 1px solid rgba(226, 232, 240, 0.8)   (was var(--color-border) solid, opaque)
  border-radius: 1rem                            (was .75rem / 12px)
  box-shadow: 0 4px 20px -2px rgba(0, 0, 0, .05)  (was the two-layer rgba(15,23,42,.04) pair)
  transition: transform .15s ease, box-shadow .15s ease   (NEW)
  &:hover: transform: translateY(-2px); box-shadow: 0 8px 30px -4px rgba(0, 0, 0, .08)   (NEW)
```

Sidebar active-link (`#sidebar .nav-link.active`):
```text
background: linear-gradient(135deg, #3B82F6, #2563EB)   (was rgba(37,99,235,.14) flat tint)
color: #FFFFFF, font-weight: 600                          (was default sidebar-ink-active color, regular weight)
box-shadow: 0 0 12px rgba(59, 130, 246, .35)              (NEW — soft icon/text glow)
```
The inactive-state left-accent-bar convention is removed for active items (the gradient fill itself is now the active indicator) — hover state on inactive items stays the existing subtle background tint, unchanged.

"Segera Hadir" placeholder badge (`.badge-soon`):
```text
background: rgba(51, 65, 85, .5)    (slate-700 at 50% — was a solid muted badge color)
color: #94A3B8                       (slate-400)
border: 1px solid rgba(71, 85, 105, .3)   (slate-600 at 30%)
border-radius: 999px (pill)
```

Permission badges (navbar, currently `<code>`-styled per sub-project 1):
```text
background: #EFF6FF (blue-50), color: #2563EB (blue-600), border: 1px solid #DBEAFE (blue-100)
border-radius: 999px (pill), padding: .25rem .625rem, font-size: .75rem
```
Replaces the `<code>` wrapper entirely for this specific badge use — `<code>` styling elsewhere (branch codes, usernames, permission *codes* shown in the Users→Permission tab) is untouched, this only changes the navbar's summary-badge presentation.

Navbar (`layouts/app.blade.php`) container:
```text
background: rgba(255, 255, 255, .72)
backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px)
border-bottom: 1px solid rgba(226, 232, 240, .6)
```
The navbar currently sits in normal document flow (not fixed/sticky), so glassmorphism is only visually meaningful with content scrolling beneath it. This spec adds `position: sticky; top: 0; z-index: 1020` (Bootstrap's navbar z-index tier) to the navbar as a necessary companion change — without it, the blur has nothing behind it to blur. This is a small structural change beyond pure CSS values, called out explicitly since it changes how the navbar behaves on scroll (previously scrolled away with content, now stays pinned).

## Shared list-page components

### `resources/views/partials/list-filter-bar.blade.php`

A glassmorphism card wrapping three slots, composed via named `@section`/`@include`-with-params (matching this project's existing partial-with-parameters convention, e.g. `empty-state` below) rather than introducing Blade Components:

```blade
@include('partials.list-filter-bar', [
    'searchPlaceholder' => 'Cari nama atau telepon...',
    'searchValue' => request('q'),
    'branchFilterBranches' => $branches,      // collection, or null to omit the branch filter entirely
    'branchFilterSelected' => $selectedBranchIds ?? [],
    'actionsHtml' => '<a href="' . route('customers.create') . '" class="btn btn-primary btn-sm">...</a>',
])
```
The partial renders `{!! $actionsHtml !!}` inside its action-area slot. `@include` doesn't support `@slot`/`@yield`-style child content, so the actions markup is built by the caller and passed as a pre-rendered HTML string param — the same "caller builds a string, partial echoes it raw" pattern is safe here because the string is always caller-authored Blade output (a `route()` URL plus static button markup), never raw user input, so there's no XSS surface to worry about.

- Search input: `input-group` with a borderless `input-group-text` icon (`bi-search`) merged visually into the input (no visible seam/border between icon and text field).
- Branch filter: reuses `resources/views/partials/branch-multiselect-filter.blade.php` (already built in dashboard-redesign) as-is — same component, just placed inside this new wrapping card instead of dashboard's bespoke header. No changes to that partial's own markup/JS.
- The whole bar submits as a single `GET` form (search input triggers on submit or a debounced auto-submit — implementer's call between the two, both are reasonable; a plain submit-on-enter is the simpler default and acceptable) to `customers.index`, carrying `q` and `branch_ids[]` as query params — **server-rendered filtering** (full page reload on submit), not AJAX. AJAX filtering (like the dashboard's) is explicitly deferred; this pilot proves the visual/interaction pattern and real query logic, not a second AJAX-partial-update subsystem in one sub-project.

### `resources/views/partials/empty-state.blade.php`

```blade
@include('partials.empty-state', [
    'icon' => 'bi-person-badge',
    'title' => 'Belum ada customer',
    'description' => 'Mulai dengan menambahkan customer pertama Anda.',
    'ctaRoute' => 'customers.create',
    'ctaLabel' => '+ Tambah Customer Pertama',
    'ctaPermission' => 'customer.create',   // the CTA button only renders if the user holds this permission
])
```
Centered layout: large muted icon (Bootstrap Icons, `font-size: 3rem`, `color: var(--color-ink-muted)` at reduced opacity), bold title, muted description, primary-button CTA gated by `@can($ctaPermission)`. Replaces the current bare `<tr><td colspan="...">Belum ada customer.</td></tr>` row — rendered in place of the table body when the collection is empty (the table's `<thead>` still renders, so column headers stay visible for context, matching the current pattern's structure).

### Table styling

No new partial — add to `design-tokens.blade.php`:
```text
.table thead th: background: #F8FAFC (was already close to this), text-transform: uppercase,
  letter-spacing: .05em, font-size: .75rem, color: #64748B (var(--color-ink-muted)), border-bottom: none
.table tbody tr: border-bottom: 1px solid var(--color-border) (was default Bootstrap border-top+bottom)
.table-hover tbody tr:hover: background: #F8FAFC
```
Applied by adding/confirming `table-hover` on `customers/index.blade.php`'s existing `<table class="table ...">` — no new class name invented, this refines the existing `.table`/`.table-hover` rules every table already uses, so **every table across the whole app gets this automatically** the moment the token file changes (same mechanism as the v2 palette rollout) — the Customer pilot is only "new" in its filter-bar and empty-state, not its table styling, which updates everywhere simultaneously.

## Customer pilot — functional search + branch filter

`CustomerController::index()`:
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

    return view('customers.index', compact('customers', 'branches'))
        ->with('selectedBranchIds', $branchIds);
}
```
Branch-filter validation mirrors the dashboard's existing pattern exactly (intersect against the user's own assigned branches, silently drop anything else) — this is a read-only view filter, same non-write-authorization reasoning already established and tested for the dashboard's session-based filter, just query-string-based here instead of session-based (no need for session persistence on a list page the user navigates away from and back to freely).

## Testing

- `CustomerManagementTest`: new tests — search by name returns matching rows only; search by phone returns matching rows only; branch filter scoped to the user's own branches (a branch_id not in the user's assigned set is silently dropped, mirroring the dashboard's existing test for the same rule); empty state renders (icon/title text) when zero customers match; empty-state CTA hidden without `customer.create`.
- Existing `CustomerManagementTest`/`CustomerBranchTabTest`/`CustomerVehicleTabTest` cases keep passing unchanged (no behavior change to store/update/show or the Cabang/Kendaraan tabs).
- `AppShellTest`: unaffected (sidebar gating logic untouched, only CSS) — full suite run confirms no incidental breakage, particularly watching for the same "shared text between two elements" assertion-collision class of bug found twice already in this project (Kartu Stok/Audit Log icon-class fix, PKB assertion fix) — the reviewer should specifically check whether any new empty-state/filter-bar text collides with an existing sidebar assertion.
- Manual verification (no visual/screenshot test infra in this project): load `/customers` as `faiz_rahmat`, confirm glassmorphism filter bar renders, type a search term and submit, toggle branch filter checkboxes and submit, trigger the empty state by searching for something that matches nothing, confirm sidebar active-item gradient/glow and navbar blur-on-scroll.

## Execution

Recommend **`subagent-driven-development` in a worktree**, matching this project's established process for multi-file cross-cutting changes. Widest blast radius yet (every screen inherits the token changes, same as sub-project 1) plus new real query logic (Customer search/filter) — expect roughly 6-8 tasks at plan time: token upgrade (card/sidebar/badges/navbar), `list-filter-bar` partial, `empty-state` partial, table CSS, Customer controller search/filter logic, Customer view retrofit wiring all three patterns together, then full-suite verification. Final whole-branch review matters here specifically for the same class of cross-task defect already caught twice in this project (CSS specificity collisions, `assertSee` text collisions) — instruct the final reviewer explicitly to check for both.
