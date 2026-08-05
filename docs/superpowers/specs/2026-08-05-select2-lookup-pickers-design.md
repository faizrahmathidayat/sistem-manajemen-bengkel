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
customers():  [{ id, text }]                                          // text = customer name
mechanics():  [{ id, text }]                                          // text = mechanic name
spareparts(): [{ id, text, code, selling_price, available_qty }]      // text = "{code} — {name}", matches
                                                                        // the existing "code — name" convention
                                                                        // already used for sparepart selects
                                                                        // elsewhere in this app (Dashboard's
                                                                        // Kartu Stok tab)
```

`id` for `spareparts()` is the `sparepart_branch_id` (not the bare `sparepart_id`) — matches every existing consumer, since the actual document line always needs to know which branch's stock/price row it's referencing, exactly like today's `sparepartsByBranch()` endpoints.

Each action: requires `q` to be at least 3 characters (return `[]` immediately if shorter or absent — defensive on the backend too, not just a frontend gate), searches `name`/`code` with `LIKE '%...%'` (escape wildcards via `addcslashes`, matching the Customer list search fix from Foundation v3), orders by name, caps at 20 results (`->limit(20)`).

## Frontend: shared JS helper

New file `public/js/select2-ajax-picker.js`, one function:

```js
function initAjaxSelect(selector, { endpoint, extraParams = {}, placeholder = '-- Cari --' }) {
    $(selector).select2({
        placeholder,
        allowClear: true,
        minimumInputLength: 3,
        language: {
            inputTooShort: function () { return 'Ketik minimal 3 huruf...'; },
            searching: function () { return 'Mencari...'; },
            noResults: function () { return 'Tidak ditemukan.'; },
        },
        ajax: {
            url: endpoint,
            delay: 300,
            data: function (params) {
                return Object.assign({ q: params.term }, typeof extraParams === 'function' ? extraParams() : extraParams);
            },
            processResults: function (data) {
                return { results: data };
            },
        },
    });
}
```

`extraParams` accepts either a plain object or a function (for `branch_id`, which is only known once the user has picked a branch elsewhere on the same form — the existing PKB/Goods Receipt/etc. forms all already gate the branch-dependent fields behind a branch selection, this preserves that). Each consuming view calls `initAjaxSelect('#customerSelect', { endpoint: '/lookup/customers', extraParams: () => ({ branch_id: currentBranchId }) })` — one line per picker, replacing that picker's current `fetchJson`/`fillSelect` call.

The existing per-module `fillSelect`/`fetchJson` helpers (`WorkOrderLineItems`, `GoodsReceiptLineItems`, `StockAdjustmentLineItems`, `StockTransferLineItems`) are **not** deleted wholesale — each still owns genuinely module-specific logic (line-item add/remove rows, the vehicle-by-customer cascade in PKB, form submission wiring). Only the specific "populate this dropdown with a big fetched list" methods for Customer/Mechanic/Sparepart are replaced by a Select2 initialization call.

## Rollout order

1. **Pilot: Work Order (PKB).** The only form using all three entities at once — proves all three new endpoints and the shared JS helper together in one place before touching anything else. `WorkOrderLookupController::customersByBranch()`, `mechanicsByBranch()`, and `sparepartsByBranch()` are removed (their routes too) once PKB's create/edit views are wired to the new shared endpoints; `vehiclesByCustomer()` and its route are untouched (out of scope).
2. **Vehicle form** (Customer picker) — second, since it's the simplest single-picker consumer and exercises the "no `branch_id`" path of `/lookup/customers` that PKB's pilot doesn't cover.
3. **Goods Receipt, Stock Adjustment, Stock Transfer** (Sparepart picker each) — same mechanical change repeated three times once the pattern is proven; each module's own `sparepartsByBranch()` method and route are removed.
4. **Sparepart Master "Tambah dari Cabang Lain"** (Sparepart picker) — last, lowest-traffic screen.

## Testing

- `LookupController` feature tests: each action — returns matching results for a 3+ char query, returns empty for <3 chars, respects the `.view` permission gate (403 without it), `spareparts()` 400s without `branch_id`, `customers()`/`mechanics()` branch-filtering works when `branch_id` given and is skipped when absent, response shape matches (`id`/`text` present, sparepart's extra fields present and correctly typed as float).
- Per rollout task: the existing tests for that module's create/edit flow (`WorkOrderManagementTest` and friends) must keep passing — this change touches only how the dropdown is populated, not what happens on form submission, so submission-flow tests should need no changes; only tests that specifically asserted on the *old* lookup endpoints' behavior need updating/removing (the old endpoints are being deleted).
- Manual verification (no JS test harness in this project, consistent with how Chart.js/AJAX filter bars were verified earlier): load each of the 7 touch points, confirm typing 1-2 characters shows "Ketik minimal 3 huruf...", 3+ characters searches and shows results, selecting a sparepart still auto-fills price/available-stock where the form already does that today.

## Execution

Recommend **`subagent-driven-development` in a worktree** — this plan's blast radius (new backend permission model for lookups, a new frontend dependency, and 7 touch points across 6 different Blade view groups) plus deleting 3+ existing endpoints puts it in the same size class as Foundation v3 and the sparepart-stock migration, both of which used this process. Expect roughly 8-9 tasks: shared `LookupController` + routes + tests, shared JS helper + CDN loading, PKB pilot (all 3 pickers), Vehicle form, Goods Receipt, Stock Adjustment, Stock Transfer, Sparepart "Tambah dari Cabang Lain", full-suite verification.
