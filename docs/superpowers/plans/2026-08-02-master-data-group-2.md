# Master Data Group 2 (Mekanik, Jasa Service, Master Sparepart) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Roll out the `list-filter-bar`/`empty-state` pattern to Jasa Service, Mekanik, and Master Sparepart — the three remaining "Master Data group 2" screens — completing the same retrofit already proven on Customer, Cabang, and Kendaraan.

**Architecture:** Three independent screen retrofits. Jasa Service mirrors Cabang exactly (flat, no branch relation). Mekanik mirrors Customer exactly (has a `mechanicBranches` relation identical in shape to `customerBranches`). Master Sparepart is architecturally different — it already has a real, session-backed, single-select branch switcher with write-target semantics; this plan only wraps it in the shared visual bar (via `extraFilterHtml`) and extends `empty-state.blade.php` with a new `ctaVisible` override so its CTA can be gated by a branch-scoped permission check instead of a global one.

**Tech Stack:** Blade, Bootstrap 5 — no new dependencies, no database changes.

## Global Constraints

- Laravel 8.75 pinned — never use `Request::integer()`.
- Every index/list endpoint uses `->simplePaginate()`, never `->paginate()` — all three already do.
- Search input sanitization: `is_string(request('q')) ? trim(request('q')) : null`, computed ONCE in the controller and passed to the view — never let a Blade view re-read `request('q')` directly (the bug class already hit and fixed three times: Customer, Kendaraan, and now Sparepart in this plan).
- LIKE-query values must be escaped with `addcslashes($q, '%_\\')` before interpolating into a `'like', "%{$q}%"'` clause.
- Branch-filter validation (Mekanik only) intersects requested `branch_ids[]` against the user's own assigned branches, silently dropping anything else, mirroring Customer's exact rule.
- Master Sparepart's existing branch-switcher (single-select, session-backed, real write-target semantics) is NOT replaced or altered in its logic — only its markup moves into a new partial and its container into `list-filter-bar`'s `extraFilterHtml` slot.
- Reuse existing shared partials/classes (`list-filter-bar`, `empty-state`, `branch-multiselect-filter`, `.status-dot`, `.card`) — do not hand-roll new one-off markup.

---

### Task 1: Jasa Service — real search + list-filter-bar + empty-state

**Files:**
- Modify: `app/Http/Controllers/ServiceCatalogController.php`
- Modify: `resources/views/service-catalogs/index.blade.php`
- Test: `tests/Feature/ServiceCatalogManagementTest.php`

