# Master Data Group 1 — Cabang & Kendaraan Redesign — Design

Status: approved by user, ready for implementation plan

## Purpose

Sub-project 3 of the UI redesign track (sub-project 1: Design System Foundation, sub-project 2: Dashboard, sub-project 2.5: Foundation v3 — all merged). Foundation v3 piloted `list-filter-bar`/`empty-state`/table-hover on the Customer module with real search + branch filtering. This sub-project rolls that proven pattern out to **Cabang** and **Kendaraan** — the rest of "Master Data group 1" per the project roadmap (Referensi Kendaraan is explicitly excluded, see Scope).

## Scope

**In scope:**
- **Cabang** (`branches/index.blade.php`, `BranchController::index()`): add real search (by `code` or `name`), retrofit to `list-filter-bar` + `empty-state`. No branch-multiselect filter — a Branch doesn't belong to other branches, so that filter concept doesn't apply here.
- **Kendaraan** (`vehicles/index.blade.php`, `VehicleController::index()`): retrofit to `list-filter-bar` + `empty-state`. The existing search (plate/frame/engine number) and customer dropdown filter are already functional — this is a visual/structural retrofit, not new query logic, except for the defensive fix below.
- **Extend `partials/list-filter-bar.blade.php`** with one new optional slot, `extraFilterHtml` (default empty string), for a caller-specific filter control that doesn't fit the existing search/branch-filter shape — Vehicle's customer dropdown uses it. Backward compatible: existing callers (Customer) that don't pass it are unaffected, since the partial defaults it internally (`$extraFilterHtml = $extraFilterHtml ?? ''`).
- **Defensive fix**: `vehicles/index.blade.php` currently echoes `request('q')` directly into the search input's `value=` attribute — the exact same latent crash Customer had (`?q[]=x` sends an array, and Blade's `{{ }}` can't accept one). Since this view is being rewritten anyway, apply the same fix already proven for Customer: sanitize once in the controller (`is_string(request('q')) ? trim(request('q')) : null`), pass the sanitized value to the view, never let the view re-read `request('q')` raw.

**Explicitly out of scope:**
- **Referensi Kendaraan** (`vehicle-references/index.blade.php`) — structurally a 3-column drill-down with inline AJAX editing, not a paginated list with search/empty-state. `list-filter-bar`/`empty-state` don't apply. It already inherits the card/shadow/hover depth upgrades from the global `design-tokens.blade.php` changes (sub-projects 1 and 2.5) with zero changes needed here.
- Vehicle's customer_id filter gaining branch-scoping (joining through `Customer::customerBranches`) — the current filter is customer-only, matching existing behavior; adding branch-awareness to Vehicle's filtering is a scope increase not requested and not needed by any current use case.
- Create/Edit forms for either module — Foundation v3's precedent only retrofitted the *list* page (Customer's create/show were untouched); this sub-project matches that same boundary for Cabang/Kendaraan.

## `list-filter-bar.blade.php` extension

Add one line near the top of the partial: `@php($extraFilterHtml = $extraFilterHtml ?? '')`. Render it as one more optional column in the filter row, between the branch-filter column (if present) and the actions column — same "echoed raw, caller-authored only" contract as `actionsHtml` (documented inline, matching the existing comment already in the file). Vehicle's caller builds it via `view('vehicles._customer_filter_select', ['customers' => $customers, 'selectedCustomerId' => $selectedCustomerId])->render()` — a small new partial containing just the `<select>` (extracted from the current inline markup in `vehicles/index.blade.php`), not inlined as a raw string in the controller (unlike `actionsHtml`'s simple static-link pattern, a dropdown with a selected-option loop is cleaner as its own tiny Blade partial than a hand-built PHP string).

## Cabang

