# Laporan Sparepart / Stok Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only, filterable Sparepart Stock report — Cabang, Status Stok (Semua default / Kritis-Minimum / Habis / Tersedia), Search by kode/nama — with 4 summary cards and a dual-mode results table: Rekap (7 columns, condensed) and Detail (10 columns, expanded with on-hand/reserved/available broken out). Activates the last remaining disabled "Laporan Sparepart" sidebar placeholder, closing out this project's entire reporting track.

**Architecture:** New standalone, single-action module (`SparepartStockReportController@index`) — pure Eloquent/query-builder over the already-shipped `SparepartBranch`/`SparepartBranchStock`/`Sparepart` models, no new tables/migrations, no new Policy. Base entity is `SparepartBranch` (one row per sparepart-per-branch), joined to `sparepart_branch_stocks` via a plain SQL `join()` (a genuine 1:1 table, unlike the correlated subquery needed for the Gap report's `pkb_total`). Design doc: `docs/superpowers/specs/2026-08-07-sparepart-stock-report-design.md`.

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL 8 (tests run against real MySQL — `phpunit.xml` sets `DB_CONNECTION=mysql`, `DB_DATABASE=bengkel_testing`), Blade + Bootstrap 5, no SPA/build step.

## Global Constraints

- PHP 7.4.33 — no PHP 8+ syntax anywhere.
- Every list endpoint uses `->simplePaginate()`, never `->paginate()` — 15 per page, at the `SparepartBranch` level in **both** modes (unlike the Gap report, Detail mode here does NOT paginate a different level — see "Detail mode divergence" below).
- **Permission code is `report.sparepart.view`** — confirmed via `MenuPermissionSeeder.php:259` and the existing sidebar placeholder gate (`sidebar.blade.php:168`), NOT `report.sparepart_stock.view`. Authorization is branch-scoped: `auth()->user()->branchesWithPermission('report.sparepart.view')`, rendering `reports.sparepart-stock.no-access` when empty — not a bare `$this->authorize()` call.
- The base query is always first scoped to `whereIn('sparepart_branches.branch_id', $permittedBranches->pluck('id'))`; `branch_ids[]` is applied only as a narrowing filter intersected against that permitted set, never trusted alone.
- **`sparepart_branch_stocks` is joined via a plain SQL `join()`** (`sparepart_branch_stocks.sparepart_branch_id = sparepart_branches.id`), not a correlated subquery — it's a genuine 1:1 table (PK = `sparepart_branch_id`), unlike `inventory_movements`/`inventory_reservations` (append-only ledgers) or `work_order_service_lines`/`work_order_sparepart_lines` (1-to-many, needed the Gap report's `SUM()` subquery). All raw SQL column references must be table-qualified (`sparepart_branch_stocks.on_hand_qty`, `sparepart_branches.minimum_stock`) to avoid ambiguity across the joined tables.
- **Status Stok categories reuse the exact "kritis" formula already established in `DashboardController::computeCriticalStockCount()`** (`app/Http/Controllers/DashboardController.php:94-99`): `minimum_stock > 0 AND (on_hand_qty - reserved_qty) < minimum_stock`. This report extends it into 3 mutually-exclusive filter categories:
  - `habis`: `on_hand_qty = 0`
  - `kritis`: `on_hand_qty > 0 AND minimum_stock > 0 AND (on_hand_qty - reserved_qty) < minimum_stock`
  - `tersedia`: `on_hand_qty > 0` and not `kritis` (i.e., `minimum_stock <= 0 OR (on_hand_qty - reserved_qty) >= minimum_stock`)
  - `semua` (default): no filter — reject-to-safe-default, any value outside `{habis, kritis, tersedia, semua}` falls back to `semua`.
