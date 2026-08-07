# Laporan Invoice (Sales Invoice Report) — Design

## Context

This is the third of four remaining Reporting placeholders, following the same pattern proven by Laporan PKB (`PkbReportController`, including its Mode Detail extension) and Laporan Piutang (`ReceivableReportController`). Both already read from `Invoice` and its related tables, so this design reuses their proven shapes wherever they fit, and calls out explicitly where Invoice's actual schema forces a divergence from what the request assumed.

## Decisions

**1. Standalone read-only module, same shape as `PkbReportController`.** New `App\Http\Controllers\InvoiceReportController@index`, route `GET /reports/invoices` → `reports.invoices.index`, inside the existing `Route::prefix('reports')->name('reports.')` group (`routes/web.php:205-208`). No new Service class — matches every other report in this codebase.

**2. Authorization is branch-scoped via `branchesWithPermission('report.invoice.view')`.** Confirmed in the seeder (`database/seeders/MenuPermissionSeeder.php:230-237`): `report.invoice.view` is seeded `is_branch_scoped => true`. Same shape as PKB/Receivables:
```php
$permittedBranches = auth()->user()->branchesWithPermission('report.invoice.view');
if ($permittedBranches->isEmpty()) {
    return view('reports.invoices.no-access');
}
```
The base query is always first scoped to `whereIn('branch_id', $permittedBranches->pluck('id'))`, with `branch_ids[]` applied only as a narrowing filter intersected against that permitted set — same safeguard as every other report.

**3. Key finding: unlike `WorkOrder`, `Invoice` already stores its money columns — no `withSum()` is needed anywhere in this report, in either mode.** The request's requirement #5 says Rekap mode computes `subtotal_service` & `subtotal_sparepart` "via withSum" (copying the PKB Report's approach). Reading the migration (`database/migrations/2026_08_05_000003_create_invoices_table.php`) shows this doesn't apply here: `invoices` has `subtotal_service`, `subtotal_sparepart`, `discount_percent`, `discount_amount`, `tax_percent`, `tax_amount`, `grand_total`, and `paid_amount` (added later, `..._000006_add_paid_amount_to_invoices_table.php`) as first-class columns on the row itself — they're written once at invoice-creation/payment time, not derived from summing line tables on every read. Rekap mode is therefore a **plain column select**, no aggregation subquery at all:
   - **Total Sisa Piutang** (remaining/outstanding) has no stored column, but doesn't need one either — `Invoice` already exposes it as a computed accessor: `getOutstandingAmountAttribute()` → `round($grand_total - $paid_amount, 2)` (`app/Models/Invoice.php:71-74`). Per-row Rekap display uses `$invoice->outstanding_amount` directly, no extra query.
   - The page-level summary card sums across the full filtered set (not just the paginated page) with the exact same `selectRaw` shape `ReceivableReportController` already uses (`app/Http/Controllers/ReceivableReportController.php:53-57`):
     ```php
     $summary = (clone $query)->selectRaw(
         'COUNT(*) as total_invoice, ' .
         'COALESCE(SUM(grand_total), 0) as total_nominal, ' .
         'COALESCE(SUM(paid_amount), 0) as total_paid, ' .
         'COALESCE(SUM(grand_total - paid_amount), 0) as total_remaining'
     )->first();
     ```

**4. Key finding: there is no `invoice_service_lines`/`invoice_sparepart_lines` split — Invoice line items live in a single `invoice_details` table with an `item_type` discriminator.** The request's requirement #5 names two separate line tables (mirroring `work_order_service_lines`/`work_order_sparepart_lines`). Reading the migration (`database/migrations/2026_08_05_000004_create_invoice_details_table.php`) and `InvoiceDetail` model shows Invoice's line items are unified: one `invoice_details` row per item, `item_type` is `'service'` or `'sparepart'` (constants in `App\Support\InvoiceDetailItemType`), and — critically — `description`, `item_code_snapshot`, `qty`, `unit_price`, `line_total` are **already its own snapshot columns on `invoice_details` itself**, independent of the `WorkOrderServiceLine`/`WorkOrderSparepartLine` rows they were copied from at invoice-creation time. Detail mode therefore:
   - Iterates `$invoice->details` (relation already defined as `hasMany(InvoiceDetail::class)->orderBy('sort_order')`, `app/Models/Invoice.php:61-64`).
   - Reads `$detail->item_type` for the Tipe Item column (`service` → "Jasa", `sparepart` → "Sparepart" — same label convention as PKB Detail Mode).
   - Reads `$detail->description`/`qty`/`unit_price`/`line_total` directly — no join or extra relation needed for display.
   - Eager-loads only `details` (`with(['details'])`) — never `serviceLine`/`sparepartLine`/`sparepartLine.sparepartBranch.sparepart`, matching the request's own instruction to avoid unnecessary master-data eager-loading (requirement #5), and matching the precedent set by PKB Detail Mode's Decision 2.

**5. Mode toggle mirrors PKB Detail Mode exactly, with the query-branch content adjusted for decision 3/4 above.**
```php
$mode = request('mode') === 'detail' ? 'detail' : 'rekap';
...
$invoices = $query->with(['branch', 'customer']);
if ($mode === 'detail') {
    $invoices->with(['details']);
}
// Rekap needs no extra eager-load or withSum at all — every Rekap column
// already lives on the `invoices` row loaded by the base query.
$invoices = $invoices->orderByDesc('invoice_date')->orderByDesc('id')
    ->simplePaginate(15)->withQueryString();
```
Any `mode` value other than the literal string `detail` falls back to `rekap` — same reject-to-safe-default rule as PKB.

