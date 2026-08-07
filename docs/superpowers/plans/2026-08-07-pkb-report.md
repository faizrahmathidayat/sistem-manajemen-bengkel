# Laporan PKB (Work Order Report) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only, filterable PKB (work order) report — Cabang, Status PKB, Mekanik, date range on `work_order_date` — with 3 summary cards (Total PKB / Total Nilai PKB / Total PKB Selesai) and a per-work-order table including derived service/sparepart/grand-total subtotals, activating the disabled "Laporan PKB" sidebar placeholder.

**Architecture:** New standalone, single-action module (`PkbReportController@index`) — pure Eloquent query over the already-shipped `WorkOrder` model plus its two line tables (`work_order_service_lines`, `work_order_sparepart_lines`), no new tables/migrations, no new Policy. Design doc: `docs/superpowers/specs/2026-08-07-pkb-report-design.md`.

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL 8 (tests run against real MySQL — `phpunit.xml` sets `DB_CONNECTION=mysql`, `DB_DATABASE=bengkel_testing`), Blade + Bootstrap 5, no SPA/build step.

## Global Constraints

- PHP 7.4.33 — no PHP 8+ syntax anywhere.
- Every list endpoint uses `->simplePaginate()`, never `->paginate()` — 15 per page (design Decision 7).
- Authorization is branch-scoped: `auth()->user()->branchesWithPermission('report.pkb.view')`, rendering `reports.pkb.no-access` when empty — **not** a bare `$this->authorize()` call (design Decision 2; `report.pkb.view` is seeded `is_branch_scoped => true`).
- The base query is always first scoped to `whereIn('branch_id', $permittedBranches->pluck('id'))`; the request's `branch_ids[]` is applied only as a narrowing filter intersected against that permitted set, never trusted alone.
- `WorkOrder` has no stored `grand_total`/subtotal columns — money is derived from `work_order_service_lines.line_total` / `work_order_sparepart_lines.line_total` (design Decision 3). `withSum()` results are `null`, not `0`, when a work order has zero lines of that type — every place a subtotal is displayed or summed must treat `null` as `0` (PHP's `null + $number` already evaluates as `$number` with no warning under PHP 7.4, but templates must not call `number_format(null, ...)` directly without a `?? 0` fallback, since `number_format` on `null` raises a deprecation notice).
- Money/aggregate SQL uses `COALESCE(SUM(...), 0)` to avoid `NULL` when a filtered query matches zero rows.
- Status badge markup (`status-dot status-inactive/active/warning/danger`) must exactly mirror what already ships on `work-orders/index.blade.php:43-52`: `draft`→`status-inactive` "Draft", `open`→`status-active` "Dikonfirmasi", `shortage`→`status-warning` "Kurang Stok", `completed`→`status-active` "Selesai", `cancelled`→`status-danger` "Dibatalkan".
- This module reuses `partials/branch-multiselect-filter.blade.php` directly, which means this plan must also replicate that partial's companion JS (select-all checkbox behavior, on-submit checked→`branch_ids[]`-hidden-inputs conversion), scoped to this page's own form id, copied inline into this view's `@push('scripts')` block — same convention as Receivables Report and the Audit Log viewer (each view carries its own copy, not a shared script).
- Reuse the `.stat-card`/`.stat-value`/`.stat-label`/`.stat-icon` component classes already defined in the design tokens partial and used on the Dashboard/Receivables Report — do not invent new summary-card markup.
- The summary cards reflect whatever the current filter state is — no hidden status exclusion (design Decision 4). With no Status filter applied, `Total Nilai PKB` sums **all** statuses, including `draft` and `cancelled`.

---

## Task 1: `PkbReportController`, query/filter/summary logic, routes, tests

**Files:**
- Create: `app/Http/Controllers/PkbReportController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PkbReportControllerTest.php` (new)

**Interfaces:**
- Consumes: `WorkOrder` (`branch_id`, `customer_id`, `vehicle_id`, `mechanic_id`, `work_order_date`, `status`, `number`, relations `branch()`/`customer()`/`vehicle()`/`mechanic()`/`serviceLines()`/`sparepartLines()`), `App\Support\WorkOrderStatus::{DRAFT,OPEN,SHORTAGE,COMPLETED,CANCELLED}`, `User::branchesWithPermission(string): Collection`.
- Produces: route `reports.pkb.index`. Task 2's view consumes the exact view-data keys this controller passes: `workOrders` (simplePaginate result, each row additionally carrying `subtotal_service`/`subtotal_sparepart` from `withSum`, possibly `null`), `summary` (object with `total_pkb`/`total_completed`/`total_value`), `branches`, `selectedBranchIds`, `mechanicSearch`, `status`, `dateFrom`, `dateTo`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PkbReportControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\UserBranchService;
use App\Support\WorkOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PkbReportControllerTest extends TestCase
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

    protected function makeScenario(Branch $branch, string $mechanicName = 'Agus Setiawan'): array
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::firstOrCreate(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id,
            'plate_number' => 'B ' . random_int(1000, 9999) . ' ' . $branch->code,
        ]);
        $mechanic = Mechanic::firstOrCreate(['name' => $mechanicName]);
        MechanicBranch::firstOrCreate(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => 'SVC-' . random_int(1000, 9999), 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => 'OLI-' . random_int(1000, 9999), 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);

        return compact('customer', 'vehicle', 'mechanic', 'catalog', 'sparepartBranch');
    }

    protected function makeWorkOrder(Branch $branch, array $scenario, string $status, float $serviceAmount = 100000, float $sparepartAmount = 0): WorkOrder
    {
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');

        $spareparts = $sparepartAmount > 0
            ? [['sparepart_branch_id' => $scenario['sparepartBranch']->id, 'qty' => 1, 'unit_price' => $sparepartAmount]]
            : [];

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $serviceAmount],
            ],
            'spareparts' => $spareparts,
        ]);
        $workOrder = WorkOrder::latest('id')->first();

        if ($status === WorkOrderStatus::DRAFT) {
            return $workOrder->fresh();
        }

        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");

        if ($status === WorkOrderStatus::OPEN) {
            return $workOrder->fresh();
        }

        if ($status === WorkOrderStatus::CANCELLED) {
            $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/cancel");

            return $workOrder->fresh();
        }

        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        return $workOrder->fresh();
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('belum memiliki akses', false);
    }

    public function test_index_lists_work_orders_for_permitted_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $workOrder = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee($workOrder->number);
    }

    public function test_index_is_scoped_to_permitted_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $scenarioA = $this->makeScenario($branchA);
        $scenarioB = $this->makeScenario($branchB);
        $workOrderA = $this->makeWorkOrder($branchA, $scenarioA, WorkOrderStatus::COMPLETED);
        $workOrderB = $this->makeWorkOrder($branchB, $scenarioB, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee($workOrderA->number);
        $response->assertDontSee($workOrderB->number);
    }

    public function test_index_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $scenarioA = $this->makeScenario($branchA);
        $scenarioB = $this->makeScenario($branchB);
        $workOrderA = $this->makeWorkOrder($branchA, $scenarioA, WorkOrderStatus::COMPLETED);
        $workOrderB = $this->makeWorkOrder($branchB, $scenarioB, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.pkb.view');
        $this->grantBranchPermission($viewer, $branchB, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get("/reports/pkb?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee($workOrderA->number);
        $response->assertDontSee($workOrderB->number);
    }

    public function test_index_filters_by_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $draft = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::DRAFT);
        $completed = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?status=' . WorkOrderStatus::COMPLETED);

        $response->assertOk();
        $response->assertSee($completed->number);
        $response->assertDontSee($draft->number);
    }

    public function test_index_filters_by_mechanic_search(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenarioA = $this->makeScenario($branch, 'Agus Setiawan');
        $scenarioB = $this->makeScenario($branch, 'Budi Wijaya');
        $workOrderA = $this->makeWorkOrder($branch, $scenarioA, WorkOrderStatus::COMPLETED);
        $workOrderB = $this->makeWorkOrder($branch, $scenarioB, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?mechanic=Agus');

        $response->assertOk();
        $response->assertSee($workOrderA->number);
        $response->assertDontSee($workOrderB->number);
    }

    public function test_index_filters_by_work_order_date_range(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $old = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED);
        $old->update(['work_order_date' => '2025-01-01']);
        $recent = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?date_from=' . now()->subDay()->toDateString());

        $response->assertOk();
        $response->assertSee($recent->number);
        $response->assertDontSee($old->number);
    }

    public function test_index_computes_summary_cards_across_all_statuses_by_default(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::DRAFT, 50000, 0);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertViewHas('summary', function ($summary) {
            return (int) $summary->total_pkb === 2
                && (int) $summary->total_completed === 1
                && (float) $summary->total_value === 210000.0;
        });
    }

    public function test_index_shows_service_sparepart_and_grand_total_per_row(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('100.000');
        $response->assertSee('60.000');
        $response->assertSee('160.000');
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/PkbReportControllerTest.php`
Expected: FAIL — route `reports.pkb.index` doesn't exist yet (the no-access test will also fail: `reports.pkb.no-access` view doesn't exist).

- [ ] **Step 3: Add the route**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\PkbReportController;
```

Inside the existing `Route::prefix('reports')->name('reports.')->group(...)` block (`routes/web.php:204`), add:

```php
        Route::get('/pkb', [PkbReportController::class, 'index'])->name('pkb.index');
```

- [ ] **Step 4: Implement `PkbReportController`**

`app/Http/Controllers/PkbReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Support\WorkOrderStatus;

class PkbReportController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.pkb.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.pkb.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $mechanicSearch = is_string(request('mechanic')) ? trim(request('mechanic')) : null;

        $status = request('status');
        $status = in_array($status, [
            WorkOrderStatus::DRAFT,
            WorkOrderStatus::OPEN,
            WorkOrderStatus::SHORTAGE,
            WorkOrderStatus::COMPLETED,
            WorkOrderStatus::CANCELLED,
        ], true) ? $status : null;

        $dateFrom = $this->parseDate(request('date_from'));
        $dateTo = $this->parseDate(request('date_to'));

        $query = WorkOrder::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when($mechanicSearch, function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('mechanic', function ($inner) use ($escaped) {
                    $inner->where('name', 'like', "%{$escaped}%");
                });
            })
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($dateFrom, fn ($q) => $q->whereDate('work_order_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('work_order_date', '<=', $dateTo));

        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_pkb, ' .
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as total_completed, ' .
            'COALESCE(SUM(' .
                '(SELECT COALESCE(SUM(line_total), 0) FROM work_order_service_lines WHERE work_order_service_lines.work_order_id = work_orders.id) + ' .
                '(SELECT COALESCE(SUM(line_total), 0) FROM work_order_sparepart_lines WHERE work_order_sparepart_lines.work_order_id = work_orders.id)' .
            '), 0) as total_value',
            [WorkOrderStatus::COMPLETED]
        )->first();

        $workOrders = $query->with(['branch', 'customer', 'vehicle', 'mechanic'])
            ->withSum('serviceLines as subtotal_service', 'line_total')
            ->withSum('sparepartLines as subtotal_sparepart', 'line_total')
            ->orderByDesc('work_order_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('reports.pkb.index', [
            'workOrders' => $workOrders,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $branchIds,
            'mechanicSearch' => $mechanicSearch,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }

    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
```

- [ ] **Step 5: Create a minimal placeholder view so the tests can pass**

Create `resources/views/reports/pkb/no-access.blade.php` (final content — this is not a throwaway, Task 2 does not touch this file again):

```php
@extends('layouts.app')
@section('title', 'Laporan PKB')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>Laporan PKB</h1>
    </div>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0">Anda belum memiliki akses laporan PKB di cabang manapun.</p>
        </div>
    </div>
@endsection
```

Create a minimal `resources/views/reports/pkb/index.blade.php` so `test_index_*` requests render successfully (Task 2 replaces this with the full filter/summary/table UI):

```php
@extends('layouts.app')
@section('title', 'Laporan PKB')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>Laporan PKB</h1>
    </div>
    @foreach ($workOrders as $workOrder)
        <div>
            {{ $workOrder->number }}
            {{ number_format($workOrder->subtotal_service ?? 0, 0, ',', '.') }}
            {{ number_format($workOrder->subtotal_sparepart ?? 0, 0, ',', '.') }}
            {{ number_format(($workOrder->subtotal_service ?? 0) + ($workOrder->subtotal_sparepart ?? 0), 0, ',', '.') }}
        </div>
    @endforeach
@endsection
```

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/PkbReportControllerTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/PkbReportController.php routes/web.php \
        resources/views/reports/pkb/index.blade.php resources/views/reports/pkb/no-access.blade.php \
        tests/Feature/PkbReportControllerTest.php
git commit -m "feat: add pkb report controller with filters and summary totals"
```

---

## Task 2: Filter card, summary cards, results table UI, sidebar wiring, browser verification

**Files:**
- Modify: `resources/views/reports/pkb/index.blade.php` (replace Task 1's minimal placeholder)
- Modify: `resources/views/partials/sidebar.blade.php`
- Test: `tests/Feature/PkbReportControllerTest.php` (extend), `tests/Feature/AppShellTest.php` (extend)

**Interfaces:**
- Consumes: every view-data key `PkbReportController::index()` passes (Task 1's "Produces" list).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PkbReportControllerTest.php`:

```php
    public function test_index_renders_summary_cards_and_filter_form(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('Total PKB');
        $response->assertSee('Total Nilai PKB');
        $response->assertSee('Total PKB Selesai');
        $response->assertSee('name="mechanic"', false);
        $response->assertSee('name="date_from"', false);
        $response->assertSee('name="date_to"', false);
    }

    public function test_index_shows_customer_vehicle_and_mechanic_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch, 'Agus Setiawan');
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('Budi Santoso');
        $response->assertSee($scenario['vehicle']->plate_number);
        $response->assertSee('Agus Setiawan');
    }

    public function test_index_shows_status_badge(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('Selesai');
    }

    public function test_index_shows_empty_state_when_no_results_match_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('Tidak ada PKB yang cocok dengan filter saat ini.');
    }
```

Append to `tests/Feature/AppShellTest.php` (following the exact shape of `test_sidebar_links_directly_to_receivables_report_when_permitted`):

```php
    public function test_sidebar_links_directly_to_pkb_report_when_permitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'report.pkb.view', 'resource' => 'report', 'action' => 'pkb.view', 'description' => 'Melihat laporan PKB']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('reports.pkb.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }
```

**Replace, don't duplicate**: `tests/Feature/AppShellTest.php` already has `test_sidebar_shows_reporting_placeholder_when_user_has_report_pkb_view_permission()` (asserts `assertSee('Laporan PKB', false)` against the disabled placeholder). Once the sidebar link becomes real, that assertion still happens to pass (the link's visible text is still "Laporan PKB"), so it will **not** fail and flag itself as stale — but it no longer tests what its name claims. Delete this test and replace it with `test_sidebar_links_directly_to_pkb_report_when_permitted` above (which does assert `assertDontSee('Segera Hadir', false)`) — do not leave both.

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/PkbReportControllerTest.php tests/Feature/AppShellTest.php`
Expected: FAIL — Task 1's minimal placeholder view has none of the summary cards, filter fields, or table columns; the sidebar still shows the disabled placeholder.

- [ ] **Step 3: Write the full view**

Replace `resources/views/reports/pkb/index.blade.php` entirely:

```php
@extends('layouts.app')
@section('title', 'Laporan PKB')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>Laporan PKB</h1>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.pkb.index') }}" id="pkbReportFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Status PKB</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Semua Status</option>
                        <option value="{{ \App\Support\WorkOrderStatus::DRAFT }}" {{ $status === \App\Support\WorkOrderStatus::DRAFT ? 'selected' : '' }}>Draft</option>
                        <option value="{{ \App\Support\WorkOrderStatus::OPEN }}" {{ $status === \App\Support\WorkOrderStatus::OPEN ? 'selected' : '' }}>Dikonfirmasi</option>
                        <option value="{{ \App\Support\WorkOrderStatus::SHORTAGE }}" {{ $status === \App\Support\WorkOrderStatus::SHORTAGE ? 'selected' : '' }}>Kurang Stok</option>
                        <option value="{{ \App\Support\WorkOrderStatus::COMPLETED }}" {{ $status === \App\Support\WorkOrderStatus::COMPLETED ? 'selected' : '' }}>Selesai</option>
                        <option value="{{ \App\Support\WorkOrderStatus::CANCELLED }}" {{ $status === \App\Support\WorkOrderStatus::CANCELLED ? 'selected' : '' }}>Dibatalkan</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Mekanik</label>
                    <input type="text" name="mechanic" value="{{ $mechanicSearch }}" class="form-control form-control-sm" placeholder="Cari nama mekanik...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tanggal Dari</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sampai</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Terapkan Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_pkb, 0, ',', '.') }}</div>
                    <div class="stat-label">Total PKB</div>
                </div>
                <i class="bi bi-file-earmark-bar-graph stat-icon"></i>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_value, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Nilai PKB</div>
                </div>
                <i class="bi bi-cash-stack stat-icon"></i>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_completed, 0, ',', '.') }}</div>
                    <div class="stat-label">Total PKB Selesai</div>
                </div>
                <i class="bi bi-check-circle stat-icon"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. PKB</th>
                        <th>Tanggal</th>
                        <th>Customer &amp; Kendaraan</th>
                        <th>Mekanik</th>
                        <th>Subtotal Jasa</th>
                        <th>Subtotal Sparepart</th>
                        <th>Grand Total</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($workOrders as $workOrder)
                        @php($subtotalService = $workOrder->subtotal_service ?? 0)
                        @php($subtotalSparepart = $workOrder->subtotal_sparepart ?? 0)
                        <tr>
                            <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                            <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                            <td>
                                {{ $workOrder->customer->name }}<br>
                                <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                            </td>
                            <td>{{ $workOrder->mechanic->name }}</td>
                            <td>{{ number_format($subtotalService, 0, ',', '.') }}</td>
                            <td>{{ number_format($subtotalSparepart, 0, ',', '.') }}</td>
                            <td>{{ number_format($subtotalService + $subtotalSparepart, 0, ',', '.') }}</td>
                            <td>
                                @if ($workOrder->status === \App\Support\WorkOrderStatus::DRAFT)
                                    <span class="status-dot status-inactive">Draft</span>
                                @elseif ($workOrder->status === \App\Support\WorkOrderStatus::OPEN)
                                    <span class="status-dot status-active">Dikonfirmasi</span>
                                @elseif ($workOrder->status === \App\Support\WorkOrderStatus::SHORTAGE)
                                    <span class="status-dot status-warning">Kurang Stok</span>
                                @elseif ($workOrder->status === \App\Support\WorkOrderStatus::COMPLETED)
                                    <span class="status-dot status-active">Selesai</span>
                                @else
                                    <span class="status-dot status-danger">Dibatalkan</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-bar-graph',
                                    'title' => 'Belum ada data PKB',
                                    'description' => 'Tidak ada PKB yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $workOrders->links() }}
    </div>

    @push('scripts')
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('pkbReportFilterForm');
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