**Interfaces:**
- Consumes: `partials/list-filter-bar.blade.php` and `partials/empty-state.blade.php` (both pre-existing, unmodified by this task — called with `branchFilterBranches => null`, no `extraFilterHtml`).
- Produces: none consumed by later tasks — Task 1-3 are independent.

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Feature/ServiceCatalogManagementTest.php` (inside the `ServiceCatalogManagementTest` class, after the existing tests):

```php
    public function test_index_search_by_code_filters_results(): void
    {
        ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        ServiceCatalog::create(['code' => 'TUNE-UP', 'name' => 'Tune Up', 'default_price' => 150000]);
        $user = $this->userWithPermissions(['service.view']);

        $response = $this->actingAs($user)->get('/service-catalogs?q=GANTI-OLI');

        $response->assertOk();
        $response->assertSee('Ganti Oli');
        $response->assertDontSee('Tune Up');
    }

    public function test_index_search_by_name_filters_results(): void
    {
        ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        ServiceCatalog::create(['code' => 'TUNE-UP', 'name' => 'Tune Up', 'default_price' => 150000]);
        $user = $this->userWithPermissions(['service.view']);

        $response = $this->actingAs($user)->get('/service-catalogs?q=Tune');

        $response->assertOk();
        $response->assertSee('Tune Up');
        $response->assertDontSee('Ganti Oli');
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        $user = $this->userWithPermissions(['service.view']);

        $response = $this->actingAs($user)->get('/service-catalogs?q[]=GANTI-OLI');

        $response->assertOk();
        $response->assertSee('Ganti Oli');
    }

    public function test_index_shows_empty_state_when_no_service_catalogs_match(): void
    {
        $user = $this->userWithPermissions(['service.view']);

        $response = $this->actingAs($user)->get('/service-catalogs');

        $response->assertOk();
        $response->assertSee('Belum ada jasa service');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['service.view', 'service.create']);

        $response = $this->actingAs($user)->get('/service-catalogs');

        $response->assertOk();
        $response->assertSee('Tambah Jasa Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['service.view']);

        $response = $this->actingAs($user)->get('/service-catalogs');

        $response->assertOk();
        $response->assertDontSee('Tambah Jasa Pertama');
    }

    public function test_index_renders_filter_bar(): void
    {
        $user = $this->userWithPermissions(['service.view']);

        $response = $this->actingAs($user)->get('/service-catalogs');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari kode atau nama jasa...');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ServiceCatalogManagementTest`
Expected: FAIL — `ServiceCatalogController::index()` has no search logic yet, and `service-catalogs/index.blade.php` has no filter-bar/empty-state markup yet.

- [ ] **Step 3: Update the controller**

Replace `ServiceCatalogController::index()` in `app/Http/Controllers/ServiceCatalogController.php` with:

```php
    public function index()
    {
        $this->authorize('service.view');

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $serviceCatalogs = ServiceCatalog::orderBy('name')
            ->when($search, function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('name', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            ->simplePaginate(15)
            ->withQueryString();

        return view('service-catalogs.index', compact('serviceCatalogs'))->with('search', $search);
    }
```

- [ ] **Step 4: Update the view**

Replace the full contents of `resources/views/service-catalogs/index.blade.php` with:

```blade
@extends('layouts.app')
@section('title', 'Jasa Service')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-tools me-2"></i>Jasa Service</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari kode atau nama jasa...',
        'searchValue' => $search,
        'branchFilterBranches' => null,
        'branchFilterSelected' => [],
        'actionsHtml' => auth()->user()->can('service.create')
            ? '<a href="' . route('service-catalogs.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah Jasa</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Harga Default</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($serviceCatalogs as $serviceCatalog)
                        <tr>
                            <td><code>{{ $serviceCatalog->code }}</code></td>
                            <td>{{ $serviceCatalog->name }}</td>
                            <td>{{ number_format($serviceCatalog->default_price, 0, ',', '.') }}</td>
                            <td>
                                @if ($serviceCatalog->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('service.edit')
                                    <a href="{{ route('service-catalogs.edit', $serviceCatalog) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-tools',
                                    'title' => 'Belum ada jasa service',
                                    'description' => 'Mulai dengan menambahkan jasa service pertama.',
                                    'ctaRoute' => 'service-catalogs.create',
                                    'ctaLabel' => '+ Tambah Jasa Pertama',
                                    'ctaPermission' => 'service.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $serviceCatalogs->links() }}
    </div>
@endsection
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=ServiceCatalogManagementTest`
Expected: PASS (all tests, including the pre-existing ones).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ServiceCatalogController.php resources/views/service-catalogs/index.blade.php tests/Feature/ServiceCatalogManagementTest.php
git commit -m "feat: add real search and retrofit Jasa Service list to list-filter-bar/empty-state"
```

---

### Task 2: Mekanik — real search + branch filter + list-filter-bar + empty-state

**Files:**
- Modify: `app/Http/Controllers/MechanicController.php`
- Modify: `resources/views/mechanics/index.blade.php`
- Test: `tests/Feature/MechanicManagementTest.php`

**Interfaces:**
- Consumes: `partials/list-filter-bar.blade.php` (with `branchFilterBranches` set, reusing `partials/branch-multiselect-filter.blade.php` internally — no changes to either) and `partials/empty-state.blade.php` (unmodified). Consumes `Mechanic::mechanicBranches()` (pre-existing hasMany to `MechanicBranch`) and `User::branches()` (pre-existing).
- Produces: none consumed by later tasks.

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Feature/MechanicManagementTest.php` (add `use App\Models\Branch; use App\Models\MechanicBranch; use App\Services\UserBranchService;` to the imports at the top, alongside the existing ones):

```php
    public function test_index_search_by_name_filters_results(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan']);
        Mechanic::create(['name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics?q=Agus');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }

    public function test_index_search_by_phone_filters_results(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan', 'phone' => '081111111111']);
        Mechanic::create(['name' => 'Budi Santoso', 'phone' => '082222222222']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics?q=081111');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics?q[]=Agus');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
    }

    public function test_index_branch_filter_scopes_to_selected_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $mechanicA = Mechanic::create(['name' => 'Agus Setiawan']);
        $mechanicB = Mechanic::create(['name' => 'Budi Santoso']);
        MechanicBranch::create(['mechanic_id' => $mechanicA->id, 'branch_id' => $branchA->id]);
        MechanicBranch::create(['mechanic_id' => $mechanicB->id, 'branch_id' => $branchB->id]);
        $user = $this->userWithPermissions(['mechanic.view']);
        (new UserBranchService())->assign($user, $branchA);
        (new UserBranchService())->assign($user, $branchB);

        $response = $this->actingAs(User::find($user->id))->get("/mechanics?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }

    public function test_index_branch_filter_drops_branch_ids_the_user_is_not_assigned_to(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $mechanicA = Mechanic::create(['name' => 'Agus Setiawan']);
        $mechanicB = Mechanic::create(['name' => 'Budi Santoso']);
        MechanicBranch::create(['mechanic_id' => $mechanicA->id, 'branch_id' => $branchA->id]);
        MechanicBranch::create(['mechanic_id' => $mechanicB->id, 'branch_id' => $branchB->id]);
        $user = $this->userWithPermissions(['mechanic.view']);
        (new UserBranchService())->assign($user, $branchA);

        $response = $this->actingAs(User::find($user->id))->get("/mechanics?branch_ids[]={$branchB->id}");

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertSee('Budi Santoso');
    }

    public function test_index_shows_empty_state_when_no_mechanics_match(): void
    {
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertSee('Belum ada mekanik');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['mechanic.view', 'mechanic.create']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertSee('Tambah Mekanik Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertDontSee('Tambah Mekanik Pertama');
    }

    public function test_index_renders_filter_bar(): void
    {
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari nama atau telepon...');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=MechanicManagementTest`
Expected: FAIL — `MechanicController::index()` has no search/filter logic yet, and `mechanics/index.blade.php` has no filter-bar/empty-state markup yet.

- [ ] **Step 3: Update the controller**

Replace `MechanicController::index()` in `app/Http/Controllers/MechanicController.php` with:

```php
    public function index()
    {
        $this->authorize('mechanic.view');

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect(auth()->user()->branches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $mechanics = Mechanic::orderBy('name')
            ->when($search, function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('phone', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            ->when($branchIds, fn ($query) => $query->whereHas('mechanicBranches', fn ($q) => $q->whereIn('branch_id', $branchIds)->where('is_active', true)))
            ->simplePaginate(15)
            ->withQueryString();

        $userBranches = auth()->user()->branches;

        return view('mechanics.index', compact('mechanics'))
            ->with('branches', $userBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }
```

- [ ] **Step 4: Update the view**

Replace the full contents of `resources/views/mechanics/index.blade.php` with:

```blade
@extends('layouts.app')
@section('title', 'Mekanik')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-gear me-2"></i>Mekanik</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nama atau telepon...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => auth()->user()->can('mechanic.create')
            ? '<a href="' . route('mechanics.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah Mekanik</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nama</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mechanics as $mechanic)
                        <tr>
                            <td>{{ $mechanic->name }}</td>
                            <td>{{ $mechanic->phone ?? '-' }}</td>
                            <td>
                                @if ($mechanic->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('mechanics.show', $mechanic) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-gear"></i> Kelola
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-person-gear',
                                    'title' => 'Belum ada mekanik',
                                    'description' => 'Mulai dengan menambahkan mekanik pertama.',
                                    'ctaRoute' => 'mechanics.create',
                                    'ctaLabel' => '+ Tambah Mekanik Pertama',
                                    'ctaPermission' => 'mechanic.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $mechanics->links() }}
    </div>
@endsection
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=MechanicManagementTest`
Expected: PASS (all tests, including the pre-existing ones).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MechanicController.php resources/views/mechanics/index.blade.php tests/Feature/MechanicManagementTest.php
git commit -m "feat: add real search/branch-filter and retrofit Mekanik list to list-filter-bar/empty-state"
```

---

### Task 3: Master Sparepart — extend empty-state, wrap branch-switcher, fix `?q[]=x`

**Files:**
- Modify: `resources/views/partials/empty-state.blade.php`
- Create: `resources/views/sparepart-branches/_branch_switcher_select.blade.php`
- Modify: `app/Http/Controllers/SparepartBranchController.php`
- Modify: `resources/views/sparepart-branches/index.blade.php`
- Test: `tests/Feature/SparepartBranchIndexAndCreateTest.php`

**Interfaces:**
- Extends: `partials/empty-state.blade.php` gains one new optional parameter, `$ctaVisible` (default `null`). When the caller passes a boolean, it overrides the existing `@can($ctaPermission)` check entirely. When left unset (every other caller in the codebase — Cabang, Kendaraan, Jasa Service, Mekanik from Tasks 1-2), behavior is byte-for-byte unchanged.
- Consumes: `partials/list-filter-bar.blade.php`'s `extraFilterHtml` slot (pre-existing, from the master-data-group-1 plan) — no changes to that partial needed.

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Feature/SparepartBranchIndexAndCreateTest.php` (inside the `SparepartBranchIndexAndCreateTest` class, after the existing tests):

```php
    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches?q[]=Ban');

        $response->assertOk();
        $response->assertSee('Ban Depan');
    }

    public function test_index_shows_empty_state_when_branch_has_no_spareparts(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response->assertOk();
        $response->assertSee('Belum ada sparepart di cabang ini');
    }

    public function test_empty_state_cta_shown_when_user_has_create_permission_in_current_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response->assertOk();
        $response->assertSee('Sparepart Baru');
    }

    public function test_empty_state_cta_hidden_when_user_lacks_create_permission_in_current_branch(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        // sparepart.create granted only in branch B, not the current branch (A).
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches?branch_id=' . $branchA->id);

        $response->assertOk();
        $response->assertDontSee('Sparepart Baru');
    }

    public function test_index_renders_filter_bar_with_branch_switcher(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cabang Jakarta');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest`
Expected: FAIL — `?q[]=x` still 500s, no `empty-state`/`list-filter-bar` markup exists yet, and `$ctaVisible` doesn't exist on `empty-state.blade.php` yet.

- [ ] **Step 3: Extend the empty-state partial**

Replace the full contents of `resources/views/partials/empty-state.blade.php` with:

```blade
@php($ctaVisible = $ctaVisible ?? auth()->user()?->can($ctaPermission))
<div class="text-center py-5">
    <i class="bi {{ $icon }}" style="font-size: 3rem; color: var(--color-ink-muted); opacity: .4;"></i>
    <h5 class="mt-3 mb-1">{{ $title }}</h5>
    <p class="text-muted small mb-3">{{ $description }}</p>
    @if ($ctaVisible)
        <a href="{{ route($ctaRoute) }}" class="btn btn-primary btn-sm">{{ $ctaLabel }}</a>
    @endif
</div>
```

- [ ] **Step 4: Extract the branch-switcher partial**

Create `resources/views/sparepart-branches/_branch_switcher_select.blade.php`:

```blade
<select name="branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
    @foreach ($allowedBranches as $branch)
        <option value="{{ $branch->id }}" {{ $branch->id === $currentBranch->id ? 'selected' : '' }}>
            {{ $branch->name }}
        </option>
    @endforeach
</select>
```

- [ ] **Step 5: Update the controller**

In `app/Http/Controllers/SparepartBranchController.php`, replace the `index()` method with:

```php
    public function index()
    {
        $user = auth()->user();
        $allowedBranches = $user->branchesWithPermission('sparepart.view');

        if ($allowedBranches->isEmpty()) {
            return view('sparepart-branches.no-access');
        }

        $requestedBranchId = request('branch_id');
        if ($requestedBranchId && $allowedBranches->firstWhere('id', (int) $requestedBranchId)) {
            session(['current_sparepart_branch_id' => (int) $requestedBranchId]);
        }

        $currentBranch = $allowedBranches->firstWhere('id', session('current_sparepart_branch_id'))
            ?? $allowedBranches->first();
        session(['current_sparepart_branch_id' => $currentBranch->id]);

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $sparepartBranches = SparepartBranch::with(['sparepart', 'stock'])
            ->where('branch_id', $currentBranch->id)
            ->when($search, function ($query, $q) {
                $query->whereHas('sparepart', function ($inner) use ($q) {
                    $inner->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('name', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            ->orderBy('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('sparepart-branches.index', compact('sparepartBranches', 'allowedBranches', 'currentBranch'))->with('search', $search);
    }
```

- [ ] **Step 6: Update the view**

Replace the full contents of `resources/views/sparepart-branches/index.blade.php` with:

```blade
@extends('layouts.app')
@section('title', 'Master Sparepart')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i>Master Sparepart</h1>
        <div class="d-flex gap-2">
            @if (auth()->user()->hasPermissionToInBranch('sparepart.create', $currentBranch->id))
                <a href="{{ route('sparepart-branches.createExisting') }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-link-45deg"></i> Tambah dari Cabang Lain
                </a>
                <a href="{{ route('sparepart-branches.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> Sparepart Baru
                </a>
            @endif
        </div>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Kode atau nama sparepart...',
        'searchValue' => $search,
        'branchFilterBranches' => null,
        'branchFilterSelected' => [],
        'extraFilterHtml' => view('sparepart-branches._branch_switcher_select', ['allowedBranches' => $allowedBranches, 'currentBranch' => $currentBranch])->render(),
        'actionsHtml' => '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Rak</th>
                        <th>Harga Jual</th>
                        <th>Stok Min</th>
                        <th>Stok Tersedia</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sparepartBranches as $sparepartBranch)
                        <tr>
                            <td><code>{{ $sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $sparepartBranch->sparepart->name }}</td>
                            <td>{{ $sparepartBranch->rack_number ?? '-' }}</td>
                            <td>{{ number_format($sparepartBranch->selling_price, 0, ',', '.') }}</td>
                            <td>{{ number_format($sparepartBranch->minimum_stock, 0, ',', '.') }}</td>
                            <td>{{ number_format($sparepartBranch->stock->available_qty, 0, ',', '.') }}</td>
                            <td>
                                @if ($sparepartBranch->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('update', $sparepartBranch)
                                    <a href="{{ route('sparepart-branches.edit', $sparepartBranch) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                                @can('delete', $sparepartBranch)
                                    @if ($sparepartBranch->is_active)
                                        <form method="POST" action="{{ route('sparepart-branches.deactivate', $sparepartBranch) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-outline-danger btn-sm">Nonaktifkan</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('sparepart-branches.activate', $sparepartBranch) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-outline-success btn-sm">Aktifkan</button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-box-seam',
                                    'title' => 'Belum ada sparepart di cabang ini',
                                    'description' => 'Mulai dengan menambahkan sparepart pertama di cabang ini.',
                                    'ctaRoute' => 'sparepart-branches.create',
                                    'ctaLabel' => '+ Sparepart Baru',
                                    'ctaVisible' => auth()->user()->hasPermissionToInBranch('sparepart.create', $currentBranch->id),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $sparepartBranches->links() }}
    </div>
@endsection
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest`
Expected: PASS (all tests, including the pre-existing ones).

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS — every test in the project passes with no regressions. `empty-state.blade.php` is shared by Cabang, Kendaraan, Jasa Service (Task 1), Mekanik (Task 2), Customer, and now Sparepart — its extension must not break any of the other five callers, none of which pass `ctaVisible`. Also, per this project's now-four-times-repeated lesson, grep the three new empty-state titles ("Belum ada jasa service", "Belum ada mekanik") and filter-bar placeholders against `tests/Feature/AppShellTest.php` and `tests/Feature/DashboardTest.php` for any accidental text collision before declaring this clean.

- [ ] **Step 9: Commit**

```bash
git add resources/views/partials/empty-state.blade.php resources/views/sparepart-branches/_branch_switcher_select.blade.php app/Http/Controllers/SparepartBranchController.php resources/views/sparepart-branches/index.blade.php tests/Feature/SparepartBranchIndexAndCreateTest.php
git commit -m "feat: wrap Sparepart branch switcher in list-filter-bar, add branch-scoped empty-state CTA, fix ?q[]=x crash"
```

---

## Manual verification checklist (after all tasks complete)

1. Log in as `faiz_rahmat`. Load `/service-catalogs`, confirm the filter bar renders, search by code and by name.
2. Load `/mechanics`, confirm the filter bar shows search + branch multiselect + "Tambah Mekanik" together, confirm branch filtering works.
3. Load `/sparepart-branches`, confirm the branch switcher now sits inside the same glassmorphism filter bar as the search box, confirm switching branches still filters the list and searching still works together with the switcher.
4. Trigger all three empty states (search for a nonsense term / switch to a branch with zero spareparts) and confirm the centered icon/title/description/CTA renders.
5. Confirm `/service-catalogs?q[]=x`, `/mechanics?q[]=x`, and `/sparepart-branches?q[]=x` all return 200, not a 500 error page.
6. Log in as `romi_ramdani` (holds `sparepart.view` in BENGKEL1 but not `sparepart.create` anywhere per `DemoUsersSeeder`), load `/sparepart-branches` with an empty branch, confirm the empty-state CTA is absent (branch-scoped gate working correctly, not just falling back to a global check).
