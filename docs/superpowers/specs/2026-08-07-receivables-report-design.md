# Laporan Piutang (Receivables Report) — Design

## Context

Migration doc §15 lists `v_receivable_aging` ("Sisa piutang berdasarkan jatuh tempo dan bucket umur") as one of the suggested reporting views, and the sidebar's "Laporan Piutang" placeholder (`resources/views/partials/sidebar.blade.php:157-163`) has been disabled since the Foundation phase, gated by an already-seeded permission code (`report.receivable.view`, branch-scoped — `database/seeders/MenuPermissionSeeder.php:240-244`).

This report only became meaningful with Migration 010 (Partial Payments & Receivables, this session): every `Invoice` now carries a live `paid_amount` (cached, atomically maintained) and a computed `outstanding_amount` accessor, and status moves through `posted → partially_paid → paid`. No new tables or migrations are needed — this is a pure read/reporting layer over data that already exists, matching how Stock Card (also a pure read report) was built directly against `inventory_movements` with no new schema.

This codebase never uses real DB `VIEW`s (grepped — zero `CREATE VIEW` statements in any migration); every "view" in the migration doc's §15 sense is implemented as a plain Eloquent query in a controller, and this report follows that same convention.

## Decisions

**1. New standalone module: `ReceivableReportController@index`, one read-only action.**
No create/store/edit — this is a filtered list+summary page, not a document. Route: `GET /reports/receivables` → `reports.receivables.index`. `ReceivableReportController` is a new, single-purpose controller (not bolted onto `InvoiceController`, since its query shape — cross-branch, aggregate summary cards, aging — is materially different from Invoice's own CRUD).

**2. Scope: only `posted`, `partially_paid`, and `paid` invoices are ever eligible rows.** `draft` and `cancelled` invoices are excluded unconditionally, before any filter is applied — a draft was never billed, and a cancelled draft never became a receivable at all (this project's `cancel` action only ever applies to `draft` invoices, see [[bengkel_foundation_decisions]]). This matches the migration doc's own guidance ("Laporan piutang berasal dari invoice `POSTED`/`PARTIALLY_PAID`... invoice `PAID` dan `VOID` dikecualikan") extended to also allow explicitly viewing `paid` history via the Status filter, since the user wants a "Lunas" option, not just outstanding rows.

**3. Filters (all via `GET` query string, `?branch_ids[]=&customer=&status=&date_from=&date_to=`), read with the same `is_string(request('q'))`-style defensive parsing already used everywhere else in this codebase:**
- **Cabang**: multi-select, reusing `partials/branch-multiselect-filter.blade.php` (same as Invoice/Goods Receipt/etc.) scoped to `auth()->user()->branchesWithPermission('report.receivable.view')`.
- **Customer**: a plain text search on `customers.name` (`LIKE`, escaped the same way `InvoiceController::index()` escapes its `q` search), **not** a dropdown/picker. A picker would need a single `branch_id` to scope its AJAX lookup against (`/lookup/customers?branch_id=`), but this report's Cabang filter is multi-select — there's no single branch to scope a picker to. Free-text search is simpler and matches how every other list page already implements search.
- **Status**: a plain `<select>` with 3 options — **Belum Lunas** (`status IN (posted, partially_paid)`), **Lunas** (`status = paid`), **Semua** (both, i.e. no status restriction beyond decision 2's baseline). Default: **Belum Lunas** (the report's primary purpose is chasing outstanding balances, matching this decision to the migration doc's default framing).
- **Rentang Tanggal Invoice**: two `<input type="date">` (`date_from`/`date_to`), filtering `invoice_date` inclusively on both ends when present. Neither is required — an unset range means no date restriction.
- This module does **not** reuse `partials/list-filter-bar.blade.php` — that partial's slot model (one search box + one branch filter + one `extraFilterHtml` slot) doesn't cleanly fit 4 independent filters (branch multiselect + text + status + 2 dates). A bespoke filter card is used instead, matching the precedent of Dashboard's own bespoke branch-multiselect filter form (also too custom for `list-filter-bar`).

**4. Summary cards (Total Tagihan / Total Terbayar / Total Sisa Piutang) are computed from the *same filtered query* as the table**, not a separate unfiltered aggregate — so changing any filter updates both the cards and the rows consistently. Computed via 3 `SUM()` aggregates in one query (`grand_total`, `paid_amount`, and `grand_total - paid_amount`), not by pulling every row into PHP and summing in memory (the table itself is paginated, so an in-PHP sum would silently only total the current page).

**5. Umur Piutang (aging) is computed from `due_date` when set, falling back to `invoice_date` when it isn't** (`due_date` is nullable — added in this session, optional per invoice), as `today - reference_date` in whole days via `now()->diffInDays($referenceDate, false)`. A negative value (not yet due) is shown as **"Belum jatuh tempo"** rather than a negative number; zero-or-positive is shown as "`N hari`". Computed in PHP per-row after the paginated query runs (cheap — max 15 rows per page), not as a SQL expression, keeping the query itself simple and portable.

**6. Table columns, in order**: No. Invoice (link to `invoices.show`) · Tanggal · Customer · Cabang · Grand Total · Sudah Dibayar · Sisa Piutang · Umur Piutang · Status (badge, reusing the exact badge logic already shipped on `invoices/show.blade.php` and `index.blade.php` this session: `partially_paid`→warning, `paid`→active, `posted`→active).

**7. Authorization**: `$this->authorize('report.receivable.view')` — a bare permission-code check (no route-model argument), which `Gate::before` already short-circuits correctly for zero-argument abilities (see the `AuthServiceProvider::boot()` closure) — no new Policy needed, this mirrors how e.g. `sparepart.view`-gated read-only pages are already authorized elsewhere. Branch-scoping happens at the query level (`whereIn('branch_id', $permittedBranches)`), the same pattern `InvoiceController::index()` already uses — not a per-row Policy check, since there's no single-record route to gate.

**8. Pagination**: `->simplePaginate(15)`, per this project's binding convention (`->paginate()` is never used anywhere in this codebase).

## UI

- `resources/views/reports/receivables/index.blade.php` — filter card (branch multiselect, customer text input, status select, 2 date inputs, "Terapkan" submit button — GET form, `withQueryString()` on the paginator so pagination links preserve filters), 3 summary `.stat-card`s (reusing the existing `.stat-card` component already defined in the design tokens partial, same as Dashboard's KPI cards), then the results table + `$results->links()`.
- Sidebar: `resources/views/partials/sidebar.blade.php:157-163`'s disabled placeholder becomes a real `<a href="{{ route('reports.receivables.index') }}">`, same swap pattern used for Invoice and Payment Receipt earlier this session.
- Empty state: reuse `partials/empty-state.blade.php` (no CTA button — a report has nothing to "create").

## Explicitly out of scope

- CSV/Excel/PDF export of the report — not requested, no precedent anywhere in this codebase yet.
- The other 4 Laporan placeholders (PKB, Invoice, Invoice-PKB Gap, Sparepart) — separate future work, unrelated to this report beyond sharing the same sidebar heading.
- A true DB `VIEW` (`v_receivable_aging`) — this codebase's established convention is Eloquent-query-as-report, not native SQL views (see Context).
- Per-invoice payment history drill-down from this report — already exists on the Invoice detail page itself (`invoices/show.blade.php`'s "Riwayat Pembayaran" card, shipped this session); this report links to that page rather than duplicating it.
