# Server-Side Select2 Lookup Pickers — Design

Status: approved by user, ready for implementation plan

## Purpose

Customer, Sparepart, and Mechanic picker dropdowns currently work by fetching **every** record for the relevant scope and dumping them into a plain `<select>` (e.g. `WorkOrderLookupController::sparepartsByBranch()` returns the entire branch's sparepart catalog on page load). This doesn't scale as these catalogs grow, and the same "fetch-all-then-fill" logic is duplicated near-identically across 4 separate modules (`WorkOrderLookupController`, `GoodsReceiptController::sparepartsByBranch()`, `StockAdjustmentController::sparepartsByBranch()`, `StockTransferController::sparepartsByBranch()`), each with its own route, its own controller method, and its own vanilla-JS helper object (`WorkOrderLineItems`, `GoodsReceiptLineItems`, etc.).

This plan replaces all of that with **Select2** (AJAX, server-side search, minimum 3 characters before a request fires) backed by **one shared lookup endpoint per entity**, consumed through **one shared JS helper**, across all 7 touch points these three entities appear in.

## Scope

**In scope — 7 touch points, 3 entities:**
1. Work Order (PKB) create/edit: Customer picker, Mechanic picker, Sparepart line-item picker (all 3 entities in one form — this is the pilot).
2. Goods Receipt create/edit: Sparepart line-item picker.
3. Stock Adjustment create/edit: Sparepart line-item picker.
4. Stock Transfer create/edit: Sparepart line-item picker.
5. Vehicle create/edit: Customer picker (currently a plain server-rendered `<select>` with every active customer — becomes AJAX search).
6. Sparepart Master "Tambah dari Cabang Lain" (`sparepart-branches/create-existing.blade.php`): Sparepart picker.

**Explicitly out of scope:**
- Branch, Jasa Service, and Kategori/Merk/Tipe Kendaraan dropdowns — small, fixed catalogs, stay plain `<select>` (user explicitly excluded these).
- `WorkOrderLookupController::vehiclesByCustomer()` (the vehicle-by-customer cascading dropdown in the PKB form) — a 4th entity (Vehicle) not part of this plan's 3-entity scope, untouched.
- The Vehicles list page's `_customer_filter_select.blade.php` — a page-level filter select (submits the whole page on change, not a form-entry picker), out of scope; confirm with the user separately if this should be revisited later.
- Select2's built-in infinite-scroll pagination — a 20-result cap per query is enough given a 3-character minimum already narrows results substantially. Revisit only if real usage shows 20 is too few.
- Making the Vehicle form's customer picker branch-scoped — it stays scope-free (all active customers), matching current behavior exactly; only the *interaction* (search vs. preloaded list) changes.

## Dependency & loading strategy

jQuery 3.7.x + Select2 4.1.x, both via CDN, loaded **only in the Blade views that use them** — not in `layouts/app.blade.php`. This mirrors the existing precedent: Chart.js is loaded only in `dashboard/index.blade.php`, not globally. Views to add the `<script>`/`<link>` tags to: `work-orders/create.blade.php`, `work-orders/edit.blade.php`, `goods-receipts/create.blade.php`, `goods-receipts/edit.blade.php`, `stock-adjustments/create.blade.php`, `stock-adjustments/edit.blade.php`, `stock-transfers/create.blade.php`, `stock-transfers/edit.blade.php`, `vehicles/create.blade.php`, `vehicles/edit.blade.php`, `sparepart-branches/create-existing.blade.php`.

## Backend: shared `LookupController`

New `app/Http/Controllers/LookupController.php`, three actions, three new top-level routes (not nested under any module's route prefix, since they're now shared):

```text
GET /lookup/customers?q=&branch_id=   → LookupController::customers()
GET /lookup/mechanics?q=&branch_id=   → LookupController::mechanics()
GET /lookup/spareparts?q=&branch_id=  → LookupController::spareparts()   (branch_id required)
```

**Permission gating — the entity's own `.view` permission, not the calling module's `.create` permission:**
- `customers()` — gate `$this->authorize('customer.view')` (global permission, matches `master.customer`'s existing gating elsewhere in the app). `branch_id` is optional: when present, results are filtered (a **data** filter, via `whereHas('customerBranches', ...)`) to customers assigned to that branch — not an authorization check, `customer.view` alone is sufficient to call the endpoint regardless of branch. When absent, all active customers matching `q` are returned (this is what the Vehicle form's picker uses).
- `mechanics()` — gate `$this->authorize('mechanic.view')` (global, matches `master.mechanic`'s existing gating). `branch_id` optional the same way, present for the PKB use case (filters to mechanics assigned to that branch).
- `spareparts()` — gate `abort_unless(auth()->user()->hasPermissionToInBranch('sparepart.view', $branchId), 403)` (branch-scoped, matching how sparepart permissions work everywhere else in this app — `persediaan.sparepart` has no global fallback). `branch_id` is **required** here (400 if missing) since sparepart data is inherently per-branch (stock, price, rack all live on `sparepart_branches`).

This is a deliberate authorization decision, not an oversight: **being able to search/see an entity for a dropdown is a read concern, separate from being able to actually create/edit the document you're filling out.** The real write-authorization check (can this user actually submit this PKB / this goods receipt / this stock adjustment) still happens exactly where it already happens today — the relevant Form Request's `authorize()` / the controller's `$this->authorize('pkb.create')` etc. at submission time — completely unaffected by this change. A user who can see spareparts in a branch but can't create a goods receipt there gains no new capability from being able to search them in a dropdown they can't actually submit.

**Response shape** — every action returns a JSON array of objects shaped for Select2 (`id` + `text` minimum), with extra fields preserved from the current endpoints so the existing "auto-fill other fields on selection" JS keeps working without a second request:

```text
customers():  [{ id, text }]                                                       // text = customer name
mechanics():  [{ id, text }]                                                       // text = mechanic name
spareparts(): [{ id, text, sparepart_id, code, selling_price, available_qty }]     // text = "{code} — {name}", matches
                                                                                     // the existing "code — name" convention
                                                                                     // already used for sparepart selects
                                                                                     // elsewhere in this app (Dashboard's
                                                                                     // Kartu Stok tab)
```

`id` for `spareparts()` is the `sparepart_branch_id` — used as Select2's own value/display key, and by 3 of the 4 sparepart-consuming modules (Work Order, Goods Receipt, Stock Adjustment all store `sparepart_branch_id` on their line items, since those documents operate within a single branch). **Stock Transfer is the exception**: `StockTransferLine` stores a bare `sparepart_id` (not `sparepart_branch_id`), because a transfer has separate from/to branches at the document header level — a single `sparepart_branch_id` wouldn't mean "this sparepart" the way it does for a single-branch document. The response therefore also includes a separate `sparepart_id` field (the bare `Sparepart` identity) alongside `id`; Stock Transfer's line-item JS reads `.sparepart_id` from the selected option's data to populate its hidden input, while every other consumer reads `.id` (== `sparepart_branch_id`) as it already does today. Select2 itself still searches/displays/tracks selection by `id` (`sparepart_branch_id`) uniformly across all four consumers — only which field gets written into the submitted form differs, and that's already true today (each module's line-item JS already has its own field-naming logic, this doesn't add new divergence, it just needs to carry one more field through the shared endpoint instead of a module-specific one).

Each action: requires `q` to be at least 3 characters (return `[]` immediately if shorter or absent — defensive on the backend too, not just a frontend gate), searches `name`/`code` with `LIKE '%...%'` (escape wildcards via `addcslashes`, matching the Customer list search fix from Foundation v3), orders by name, caps at 20 results (`->limit(20)`).

**Second query mode — resolve by ID, for pre-selecting an existing value.** Each action also accepts `ids[]` as an alternative to `q`: `GET /lookup/spareparts?ids[]=42&branch_id=3` returns that record's `{id, text, ...}` shape regardless of the 3-character rule (multiple ids may be passed for the sparepart line-item case, one call resolves every existing line at once). This exists specifically to render "already selected" state — a Select2 AJAX picker has no full option list to draw an initial label from, so the only way to show "PKB is being edited and already has Sparepart X selected" (or replay an `old()` value after a failed validation) is to explicitly resolve that one record's display text via this same endpoint before Select2 initializes on that field. Same permission gate as the `q` path — this is still a read lookup, not a new capability.

If both `q` and `ids[]` are present, `ids[]` wins (mutually exclusive in practice — the frontend never sends both in the same request).

## Frontend: shared JS helper

**Reality check that shapes this section:** the sparepart picker in all four line-item modules (Work Order, Goods Receipt, Stock Adjustment, Stock Transfer) is not a fixed page element — each is added dynamically via `<template>` cloning when the user clicks "+ Tambah ...", and today's auto-fill (unit price, available stock) reads a JSON blob the old `fillSelect()` stashed on `option.dataset.item`, and validation-error replay works today only because the *entire* catalog was already fetched, so setting `select.value = oldId` was enough to show the right text. None of those three things works unmodified with an AJAX-search Select2 (no pre-fetched catalog exists to draw options or text from). The shared helper is designed around all three:

New file `public/js/select2-ajax-picker.js`, one function:

```js
function initAjaxSelect(el, { endpoint, extraParams = {}, placeholder = '-- Cari --', onSelect = null }) {
    const $el = $(el);
    $el.select2({
        placeholder,
        allowClear: true,
        minimumInputLength: 3,
        width: '100%',
        language: {
            inputTooShort: function () { return 'Ketik minimal 3 huruf...'; },
            searching: function () { return 'Mencari...'; },
            noResults: function () { return 'Tidak ditemukan.'; },
        },
        ajax: {
            url: endpoint,
            delay: 300,
            data: function (params) {
                return Object.assign({ q: params.term }, typeof extraParams === 'function' ? extraParams() : extraParams());
            },
            processResults: function (data) {
                return { results: data };
            },
        },
    });
    if (onSelect) {
        $el.on('select2:select', function (e) {
            onSelect(e.params.data);
        });
    }
    return $el;
}

async function preselectAjaxOption(el, { endpoint, id, extraParams = {} }) {
    const params = new URLSearchParams(Object.assign({ 'ids[]': id }, typeof extraParams === 'function' ? extraParams() : extraParams()));
    const response = await fetch(`${endpoint}?${params}`, { headers: { Accept: 'application/json' } });
    const [item] = await response.json();
    if (!item) return null;
    const option = new Option(item.text, item.id, true, true);
    $(el).append(option);
    return item;
}
```

- `initAjaxSelect(el, {...})`: sets up the AJAX search behavior. `extraParams` is **always a function** (not the plain-object-or-function union an earlier draft of this spec had — a function is required uniformly because `branch_id` is only known after the user picks a branch, and for dynamically-added sparepart-line selects the branch may already be known by the time the row is created; a function covers both cases without a second code path). `onSelect`, when given, is called with the *full* AJAX result object for whatever the user picked — this is how auto-fill (unit price, available stock) is wired: the consuming view passes an `onSelect` that reads `data.selling_price` / `data.available_qty` directly, replacing the old `dataset.item` read.
- `preselectAjaxOption(el, {...})`: for the "this field already has a value, show it before the user searches" case — edit-page initial state, and create-page validation-error replay. Calls the same lookup endpoint with `ids[]` (per the backend's ID-resolve mode above), builds one real `<option>` with the correct text via the standard `new Option(text, value, true, true)` pattern (the two `true`s mark it default-selected), appends it to the `<select>`, and returns the resolved item so the caller can also drive any dependent auto-fill (e.g. showing a sparepart line's available stock on edit-page load, not just on user interaction). Called **before** `initAjaxSelect()` on the same element, so Select2 picks up the pre-existing selected option as its starting state.

**Dynamic sparepart-line rows**: each module's existing `addSparepartLine()` (or equivalently-named function) already runs once per "+ Tambah Sparepart" click, cloning the `<template>` and appending it to the DOM. The only change to that function is adding one call at the end: `initAjaxSelect(newRow.querySelector('.sparepart-select'), { endpoint: '/lookup/spareparts', extraParams: () => ({ branch_id: currentBranchId }), onSelect: function (item) { /* fill unit price + available qty from item, same fields as today, just sourced from the callback argument instead of dataset.item */ } })`. `currentBranchId` is whatever module-scoped variable already tracks the selected branch (every one of these forms already gates line-item add on a branch being chosen first, so this variable already exists in each view — only its name may differ per module).

**Edit-page initial state and create-page validation replay** both use `preselectAjaxOption()` the same way: for each already-known value (an existing PKB's `customer_id`/`mechanic_id`/each sparepart line's `sparepart_branch_id` on `edit.blade.php`, or `old('customer_id')`/`old('mechanic_id')`/`old('spareparts')[].sparepart_branch_id` on `create.blade.php` after a failed submission), call `preselectAjaxOption()` on that field's `<select>` before `initAjaxSelect()` runs on it. For sparepart lines specifically, this means the existing `replayOldLines()`-style function now does, per line: clone the template, append it, call `preselectAjaxOption()` with the line's `old sparepart_branch_id`, **then** `initAjaxSelect()` — sequential, since `initAjaxSelect()` needs the pre-existing option already in the DOM to recognize it as the starting selection.

The existing per-module `fillSelect`/`fetchJson` helpers (`WorkOrderLineItems`, `GoodsReceiptLineItems`, `StockAdjustmentLineItems`, `StockTransferLineItems`) are **not** deleted wholesale — each still owns genuinely module-specific logic (line-item add/remove rows, the vehicle-by-customer cascade in PKB, form submission wiring, the template-cloning mechanics themselves). Only the specific "populate this dropdown with a big fetched list" methods for Customer/Mechanic/Sparepart are replaced by calls into the two shared functions above.

## Rollout order

1. **Pilot: Work Order (PKB).** The only form using all three entities at once — proves all three new endpoints and the shared JS helper together in one place before touching anything else. `WorkOrderLookupController::customersByBranch()`, `mechanicsByBranch()`, and `sparepartsByBranch()` are removed (their routes too) once PKB's create/edit views are wired to the new shared endpoints; `vehiclesByCustomer()` and its route are untouched (out of scope).
2. **Vehicle form** (Customer picker) — second, since it's the simplest single-picker consumer and exercises the "no `branch_id`" path of `/lookup/customers` that PKB's pilot doesn't cover.
3. **Goods Receipt, Stock Adjustment, Stock Transfer** (Sparepart picker each) — same mechanical change repeated three times once the pattern is proven; each module's own `sparepartsByBranch()` method and route are removed.
4. **Sparepart Master "Tambah dari Cabang Lain"** (Sparepart picker) — last, lowest-traffic screen.

## Testing

- `LookupController` feature tests: each action — returns matching results for a 3+ char query, returns empty for <3 chars, respects the `.view` permission gate (403 without it), `spareparts()` 400s without `branch_id`, `customers()`/`mechanics()` branch-filtering works when `branch_id` given and is skipped when absent, response shape matches (`id`/`text` present, sparepart's extra fields present and correctly typed as float), **and the `ids[]` resolve mode**: returns the matching record(s) regardless of length, multiple `ids[]` in one call resolve all of them, an id outside the caller's branch/permission scope is silently excluded (not a 403 for the whole request — mirrors how the rest of this app treats out-of-scope ids in a list as "filtered", not "forbidden").
- Per rollout task: the existing tests for that module's create/edit flow (`WorkOrderManagementTest` and friends) must keep passing — this change touches only how the dropdown is populated, not what happens on form submission, so submission-flow tests should need no changes; only tests that specifically asserted on the *old* lookup endpoints' behavior need updating/removing (the old endpoints are being deleted).
- Manual verification (no JS test harness in this project, consistent with how Chart.js/AJAX filter bars were verified earlier): load each of the 7 touch points and confirm — typing 1-2 characters shows "Ketik minimal 3 huruf...", 3+ characters searches and shows results, selecting a sparepart still auto-fills price/available-stock where the form already does that today, **opening an existing PKB/Goods Receipt/Stock Adjustment/Stock Transfer for edit shows its already-selected customer/mechanic/sparepart lines with correct display text (not blank, not just an id)**, and **submitting a PKB with a validation error (e.g. missing tanggal) and having it bounce back still shows the previously-picked customer/mechanic/sparepart lines correctly** rather than reverting to empty pickers.

## Execution

Recommend **`subagent-driven-development` in a worktree** — this plan's blast radius (new backend permission model for lookups, a new frontend dependency, a dynamic-row-aware JS pattern with a preselect/replay mechanism, and 7 touch points across 6 different Blade view groups) plus deleting 3+ existing endpoints puts it in the same size class as Foundation v3 and the sparepart-stock migration, both of which used this process — if anything this is denser per-task than either, given the dynamic-row/replay mechanics confirmed during plan research (see the effort-revision discussion this session: user explicitly confirmed proceeding at full scope after this was surfaced). Expect roughly 9-10 tasks: shared `LookupController` (3 actions + `ids[]` resolve mode) + routes + tests, shared JS helper (`initAjaxSelect` + `preselectAjaxOption`) + CDN loading, PKB pilot (all 3 pickers, including edit-page preselect and create-page validation replay for all three), Vehicle form, Goods Receipt, Stock Adjustment, Stock Transfer, Sparepart "Tambah dari Cabang Lain", full-suite verification. The PKB pilot task in particular should not be split further despite its size — it's the one place all the hard parts (dynamic rows, auto-fill, preselect, replay) need to work together before any other module copies the pattern.
