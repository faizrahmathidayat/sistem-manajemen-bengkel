# Master Data Group 2 (Mekanik, Jasa Service, Master Sparepart) — Design

Status: approved by user, ready for implementation plan

## Purpose

Sub-project 4 of the UI redesign track (sub-projects 1-3 merged: Design System Foundation, Dashboard, Foundation v3 + Master Data group 1). Rolls the `list-filter-bar`/`empty-state` pattern onto the remaining three "Master Data group 2" screens: Mekanik, Jasa Service, Master Sparepart. Unlike group 1's two structurally-similar modules, these three have genuinely different shapes and each gets a different treatment.

## Scope

**In scope:**
- **Jasa Service** (`service-catalogs/index.blade.php`, `ServiceCatalogController::index()`): flat, no branch relation — same treatment as Cabang in group 1. Add real search (code/name), retrofit to `list-filter-bar` (no branch filter) + `empty-state`.
- **Mekanik** (`mechanics/index.blade.php`, `MechanicController::index()`): has `mechanicBranches`/`branches()` exactly mirroring Customer's `customerBranches`/`branches()` shape. Add real search (name/phone) + multi-branch filter (reusing `branch-multiselect-filter.blade.php` via `list-filter-bar`'s existing `branchFilterBranches` slot — no new component work needed) + `empty-state`. Query logic is a direct copy of `CustomerController::index()`'s pattern, substituting `mechanicBranches` for `customerBranches`.
- **Master Sparepart** (`sparepart-branches/index.blade.php`, `SparepartBranchController::index()`): architecturally different from the other two — already branch-scoped via a single-select session-based branch switcher (`current_sparepart_branch_id`), which determines which branch's data is displayed AND which branch new records get written to. This is NOT the same concept as the multi-select convenience filter used elsewhere (Customer/Mekanik: narrow a global list down to "records in my branches", no write-target implications). The existing switcher stays exactly as-is (single-select, session-backed, real access-control semantics per the sparepart module's existing spec/tests) — it moves into `list-filter-bar`'s `extraFilterHtml` slot purely for visual consistency (one bar, same card/spacing as every other list page), not replaced by the multi-select component. Retrofit to `list-filter-bar` (search + `extraFilterHtml` = the extracted branch-switcher partial + actions) + `empty-state` for the "no sparepart in this branch" case (distinct from the existing `no-access.blade.php`, which handles "zero branch permissions at all" and is untouched).
- **Defensive fix**: `sparepart-branches/index.blade.php` currently echoes `request('q')` directly into the search input's `value=` — the same latent `?q[]=x` crash already fixed twice (Customer, Kendaraan). Fourth instance of the identical bug class; fixed the same way (sanitize once in the controller, pass to the view).

