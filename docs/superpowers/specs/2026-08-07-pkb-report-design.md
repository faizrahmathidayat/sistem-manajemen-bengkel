# Laporan PKB (Work Order Report) — Design

## Context

The sidebar has carried a disabled "Laporan PKB" placeholder (gated by `report.pkb.view`) since the Foundation phase. This is the first of four remaining Reporting placeholders to be activated, following the same pattern already proven by the Receivables Report (`ReceivableReportController`, shipped and in production use). This design deliberately reuses that pattern wherever it fits, and calls out explicitly where PKB's data shape forces a divergence.

## Decisions

**1. Standalone read-only module, same shape as `ReceivableReportController`.** New `App\Http\Controllers\PkbReportController@index`, route `GET /reports/pkb` → `reports.pkb.index`, inside the existing `Route::prefix('reports')->name('reports.')` group (`routes/web.php:204`). No new Service class — all query logic lives directly in the controller, matching the Receivables precedent (a report has no business transitions to protect, unlike `InvoiceService`/`PaymentService`).

**2. Authorization is branch-scoped via `branchesWithPermission('report.pkb.view')`, not a bare `authorize()`.** Confirmed in the seeder (`database/seeders/MenuPermissionSeeder.php:225`): `report.pkb.view` is seeded `is_branch_scoped => true` — same scoping as `report.receivable.view`, opposite of the Audit Log's global `audit_log.view`. `PkbReportController::index()` follows the exact Receivables shape:
```php
$permittedBranches = auth()->user()->branchesWithPermission('report.pkb.view');
if ($permittedBranches->isEmpty()) {
    return view('reports.pkb.no-access');
}
```
The base query is **always** first scoped to `whereIn('branch_id', $permittedBranches->pluck('id'))`, with the request's `branch_ids[]` applied only as a narrowing filter intersected against that permitted set — never trusted alone. This is the exact safeguard the Receivables Report plan had to correct mid-review; stating it here up front avoids repeating that mistake.

**3. `WorkOrder` has no stored `grand_total` / subtotal columns — they must be derived from its line tables.** Confirmed by reading the migrations directly: `work_orders` (`database/migrations/..._create_work_orders_table.php`) has no monetary columns at all. The money lives on `work_order_service_lines.line_total` and `work_order_sparepart_lines.line_total` (both `decimal(18,2)`, already the pre-computed qty × unit_price for that line — no need to re-multiply). Two different aggregation strategies are used for two different needs:
   - **Per-row table columns** (`Subtotal Jasa`, `Subtotal Sparepart`, `Grand Total`): `withSum('serviceLines as subtotal_service', 'line_total')` and `withSum('sparepartLines as subtotal_sparepart', 'line_total')` on the paginated (max 15 rows) query — simple, readable, cheap at this row count.
   - **Page-level summary card** (`Total Nilai PKB`): a single correlated-subquery `selectRaw` on the *filtered* query (before pagination), mirroring exactly how `ReceivableReportController` computes its summary row with one aggregate SQL query instead of loading the full filtered set into PHP:
     ```php
     $summary = (clone $query)->selectRaw(
         'COUNT(*) as total_pkb, ' .
         'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as total_completed, ' .
         'COALESCE(SUM(' .
             '(SELECT COALESCE(SUM(line_total), 0) FROM work_order_service_lines WHERE work_order_service_lines.work_order_id = work_orders.id) + ' .
             '(SELECT COALESCE(SUM(line_total), 0) FROM work_order_sparepart_lines WHERE work_order_sparepart_lines.work_order_id = work_orders.id)' .
         '), 0) as total_value',
         [WorkOrderStatus::COMPLETED]
     )->first();
     ```

