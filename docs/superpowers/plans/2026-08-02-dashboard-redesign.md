# Dashboard Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the two-stat-card Dashboard with a multi-cabang-filterable dashboard (KPI cards, Chart.js charts, a 3-tab live-data section), wiring real data where the underlying tables exist (sparepart stock) and clearly-labeled dummy data everywhere else (PKB/Invoice/Payment/stock-ledger/audit-log — migrations 006-011 don't exist yet).

**Architecture:** One `DashboardController@index` action serves both a full HTML page (normal navigation) and a JSON payload (AJAX filter/tab-switch requests, detected via `$request->wantsJson()`), sharing one `buildPayload()` method so the two response shapes never compute data differently. A new reusable multi-select branch-filter Blade partial drives which branches are in scope; a session key holds the user's last selection as a pure view convenience (never a write-authorization boundary — every real query still independently intersects the selection with the user's actual `sparepart.view` grants).

**Tech Stack:** Blade, Bootstrap 5 (CDN, already loaded), Chart.js (CDN, added in this plan, scoped to the dashboard view only), vanilla JS — no new backend dependencies.

## Global Constraints

- Laravel 8.75 pinned — never use `Request::integer()` or other Laravel 9+ Request helpers; cast manually.
- No hard deletes — not applicable, this plan writes no new tables/rows besides the pre-existing session key.
- Reuse the design system from sub-project 1: `.card`, `.stat-card`, `.nav-tabs`, `.status-dot`, `--color-accent`/`--color-success`/`--color-warning`/`--color-danger` tokens — this is the first real consumer of `--color-warning`.
- Dummy data lives in small private controller methods, regenerated identically on every request — no new migrations, no new seeders, nothing persisted.
- The branch filter's session key (`dashboard_selected_branch_ids`) is a view convenience only. Every real data computation must independently intersect the selection with `$user->branchesWithPermission('sparepart.view')` — never trust the raw selection for anything beyond "which branches to show."
- Follow the existing `{resource}/_tab_{name}.blade.php` convention (already used by `users/_tab_profil.blade.php` etc.) for the 3 dashboard tabs.

---

### Task 1: Branch filter component + controller/view scaffolding

**Files:**
- Create: `resources/views/partials/branch-multiselect-filter.blade.php`
- Create: `resources/views/dashboard/index.blade.php`
- Delete: `resources/views/dashboard.blade.php` (replaced by the file above)
- Modify: `app/Http/Controllers/DashboardController.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Produces: `DashboardController::resolveSelectedBranchIds(Request $request, User $user, \Illuminate\Support\Collection $allowedBranches): array` (protected), `DashboardController::buildPayload(User $user, array $selectedBranchIds): array` (protected) — every later task adds keys to this method's returned array; `DashboardController::scopedBranchIds(User $user, array $selectedBranchIds): array` (protected, the permission-intersection helper every real-data task must call before querying). The Blade partial expects `$allowedBranches` (Collection of `Branch`) and `$selectedBranchIds` (array of int) in scope.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/DashboardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_defaults_filter_to_users_default_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $branchService = new UserBranchService();
        $branchService->assign($user, $branchA, true);
        $branchService->assign($user, $branchB, false);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta', false);
    }

    public function test_dashboard_ignores_branch_ids_the_user_is_not_assigned_to(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branchA, true);

        $response = $this->actingAs(User::find($user->id))
            ->getJson('/dashboard?branch_ids[]=' . $branchA->id . '&branch_ids[]=' . $branchB->id);

        $response->assertOk();
        $response->assertJson(['selectedBranchIds' => [$branchA->id]]);
    }

    public function test_dashboard_json_response_shape(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch, true);

        $response = $this->actingAs(User::find($user->id))->getJson('/dashboard');

        $response->assertOk();
        $response->assertJsonStructure(['selectedBranchIds']);
    }

    public function test_dashboard_shows_empty_state_for_user_with_zero_branches(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('belum ditugaskan ke cabang manapun', false);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL — `/dashboard` currently renders the old two-stat-card view with none of this content, and doesn't respond to JSON requests distinctly.

- [ ] **Step 3: Write the controller**

Replace the full contents of `app/Http/Controllers/DashboardController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $allowedBranches = $user->branches;

        $selectedBranchIds = $this->resolveSelectedBranchIds($request, $user, $allowedBranches);

        $payload = $this->buildPayload($user, $selectedBranchIds);

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return view('dashboard.index', array_merge($payload, [
            'allowedBranches' => $allowedBranches,
            'selectedBranchIds' => $selectedBranchIds,
        ]));
    }

    protected function resolveSelectedBranchIds(Request $request, User $user, Collection $allowedBranches): array
    {
        $allowedIds = $allowedBranches->pluck('id')->all();

        if ($request->has('branch_ids')) {
            $requested = array_map('intval', (array) $request->input('branch_ids', []));
            $valid = array_values(array_intersect($requested, $allowedIds));
            session(['dashboard_selected_branch_ids' => $valid]);

            return $valid;
        }

        $sessionValue = session('dashboard_selected_branch_ids');
        if (is_array($sessionValue)) {
            $valid = array_values(array_intersect($sessionValue, $allowedIds));
            if (! empty($valid)) {
                return $valid;
            }
        }

        $default = $user->defaultBranch();
        if ($default && in_array($default->id, $allowedIds, true)) {
            return [$default->id];
        }

        return $allowedBranches->isNotEmpty() ? [$allowedBranches->first()->id] : [];
    }

    protected function scopedBranchIds(User $user, array $selectedBranchIds): array
    {
        $permittedBranchIds = $user->branchesWithPermission('sparepart.view')->pluck('id')->all();

        return array_values(array_intersect($selectedBranchIds, $permittedBranchIds));
    }

    protected function buildPayload(User $user, array $selectedBranchIds): array
    {
        return [
            'selectedBranchIds' => $selectedBranchIds,
        ];
    }
}
```

- [ ] **Step 4: Write the branch filter partial**

Create `resources/views/partials/branch-multiselect-filter.blade.php`:

```blade
@if ($allowedBranches->isEmpty())
    <p class="text-muted small mb-0">Anda belum ditugaskan ke cabang manapun.</p>