- **The "Total Item Kritis" summary card is computed independently of the active `stock_status` filter value, using the Dashboard's unmodified formula** (`minimum_stock > 0 AND (on_hand_qty - reserved_qty) < minimum_stock`, with NO `on_hand_qty > 0` exclusion) — it deliberately INCLUDES items in the "Habis" category too (a zero-stock item with `minimum_stock > 0` trivially satisfies `available < minimum_stock`). This is a cross-page-consistent KPI matching Dashboard's own critical-stock count, not merely "however many rows match whichever `stock_status` filter is currently selected."
- **No `is_active` filter** — active and inactive `SparepartBranch` rows both appear, no hidden exclusion, matching the "no hidden status exclusion" precedent from Laporan Invoice/PKB.
- The Search filter (`search` query param) matches sparepart `code` OR `name` via `whereHas('sparepart', ...)`, same `addcslashes($term, '%_\\')` escaping used by every other report's text search.
- Mode toggle (`mode=rekap` default / `mode=detail`) uses the exact same reject-to-safe-default rule as every other dual-mode report: `$mode = request('mode') === 'detail' ? 'detail' : 'rekap';`.
- **Detail mode divergence (documented, not a bug)**: unlike PKB/Invoice/Gap reports, `SparepartBranch` has no line-item children to expand into — Detail mode shows the SAME rows as Rekap, just with more columns (on-hand/reserved/available broken out instead of a single combined value). Pagination is at the `SparepartBranch` level in both modes, no varying row-count-per-page concern.
- **No drill-down links** from Kode/Nama Sparepart — `SparepartBranch` has no read-only "show" page (only `edit`, gated by the write-permission `sparepart.edit`, a different code than this report's `report.sparepart.view`) — mixing them would blur read/write authorization. Report rows are plain text, no `<a href>`.
- Reuse the `.stat-card`/`.stat-value`/`.stat-label`/`.stat-icon` component classes and `partials/branch-multiselect-filter.blade.php` / `partials/empty-state.blade.php` — do not invent new markup.
- **Sidebar note:** the existing placeholder text is "Laporan Sparepart" with icon `bi-file-earmark-spreadsheet` — Task 2 must keep both exactly as-is, only removing the `nav-link-disabled`/`badge-soon` wrapper and adding a real `href`.

---

## Task 1: `SparepartStockReportController`, join-based query/filter/summary logic, routes, tests

**Files:**
- Create: `app/Http/Controllers/SparepartStockReportController.php`
- Modify: `routes/web.php`
- Create: `resources/views/reports/sparepart-stock/no-access.blade.php` (final content)
- Create: `resources/views/reports/sparepart-stock/index.blade.php` (minimal placeholder — Task 2 replaces with full UI)
- Test: `tests/Feature/SparepartStockReportControllerTest.php` (new)

**Interfaces:**
- Consumes: `SparepartBranch` (`sparepart_id`, `branch_id`, `minimum_stock`, `selling_price`, relations `sparepart()`/`branch()`/`stock()`), `SparepartBranchStock` (`on_hand_qty`, `reserved_qty`), `Sparepart` (`code`, `name`), `User::branchesWithPermission(string): Collection`.
- Produces: route `reports.sparepart-stock.index`. Task 2's view consumes the exact view-data keys this controller passes: `sparepartBranches` (simplePaginate result; each row has `on_hand_qty`/`reserved_qty` computed attributes from the join, plus its own native `minimum_stock`/`selling_price`, and eager-loaded `sparepart`/`branch`), `summary` (object with `total_jenis_item`/`total_qty_on_hand`/`total_item_kritis`/`total_nilai_inventaris`), `branches`, `selectedBranchIds`, `search`, `stockStatus`, `mode`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SparepartStockReportControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SparepartStockReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function grantBranchPermission(User $user, Branch $branch, string $code): void
    {
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    protected function makeSparepartBranch(
        Branch $branch,
        string $code,
        string $name,
        float $onHand,
        float $reserved,
        float $minimumStock,
        float $sellingPrice
    ): SparepartBranch {
        $sparepart = Sparepart::create(['code' => $code, 'name' => $name]);
        $sparepartBranch = SparepartBranch::create([
            'sparepart_id' => $sparepart->id,
            'branch_id' => $branch->id,
            'selling_price' => $sellingPrice,
            'minimum_stock' => $minimumStock,
        ]);
        DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update([
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);

        return $sparepartBranch->fresh();
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertSee('belum memiliki akses', false);
    }

    public function test_index_lists_stock_rows_for_permitted_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 2, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertSee('OLI-001');
        $response->assertSee('Oli Mesin');
    }

    public function test_index_is_scoped_to_permitted_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->makeSparepartBranch($branchA, 'OLI-A', 'Oli A', 10, 0, 5, 50000);
        $this->makeSparepartBranch($branchB, 'OLI-B', 'Oli B', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertSee('OLI-A');
        $response->assertDontSee('OLI-B');
    }

    public function test_index_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->makeSparepartBranch($branchA, 'OLI-A', 'Oli A', 10, 0, 5, 50000);
        $this->makeSparepartBranch($branchB, 'OLI-B', 'Oli B', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.sparepart.view');
        $this->grantBranchPermission($viewer, $branchB, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get("/reports/sparepart-stock?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee('OLI-A');
        $response->assertDontSee('OLI-B');
    }

    public function test_index_filters_by_search_matching_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $this->makeSparepartBranch($branch, 'FIL-002', 'Filter Udara', 10, 0, 5, 30000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?search=OLI-001');

        $response->assertOk();
        $response->assertSee('OLI-001');
        $response->assertDontSee('FIL-002');
    }

    public function test_index_filters_by_search_matching_name(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $this->makeSparepartBranch($branch, 'FIL-002', 'Filter Udara', 10, 0, 5, 30000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?search=' . urlencode('Filter Udara'));

        $response->assertOk();
        $response->assertSee('FIL-002');
        $response->assertDontSee('OLI-001');
    }

    public function test_index_stock_status_default_semua_shows_all(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertSee('HABIS-1');
        $response->assertSee('KRITIS-1');
        $response->assertSee('TERSEDIA-1');
    }

    public function test_index_stock_status_habis(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=habis');

        $response->assertOk();
        $response->assertSee('HABIS-1');
        $response->assertDontSee('KRITIS-1');
        $response->assertDontSee('TERSEDIA-1');
    }

    public function test_index_stock_status_kritis(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=kritis');

        $response->assertOk();
        $response->assertDontSee('HABIS-1');
        $response->assertSee('KRITIS-1');
        $response->assertDontSee('TERSEDIA-1');
    }

    public function test_index_stock_status_tersedia(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=tersedia');

        $response->assertOk();
        $response->assertDontSee('HABIS-1');
        $response->assertDontSee('KRITIS-1');
        $response->assertSee('TERSEDIA-1');
    }

    public function test_index_invalid_stock_status_value_falls_back_to_semua(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=bogus');

        $response->assertOk();
        $response->assertViewHas('stockStatus', 'semua');
        $response->assertSee('HABIS-1');
        $response->assertSee('TERSEDIA-1');
    }

    public function test_index_computes_summary_cards_correctly(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        // Habis: on_hand=0, min=5, price=50000 -> counts as kritis too (available 0 < 5).
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 50000);
        // Kritis: on_hand=2, reserved=0, min=5, price=30000 -> available 2 < 5.
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 30000);
        // Tersedia: on_hand=10, reserved=0, min=5, price=20000 -> available 10 >= 5.
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 20000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=semua');

        $response->assertOk();
        $response->assertViewHas('summary', function ($summary) {
            return (int) $summary->total_jenis_item === 3
                && (float) $summary->total_qty_on_hand === 12.0
                && (int) $summary->total_item_kritis === 2
                && (float) $summary->total_nilai_inventaris === 260000.0;
        });
    }

    public function test_index_defaults_to_rekap_mode(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
    }

    public function test_index_invalid_mode_value_falls_back_to_rekap(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?mode=bogus');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
    }

    public function test_index_detail_mode_exposes_reserved_and_on_hand_data(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 3, 5, 20000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?mode=detail');

        $response->assertOk();
        $response->assertViewHas('mode', 'detail');
        $response->assertViewHas('sparepartBranches', function ($rows) {
            $row = $rows->first();

            return (float) $row->on_hand_qty === 10.0 && (float) $row->reserved_qty === 3.0;
        });
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/SparepartStockReportControllerTest.php`
Expected: FAIL — route `reports.sparepart-stock.index` doesn't exist yet (all 15 tests fail with a 404-related assertion failure).

- [ ] **Step 3: Add the route**

In `routes/web.php`, add the import (alphabetically placed among the other controller imports — sorts after `SparepartBranchController` and before `StockAdjustmentController`, since `SparepartB` < `SparepartS` < `Stock`):

```php
use App\Http\Controllers\SparepartStockReportController;
```

Inside the existing `Route::prefix('reports')->name('reports.')->group(...)` block, add (after the `invoice-pkb-gap.index` line):

```php
        Route::get('/sparepart-stock', [SparepartStockReportController::class, 'index'])->name('sparepart-stock.index');
```

- [ ] **Step 4: Implement `SparepartStockReportController`**

`app/Http/Controllers/SparepartStockReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\SparepartBranch;

class SparepartStockReportController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.sparepart.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.sparepart-stock.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('search')) ? trim(request('search')) : null;

        $stockStatus = request('stock_status');
        $stockStatus = in_array($stockStatus, ['habis', 'kritis', 'tersedia', 'semua'], true)
            ? $stockStatus : 'semua';

        $mode = request('mode') === 'detail' ? 'detail' : 'rekap';

        $query = SparepartBranch::query()
            ->join('sparepart_branch_stocks', 'sparepart_branch_stocks.sparepart_branch_id', '=', 'sparepart_branches.id')
            ->whereIn('sparepart_branches.branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($q) => $q->whereIn('sparepart_branches.branch_id', $branchIds))
            ->when($search, function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('sparepart', function ($inner) use ($escaped) {
                    $inner->where('code', 'like', "%{$escaped}%")
                        ->orWhere('name', 'like', "%{$escaped}%");
                });
            })
            ->when($stockStatus === 'habis', fn ($q) => $q->where('sparepart_branch_stocks.on_hand_qty', 0))
            ->when($stockStatus === 'kritis', function ($q) {
                $q->where('sparepart_branch_stocks.on_hand_qty', '>', 0)
                    ->where('sparepart_branches.minimum_stock', '>', 0)
                    ->whereRaw('(sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) < sparepart_branches.minimum_stock');
            })
            ->when($stockStatus === 'tersedia', function ($q) {
                $q->where('sparepart_branch_stocks.on_hand_qty', '>', 0)
                    ->where(function ($inner) {
                        $inner->where('sparepart_branches.minimum_stock', '<=', 0)
                            ->orWhereRaw('(sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) >= sparepart_branches.minimum_stock');
                    });
            });

        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_jenis_item, ' .
            'COALESCE(SUM(sparepart_branch_stocks.on_hand_qty), 0) as total_qty_on_hand, ' .
            'COALESCE(SUM(CASE WHEN sparepart_branches.minimum_stock > 0 AND (sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) < sparepart_branches.minimum_stock THEN 1 ELSE 0 END), 0) as total_item_kritis, ' .
            'COALESCE(SUM(sparepart_branch_stocks.on_hand_qty * sparepart_branches.selling_price), 0) as total_nilai_inventaris'
        )->first();

        $sparepartBranches = $query->select('sparepart_branches.*')
            ->addSelect(['sparepart_branch_stocks.on_hand_qty', 'sparepart_branch_stocks.reserved_qty'])
            ->with(['sparepart', 'branch'])
            ->orderBy('sparepart_branches.branch_id')
            ->orderBy('sparepart_branches.id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('reports.sparepart-stock.index', [
            'sparepartBranches' => $sparepartBranches,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $branchIds,
            'search' => $search,
            'stockStatus' => $stockStatus,
            'mode' => $mode,
        ]);
    }
}
```

- [ ] **Step 5: Create the no-access view and a minimal placeholder view**

Create `resources/views/reports/sparepart-stock/no-access.blade.php` (final content — this is not a throwaway, Task 2 does not touch this file again):

```php
@extends('layouts.app')
@section('title', 'Laporan Sparepart')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Laporan Sparepart</h1>
    </div>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0">Anda belum memiliki akses laporan sparepart di cabang manapun.</p>
        </div>
    </div>