`BranchController::index()`:
```php
public function index()
{
    $this->authorize('branch.view');

    $search = is_string(request('q')) ? trim(request('q')) : null;

    $branches = Branch::orderBy('name')
        ->when($search, function ($query, $q) {
            $query->where(function ($inner) use ($q) {
                $inner->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%')
                    ->orWhere('name', 'like', '%' . addcslashes($q, '%_\\') . '%');
            });
        })
        ->simplePaginate(15)
        ->withQueryString();

    return view('branches.index', compact('branches'))->with('search', $search);
}
```
(Mirrors Customer's exact `addcslashes` escaping for LIKE wildcards and the "sanitize once, pass to view" rule — same defensive pattern, applied consistently.)

`branches/index.blade.php`: replace the current bare header+table with `list-filter-bar` (`branchFilterBranches` => `null`, no `extraFilterHtml`) and `empty-state` (icon `bi-shop`, title "Belum ada cabang", CTA gated by `branch.create`), same structural shape as `customers/index.blade.php`.

## Kendaraan

`VehicleController::index()` gains the search sanitization fix (signature/logic otherwise unchanged):
```php
public function index()
{
    $this->authorize('vehicle.view');

    $search = is_string(request('q')) ? trim(request('q')) : null;
    $customerId = request('customer_id') ? (int) request('customer_id') : null;

    $vehicles = Vehicle::with(['customer', 'category', 'brand', 'type'])
        ->when($customerId, fn ($query, $id) => $query->where('customer_id', $id))
        ->when($search, function ($query, $q) {
            $query->where(function ($inner) use ($q) {
                $inner->where('plate_number', 'like', '%' . addcslashes($q, '%_\\') . '%')
                    ->orWhere('frame_number', 'like', '%' . addcslashes($q, '%_\\') . '%')
                    ->orWhere('engine_number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            });
        })
        ->orderBy('created_at', 'desc')
        ->simplePaginate(15)
        ->withQueryString();

    $customers = Customer::where('is_active', true)->orderBy('name')->get();

    return view('vehicles.index', compact('vehicles', 'customers'))
        ->with('search', $search)
        ->with('selectedCustomerId', $customerId);
}
```

`vehicles/index.blade.php`: replace the current bare header + inline `<form>` filter with `list-filter-bar` (`branchFilterBranches` => `null`, `extraFilterHtml` => the new customer-select partial's rendered output) and `empty-state` (icon `bi-car-front`, title "Belum ada kendaraan", CTA gated by `vehicle.create`).

New `vehicles/_customer_filter_select.blade.php`:
```blade
<select name="customer_id" class="form-select form-select-sm">
    <option value="">-- Semua Customer --</option>
    @foreach ($customers as $customer)
        <option value="{{ $customer->id }}" {{ $selectedCustomerId === $customer->id ? 'selected' : '' }}>{{ $customer->name }}</option>
    @endforeach
</select>
```
(Extracted verbatim from the current inline `<select>` in `vehicles/index.blade.php`, just moved to its own file so it can be rendered independently via `view(...)->render()` for the `extraFilterHtml` slot.)

## Testing

- `BranchManagementTest`: search by code returns matching rows only; search by name returns matching rows only; empty state renders (icon/title) when zero branches match a search; empty-state CTA hidden without `branch.create`.
- `VehicleManagementTest`: existing customer_id/search behavior unchanged (assert against the retrofitted view, same query results as before); empty state renders when zero vehicles match; **regression test** — `GET /vehicles?q[]=x` returns 200, not 500 (mirrors the exact test added for Customer's equivalent bug).
- `AppShellTest` / full suite: no behavior change to sidebar/navbar, but per this project's now-three-times-repeated lesson, the final reviewer must explicitly check for new text collisions between the retrofitted pages' visible strings (empty-state titles, filter-bar placeholders) and any existing `assertSee`/`assertDontSee` assertion elsewhere in the suite — this has bitten the project in both directions (`assertDontSee` and `assertSee`) already.
- Manual verification: load `/branches` and `/vehicles` as `faiz_rahmat`, confirm the glassmorphism filter bar and empty-state render, search/filter and confirm results, trigger the empty state with a non-matching search term.

## Execution

Recommend **`subagent-driven-development` in a worktree**, matching established process. Smaller than Foundation v3 (no design-token changes, no new visual-depth work — purely applying an already-built pattern to two more screens) — expect roughly 4-5 tasks: `list-filter-bar` extension, Cabang retrofit, Kendaraan retrofit (including the customer-select partial extraction and the `?q[]=x` fix), then full-suite verification. Given this project's repeated history of cross-feature text collisions surviving per-task review, the final whole-branch review should specifically grep for shared substrings between this sub-project's new empty-state/filter-bar text and existing sidebar/dashboard assertions, not just trust that "no new permission logic" means "no risk."