@else
    <div class="dropdown" id="branchMultiselectFilter">
        <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" id="branchFilterToggle" data-bs-toggle="dropdown" aria-expanded="false">
            <span id="branchFilterLabel">
                @if (count($selectedBranchIds) === $allowedBranches->count())
                    Semua Cabang Saya
                @elseif (count($selectedBranchIds) === 1)
                    {{ $allowedBranches->firstWhere('id', $selectedBranchIds[0])->name ?? '1 Cabang Terpilih' }}
                @else
                    {{ count($selectedBranchIds) }} Cabang Terpilih
                @endif
            </span>
        </button>
        <div class="dropdown-menu p-3" aria-labelledby="branchFilterToggle" id="branchFilterMenu" style="min-width: 240px;">
            <div class="form-check mb-2">
                <input type="checkbox" class="form-check-input" id="branchFilterSelectAll" {{ count($selectedBranchIds) === $allowedBranches->count() ? 'checked' : '' }}>
                <label class="form-check-label fw-semibold" for="branchFilterSelectAll">Pilih Semua Cabang Saya</label>
            </div>
            <hr>
            @foreach ($allowedBranches as $branch)
                <div class="form-check">
                    <input type="checkbox" class="form-check-input branch-filter-checkbox" id="branchFilter-{{ $branch->id }}" value="{{ $branch->id }}" {{ in_array($branch->id, $selectedBranchIds) ? 'checked' : '' }}>
                    <label class="form-check-label" for="branchFilter-{{ $branch->id }}">{{ $branch->name }}</label>
                </div>
            @endforeach
        </div>
    </div>
@endif
```

- [ ] **Step 5: Write the dashboard view**

Create `resources/views/dashboard/index.blade.php`:

```blade
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Dashboard</h1>
            <p class="mb-0" style="color: var(--color-ink-muted);">Selamat datang kembali, {{ auth()->user()->name }}.</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            @include('partials.branch-multiselect-filter')
            <a href="{{ route('sparepart-branches.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Sparepart Baru
            </a>
            <span class="btn btn-outline-secondary btn-sm disabled" style="cursor: not-allowed;" aria-disabled="true">
                <i class="bi bi-clipboard-plus"></i> Buat PKB Baru
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </div>
    </div>

    <div id="dashboardContent">
        <p class="text-muted">Data ringkasan akan tampil di sini.</p>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    const selectAll = document.getElementById('branchFilterSelectAll');
    const menu = document.getElementById('branchFilterMenu');

    if (menu) {
        menu.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
            applyBranchFilter();
        });
    }

    document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) {
        checkbox.addEventListener('change', applyBranchFilter);
    });

    function applyBranchFilter() {
        const selected = Array.from(document.querySelectorAll('.branch-filter-checkbox:checked')).map(function (cb) {
            return cb.value;
        });
        const params = new URLSearchParams();
        selected.forEach(function (id) { params.append('branch_ids[]', id); });
        window.location.search = params.toString();
    }
})();
</script>
@endpush
```

Note: this task wires the filter's checkboxes to a plain full-page navigation (`window.location.search = ...`) as a working baseline. Task 8 replaces `applyBranchFilter()` with the AJAX + loading-overlay version described in the spec — this task's version is deliberately simple so the filter is testable and functional from the first commit, not broken until the last task.

- [ ] **Step 6: Delete the old dashboard view**

```bash
rm resources/views/dashboard.blade.php
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (all 4 tests).

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS — the pre-existing `AppShellTest` sidebar/navbar tests all hit `/dashboard` and must still render correctly with the new view.

- [ ] **Step 9: Commit**

```bash
git add resources/views/partials/branch-multiselect-filter.blade.php resources/views/dashboard/index.blade.php app/Http/Controllers/DashboardController.php tests/Feature/DashboardTest.php
git rm resources/views/dashboard.blade.php
git commit -m "feat: add branch multiselect filter and dashboard scaffolding"
```

---

### Task 2: Real KPI — Overview Stok + Alert Stok Kritis

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `resources/views/dashboard/index.blade.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `scopedBranchIds()` (Task 1), `SparepartBranch`/`SparepartBranchStock` models (migration 005).
- Produces: `buildPayload()` return array gains `stockOverview: ['onHand' => float, 'reserved' => float, 'available' => float]` and `criticalStockCount: int`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/DashboardTest.php` (add these imports at the top: `use App\Models\Sparepart; use App\Models\SparepartBranch; use App\Models\Permission; use App\Models\UserBranchPermission;` alongside the existing ones):

```php
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

    public function test_stock_overview_sums_on_hand_and_reserved_across_selected_branches(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $this->grantBranchPermission($user, $branchB, 'sparepart.view');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $configA = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchA->id, 'selling_price' => 100000]);
        $configB = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchB->id, 'selling_price' => 100000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $configA->id)->update(['on_hand_qty' => 10, 'reserved_qty' => 2]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $configB->id)->update(['on_hand_qty' => 5, 'reserved_qty' => 1]);

        $response = $this->actingAs(User::find($user->id))
            ->getJson('/dashboard?branch_ids[]=' . $branchA->id . '&branch_ids[]=' . $branchB->id);

        $response->assertOk();
        $response->assertJson(['stockOverview' => ['onHand' => 15.0, 'reserved' => 3.0, 'available' => 12.0]]);
    }

    public function test_stock_overview_excludes_branches_without_sparepart_view_permission(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        (new UserBranchService())->assign($user, $branchB);
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $configA = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchA->id, 'selling_price' => 100000]);
        $configB = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchB->id, 'selling_price' => 100000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $configA->id)->update(['on_hand_qty' => 10]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $configB->id)->update(['on_hand_qty' => 999]);

        $response = $this->actingAs(User::find($user->id))
            ->getJson('/dashboard?branch_ids[]=' . $branchA->id . '&branch_ids[]=' . $branchB->id);

        $response->assertOk();
        $response->assertJson(['stockOverview' => ['onHand' => 10.0, 'reserved' => 0.0, 'available' => 10.0]]);
    }

    public function test_critical_stock_count_finds_configs_under_minimum(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $low = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $lowConfig = SparepartBranch::create(['sparepart_id' => $low->id, 'branch_id' => $branch->id, 'selling_price' => 100000, 'minimum_stock' => 5]);
        $ok = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $okConfig = SparepartBranch::create(['sparepart_id' => $ok->id, 'branch_id' => $branch->id, 'selling_price' => 50000, 'minimum_stock' => 2]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $lowConfig->id)->update(['on_hand_qty' => 1]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $okConfig->id)->update(['on_hand_qty' => 10]);

        $response = $this->actingAs(User::find($user->id))->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $response->assertJson(['criticalStockCount' => 1]);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DashboardTest`