@endsection
```

Create a minimal `resources/views/reports/sparepart-stock/index.blade.php` so `test_index_*` requests render successfully (Task 2 replaces this with the full dual-mode filter/summary/table UI):

```php
@extends('layouts.app')
@section('title', 'Laporan Sparepart')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Laporan Sparepart</h1>
    </div>
    @foreach ($sparepartBranches as $sparepartBranch)
        <div>
            {{ $sparepartBranch->sparepart->code }}
            {{ $sparepartBranch->sparepart->name }}
            {{ number_format((float) $sparepartBranch->on_hand_qty, 0, ',', '.') }}
        </div>
    @endforeach
@endsection
```

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/SparepartStockReportControllerTest.php`
Expected: PASS (15 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (712 + 15 = 727), no regressions.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/SparepartStockReportController.php routes/web.php \
        resources/views/reports/sparepart-stock/index.blade.php resources/views/reports/sparepart-stock/no-access.blade.php \
        tests/Feature/SparepartStockReportControllerTest.php
git commit -m "feat: add sparepart stock report controller with dual-mode query and summary totals"
```

---

## Task 2: Filter card, summary cards, dual-mode results table, sidebar wiring, browser verification

**Files:**
- Modify: `resources/views/reports/sparepart-stock/index.blade.php`
- Modify: `resources/views/partials/sidebar.blade.php`
- Test: `tests/Feature/SparepartStockReportControllerTest.php` (append)
- Test: `tests/Feature/AppShellTest.php` (append)

**Interfaces:**
- Consumes: `mode`, `sparepartBranches` (Rekap: `sparepart.code`/`sparepart.name`/`branch.name`/`minimum_stock`/`on_hand_qty` + computed nilai inventaris; Detail: additionally `reserved_qty`, computed available, `selling_price`, computed nilai total), `summary`, `branches`, `selectedBranchIds`, `search`, `stockStatus` — all from Task 1.
- Produces: no new interfaces — this is the final task in the plan, and closes out this project's entire reporting track.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SparepartStockReportControllerTest.php` (inside the class, after `test_index_detail_mode_exposes_reserved_and_on_hand_data`):

```php
    public function test_index_renders_filter_form_and_summary_cards(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertSee('Total Jenis Item');
        $response->assertSee('Total Qty On-Hand');
        $response->assertSee('Total Item Kritis');
        $response->assertSee('Total Nilai Inventaris');
        $response->assertSee('name="search"', false);
        $response->assertSee('<option value="semua" selected>Semua</option>', false);
        $response->assertSee('<option value="rekap" selected>Rekap</option>', false);
    }

    public function test_index_rekap_mode_shows_columns_and_status_badges(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=semua');

        $response->assertOk();
        $response->assertSee('Stok Min');
        $response->assertSee('Nilai Inventaris');
        $response->assertSee('>Habis<', false);
        $response->assertSee('>Kritis<', false);
        $response->assertSee('>Tersedia<', false);
    }

    public function test_index_detail_mode_shows_expanded_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        // Distinctive multi-digit amounts — a bare single-digit assertSee (e.g. "7") is unsafe,
        // it can false-match unrelated digits elsewhere on the page (established project lesson,
        // see Kartu Stok's own test-fragility fix in bengkel_foundation_decisions memory).
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 847, 212, 5, 17000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?mode=detail');

        $response->assertOk();
        $response->assertSee('Reserved');
        $response->assertSee('Available');
        $response->assertSee('Harga Satuan');
        $response->assertSee('Nilai Total');
        // available = 847 - 212 = 635; nilai total = 847 * 17000 = 14.399.000
        $response->assertSee('635');
        $response->assertSee('14.399.000');
    }

    public function test_index_rekap_mode_does_not_show_detail_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertDontSee('Reserved');
        $response->assertDontSee('Available');
        $response->assertSee('Nilai Inventaris');
    }

    public function test_index_shows_empty_state_when_no_results_match_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertSee('Tidak ada sparepart yang cocok dengan filter saat ini.');
    }
```

Append to `tests/Feature/AppShellTest.php` (inside the class, near `test_sidebar_links_directly_to_invoice_pkb_gap_report_when_permitted`):

```php
    public function test_sidebar_links_directly_to_sparepart_stock_report_when_permitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'report.sparepart.view', 'resource' => 'report', 'action' => 'sparepart.view', 'description' => 'Melihat laporan sparepart']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('reports.sparepart-stock.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }
```

Also update `test_sidebar_hides_all_new_placeholder_headings_without_any_permission` in the same file: add one more assertion after the existing `$response->assertDontSee('bi-bar-chart-steps', false);` line:

```php
        $response->assertDontSee('Laporan Sparepart', false);
```

(Note: unlike PKB vs Invoice's icon collision, `bi-file-earmark-spreadsheet` is not used anywhere else in this app's authenticated pages as of this plan, so a plain text assertion is safe here — no icon-class workaround needed.)

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/SparepartStockReportControllerTest.php`
Run: `php artisan test tests/Feature/AppShellTest.php`

Expected: the 5 new `SparepartStockReportControllerTest` tests FAIL (placeholder view lacks the new UI), and `test_sidebar_links_directly_to_sparepart_stock_report_when_permitted` FAILS (sidebar still shows the disabled placeholder).

- [ ] **Step 3: Replace the placeholder view with the full dual-mode UI**

Replace `resources/views/reports/sparepart-stock/index.blade.php` entirely with:

```php
@extends('layouts.app')
@section('title', 'Laporan Sparepart')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Laporan Sparepart</h1>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.sparepart-stock.index') }}" id="sparepartStockFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Status Stok</label>
                    <select name="stock_status" class="form-select form-select-sm">
                        <option value="semua" {{ $stockStatus === 'semua' ? 'selected' : '' }}>Semua</option>
                        <option value="kritis" {{ $stockStatus === 'kritis' ? 'selected' : '' }}>Kritis/Minimum</option>
                        <option value="habis" {{ $stockStatus === 'habis' ? 'selected' : '' }}>Habis</option>
                        <option value="tersedia" {{ $stockStatus === 'tersedia' ? 'selected' : '' }}>Tersedia</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Kode / Nama Sparepart</label>
                    <input type="text" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Cari...">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Tampilan</label>
                    <select name="mode" class="form-select form-select-sm">
                        <option value="rekap" {{ $mode === 'rekap' ? 'selected' : '' }}>Rekap</option>
                        <option value="detail" {{ $mode === 'detail' ? 'selected' : '' }}>Detail</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Terapkan Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_jenis_item, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Jenis Item</div>
                </div>
                <i class="bi bi-box-seam stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_qty_on_hand, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Qty On-Hand</div>
                </div>
                <i class="bi bi-boxes stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_item_kritis, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Item Kritis</div>
                </div>
                <i class="bi bi-exclamation-triangle stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_nilai_inventaris, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Nilai Inventaris</div>
                </div>
                <i class="bi bi-cash-stack stat-icon"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
        @if ($mode === 'detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama Sparepart</th>
                        <th>Cabang</th>
                        <th>Stok Min</th>
                        <th>On-Hand</th>
                        <th>Reserved</th>
                        <th>Available</th>
                        <th>Harga Satuan</th>
                        <th>Nilai Total</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sparepartBranches as $sparepartBranch)
                        @php
                            $onHand = (float) $sparepartBranch->on_hand_qty;
                            $reserved = (float) $sparepartBranch->reserved_qty;
                            $available = $onHand - $reserved;
                            $minimumStock = (float) $sparepartBranch->minimum_stock;
                            $sellingPrice = (float) $sparepartBranch->selling_price;
                            $nilaiTotal = $onHand * $sellingPrice;

                            if ($onHand == 0.0) {
                                $statusBadge = '<span class="status-dot status-danger">Habis</span>';
                            } elseif ($minimumStock > 0.0 && $available < $minimumStock) {
                                $statusBadge = '<span class="status-dot status-warning">Kritis</span>';
                            } else {
                                $statusBadge = '<span class="status-dot status-active">Tersedia</span>';
                            }
                        @endphp
                        <tr>
                            <td><code>{{ $sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $sparepartBranch->sparepart->name }}</td>
                            <td>{{ $sparepartBranch->branch->name }}</td>
                            <td>{{ number_format($minimumStock, 0, ',', '.') }}</td>
                            <td>{{ number_format($onHand, 0, ',', '.') }}</td>
                            <td>{{ number_format($reserved, 0, ',', '.') }}</td>
                            <td>{{ number_format($available, 0, ',', '.') }}</td>
                            <td>{{ number_format($sellingPrice, 0, ',', '.') }}</td>
                            <td>{{ number_format($nilaiTotal, 0, ',', '.') }}</td>
                            <td>{!! $statusBadge !!}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-spreadsheet',
                                    'title' => 'Belum ada data sparepart',
                                    'description' => 'Tidak ada sparepart yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama Sparepart</th>
                        <th>Cabang</th>
                        <th>Stok Min</th>
                        <th>Stok On-Hand</th>
                        <th>Nilai Inventaris</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sparepartBranches as $sparepartBranch)
                        @php
                            $onHand = (float) $sparepartBranch->on_hand_qty;
                            $reserved = (float) $sparepartBranch->reserved_qty;
                            $available = $onHand - $reserved;
                            $minimumStock = (float) $sparepartBranch->minimum_stock;
                            $sellingPrice = (float) $sparepartBranch->selling_price;
                            $nilaiInventaris = $onHand * $sellingPrice;

                            if ($onHand == 0.0) {
                                $statusBadge = '<span class="status-dot status-danger">Habis</span>';
                            } elseif ($minimumStock > 0.0 && $available < $minimumStock) {
                                $statusBadge = '<span class="status-dot status-warning">Kritis</span>';
                            } else {
                                $statusBadge = '<span class="status-dot status-active">Tersedia</span>';
                            }
                        @endphp
                        <tr>
                            <td><code>{{ $sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $sparepartBranch->sparepart->name }}</td>
                            <td>{{ $sparepartBranch->branch->name }}</td>
                            <td>{{ number_format($minimumStock, 0, ',', '.') }}</td>
                            <td>{{ number_format($onHand, 0, ',', '.') }}</td>
                            <td>{{ number_format($nilaiInventaris, 0, ',', '.') }}</td>
                            <td>{!! $statusBadge !!}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-spreadsheet',
                                    'title' => 'Belum ada data sparepart',
                                    'description' => 'Tidak ada sparepart yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @endif
        </div>
    </div>

    <div class="mt-3">
        {{ $sparepartBranches->links() }}
    </div>

    @push('scripts')
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('sparepartStockFilterForm');
        if (!menu || !form) return;

        menu.addEventListener('click', function (event) { event.stopPropagation(); });

        const selectAll = document.getElementById('branchFilterSelectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
        }

        form.addEventListener('submit', function () {
            form.querySelectorAll('input[data-branch-hidden]').forEach(function (el) { el.remove(); });
            document.querySelectorAll('.branch-filter-checkbox:checked').forEach(function (checkbox) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'branch_ids[]';
                hidden.value = checkbox.value;
                hidden.setAttribute('data-branch-hidden', '1');
                form.appendChild(hidden);
            });
        });
    })();
    </script>
    @endpush