**4. The summary cards reflect whatever the current filter state is — no hidden status exclusion.** Unlike Receivables (which defaults to `unpaid` because "outstanding" is the report's whole point), a PKB report has no single obviously-default status. The Status PKB filter defaults to **"Semua Status"** (no `WHERE status = ...` applied at all), and `Total Nilai PKB` sums whatever the active filter returns — including `cancelled` and `draft` PKBs if the user hasn't narrowed by status. This keeps the summary card's number always consistent with what's visibly listed below it, at the cost of not being a "confirmed revenue" figure by default; a user who wants that narrows via the Status filter themselves (e.g. selects "Selesai").

**5. Filter form — four fields, same multiselect-branch partial as every other report:**
   - **Cabang**: `@include('partials.branch-multiselect-filter', ...)` — identical include, identical JS (copied inline into the view's `@push('scripts')`, matching the copy-not-share convention already established by Receivables and Audit Log's views).
   - **Status PKB**: `<select>` with options for each `WorkOrderStatus` constant (`draft`, `open`, `shortage`, `completed`, `cancelled`) plus "Semua Status", labeled in Indonesian matching `work-orders/index.blade.php`'s existing status badge text (Draft / Dikonfirmasi / Kurang Stok / Selesai / Dibatalkan).
   - **Mekanik**: free-text search box, matched via `whereHas('mechanic', fn ($q) => $q->where('name', 'like', "%{$escaped}%"))` — same `addcslashes($term, '%_\\')` escaping pattern as the Receivables customer search and Audit Log user search.
   - **Rentang Tanggal PKB**: `date_from`/`date_to` against `work_order_date`, same `whereDate(...)` + `parseDate()` regex-validated helper copied from `ReceivableReportController::parseDate()`.

**6. Table columns and their data sources:**

| Column | Source |
|---|---|
| No. PKB | `$workOrder->number`, linked via `route('work-orders.show', $workOrder)` |
| Tanggal | `$workOrder->work_order_date->format('d/m/Y')` |
| Customer & Kendaraan | `{{ $workOrder->customer->name }}` + `{{ $workOrder->vehicle->plate_number }}` in one cell (two lines), requires eager-loading `customer` and `vehicle` |
| Mekanik | `$workOrder->mechanic->name` (eager-loaded) |
| Subtotal Jasa | `$workOrder->subtotal_service` (from `withSum`, decision 3) |
| Subtotal Sparepart | `$workOrder->subtotal_sparepart` (from `withSum`, decision 3) |
| Grand Total | `$workOrder->subtotal_service + $workOrder->subtotal_sparepart`, computed in the view (no separate query needed — both addends are already loaded) |
| Status Badge | Same `status-dot` classes as `work-orders/index.blade.php:43-52` (`status-inactive` draft, `status-active` open/completed, `status-warning` shortage, `status-danger` cancelled) |

**7. Pagination is `simplePaginate(15)`**, matching the Receivables Report exactly (not the Audit Log's 20 — no strong reason to diverge, and consistency with the report the user will most directly compare this one against is preferred).

**8. Sidebar wiring is a pure markup swap, no logic change.** `resources/views/partials/sidebar.blade.php:140-147`'s `@if ($user->branchesWithPermission('report.pkb.view')->isNotEmpty())` wrapper is already correct and unchanged; only the inner `<span class="nav-link nav-link-disabled">...<span class="badge-soon">Segera Hadir</span></span>` is replaced with a real `<a href="{{ route('reports.pkb.index') }}" class="nav-link {{ request()->routeIs('reports.pkb.*') ? 'active' : '' }}">`, identical to how Receivables' placeholder was converted.

**9. `no-access` view is a near-duplicate of Receivables', not a shared partial.** `resources/views/reports/pkb/no-access.blade.php` follows the same structure as `reports/receivables/no-access.blade.php` (page title + centered card with a "belum memiliki akses" message) — kept as its own file rather than extracted into a shared partial, matching this codebase's established preference for small per-report views over premature sharing (each report view already duplicates its filter-JS block rather than sharing it).

## Out of Scope

- Editing or acting on work orders from this report (read-only, matches Receivables).
- The "PKB vs Invoice" gap report (`report.invoice_pkb_gap.view`) — a separate, not-yet-designed placeholder.
- Any change to `WorkOrder`, `WorkOrderServiceLine`, or `WorkOrderSparepartLine` schema — this report only reads existing data.