**6. The summary cards reflect whatever the current filter state is — no hidden status exclusion, same as PKB (not Receivables).** Receivables Report defaults its Status filter to `unpaid` because "outstanding" is that report's whole point. Laporan Invoice has no single obviously-default status — the Status Invoice filter defaults to **"Semua Status"** (no `WHERE status = ...`), and all 4 summary cards sum whatever the active filter returns, including `draft` and `cancelled` invoices if the user hasn't narrowed by status. A user who wants "confirmed billing only" narrows via the Status filter themselves (e.g. selects "Diposting" + "Dibayar Sebagian" — though note: the Status filter is a single-select here, matching PKB's single-select Status PKB field, not Receivables' 3-way unpaid/paid/all preset; selecting one exact `InvoiceStatus` value at a time keeps the filter's mental model consistent with PKB's).

**7. Filter form — five fields:**
   - **Cabang**: `@include('partials.branch-multiselect-filter', ...)`, identical include + copied-inline JS convention as every other report.
   - **Status Invoice**: `<select>` with "Semua Status" plus one `<option>` per `InvoiceStatus` constant (`draft`, `posted`, `partially_paid`, `paid`, `cancelled`), labeled in Indonesian matching `invoices/index.blade.php`'s existing status text (Draft / Diposting / Dibayar Sebagian / Lunas / Dibatalkan).
   - **Tampilan**: `<select name="mode">` with `rekap` (default, "Rekap") / `detail` ("Detail") — identical field to PKB Detail Mode.
   - **Customer/No. Invoice**: a single free-text search box matching *either* field — `where('number', 'like', "%{$escaped}%")->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$escaped}%"))`, same `addcslashes($term, '%_\\')` escaping convention as every other report's text search. (The existing operational `InvoiceController::index()` only searches `number`; Receivables only searches customer name; this report's request explicitly asks for both in one box, so both conditions are OR'd together.)
   - **Rentang Tanggal Invoice**: `date_from`/`date_to` against `invoice_date`, same `whereDate(...)` + `parseDate()` regex-validated helper as every other report.

**8. Rekap table columns and their data sources (10 columns):**

| Column | Source |
|---|---|
| No. Invoice | `$invoice->number`, linked via `route('invoices.show', $invoice)` |
| Tanggal | `$invoice->invoice_date->format('d/m/Y')` |
| Customer | `$invoice->customer->name` (eager-loaded) |
| Subtotal Jasa | `$invoice->subtotal_service` — stored column, decision 3 |
| Subtotal Sparepart | `$invoice->subtotal_sparepart` — stored column, decision 3 |
| Discount | `$invoice->discount_amount` — stored column |
| Grand Total | `$invoice->grand_total` — stored column |
| Terbayar | `$invoice->paid_amount` — stored column |
| Sisa Piutang | `$invoice->outstanding_amount` — model accessor, decision 3 |
| Status Badge | Same `status-dot` classes as `invoices/index.blade.php:39-49` (`status-inactive` draft "Draft", `status-active` posted "Diposting", `status-warning` partially_paid "Dibayar Sebagian", `status-active` paid "Lunas", `status-danger` cancelled "Dibatalkan") |

**9. Detail table columns (9 columns), same shape and safeguards as PKB Detail Mode:**

| Column | Source |
|---|---|
| No. Invoice | `$invoice->number`, linked via `route('invoices.show', $invoice)` |
| Tanggal | `$invoice->invoice_date->format('d/m/Y')` |
| Customer | `$invoice->customer->name` |
| Tipe Item | `$detail->item_type === InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart'` |
| Nama Item | `$detail->description` |
| Qty | `$detail->qty`, `number_format(..., 0, ',', '.')` |
| Harga Satuan | `$detail->unit_price`, `number_format(..., 0, ',', '.')` |
| Subtotal Line | `$detail->line_total`, `number_format(..., 0, ',', '.')` |
| Status | Same badge as the Rekap column above, repeated per line row |

Identical rules to PKB Detail Mode apply: PKB-identifying columns (No. Invoice, Tanggal, Customer, Status) repeat on every line row (no `rowspan` — same reasoning as PKB Decision 5), and an invoice with zero `details` rows still renders exactly one row with `—` in the item columns rather than disappearing (defensive, even though every invoice observed in this codebase is created from a fully-lined `WorkOrder` and should always have at least one detail).

**10. Pagination is `simplePaginate(15)` on `Invoice`, in both modes** — never re-paginate by detail-row count, same reasoning as PKB Decision 4 (a page must always represent a fixed set of invoices regardless of which mode is showing).

**11. Sidebar wiring is a pure markup swap.** `resources/views/partials/sidebar.blade.php:147-154`'s `@if ($user->branchesWithPermission('report.invoice.view')->isNotEmpty())` wrapper is already correct and unchanged; only the inner `<span class="nav-link nav-link-disabled">...<span class="badge-soon">Segera Hadir</span></span>` is replaced with a real `<a href="{{ route('reports.invoices.index') }}" class="nav-link {{ request()->routeIs('reports.invoices.*') ? 'active' : '' }}">`.

**12. `no-access` view is a near-duplicate of Receivables'/PKB's, not a shared partial.** `resources/views/reports/invoices/no-access.blade.php` follows the same title + centered "belum memiliki akses" card structure, kept as its own file — same established preference for small per-report views over premature sharing.

## Out of Scope

- Editing, posting, cancelling, or otherwise acting on invoices from this report (read-only, matches every other report).
- The "PKB vs Invoice" gap report (`report.invoice_pkb_gap.view`) — a separate, not-yet-designed placeholder.
- Payment allocation / receipt detail (which payment receipts paid how much of an invoice) — that's `PaymentReceipt`/`PaymentAllocation` territory, already covered by the existing Payment Receipt module; this report only reads `invoices.paid_amount`.
- Any schema change to `Invoice` or `InvoiceDetail` — this report only reads existing data.