@endsection
```

- [ ] **Step 4: Wire the sidebar link**

In `resources/views/partials/sidebar.blade.php`, replace the disabled placeholder (currently lines 168-174):

```blade
        @if ($user->branchesWithPermission('report.sparepart.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i> Laporan Sparepart
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
```

with:

```blade
        @if ($user->branchesWithPermission('report.sparepart.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('reports.sparepart-stock.index') }}" class="nav-link {{ request()->routeIs('reports.sparepart-stock.*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i> Laporan Sparepart
            </a>
        </li>
        @endif
```

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/SparepartStockReportControllerTest.php`
Run: `php artisan test tests/Feature/AppShellTest.php`
Expected: all PASS (20 + 27 respectively — run separately if combined output looks truncated, a known Windows quirk from prior reports in this project).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (727 + 6 = 733), no regressions.

- [ ] **Step 7: Manual browser verification**

Using the dev server and real data created directly via Eloquent (mirroring this plan's test helper — `SparepartBranch::create()` + direct `sparepart_branch_stocks` update, no PKB/Invoice lifecycle needed since stock rows have no such flow), verify against a short-lived demo user/branch (cleaned up after, ID-scoped per this project's established discipline — confirmed working correctly in the Gap report's own verification):

1. Log in as a user with `report.sparepart.view` on at least one branch.
2. Load `/reports/sparepart-stock` — confirm "Status Stok" defaults to "Semua" selected, "Tampilan" defaults to "Rekap" selected, summary cards show correct totals.
3. Create 3 SparepartBranch rows covering all 3 status categories (Habis, Kritis, Tersedia) and confirm each appears/disappears correctly as "Status Stok" is switched through all 4 options via real UI interaction.
4. Confirm "Total Item Kritis" summary card counts BOTH the Habis and Kritis rows (2), not just whichever filter is currently selected — reload with `stock_status=tersedia` and confirm the card still shows 2, not 0 or 1.
5. Switch "Tampilan" to "Detail" — confirm On-Hand/Reserved/Available/Harga Satuan/Nilai Total render correctly for a row with non-zero `reserved_qty`.
6. Confirm Search (kode/nama) narrows results correctly in both modes.
7. Confirm the empty-state renders correctly when filters match nothing.
8. Confirm the sidebar "Laporan Sparepart" link is real (not "Segera Hadir").
9. Clean up any demo user/branch/sparepart data created for verification, scoped strictly by the IDs created in this session; leave existing data untouched.

- [ ] **Step 8: Commit**

```bash
git add resources/views/reports/sparepart-stock/index.blade.php resources/views/partials/sidebar.blade.php \
        tests/Feature/SparepartStockReportControllerTest.php tests/Feature/AppShellTest.php
git commit -m "feat: add sparepart stock report dual-mode UI and wire sidebar link"
```

---

## Final Step

After Task 2 passes and the full suite is green, report the final test count and a short end-to-end summary. **This closes out this project's entire reporting track — all 5 Laporan placeholders (Piutang, PKB, Invoice, PKB vs Invoice Gap, Sparepart) are now active.** Do not start any new scope (migration 011's audit log, or anything else) without explicit user instruction.
