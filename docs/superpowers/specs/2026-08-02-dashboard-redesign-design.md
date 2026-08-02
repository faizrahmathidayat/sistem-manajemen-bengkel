# Dashboard Redesign — Design

Status: approved by user, ready for implementation plan

## Purpose

Sub-project 2 of the decomposed UI redesign (sub-project 1, Design System Foundation, is merged). The current Dashboard (`resources/views/dashboard.blade.php`) is two stat cards and a greeting. The user provided a detailed target spec: a multi-cabang filterable dashboard with KPI cards, charts, and a tabbed live-data section covering modules that mostly don't exist yet (PKB, Invoice, stock ledger, audit log — migrations 006-011). This spec resolves that ambition against current reality: real data where the underlying tables exist (sparepart stock), dummy/simulated data everywhere else, clearly labeled so nobody mistakes a dummy number for a real one later.

## Scope

**In scope:**
- A reusable multi-select branch filter component (new — the sparepart module's existing branch switcher is single-select; this is a genuinely different, page-level filter).
- `DashboardController@index` rewritten to serve both a full HTML page (normal navigation) and a JSON payload (AJAX filter/tab changes), sharing one data-computation method.
- 4 KPI cards, 2 Chart.js charts, and a 3-tab live-data section, per the breakdown below.
- Loading-overlay JS (vanilla, no new dependency) for filter changes and tab switches.
- Two header quick-action buttons.

**Explicitly out of scope / deferred:**
- Making PKB/Invoice/Payment/stock-ledger/audit-log data real — those activate as migrations 006-011 ship. This dashboard's dummy sections are placeholders with realistic shape, not a commitment to a particular future schema.
- Per-tab independent branch filters. The user's original spec implied each section (Operasional, Persediaan, Audit) could have its own multi-cabang filter; this spec uses **one shared page-level filter** scoping the KPI cards and all 3 tabs uniformly. Maintaining 3-4 independent filter states on a page that's mostly dummy data isn't worth the complexity yet — revisit once PKB/Invoice are real and might genuinely need a different branch scope than the stock tab.
- Wiring the navbar quick-search (already deferred in sub-project 1).
- A generic multi-select-filter Blade component usable by *future* Operasional/Persediaan/Reporting screens is a nice-to-have this design happens to produce as a side effect (see Components below), but no other screen consumes it yet — don't over-generalize its API for hypothetical future callers.

## Real vs. dummy data map

| Element | Status | Source |
|---|---|---|
| Multi-select branch filter options | Real | `auth()->user()->branches` (all assigned branches, per user's spec — not permission-filtered at the filter-option level) |
| KPI: Overview Stok (on-hand/reserved/available) | Real | `SUM()` over `sparepart_branch_stocks` joined to `sparepart_branches`, scoped to selected branches **intersected with** `branchesWithPermission('sparepart.view')` |
| KPI: Alert Stok Kritis | Real | Count of `sparepart_branches` (same permission-intersected scope) where `available_qty < minimum_stock` |
| KPI: Status PKB | Dummy | Static PHP array in controller |
| KPI: Pendapatan & Piutang | Dummy | Static PHP array in controller |
| Chart: PKB vs Invoice trend | Dummy | Static PHP array, shaped for Chart.js line/bar |
| Chart: Piutang composition | Dummy | Static PHP array, shaped for Chart.js doughnut |
| Tab 1: PKB & Invoice terbaru (table) | Dummy | Static PHP array of row objects |
| Tab 2: Kartu Stok — sparepart picker | Real | Distinct `spareparts` configured in the selected (permission-intersected) branches |
| Tab 2: Kartu Stok — 3-tier widget (on-hand/reserved/available) | Real | The selected sparepart's `sparepart_branch_stocks` row(s), summed across selected branches |
| Tab 2: Kartu Stok — mutation table | Dummy | Static PHP array (no `inventory_movements` table exists — migration 008) |
| Tab 3: Audit Log activity feed | Dummy | Static PHP array (`audit_logs` table doesn't exist — explicitly deferred in project roadmap) |

Dummy arrays are simple return values from small private controller methods (`dummyPkbStatus()`, `dummyReceivables()`, etc.) — not stored anywhere, not seeded, regenerated identically on every request. No new migrations, no new seeders.

## Header

- Greeting: "Selamat datang kembali, {{ auth()->user()->name }}."
- **"+ Sparepart Baru"** — real, links to `route('sparepart-branches.create')`. The only quick action with a genuine destination today.
- **"+ Buat PKB Baru"** — inert placeholder, same visual treatment as the sidebar's "Segera Hadir" items from sub-project 1 (muted, `cursor: not-allowed`, small badge), for consistency. The original spec's third button ("+ Stock Adjustment") is dropped — one real action plus one placeholder communicates the pattern without cluttering the header with two disabled buttons.

## Multi-select branch filter

New reusable partial: `resources/views/partials/branch-multiselect-filter.blade.php`.

- A Bootstrap dropdown button whose label summarizes the selection ("Semua Cabang Saya" when all are selected, the single branch name when exactly one is selected, "N Cabang Terpilih" otherwise).
- Dropdown body: a checkbox per branch in `auth()->user()->branches`, plus a "Pilih Semua Cabang Saya" checkbox that checks/unchecks all others (mirrors the select-all pattern already used in the Permission tab's accordions). The dropdown menu stays open across checkbox clicks (`e.stopPropagation()` on the menu), closing only on an outside click, so a user can tick several branches without it collapsing after each one.
- **Session key**: `dashboard_selected_branch_ids` (array of branch IDs). On every request, the controller validates any incoming selection against `auth()->user()->branches->pluck('id')` and drops anything not in that set — this is a read-only view filter, not a write-authorization boundary (nothing is ever written based on it), so a stale or tampered session value can only narrow or misdirect *what the same authenticated user sees*, never expose another user's data, since every real query still independently intersects with that user's actual `sparepart.view` grants.
- **Default selection**: `auth()->user()->defaultBranch()`. If the user has no default branch (edge case — shouldn't happen given `UserBranchService::assign()` always sets one on first assignment, but defensive anyway), fall back to their first assigned branch. If the user has zero assigned branches at all, the filter renders a short "Anda belum ditugaskan ke cabang manapun" message instead of the dropdown, and every real KPI/section shows zero/empty (dummy sections are unaffected — they're page furniture, not meaningfully branch-scoped).

## Update architecture (filter change / tab switch)

One shared endpoint, two response shapes:

- `GET /dashboard` — normal navigation. `DashboardController::index()` computes the full data payload and renders `dashboard.blade.php` with it.
- `GET /dashboard?branch_ids[]=1&branch_ids[]=2` (or with an added `sparepart_id=` for Tab 2), requested via `fetch(..., { headers: { Accept: 'application/json' } })` — `$request->wantsJson()` is true, so the same `index()` action returns `response()->json($payload)` instead of a view, using the exact same underlying data (one private `buildPayload(User $user, array $selectedBranchIds, ?int $sparepartId): array` method feeds both branches — no duplicated computation).

Client-side (`dashboard.blade.php`'s `@push('scripts')`):
- Changing the branch filter or switching a tab shows a lightweight overlay (Bootstrap spinner over the affected card/table, absolutely positioned) via a small reusable `withLoadingOverlay(container, workFn)` helper.
- The helper races the real `fetch()` against a 400ms minimum-delay promise (`Promise.all([fetch(...), minDelay(400)])`) so the spinner is visibly perceptible even when the server answers instantly on localhost — this directly satisfies the "300-500ms visual feedback" requirement without faking the data itself.
- On response, JS updates: the 4 KPI card values, both charts (`Chart.js` instance `.data.update()`), the currently-active tab's content (only the active tab's markup is replaced — switching tabs re-uses the last-fetched payload already in memory rather than re-fetching, unless the branch filter changed since).

## KPI cards

Reuse `.stat-card` from the design system (Task 1 of sub-project 1). Four cards:
1. **Status PKB Hari Ini** — dummy: total, with a small `OPEN` / `SHORTAGE` / `COMPLETED` breakdown row.
2. **Pendapatan & Piutang** — dummy: revenue figure + unpaid/aging total.
3. **Overview Stok Cabang** — real: on-hand / reserved / available, three numbers in one card (available styled with `--color-success` when positive, `--color-warning` when any selected sparepart is under minimum — this is the first real consumer of the `--color-warning` token defined but unused in sub-project 1).
4. **Alert Stok Kritis** — real: count of sparepart configs under minimum stock in the selected scope, styled with `--color-warning`/`--color-danger` depending on count.

## Charts

Chart.js via CDN (`<script src="https://cdn.jsdelivr.net/npm/chart.js">`), loaded only in `dashboard.blade.php` via `@push('scripts')` — no other page needs it yet, so it stays out of the global layout rather than adding weight to every screen.
- **PKB vs Invoice trend** (line chart) — dummy weekly series, 6-8 data points.
- **Piutang composition** (doughnut) — dummy categories: Belum Jatuh Tempo, 1-30 Hari, 31-60 Hari, >60 Hari.

## Tabbed live-data section

Bootstrap nav-tabs (reusing `.nav-tabs` from the design system).

**Tab 1 — Status PKB & Invoice Terbaru**: filter bar (search input, status dropdown, date range — all inert/decorative, since there's no backing data to actually filter) + a dummy table (No. PKB/Invoice, Customer & Plat, Cabang, Status badge, Aksi placeholder).

**Tab 2 — Kartu Stok**: its own small filter row — a sparepart `<select>` (real, populated from spareparts configured in the currently-selected branches) and a "Jenis Mutasi" dropdown (decorative, no real mutation types exist yet). A 3-tier summary widget (on-hand / reserved / available, real, for the selected sparepart across the selected branches) and a mutation table (dummy rows: tanggal, tipe badge, referensi, masuk/keluar/reservasi columns, saldo akhir, cabang).

**Tab 3 — Live Audit Log Activity Feed**: filter bar (user dropdown, event-type dropdown — decorative) + a dummy timeline/activity-feed list (timestamp, user, permission badge, description, impact badge).

## Testing

Given this dashboard has real logic behind a presentational surface (branch-filter validation, stock aggregation, critical-stock detection, JSON vs HTML branching), it needs proper feature tests, not just smoke tests:

- Dashboard renders for a user with one branch, defaulting the filter to that branch.
- Submitting `branch_ids[]` not in the user's assigned branches is silently dropped (session/response reflects only the valid subset).
- Stock-overview KPI correctly sums `on_hand_qty`/`reserved_qty` across multiple selected branches (seed real stock values directly via `DB::table('sparepart_branch_stocks')->update()` in the test, since the app itself never writes non-zero values yet).
- Critical-stock KPI correctly counts configs where `available_qty < minimum_stock`.
- A branch where the user holds branch membership but NOT `sparepart.view` is excluded from the real KPIs even if selected in the filter (the permission-intersection rule).
- `GET /dashboard` with `Accept: application/json` returns the JSON shape, not HTML.
- Tab 2's sparepart dropdown only lists spareparts configured in the selected branches.
- A zero-branch user sees the empty-state message, no 500 error.
- The "+ Sparepart Baru" link resolves to the real route; the "+ Buat PKB Baru" placeholder is not a link (same `<span>`-not-`<a>` convention as the sidebar).

## Execution

Recommend **`subagent-driven-development` in a worktree**, matching this project's established process. This sub-project is larger than sub-project 1 (real aggregation queries, a new reusable filter component, an AJAX/JSON response branch, Chart.js integration, three tabs) — expect roughly 7-8 tasks at plan time: filter component + controller scaffolding, real stock KPIs, dummy KPIs, charts, Tab 1, Tab 2, Tab 3, then loading-state JS + header + full-suite verification. No auth-model changes are involved (this reuses the sparepart module's existing `branchesWithPermission()` primitive rather than inventing new authorization), so risk is concentrated in correctness of the aggregation queries and the dummy/real data seams, not in security.