- [ ] **Step 4: Wire the sidebar placeholder**

In `resources/views/partials/sidebar.blade.php:140-147`, replace:

```php
        @if ($user->branchesWithPermission('report.pkb.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-file-earmark-bar-graph me-2"></i> Laporan PKB
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
```

with:

```php
        @if ($user->branchesWithPermission('report.pkb.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('reports.pkb.index') }}" class="nav-link {{ request()->routeIs('reports.pkb.*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-bar-graph me-2"></i> Laporan PKB
            </a>
        </li>
        @endif
```

(The `@if`/`@endif` wrapper itself is unchanged — only the inner `<span>` becomes an `<a>`.)

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/PkbReportControllerTest.php tests/Feature/AppShellTest.php`
Expected: PASS.

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions (this is the plan's final full-suite run).

- [ ] **Step 7: Manual browser verification**

Start the dev server. Seed via tinker: grant a demo user `report.pkb.view` for one branch (via `UserBranchPermission`, branch-scoped — not global), then create 2-3 real work orders end-to-end through the actual HTTP flow (`POST /work-orders` → `PATCH .../confirm` → `PATCH .../complete`, at least one left in `draft` or `cancelled`) so real rows exist with real service/sparepart lines, not synthetic data.
- Load `/reports/pkb`, confirm the table shows correct No. PKB (linked), tanggal, customer+kendaraan, mekanik, subtotal jasa/sparepart, grand total, and status badge.
- Confirm the 3 summary cards show correct totals matching what's filtered.
- Filter by Cabang, Status, Mekanik, and date range one at a time (via real form interaction, not just query strings, to also exercise the branch-multiselect JS), confirm each narrows correctly.
- Confirm the sidebar "Laporan PKB" link is real (not "Segera Hadir").
- Clean up all demo data afterward via tinker, stop the server.

- [ ] **Step 8: Commit**

```bash
git add resources/views/reports/pkb/index.blade.php resources/views/partials/sidebar.blade.php \
        tests/Feature/PkbReportControllerTest.php tests/Feature/AppShellTest.php
git commit -m "feat: add pkb report filter/summary/table UI and wire sidebar link"
```

---

## After all tasks

Report final test count and a short end-to-end summary (what shipped, what's next). Do not start any of the remaining 3 Laporan placeholders (Laporan Invoice, PKB vs Invoice, Laporan Sparepart) or any other scope without explicit user instruction.