Expected: the 3 new tests FAIL (`stockOverview`/`criticalStockCount` keys don't exist in the JSON response yet).

- [ ] **Step 3: Add the computation methods**

In `app/Http/Controllers/DashboardController.php`, add this import:

```php
use App\Models\SparepartBranch;
```

Add these two protected methods (after `scopedBranchIds`):

```php
    protected function computeStockOverview(array $scopedBranchIds): array
    {
        if (empty($scopedBranchIds)) {
            return ['onHand' => 0.0, 'reserved' => 0.0, 'available' => 0.0];
        }

        $totals = SparepartBranch::whereIn('branch_id', $scopedBranchIds)
            ->where('is_active', true)
            ->join('sparepart_branch_stocks', 'sparepart_branch_stocks.sparepart_branch_id', '=', 'sparepart_branches.id')
            ->selectRaw('SUM(sparepart_branch_stocks.on_hand_qty) as on_hand, SUM(sparepart_branch_stocks.reserved_qty) as reserved')
            ->first();

        $onHand = (float) ($totals->on_hand ?? 0);
        $reserved = (float) ($totals->reserved ?? 0);

        return ['onHand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];
    }

    protected function computeCriticalStockCount(array $scopedBranchIds): int
    {
        if (empty($scopedBranchIds)) {
            return 0;
        }

        return SparepartBranch::whereIn('branch_id', $scopedBranchIds)
            ->where('is_active', true)
            ->where('minimum_stock', '>', 0)
            ->join('sparepart_branch_stocks', 'sparepart_branch_stocks.sparepart_branch_id', '=', 'sparepart_branches.id')
            ->whereRaw('(sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) < sparepart_branches.minimum_stock')
            ->count();
    }
```

Update `buildPayload()` to:

```php
    protected function buildPayload(User $user, array $selectedBranchIds): array
    {
        $scopedBranchIds = $this->scopedBranchIds($user, $selectedBranchIds);

        return [
            'selectedBranchIds' => $selectedBranchIds,
            'stockOverview' => $this->computeStockOverview($scopedBranchIds),
            'criticalStockCount' => $this->computeCriticalStockCount($scopedBranchIds),
        ];
    }
```

- [ ] **Step 4: Add the KPI cards to the view**

In `resources/views/dashboard/index.blade.php`, replace:

```blade
    <div id="dashboardContent">
        <p class="text-muted">Data ringkasan akan tampil di sini.</p>
    </div>
```

with:

```blade
    <div class="row g-3 mb-4" id="kpiCardsRow">
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value" id="kpiStockAvailable">{{ number_format($stockOverview['available'], 0, ',', '.') }}</div>
                    <div class="stat-label">Stok Tersedia</div>
                    <div class="small mt-1" style="color: var(--color-ink-muted);">On-hand {{ number_format($stockOverview['onHand'], 0, ',', '.') }} &middot; Reservasi {{ number_format($stockOverview['reserved'], 0, ',', '.') }}</div>
                </div>
                <i class="bi bi-box-seam stat-icon"></i>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value" id="kpiCriticalStock" style="{{ $criticalStockCount > 0 ? 'color: var(--color-warning);' : '' }}">{{ $criticalStockCount }}</div>
                    <div class="stat-label">Alert Stok Kritis</div>
                </div>
                <i class="bi bi-exclamation-triangle stat-icon"></i>
            </div>
        </div>
    </div>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (all tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DashboardController.php resources/views/dashboard/index.blade.php tests/Feature/DashboardTest.php
git commit -m "feat: add real stock overview and critical stock KPI cards"
```

---

### Task 3: Dummy KPI — Status PKB + Pendapatan & Piutang

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `resources/views/dashboard/index.blade.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Produces: `buildPayload()` gains `pkbStatus: ['open' => int, 'shortage' => int, 'completed' => int]` and `receivables: ['revenue' => int, 'unpaid' => int]`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DashboardTest.php`:

```php
    public function test_dashboard_includes_dummy_pkb_status_and_receivables(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch, true);

        $response = $this->actingAs(User::find($user->id))->getJson('/dashboard');

        $response->assertOk();
        $response->assertJsonStructure(['pkbStatus' => ['open', 'shortage', 'completed'], 'receivables' => ['revenue', 'unpaid']]);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL — `pkbStatus`/`receivables` keys don't exist yet.

- [ ] **Step 3: Add the dummy-data methods**

In `app/Http/Controllers/DashboardController.php`, add these protected methods (after `computeCriticalStockCount`):

```php
    protected function dummyPkbStatus(): array
    {
        return ['open' => 8, 'shortage' => 2, 'completed' => 15];
    }

    protected function dummyReceivables(): array
    {
        return ['revenue' => 42500000, 'unpaid' => 7300000];
    }
```

Update `buildPayload()` to:

```php
    protected function buildPayload(User $user, array $selectedBranchIds): array
    {
        $scopedBranchIds = $this->scopedBranchIds($user, $selectedBranchIds);

        return [
            'selectedBranchIds' => $selectedBranchIds,
            'stockOverview' => $this->computeStockOverview($scopedBranchIds),
            'criticalStockCount' => $this->computeCriticalStockCount($scopedBranchIds),
            'pkbStatus' => $this->dummyPkbStatus(),
            'receivables' => $this->dummyReceivables(),
        ];
    }
```

- [ ] **Step 4: Add the KPI cards to the view**

In `resources/views/dashboard/index.blade.php`, inside `<div class="row g-3 mb-4" id="kpiCardsRow">`, add these two cards before the closing `</div>` of that row (after the "Alert Stok Kritis" card):

```blade
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value" id="kpiPkbTotal">{{ $pkbStatus['open'] + $pkbStatus['shortage'] + $pkbStatus['completed'] }}</div>
                    <div class="stat-label">Status PKB Hari Ini</div>
                    <div class="small mt-1" style="color: var(--color-ink-muted);">Open {{ $pkbStatus['open'] }} &middot; Shortage {{ $pkbStatus['shortage'] }} &middot; Selesai {{ $pkbStatus['completed'] }}</div>
                </div>
                <i class="bi bi-clipboard-check stat-icon"></i>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value" id="kpiRevenue">{{ number_format($receivables['revenue'], 0, ',', '.') }}</div>
                    <div class="stat-label">Pendapatan & Piutang</div>
                    <div class="small mt-1" style="color: var(--color-ink-muted);">Piutang belum lunas {{ number_format($receivables['unpaid'], 0, ',', '.') }}</div>
                </div>
                <i class="bi bi-cash-coin stat-icon"></i>
            </div>
        </div>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (all tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DashboardController.php resources/views/dashboard/index.blade.php tests/Feature/DashboardTest.php
git commit -m "feat: add dummy PKB status and receivables KPI cards"
```

---

### Task 4: Charts — PKB vs Invoice trend + Piutang composition

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `resources/views/dashboard/index.blade.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Produces: `buildPayload()` gains `chartTrend: ['labels' => string[], 'pkb' => int[], 'invoice' => int[]]` and `chartReceivables: ['labels' => string[], 'values' => int[]]`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DashboardTest.php`:

```php
    public function test_dashboard_includes_dummy_chart_data(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch, true);

        $response = $this->actingAs(User::find($user->id))->getJson('/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'chartTrend' => ['labels', 'pkb', 'invoice'],
            'chartReceivables' => ['labels', 'values'],
        ]);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL — `chartTrend`/`chartReceivables` keys don't exist yet.

- [ ] **Step 3: Add the dummy chart-data methods**

In `app/Http/Controllers/DashboardController.php`, add these protected methods (after `dummyReceivables`):

```php
    protected function dummyChartTrend(): array
    {
        return [
            'labels' => ['Pekan 1', 'Pekan 2', 'Pekan 3', 'Pekan 4', 'Pekan 5', 'Pekan 6'],
            'pkb' => [12, 15, 9, 18, 14, 20],
            'invoice' => [10, 13, 8, 16, 12, 17],
        ];
    }

    protected function dummyChartReceivables(): array
    {
        return [
            'labels' => ['Belum Jatuh Tempo', '1-30 Hari', '31-60 Hari', '>60 Hari'],
            'values' => [4200000, 1800000, 900000, 400000],
        ];
    }
```

Update `buildPayload()` to add these two keys:

```php
    protected function buildPayload(User $user, array $selectedBranchIds): array
    {
        $scopedBranchIds = $this->scopedBranchIds($user, $selectedBranchIds);

        return [
            'selectedBranchIds' => $selectedBranchIds,
            'stockOverview' => $this->computeStockOverview($scopedBranchIds),
            'criticalStockCount' => $this->computeCriticalStockCount($scopedBranchIds),
            'pkbStatus' => $this->dummyPkbStatus(),
            'receivables' => $this->dummyReceivables(),
            'chartTrend' => $this->dummyChartTrend(),
            'chartReceivables' => $this->dummyChartReceivables(),
        ];
    }
```

- [ ] **Step 4: Add Chart.js and the chart canvases**

In `resources/views/dashboard/index.blade.php`, add this row right after the `</div>` that closes `id="kpiCardsRow"`:

```blade
    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-3">Tren PKB vs Invoice Posted Mingguan</h2>
                    <canvas id="trendChart" height="220"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-3">Komposisi Status Piutang</h2>
                    <canvas id="receivablesChart" height="220"></canvas>
                </div>
            </div>
        </div>
    </div>
```

At the very top of the `@push('scripts')` block (before the existing IIFE), add the Chart.js CDN script tag and chart initialization. Replace the opening of the `@push('scripts')` block:

```blade
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const trendChart = new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: @json($chartTrend['labels']),
        datasets: [
            { label: 'PKB', data: @json($chartTrend['pkb']), borderColor: '#2563EB', backgroundColor: 'rgba(37, 99, 235, .1)', tension: 0.3 },
            { label: 'Invoice', data: @json($chartTrend['invoice']), borderColor: '#10B981', backgroundColor: 'rgba(16, 185, 129, .1)', tension: 0.3 },
        ],
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } },
});

const receivablesChart = new Chart(document.getElementById('receivablesChart'), {
    type: 'doughnut',
    data: {
        labels: @json($chartReceivables['labels']),
        datasets: [{ data: @json($chartReceivables['values']), backgroundColor: ['#10B981', '#F59E0B', '#DC2626', '#64748B'] }],
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } },
});
</script>
<script>
```

(the existing IIFE `(function () { ... })();` script stays as its own `<script>` block right after — do not merge the two `<script>` tags, keep the chart-init code separate from the filter-checkbox code for readability).

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (all tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DashboardController.php resources/views/dashboard/index.blade.php tests/Feature/DashboardTest.php
git commit -m "feat: add PKB/Invoice trend and receivables composition charts"
```

---

### Task 5: Tab 1 — Status PKB & Invoice Terbaru (dummy)

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Create: `resources/views/dashboard/_tab_pkb_invoice.blade.php`
- Modify: `resources/views/dashboard/index.blade.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Produces: `buildPayload()` gains `pkbInvoiceRows: array[]` (each row: `['number' => string, 'customer' => string, 'plate' => string, 'branch' => string, 'status' => string]`). Establishes the `nav-tabs` + `tab-content` shell in `index.blade.php` that Tasks 6-7 add panes to.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DashboardTest.php`:

```php
    public function test_dashboard_shows_pkb_invoice_tab_rows(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch, true);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('PKB-2026080001', false);
    }
```

- [ ] **Step 2: Run tests to verify it fails**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL — no such text rendered yet.

- [ ] **Step 3: Add the dummy-rows method**

In `app/Http/Controllers/DashboardController.php`, add (after `dummyChartReceivables`):

```php
    protected function dummyPkbInvoiceRows(): array
    {
        return [
            ['number' => 'PKB-2026080001', 'customer' => 'Budi Santoso', 'plate' => 'B 1234 ABC', 'branch' => 'Cabang Jakarta', 'status' => 'OPEN'],
            ['number' => 'PKB-2026080002', 'customer' => 'Siti Aminah', 'plate' => 'B 5678 XYZ', 'branch' => 'Cabang Jakarta', 'status' => 'SHORTAGE'],
            ['number' => 'INV-2026080001', 'customer' => 'Andi Wijaya', 'plate' => 'D 4321 DEF', 'branch' => 'Cabang Bandung', 'status' => 'POSTED'],
            ['number' => 'PKB-2026080003', 'customer' => 'Dewi Lestari', 'plate' => 'B 9999 GHI', 'branch' => 'Cabang Jakarta', 'status' => 'COMPLETED'],
        ];
    }
```

Add `'pkbInvoiceRows' => $this->dummyPkbInvoiceRows(),` to the array returned by `buildPayload()`.

- [ ] **Step 4: Create the tab partial**

Create `resources/views/dashboard/_tab_pkb_invoice.blade.php`:

```blade
<div class="row g-2 mb-3">
    <div class="col-md-4">
        <input type="text" class="form-control form-control-sm" placeholder="Cari No. PKB/Invoice, Customer, No. Polisi..." disabled>
    </div>
    <div class="col-md-3">
        <select class="form-select form-select-sm" disabled>
            <option>Semua Status</option>
        </select>
    </div>
    <div class="col-md-3">
        <input type="text" class="form-control form-control-sm" placeholder="Rentang Tanggal" disabled>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>No. PKB/Invoice</th>
                <th>Customer &amp; No. Polisi</th>
                <th>Cabang</th>
                <th>Status</th>
                <th class="text-end">Aksi</th>
            </tr>
        </thead>
        <tbody id="pkbInvoiceTabBody">
            @foreach ($pkbInvoiceRows as $row)
                <tr>
                    <td><code>{{ $row['number'] }}</code></td>
                    <td>{{ $row['customer'] }} &middot; {{ $row['plate'] }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td><span class="status-dot status-active">{{ $row['status'] }}</span></td>
                    <td class="text-end text-muted small">&mdash;</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
```

- [ ] **Step 5: Add the tabs shell to the dashboard view**

In `resources/views/dashboard/index.blade.php`, add this after the charts `row`:

```blade
    <div class="card shadow-sm">
        <div class="card-body">
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pkb-invoice" type="button" role="tab">Status PKB & Invoice</button>
                </li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab-pkb-invoice" role="tabpanel">
                    @include('dashboard._tab_pkb_invoice')
                </div>
            </div>
        </div>
    </div>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (all tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/DashboardController.php resources/views/dashboard/_tab_pkb_invoice.blade.php resources/views/dashboard/index.blade.php tests/Feature/DashboardTest.php
git commit -m "feat: add PKB & Invoice terbaru tab with dummy rows"
```

---

### Task 6: Tab 2 — Kartu Stok (real sparepart picker + 3-tier widget, dummy mutations)

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Create: `resources/views/dashboard/_tab_kartu_stok.blade.php`
- Modify: `resources/views/dashboard/index.blade.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `scopedBranchIds()` (Task 1), `Sparepart`/`SparepartBranch` models.
- Produces: `buildPayload()` gains `kartuStok: ['spareparts' => [['id'=>int,'code'=>string,'name'=>string]], 'selected' => ['id'=>?int,'onHand'=>float,'reserved'=>float,'available'=>float], 'mutations' => array[]]`. `DashboardController::index()` reads an optional `sparepart_id` query param and passes it to `buildPayload()`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/DashboardTest.php`:

```php
    public function test_kartu_stok_tab_lists_only_spareparts_in_selected_branches(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $inA = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        SparepartBranch::create(['sparepart_id' => $inA->id, 'branch_id' => $branchA->id, 'selling_price' => 100000]);
        $inB = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $inB->id, 'branch_id' => $branchB->id, 'selling_price' => 50000]);

        $response = $this->actingAs(User::find($user->id))->getJson('/dashboard?branch_ids[]=' . $branchA->id);

        $response->assertOk();
        $data = $response->json();
        $codes = array_column($data['kartuStok']['spareparts'], 'code');
        $this->assertContains('BAN-01', $codes);
        $this->assertNotContains('OLI-01', $codes);
    }

    public function test_kartu_stok_widget_shows_selected_sparepart_totals(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $config = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $config->id)->update(['on_hand_qty' => 7, 'reserved_qty' => 2]);

        $response = $this->actingAs(User::find($user->id))
            ->getJson('/dashboard?branch_ids[]=' . $branch->id . '&sparepart_id=' . $sparepart->id);

        $response->assertOk();
        $response->assertJson(['kartuStok' => ['selected' => ['id' => $sparepart->id, 'onHand' => 7.0, 'reserved' => 2.0, 'available' => 5.0]]]);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL — `kartuStok` key doesn't exist yet.

- [ ] **Step 3: Add the Kartu Stok computation and dummy-mutations methods**

In `app/Http/Controllers/DashboardController.php`, add this import:

```php
use App\Models\Sparepart;
```

Add these protected methods (after `computeCriticalStockCount`):

```php
    protected function computeKartuStok(array $scopedBranchIds, ?int $sparepartId): array
    {
        $spareparts = Sparepart::where('is_active', true)
            ->whereHas('sparepartBranches', function ($query) use ($scopedBranchIds) {
                $query->whereIn('branch_id', $scopedBranchIds)->where('is_active', true);
            })
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $resolvedId = $sparepartId ?? optional($spareparts->first())->id;

        $selected = ['id' => $resolvedId, 'onHand' => 0.0, 'reserved' => 0.0, 'available' => 0.0];

        if ($resolvedId && ! empty($scopedBranchIds)) {
            $totals = SparepartBranch::where('sparepart_id', $resolvedId)
                ->whereIn('branch_id', $scopedBranchIds)
                ->where('is_active', true)
                ->join('sparepart_branch_stocks', 'sparepart_branch_stocks.sparepart_branch_id', '=', 'sparepart_branches.id')
                ->selectRaw('SUM(sparepart_branch_stocks.on_hand_qty) as on_hand, SUM(sparepart_branch_stocks.reserved_qty) as reserved')
                ->first();

            $onHand = (float) ($totals->on_hand ?? 0);
            $reserved = (float) ($totals->reserved ?? 0);
            $selected = ['id' => $resolvedId, 'onHand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];
        }

        return [
            'spareparts' => $spareparts->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->all(),
            'selected' => $selected,
            'mutations' => $this->dummyMutationRows(),
        ];
    }

    protected function dummyMutationRows(): array
    {
        return [
            ['date' => '2026-08-01 09:15', 'type' => 'RECEIPT', 'reference' => 'RCV-2026080001', 'in' => 20, 'out' => 0, 'reserved' => 0, 'balance' => 20],
            ['date' => '2026-08-01 14:30', 'type' => 'PKB_RESERVATION', 'reference' => 'PKB-2026080001', 'in' => 0, 'out' => 0, 'reserved' => 2, 'balance' => 20],
            ['date' => '2026-08-02 10:00', 'type' => 'INVOICE', 'reference' => 'INV-2026080001', 'in' => 0, 'out' => 2, 'reserved' => -2, 'balance' => 18],
        ];
    }
```

Update `DashboardController::index()` to pass the `sparepart_id` query param through:

```php
    public function index(Request $request)
    {
        $user = $request->user();
        $allowedBranches = $user->branches;

        $selectedBranchIds = $this->resolveSelectedBranchIds($request, $user, $allowedBranches);
        $sparepartId = $request->filled('sparepart_id') ? (int) $request->input('sparepart_id') : null;

        $payload = $this->buildPayload($user, $selectedBranchIds, $sparepartId);

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return view('dashboard.index', array_merge($payload, [
            'allowedBranches' => $allowedBranches,
            'selectedBranchIds' => $selectedBranchIds,
        ]));
    }
```

Update `buildPayload()`'s signature and body to:

```php
    protected function buildPayload(User $user, array $selectedBranchIds, ?int $sparepartId = null): array
    {
        $scopedBranchIds = $this->scopedBranchIds($user, $selectedBranchIds);

        return [
            'selectedBranchIds' => $selectedBranchIds,
            'stockOverview' => $this->computeStockOverview($scopedBranchIds),
            'criticalStockCount' => $this->computeCriticalStockCount($scopedBranchIds),
            'pkbStatus' => $this->dummyPkbStatus(),
            'receivables' => $this->dummyReceivables(),
            'chartTrend' => $this->dummyChartTrend(),
            'chartReceivables' => $this->dummyChartReceivables(),
            'pkbInvoiceRows' => $this->dummyPkbInvoiceRows(),
            'kartuStok' => $this->computeKartuStok($scopedBranchIds, $sparepartId),
        ];
    }
```

- [ ] **Step 4: Create the tab partial**

Create `resources/views/dashboard/_tab_kartu_stok.blade.php`:

```blade
<div class="row g-2 mb-3">
    <div class="col-md-5">
        <select class="form-select form-select-sm" id="kartuStokSparepartSelect">
            @forelse ($kartuStok['spareparts'] as $sparepart)
                <option value="{{ $sparepart['id'] }}" {{ $sparepart['id'] === $kartuStok['selected']['id'] ? 'selected' : '' }}>
                    {{ $sparepart['code'] }} &mdash; {{ $sparepart['name'] }}
                </option>
            @empty
                <option value="">Belum ada sparepart di cabang terpilih</option>
            @endforelse
        </select>
    </div>
    <div class="col-md-4">
        <select class="form-select form-select-sm" disabled>
            <option>Semua Jenis Mutasi</option>
        </select>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-4">
        <div class="stat-card">
            <div>
                <div class="stat-value" id="kartuStokOnHand">{{ number_format($kartuStok['selected']['onHand'], 0, ',', '.') }}</div>
                <div class="stat-label">Stok Fisik</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card">
            <div>
                <div class="stat-value" id="kartuStokReserved">{{ number_format($kartuStok['selected']['reserved'], 0, ',', '.') }}</div>
                <div class="stat-label">Stok Reservasi</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card">
            <div>
                <div class="stat-value" id="kartuStokAvailable" style="color: var(--color-success);">{{ number_format($kartuStok['selected']['available'], 0, ',', '.') }}</div>
                <div class="stat-label">Stok Tersedia</div>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>Tanggal</th>
                <th>Tipe Mutasi</th>
                <th>Referensi</th>
                <th class="text-end">Masuk</th>
                <th class="text-end">Keluar</th>
                <th class="text-end">Reservasi</th>
                <th class="text-end">Saldo Akhir</th>
            </tr>
        </thead>
        <tbody id="kartuStokMutationsBody">
            @foreach ($kartuStok['mutations'] as $row)
                <tr>
                    <td>{{ $row['date'] }}</td>
                    <td><span class="status-dot status-active">{{ $row['type'] }}</span></td>
                    <td><code>{{ $row['reference'] }}</code></td>
                    <td class="text-end">{{ $row['in'] }}</td>
                    <td class="text-end">{{ $row['out'] }}</td>
                    <td class="text-end">{{ $row['reserved'] }}</td>
                    <td class="text-end">{{ $row['balance'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
```

- [ ] **Step 5: Wire the tab into the dashboard view**

In `resources/views/dashboard/index.blade.php`, replace the `<ul class="nav nav-tabs ...">` block with:

```blade
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pkb-invoice" type="button" role="tab">Status PKB & Invoice</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-kartu-stok" type="button" role="tab">Kartu Stok</button>
                </li>
            </ul>
```

and replace the `<div class="tab-content">` block with:

```blade
            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab-pkb-invoice" role="tabpanel">
                    @include('dashboard._tab_pkb_invoice')
                </div>
                <div class="tab-pane fade" id="tab-kartu-stok" role="tabpanel">
                    @include('dashboard._tab_kartu_stok')
                </div>
            </div>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (all tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/DashboardController.php resources/views/dashboard/_tab_kartu_stok.blade.php resources/views/dashboard/index.blade.php tests/Feature/DashboardTest.php
git commit -m "feat: add Kartu Stok tab with real sparepart picker and stock widget"
```

---

### Task 7: Tab 3 — Live Audit Log Activity Feed (dummy)

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Create: `resources/views/dashboard/_tab_audit_log.blade.php`
- Modify: `resources/views/dashboard/index.blade.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Produces: `buildPayload()` gains `auditLogRows: array[]` (each row: `['timestamp' => string, 'user' => string, 'permission' => string, 'description' => string, 'impact' => string]`).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DashboardTest.php`:

```php
    public function test_dashboard_shows_audit_log_tab_rows(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch, true);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('sparepart.create', false);
    }
```

- [ ] **Step 2: Run tests to verify it fails**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL — no such text rendered yet.

- [ ] **Step 3: Add the dummy-rows method**

In `app/Http/Controllers/DashboardController.php`, add (after `dummyMutationRows`):

```php
    protected function dummyAuditLogRows(): array
    {
        return [
            ['timestamp' => '2026-08-02 10:12', 'user' => 'faiz_rahmat', 'permission' => 'sparepart.create', 'description' => 'Menambahkan sparepart BAN-01 ke Cabang Jakarta', 'impact' => 'LOW'],
            ['timestamp' => '2026-08-02 09:48', 'user' => 'romi_ramdani', 'permission' => 'pkb.create', 'description' => 'Membuat PKB baru untuk B 1234 ABC', 'impact' => 'MEDIUM'],
            ['timestamp' => '2026-08-01 16:30', 'user' => 'faiz_rahmat', 'permission' => 'user_permission.manage', 'description' => 'Mengubah permission user romi_ramdani', 'impact' => 'HIGH'],
        ];
    }
```

Add `'auditLogRows' => $this->dummyAuditLogRows(),` to the array returned by `buildPayload()`.

- [ ] **Step 4: Create the tab partial**

Create `resources/views/dashboard/_tab_audit_log.blade.php`:

```blade
<div class="row g-2 mb-3">
    <div class="col-md-4">
        <select class="form-select form-select-sm" disabled>
            <option>Semua User</option>
        </select>
    </div>
    <div class="col-md-4">
        <select class="form-select form-select-sm" disabled>
            <option>Semua Jenis Event</option>
        </select>
    </div>
</div>
<ul class="list-group list-group-flush" id="auditLogFeed">
    @foreach ($auditLogRows as $row)
        <li class="list-group-item px-0">
            <div class="d-flex justify-content-between">
                <span class="fw-semibold">{{ $row['user'] }}</span>
                <span class="small" style="color: var(--color-ink-muted);">{{ $row['timestamp'] }}</span>
            </div>
            <div class="small mb-1">
                <code>{{ $row['permission'] }}</code>
            </div>
            <div>{{ $row['description'] }}</div>
            <span class="status-dot {{ $row['impact'] === 'HIGH' ? 'status-inactive' : 'status-active' }}">{{ $row['impact'] }}</span>
        </li>
    @endforeach
</ul>
```

- [ ] **Step 5: Wire the tab into the dashboard view**

In `resources/views/dashboard/index.blade.php`, add a third `<li class="nav-item">` after the "Kartu Stok" one:

```blade
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-audit-log" type="button" role="tab">Audit Log</button>
                </li>
```

and a third `<div class="tab-pane fade">` after the "tab-kartu-stok" pane:

```blade
                <div class="tab-pane fade" id="tab-audit-log" role="tabpanel">
                    @include('dashboard._tab_audit_log')
                </div>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (all tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/DashboardController.php resources/views/dashboard/_tab_audit_log.blade.php resources/views/dashboard/index.blade.php tests/Feature/DashboardTest.php
git commit -m "feat: add Audit Log activity feed tab"
```

---

### Task 8: Loading-overlay JS + AJAX filter wiring + full-suite verification

**Files:**
- Modify: `resources/views/dashboard/index.blade.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: the full `buildPayload()` JSON shape from Tasks 1-7 (`selectedBranchIds`, `stockOverview`, `criticalStockCount`, `pkbStatus`, `receivables`, `chartTrend`, `chartReceivables`, `pkbInvoiceRows`, `kartuStok`, `auditLogRows`).
- Produces: no new PHP interfaces — this task replaces the Task 1 placeholder `applyBranchFilter()` (full-page navigation) with an AJAX version, and adds a Kartu Stok sparepart-select change handler, both using the same loading-overlay helper.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DashboardTest.php`:

```php
    public function test_dashboard_page_includes_loading_overlay_markup(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch, true);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('dashboard-loading-overlay', false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL — no such class/element exists yet.

- [ ] **Step 3: Add the loading-overlay CSS**

In `resources/views/partials/design-tokens.blade.php`, add this block right after the `.badge-soon { ... }` rule (still inside the file, no new section header needed — this is a small utility class usable anywhere a card/table needs a busy-state overlay):

```css
    .dashboard-loading-overlay {
        position: absolute;
        inset: 0;
        background: rgba(255, 255, 255, .7);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 5;
    }
    .dashboard-loading-parent { position: relative; min-height: 80px; }
```

- [ ] **Step 4: Wrap the dynamic sections in loading-overlay containers**

In `resources/views/dashboard/index.blade.php`, wrap the KPI row, the charts row, and the tab-content card each in a `dashboard-loading-parent` div with a hidden overlay child.

Replace:

```blade
    <div class="row g-3 mb-4" id="kpiCardsRow">
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value" id="kpiStockAvailable">{{ number_format($stockOverview['available'], 0, ',', '.') }}</div>
                    <div class="stat-label">Stok Tersedia</div>
                    <div class="small mt-1" style="color: var(--color-ink-muted);">On-hand {{ number_format($stockOverview['onHand'], 0, ',', '.') }} &middot; Reservasi {{ number_format($stockOverview['reserved'], 0, ',', '.') }}</div>
                </div>
                <i class="bi bi-box-seam stat-icon"></i>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value" id="kpiCriticalStock" style="{{ $criticalStockCount > 0 ? 'color: var(--color-warning);' : '' }}">{{ $criticalStockCount }}</div>
                    <div class="stat-label">Alert Stok Kritis</div>
                </div>
                <i class="bi bi-exclamation-triangle stat-icon"></i>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value" id="kpiPkbTotal">{{ $pkbStatus['open'] + $pkbStatus['shortage'] + $pkbStatus['completed'] }}</div>
                    <div class="stat-label">Status PKB Hari Ini</div>
                    <div class="small mt-1" style="color: var(--color-ink-muted);">Open {{ $pkbStatus['open'] }} &middot; Shortage {{ $pkbStatus['shortage'] }} &middot; Selesai {{ $pkbStatus['completed'] }}</div>
                </div>
                <i class="bi bi-clipboard-check stat-icon"></i>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value" id="kpiRevenue">{{ number_format($receivables['revenue'], 0, ',', '.') }}</div>
                    <div class="stat-label">Pendapatan & Piutang</div>
                    <div class="small mt-1" style="color: var(--color-ink-muted);">Piutang belum lunas {{ number_format($receivables['unpaid'], 0, ',', '.') }}</div>
                </div>
                <i class="bi bi-cash-coin stat-icon"></i>
            </div>
        </div>
    </div>
```

with the exact same content wrapped:

```blade
    <div class="dashboard-loading-parent" id="kpiSection">
        <div class="dashboard-loading-overlay d-none"><div class="spinner-border text-primary" role="status"></div></div>
        <div class="row g-3 mb-4" id="kpiCardsRow">
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" id="kpiStockAvailable">{{ number_format($stockOverview['available'], 0, ',', '.') }}</div>
                        <div class="stat-label">Stok Tersedia</div>
                        <div class="small mt-1" style="color: var(--color-ink-muted);">On-hand {{ number_format($stockOverview['onHand'], 0, ',', '.') }} &middot; Reservasi {{ number_format($stockOverview['reserved'], 0, ',', '.') }}</div>
                    </div>
                    <i class="bi bi-box-seam stat-icon"></i>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" id="kpiCriticalStock" style="{{ $criticalStockCount > 0 ? 'color: var(--color-warning);' : '' }}">{{ $criticalStockCount }}</div>
                        <div class="stat-label">Alert Stok Kritis</div>
                    </div>
                    <i class="bi bi-exclamation-triangle stat-icon"></i>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" id="kpiPkbTotal">{{ $pkbStatus['open'] + $pkbStatus['shortage'] + $pkbStatus['completed'] }}</div>
                        <div class="stat-label">Status PKB Hari Ini</div>
                        <div class="small mt-1" style="color: var(--color-ink-muted);">Open {{ $pkbStatus['open'] }} &middot; Shortage {{ $pkbStatus['shortage'] }} &middot; Selesai {{ $pkbStatus['completed'] }}</div>
                    </div>
                    <i class="bi bi-clipboard-check stat-icon"></i>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" id="kpiRevenue">{{ number_format($receivables['revenue'], 0, ',', '.') }}</div>
                        <div class="stat-label">Pendapatan & Piutang</div>
                        <div class="small mt-1" style="color: var(--color-ink-muted);">Piutang belum lunas {{ number_format($receivables['unpaid'], 0, ',', '.') }}</div>
                    </div>
                    <i class="bi bi-cash-coin stat-icon"></i>
                </div>
            </div>
        </div>
    </div>
```

Next, replace the charts row:

```blade
    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-3">Tren PKB vs Invoice Posted Mingguan</h2>
                    <canvas id="trendChart" height="220"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-3">Komposisi Status Piutang</h2>
                    <canvas id="receivablesChart" height="220"></canvas>
                </div>
            </div>
        </div>
    </div>
```

with:

```blade
    <div class="dashboard-loading-parent" id="chartsSection">
        <div class="dashboard-loading-overlay d-none"><div class="spinner-border text-primary" role="status"></div></div>
        <div class="row g-3 mb-4">
            <div class="col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 mb-3">Tren PKB vs Invoice Posted Mingguan</h2>
                        <canvas id="trendChart" height="220"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 mb-3">Komposisi Status Piutang</h2>
                        <canvas id="receivablesChart" height="220"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
```

Finally, replace the tabs card:

```blade
    <div class="card shadow-sm">
        <div class="card-body">
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pkb-invoice" type="button" role="tab">Status PKB & Invoice</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-kartu-stok" type="button" role="tab">Kartu Stok</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-audit-log" type="button" role="tab">Audit Log</button>
                </li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab-pkb-invoice" role="tabpanel">
                    @include('dashboard._tab_pkb_invoice')
                </div>
                <div class="tab-pane fade" id="tab-kartu-stok" role="tabpanel">
                    @include('dashboard._tab_kartu_stok')
                </div>
                <div class="tab-pane fade" id="tab-audit-log" role="tabpanel">
                    @include('dashboard._tab_audit_log')
                </div>
            </div>
        </div>
    </div>
```

with:

```blade
    <div class="dashboard-loading-parent" id="tabsSection">
        <div class="dashboard-loading-overlay d-none"><div class="spinner-border text-primary" role="status"></div></div>
        <div class="card shadow-sm">
            <div class="card-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pkb-invoice" type="button" role="tab">Status PKB & Invoice</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-kartu-stok" type="button" role="tab">Kartu Stok</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-audit-log" type="button" role="tab">Audit Log</button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tab-pkb-invoice" role="tabpanel">
                        @include('dashboard._tab_pkb_invoice')
                    </div>
                    <div class="tab-pane fade" id="tab-kartu-stok" role="tabpanel">
                        @include('dashboard._tab_kartu_stok')
                    </div>
                    <div class="tab-pane fade" id="tab-audit-log" role="tabpanel">
                        @include('dashboard._tab_audit_log')
                    </div>
                </div>
            </div>
        </div>
    </div>
```

- [ ] **Step 5: Replace the filter/tab JS with the AJAX version**

In `resources/views/dashboard/index.blade.php`, replace the entire filter-handling `<script>` block (the one with `applyBranchFilter`) with:

```blade
<script>
(function () {
    const selectAll = document.getElementById('branchFilterSelectAll');
    const menu = document.getElementById('branchFilterMenu');
    const sections = ['kpiSection', 'chartsSection', 'tabsSection'];

    if (menu) {
        menu.addEventListener('click', function (event) { event.stopPropagation(); });
    }

    function showOverlays() {
        sections.forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.querySelector('.dashboard-loading-overlay').classList.remove('d-none');
        });
    }

    function hideOverlays() {
        sections.forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.querySelector('.dashboard-loading-overlay').classList.add('d-none');
        });
    }

    function minDelay(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function fetchDashboard(params) {
        showOverlays();
        const url = '{{ route('dashboard') }}?' + params.toString();

        return Promise.all([
            fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }),
            minDelay(400),
        ]).then(function (results) {
            applyPayload(results[0]);
            hideOverlays();
        });
    }

    function applyPayload(data) {
        document.getElementById('kpiStockAvailable').textContent = Math.round(data.stockOverview.available).toLocaleString('id-ID');
        document.getElementById('kpiCriticalStock').textContent = data.criticalStockCount;
        document.getElementById('kpiPkbTotal').textContent = data.pkbStatus.open + data.pkbStatus.shortage + data.pkbStatus.completed;
        document.getElementById('kpiRevenue').textContent = Math.round(data.receivables.revenue).toLocaleString('id-ID');

        trendChart.data.labels = data.chartTrend.labels;
        trendChart.data.datasets[0].data = data.chartTrend.pkb;
        trendChart.data.datasets[1].data = data.chartTrend.invoice;
        trendChart.update();

        receivablesChart.data.labels = data.chartReceivables.labels;
        receivablesChart.data.datasets[0].data = data.chartReceivables.values;
        receivablesChart.update();

        document.getElementById('kartuStokOnHand').textContent = Math.round(data.kartuStok.selected.onHand).toLocaleString('id-ID');
        document.getElementById('kartuStokReserved').textContent = Math.round(data.kartuStok.selected.reserved).toLocaleString('id-ID');
        document.getElementById('kartuStokAvailable').textContent = Math.round(data.kartuStok.selected.available).toLocaleString('id-ID');

        const sparepartSelect = document.getElementById('kartuStokSparepartSelect');
        if (sparepartSelect) {
            sparepartSelect.innerHTML = '';
            data.kartuStok.spareparts.forEach(function (sparepart) {
                const option = document.createElement('option');
                option.value = sparepart.id;
                option.textContent = sparepart.code + ' — ' + sparepart.name;
                option.selected = sparepart.id === data.kartuStok.selected.id;
                sparepartSelect.appendChild(option);
            });
        }
    }

    function currentBranchIds() {
        return Array.from(document.querySelectorAll('.branch-filter-checkbox:checked')).map(function (cb) { return cb.value; });
    }

    function applyBranchFilter() {
        const params = new URLSearchParams();
        currentBranchIds().forEach(function (id) { params.append('branch_ids[]', id); });
        fetchDashboard(params);
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
            applyBranchFilter();
        });
    }

    document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) {
        checkbox.addEventListener('change', applyBranchFilter);
    });

    document.addEventListener('change', function (event) {
        if (event.target && event.target.id === 'kartuStokSparepartSelect') {
            const params = new URLSearchParams();
            currentBranchIds().forEach(function (id) { params.append('branch_ids[]', id); });
            params.append('sparepart_id', event.target.value);
            fetchDashboard(params);
        }
    });
})();
</script>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (all tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS — every test in the project, including all Dashboard tests and every pre-existing test (199 baseline), passes with no regressions.

- [ ] **Step 8: Commit**

```bash
git add resources/views/dashboard/index.blade.php resources/views/partials/design-tokens.blade.php tests/Feature/DashboardTest.php
git commit -m "feat: wire AJAX branch filter and loading overlays for the dashboard"
```

---

## Manual verification checklist (after all tasks complete)

1. Log in as `faiz_rahmat` (all branches, all permissions per `DemoUsersSeeder`). Confirm the Dashboard shows real Stok Tersedia/Alert Stok Kritis (both 0, since no stock has been received yet — migration 008 hasn't shipped), and dummy PKB/Pendapatan/charts/tabs render with plausible-looking numbers.
2. Toggle branches in the multi-select filter; confirm the loading spinner briefly appears on the KPI/chart/tab sections, then values update (or stay the same, since only sparepart-related numbers are wired to real per-branch data).
3. Switch to the Kartu Stok tab, change the sparepart dropdown; confirm the 3-tier widget updates and the loading spinner shows during the fetch.
4. Click "+ Sparepart Baru"; confirm it navigates to the real sparepart creation form. Confirm "+ Buat PKB Baru" is visibly inert (muted, not clickable).
5. Log in as `romi_ramdani` (BENGKEL1 only, no `sparepart.view`) and confirm the Stok Tersedia/Alert Stok Kritis KPIs show 0 (not an error), since he holds no branch grant for `sparepart.view` even though he's assigned to a branch.
