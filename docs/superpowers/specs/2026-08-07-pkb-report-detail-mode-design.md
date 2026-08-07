# Laporan PKB — Mode Tampilan Detail — Design

## Context

The Laporan PKB (`PkbReportController@index`, shipped and in production use) currently shows one row per PKB with two aggregated columns (`Subtotal Jasa`, `Subtotal Sparepart`) computed via `withSum()`. Users reviewing a specific PKB's composition — which jasa or sparepart items actually made up its total — have to leave the report and open the PKB detail page (`work-orders/show.blade.php`) to see that. This adds a second view mode to the same report, letting the user switch between the existing aggregated view ("Rekap") and a new line-item view ("Detail") without leaving the page.

## Decisions

**1. A third filter field, "Tampilan", toggles between `mode=rekap` (default) and `mode=detail`.** Added to the existing filter form (`resources/views/reports/pkb/index.blade.php`) as a `<select name="mode">` with two options, submitted via the same GET form as Cabang/Status/Mekanik/Tanggal. `PkbReportController::index()` reads it as:
```php
$mode = request('mode') === 'detail' ? 'detail' : 'rekap';
```
Any value other than the literal string `detail` (missing, empty, garbage) falls back to `rekap` — matching the existing `parseDate()`/`status` validation style already in the controller (reject-to-safe-default, never throw on bad query input).

**2. The Nama Item/Jasa column reads existing snapshot fields — no `serviceCatalog`/`sparepartBranch.sparepart` eager-loading is needed.** The request's requirement #3 named `serviceLines.serviceCatalog` and `sparepartLines.sparepartBranch.sparepart` as relations to eager-load for item names. Reading `WorkOrderServiceLine`/`WorkOrderSparepartLine` (`app/Models/WorkOrderServiceLine.php`, `app/Models/WorkOrderSparepartLine.php`) and the existing PKB detail page (`resources/views/work-orders/show.blade.php:74,95-96`) shows both line tables already carry denormalized snapshot columns for exactly this purpose:
   - `work_order_service_lines.description` — the jasa name, already `fillable`, already what `work-orders/show.blade.php` displays.
   - `work_order_sparepart_lines.item_name_snapshot` (name) and `item_code_snapshot` (code) — same story.

   These snapshots exist precisely so a PKB's line items keep displaying their name/code as of the time they were added, independent of later renames in the `service_catalogs`/`spareparts` master tables. Loading `serviceCatalog`/`sparepartBranch.sparepart` would add two extra relation queries per line for data the report never displays. The only eager-loading Detail mode needs is the line collections themselves: `serviceLines`, `sparepartLines` (both already `hasMany` on `WorkOrder`, both already `orderBy('sort_order')` in their relation definitions — no extra ordering logic needed).

**3. Conditional eager-loading/aggregation, branching on `$mode`, applied to the same base query.** The base `$query` (branch scoping, status/mechanic/date filters) is untouched — only the loads attached before `simplePaginate(15)` differ:
```php
$workOrders = $query->with(['branch', 'customer', 'vehicle', 'mechanic']);
if ($mode === 'detail') {
    $workOrders->with(['serviceLines', 'sparepartLines']);
} else {
    $workOrders->withSum('serviceLines as subtotal_service', 'line_total')
                ->withSum('sparepartLines as subtotal_sparepart', 'line_total');
}
$workOrders = $workOrders->orderByDesc('work_order_date')->orderByDesc('id')
    ->simplePaginate(15)->withQueryString();
```
Rekap mode never loads the full line collections (unnecessary — it only needs the `withSum` totals). Detail mode never runs the two `withSum` subqueries (unnecessary — it displays `line_total` per row, not an aggregate). Each mode does exactly the queries it needs, nothing more.