**Explicitly out of scope:**
- Any change to Mekanik's detail page (`mechanics/show.blade.php`, Profil/Cabang tabs) or Sparepart's create/create-existing/edit pages — matches group 1's precedent of only retrofitting the index/list page.
- Changing Master Sparepart's branch-switching mechanism itself (single-select, session-based, write-target semantics) — explicitly preserved as-is. Do not attempt to unify it with the multi-select `branch-multiselect-filter` component; they solve different problems (viewing/writing to one specific branch vs. filtering a read-only list across several).
- A "Sparepart Baru" vs "Tambah dari Cabang Lain" empty-state CTA choice — the empty-state slot supports one CTA; use "Sparepart Baru" (the page's existing primary/`btn-primary` action) and leave "Tambah dari Cabang Lain" as a header button only, unchanged.

## Jasa Service

`ServiceCatalogController::index()`:
```php
public function index()
{
    $this->authorize('service.view');

    $search = is_string(request('q')) ? trim(request('q')) : null;

    $serviceCatalogs = ServiceCatalog::orderBy('name')
        ->when($search, function ($query, $q) {
            $query->where(function ($inner) use ($q) {
                $inner->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%')
                    ->orWhere('name', 'like', '%' . addcslashes($q, '%_\\') . '%');
            });
        })
        ->simplePaginate(15)
        ->withQueryString();

    return view('service-catalogs.index', compact('serviceCatalogs'))->with('search', $search);
}
```
View retrofit mirrors `branches/index.blade.php` exactly (same `list-filter-bar` call shape with `branchFilterBranches => null`, same `empty-state` call shape) — icon `bi-tools`, title "Belum ada jasa service", CTA "+ Tambah Jasa Pertama", gated by `service.create`.

## Mekanik

`MechanicController::index()`:
```php
public function index()
{
    $this->authorize('mechanic.view');

    $branchIds = collect(request('branch_ids', []))
        ->map(fn ($id) => (int) $id)
        ->intersect(auth()->user()->branches->pluck('id'))
        ->values()->all();

    $search = is_string(request('q')) ? trim(request('q')) : null;

    $mechanics = Mechanic::orderBy('name')
        ->when($search, function ($query, $q) {
            $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', '%' . addcslashes($q, '%_\\') . '%')
                    ->orWhere('phone', 'like', '%' . addcslashes($q, '%_\\') . '%');
            });
        })
        ->when($branchIds, fn ($query) => $query->whereHas('mechanicBranches', fn ($q) => $q->whereIn('branch_id', $branchIds)->where('is_active', true)))
        ->simplePaginate(15)
        ->withQueryString();

    $userBranches = auth()->user()->branches;

    return view('mechanics.index', compact('mechanics'))
        ->with('branches', $userBranches)
        ->with('selectedBranchIds', $branchIds)
        ->with('search', $search);
}
```
(Identical structure to `CustomerController::index()`, `mechanicBranches` substituted for `customerBranches` — including the same fail-open behavior for an empty/all-dropped branch selection, matching the existing Customer precedent rather than introducing a different rule.)

View retrofit mirrors `customers/index.blade.php` exactly — icon `bi-person-gear`, title "Belum ada mekanik", CTA "+ Tambah Mekanik Pertama", gated by `mechanic.create`.

## Master Sparepart

`SparepartBranchController::index()` gains the search sanitization fix (branch-resolution logic unchanged):
```php
$search = is_string(request('q')) ? trim(request('q')) : null;

$sparepartBranches = SparepartBranch::with(['sparepart', 'stock'])
    ->where('branch_id', $currentBranch->id)
    ->when($search, function ($query, $q) {
        $query->whereHas('sparepart', function ($inner) use ($q) {
            $inner->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%')
                ->orWhere('name', 'like', '%' . addcslashes($q, '%_\\') . '%');
        });
    })
    ->orderBy('id')
    ->simplePaginate(15)
    ->withQueryString();

return view('sparepart-branches.index', compact('sparepartBranches', 'allowedBranches', 'currentBranch'))->with('search', $search);
```

New `sparepart-branches/_branch_switcher_select.blade.php` (extracted verbatim from the current inline `<select>`):
```blade
<select name="branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
    @foreach ($allowedBranches as $branch)
        <option value="{{ $branch->id }}" {{ $branch->id === $currentBranch->id ? 'selected' : '' }}>
            {{ $branch->name }}
        </option>
    @endforeach
</select>
```

View retrofit: replace the current two-form `<div class="row mb-3">` block with one `list-filter-bar` call (`branchFilterBranches => null`, `extraFilterHtml => view('sparepart-branches._branch_switcher_select', ['allowedBranches' => $allowedBranches, 'currentBranch' => $currentBranch])->render()`), keeping the header's two action buttons unchanged. Replace the bare empty `<tr>` with `empty-state` (icon `bi-box-seam`, title "Belum ada sparepart di cabang ini", CTA "+ Sparepart Baru" routed to `sparepart-branches.create`).

**`empty-state.blade.php` extension (confirmed needed — checked the current implementation, it only supports `@can($ctaPermission)`, a global-permission check with no branch-scoped equivalent):** add one new optional parameter, `$ctaVisible` (default `null`). When the caller passes a boolean (not `null`), it overrides the `@can($ctaPermission)` check entirely — the caller has already computed the real (possibly branch-scoped) gate. When left `null` (every existing caller — Cabang, Kendaraan, Customer, and this plan's Jasa Service/Mekanik), behavior is unchanged: falls back to `@can($ctaPermission)`. New partial body:
```blade
@php($ctaVisible = $ctaVisible ?? auth()->user()?->can($ctaPermission))
...
@if ($ctaVisible)
    <a href="{{ route($ctaRoute) }}" class="btn btn-primary btn-sm">{{ $ctaLabel }}</a>
@endif
```
Sparepart's call passes `'ctaVisible' => auth()->user()->hasPermissionToInBranch('sparepart.create', $currentBranch->id)` and omits `ctaPermission` (or passes an unused placeholder — `ctaPermission` becomes irrelevant once `ctaVisible` is explicitly set, since the `?? ` short-circuits before `@can` ever evaluates it).

## Testing

- `ServiceCatalogManagementTest`: search by code filters results; search by name filters results; `?q[]=x` doesn't 500; empty state renders; empty-state CTA shown/hidden by `service.create`; filter bar renders.
- `MechanicManagementTest`: search by name filters results; search by phone filters results; `?q[]=x` doesn't 500; branch filter scopes to selected branch; branch filter drops branch_ids the user isn't assigned to; empty state renders; empty-state CTA shown/hidden by `mechanic.create`; filter bar renders (mirrors `CustomerManagementTest`'s equivalent tests exactly).
- `SparepartBranchIndexAndCreateTest` (258 lines currently — adding to the existing file, not creating a new one, matching this project's convention of grouping tests by controller area): `?q[]=x` doesn't 500 (regression test matching the other three instances); empty state renders when a branch has zero sparepart configs; empty-state CTA is gated by branch-scoped `sparepart.create`, not a global permission (a user with `sparepart.create` in a DIFFERENT branch than `$currentBranch` must NOT see the CTA); branch-switcher still functions identically after the retrofit (switching branches still filters the list, per existing tests).
- Full suite + explicit text-collision grep (now standard practice for every sub-project in this track, per the three prior incidents) — specifically check the three new empty-state titles and the "Cari..." placeholders against `AppShellTest`/`DashboardTest`.

## Execution

Recommend the same approach as group 1 (inline execution in a worktree was used successfully there with zero fix rounds) — either inline or subagent-driven-development is reasonable given the low complexity and strong precedent; ask the user at plan time. Expect 3 tasks (one per module), each following the exact pattern already proven twice (Customer, then Cabang/Kendaraan) for the two simple modules, plus the one extra `empty-state` extension check for Sparepart's branch-scoped CTA gating.