**4. Pagination stays `simplePaginate(15)` on `WorkOrder`, not on line rows.** This was explicit in the request (#4) and is worth stating precisely because it has a visible consequence: a PKB with 6 jasa + 4 sparepart lines renders as 10 table rows in Detail mode, so a page of 15 PKBs can render anywhere from 15 to 100+ `<tr>`s depending on how line-heavy those particular PKBs are. This is accepted as correct — re-paginating by line count would decouple "page N" from a fixed set of PKBs, making the Rekap ↔ Detail toggle behave inconsistently for the same filter + page combination. Summary cards (Total PKB, Total Nilai PKB, Total PKB Selesai) are computed from the same `(clone $query)->selectRaw(...)` used today, unchanged by `$mode` — they always describe the full filtered PKB set, not just the current page's rows, in either view.

**5. Detail mode renders one `<tr>` per line, with PKB-identifying columns (No. PKB, Tanggal, Customer & Kendaraan, Status) repeated on every line row — no `rowspan` grouping.** Confirmed via search that no `rowspan` pattern exists anywhere else in `resources/views`; introducing one here would be a one-off. Repeating the PKB columns keeps each row independently readable (sortable/scrollable/copy-pasteable without needing to look at a preceding row for context) and keeps the Blade template a flat `@foreach ($workOrders as $workOrder) @foreach ($workOrder->serviceLines ...) @foreach ($workOrder->sparepartLines ...)` — no conditional colspan/rowspan bookkeeping.

**6. Detail table columns and their data sources (9 columns):**

| Column | Source |
|---|---|
| No. PKB | `$workOrder->number`, linked via `route('work-orders.show', $workOrder)` (same as Rekap) |
| Tanggal | `$workOrder->work_order_date->format('d/m/Y')` |
| Customer & Kendaraan | `$workOrder->customer->name` + `$workOrder->vehicle->plate_number` (same as Rekap) |
| Tipe Item | Literal `"Jasa"` for rows from `serviceLines`, `"Sparepart"` for rows from `sparepartLines` — a small badge/text, not a DB column |
| Nama Item/Jasa | `$line->description` (service line) or `$line->item_name_snapshot` (sparepart line) — decision 2 |
| Qty | `$line->qty`, `number_format($line->qty, 0, ',', '.')` (same formatting convention as `work-orders/show.blade.php`) |
| Harga Satuan | `$line->unit_price`, `number_format(..., 0, ',', '.')` |
| Subtotal Line | `$line->line_total`, `number_format(..., 0, ',', '.')` — already the pre-computed qty × unit_price, no re-multiplication |
| Status | Same `status-dot` badge markup as Rekap (`work-orders/index.blade.php:43-52`), repeated per line row — reflects the parent PKB's status, not a per-line status (line items have none) |

Within a PKB group, jasa lines are listed before sparepart lines (matching the existing order of the two cards on `work-orders/show.blade.php`: "Baris Jasa" then "Baris Sparepart"). If a PKB has zero lines in both collections (no jasa and no sparepart added yet — possible for a fresh `draft`), it still renders exactly one row with `"—"` in Tipe Item/Nama Item/Qty/Harga/Subtotal, so the PKB is never silently absent from the Detail table.

**7. Single `index.blade.php`, no new partial files.** The Rekap and Detail tables are two `@if ($mode === 'detail') ... @else ... @endif` blocks inside the existing `reports/pkb/index.blade.php`, not split into separate `partials/rekap-table.blade.php` / `partials/detail-table.blade.php` files. Splitting would be premature — the file stays well under a size where that split earns its keep, and every other report view in this codebase (Receivables, Audit Log) already keeps its single table inline rather than extracting one.

**8. Summary cards, filter form (Cabang/Status/Mekanik/Tanggal), empty-state, and pagination links are shared unchanged between both modes** — only the results table swaps. The "Tampilan" select is simply a fifth form field in the same `<form id="pkbReportFilterForm">`; switching it submits the same GET request with `mode` added to the query string, so `$workOrders->withQueryString()` and `$workOrders->links()` continue to work with no changes. The empty-state partial (`partials.empty-state`) is reused as-is when zero PKBs match the filter, in either mode (colspan changes from 8 to 9 for the Detail table's `<td colspan="...">`).

## Out of Scope

- Editing, adding, or removing PKB line items from the report (read-only, same as the base Rekap view).
- A separate "Nilai per Item" or "Item Terlaris" aggregate breakdown across all PKBs — this is a per-PKB drill-down, not a cross-PKB item report.
- Any schema change to `work_order_service_lines` / `work_order_sparepart_lines`.
- Exporting Detail mode results (CSV/PDF) — no export exists for Laporan PKB today in either mode.
