# Master Data Group 1 (Cabang & Kendaraan) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Roll out the `list-filter-bar`/`empty-state` pattern (already proven on Customer in Foundation v3) to the Cabang and Kendaraan list screens, adding real search to Cabang and fixing a latent `?q[]=x` crash in Kendaraan along the way.

**Architecture:** Two independent screen retrofits reusing existing shared partials. Kendaraan additionally needs one small, backward-compatible extension to the shared `list-filter-bar` partial (a new optional `extraFilterHtml` slot) since its customer-dropdown filter doesn't fit the existing search/branch-filter shape.

**Tech Stack:** Blade, Bootstrap 5 — no new dependencies, no database changes.

## Global Constraints

- Laravel 8.75 pinned — never use `Request::integer()`.
- Every index/list endpoint uses `->simplePaginate()`, never `->paginate()` — both endpoints already do.
- Search input sanitization: `is_string(request('q')) ? trim(request('q')) : null`, computed ONCE in the controller and passed to the view — never let a Blade view re-read `request('q')` directly (the exact bug class already hit once for Customer and hit here for Vehicle).
- LIKE-query values must be escaped with `addcslashes($q, '%_\\')` before interpolating into a `'like', "%{$q}%"'` clause, matching the existing Customer pattern exactly.
- Branch-filter validation (where applicable) intersects requested IDs against the user's own assigned branches, silently dropping anything else — not applicable to this plan (neither Cabang nor Kendaraan get a branch-multiselect filter, per the spec).
- Reuse existing shared partials/classes (`list-filter-bar`, `empty-state`, `.status-dot`, `.card`) — do not hand-roll new one-off markup for concepts these partials already cover.

---

### Task 1: Cabang — real search + list-filter-bar + empty-state

**Files:**
- Modify: `app/Http/Controllers/BranchController.php`
- Modify: `resources/views/branches/index.blade.php`
- Test: `tests/Feature/BranchManagementTest.php`

**Interfaces:**
- Consumes: `partials/list-filter-bar.blade.php` and `partials/empty-state.blade.php` (both pre-existing from Foundation v3, unmodified by this task — called with `branchFilterBranches => null` and no `extraFilterHtml`, since neither applies to Cabang).
- Produces: none consumed by Task 2 (Cabang and Kendaraan are independent) — but establishes the `$search` sanitization convention Task 2 repeats identically for Vehicle.

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Feature/BranchManagementTest.php` (inside the `BranchManagementTest` class, after the existing tests):

```php
    public function test_index_search_by_code_filters_results(): void
    {
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches?q=JKT');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
        $response->assertDontSee('Cabang Bandung');
    }

    public function test_index_search_by_name_filters_results(): void
    {
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches?q=Bandung');

        $response->assertOk();
        $response->assertSee('Cabang Bandung');
        $response->assertDontSee('Cabang Jakarta');
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches?q[]=JKT');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
    }

    public function test_index_shows_empty_state_when_no_branches_match(): void
    {
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches');

        $response->assertOk();
        $response->assertSee('Belum ada cabang');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['branch.view', 'branch.create']);

        $response = $this->actingAs($user)->get('/branches');

        $response->assertOk();
        $response->assertSee('Tambah Cabang Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches');

        $response->assertOk();
        $response->assertDontSee('Tambah Cabang Pertama');
    }

    public function test_index_renders_filter_bar(): void
    {
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari kode atau nama cabang...');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=BranchManagementTest`
Expected: FAIL — `BranchController::index()` has no search logic yet, and `branches/index.blade.php` has no filter-bar/empty-state markup yet.

- [ ] **Step 3: Update the controller**

Replace `BranchController::index()` in `app/Http/Controllers/BranchController.php` with:

```php
    public function index()
    {
        $this->authorize('branch.view');

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $branches = Branch::orderBy('name')
            ->when($search, function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('name', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            ->simplePaginate(15)
            ->withQueryString();

        return view('branches.index', compact('branches'))->with('search', $search);
    }
```

- [ ] **Step 4: Update the view**

Replace the full contents of `resources/views/branches/index.blade.php` with:

```blade
@extends('layouts.app')
@section('title', 'Cabang')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-shop me-2"></i>Cabang</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari kode atau nama cabang...',
        'searchValue' => $search,
        'branchFilterBranches' => null,
        'branchFilterSelected' => [],
        'actionsHtml' => auth()->user()->can('branch.create')
            ? '<a href="' . route('branches.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah Cabang</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($branches as $branch)
                        <tr>
                            <td><code>{{ $branch->code }}</code></td>
                            <td>{{ $branch->name }}</td>
                            <td>{{ $branch->phone ?? '-' }}</td>
                            <td>
                                @if ($branch->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('branch.edit')
                                    <a href="{{ route('branches.edit', $branch) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-shop',
                                    'title' => 'Belum ada cabang',
                                    'description' => 'Mulai dengan menambahkan cabang pertama Anda.',
                                    'ctaRoute' => 'branches.create',
                                    'ctaLabel' => '+ Tambah Cabang Pertama',
                                    'ctaPermission' => 'branch.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $branches->links() }}
    </div>
@endsection
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=BranchManagementTest`
Expected: PASS (all tests, including the pre-existing ones).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/BranchController.php resources/views/branches/index.blade.php tests/Feature/BranchManagementTest.php
git commit -m "feat: add real search and retrofit Cabang list to list-filter-bar/empty-state"
```

---

### Task 2: Kendaraan — extend list-filter-bar, retrofit view, fix `?q[]=x` crash

**Files:**
- Modify: `resources/views/partials/list-filter-bar.blade.php`
- Create: `resources/views/vehicles/_customer_filter_select.blade.php`
- Modify: `app/Http/Controllers/VehicleController.php`
- Modify: `resources/views/vehicles/index.blade.php`
- Test: `tests/Feature/VehicleManagementTest.php`

**Interfaces:**
- Consumes: `partials/empty-state.blade.php` (unmodified). Extends `partials/list-filter-bar.blade.php` with a new optional parameter.
- Produces: `list-filter-bar`'s new `extraFilterHtml` slot (string, default `''`) — available to any future caller, though only Kendaraan uses it in this plan.

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/Feature/VehicleManagementTest.php` (inside the `VehicleManagementTest` class, after the existing tests):

```php
    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->get('/vehicles?q[]=B%201234');

        $response->assertOk();
        $response->assertSee('B 1234 XYZ');
    }

    public function test_index_shows_empty_state_when_no_vehicles_match(): void
    {
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->get('/vehicles');

        $response->assertOk();
        $response->assertSee('Belum ada kendaraan');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['vehicle.view', 'vehicle.create']);

        $response = $this->actingAs($user)->get('/vehicles');

        $response->assertOk();
        $response->assertSee('Tambah Kendaraan Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->get('/vehicles');

        $response->assertOk();
        $response->assertDontSee('Tambah Kendaraan Pertama');
    }

    public function test_index_renders_filter_bar_with_customer_dropdown(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->get('/vehicles');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari no. polisi/rangka/mesin...');
        $response->assertSee('Budi');
    }

    public function test_index_customer_filter_still_scopes_results_after_retrofit(): void
    {
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti', 'stnk_name' => 'Siti']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        Vehicle::create([
            'customer_id' => $customerA->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1111 AAA',
        ]);
        Vehicle::create([
            'customer_id' => $customerB->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 2222 BBB',
        ]);
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->get("/vehicles?customer_id={$customerA->id}");

        $response->assertOk();
        $response->assertSee('B 1111 AAA');
        $response->assertDontSee('B 2222 BBB');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=VehicleManagementTest`
Expected: the 6 new tests FAIL (the `?q[]=` crash test fails with a 500; the rest fail because the filter-bar/empty-state markup doesn't exist yet). The pre-existing tests in this file still pass.

- [ ] **Step 3: Extend the list-filter-bar partial**

In `resources/views/partials/list-filter-bar.blade.php`, add this line as the very first line of the file (before the existing `<div class="card">`):

```blade
@php($extraFilterHtml = $extraFilterHtml ?? '')
```

Then, inside the form, add a new column right after the branch-filter column's `@endif` and before the actions column `<div class="col-md-4 text-md-end">`:

```blade
            @if ($extraFilterHtml !== '')
            <div class="col-md-3">
                {!! $extraFilterHtml !!}
            </div>
            @endif
```

(Same "caller-authored only, never raw user input" contract as `actionsHtml` — add a one-line comment above this new block reusing the exact wording already in the file for `actionsHtml`, so both raw-echo slots carry the same documented warning.)

- [ ] **Step 4: Extract the customer-select partial**

Create `resources/views/vehicles/_customer_filter_select.blade.php`:

```blade
<select name="customer_id" class="form-select form-select-sm" onchange="this.form.submit()">
    <option value="">-- Semua Customer --</option>
    @foreach ($customers as $customer)
        <option value="{{ $customer->id }}" {{ $selectedCustomerId === $customer->id ? 'selected' : '' }}>{{ $customer->name }}</option>
    @endforeach
</select>
```

- [ ] **Step 5: Update the controller**

Replace `VehicleController::index()` in `app/Http/Controllers/VehicleController.php` with:

```php
    public function index()
    {
        $this->authorize('vehicle.view');

        $search = is_string(request('q')) ? trim(request('q')) : null;
        $customerId = request('customer_id') ? (int) request('customer_id') : null;

        $vehicles = Vehicle::with(['customer', 'category', 'brand', 'type'])
            ->when($customerId, fn ($query, $id) => $query->where('customer_id', $id))
            ->when($search, function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('plate_number', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('frame_number', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('engine_number', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            ->orderBy('created_at', 'desc')
            ->simplePaginate(15)
            ->withQueryString();

        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        return view('vehicles.index', compact('vehicles', 'customers'))
            ->with('search', $search)
            ->with('selectedCustomerId', $customerId);
    }
```

- [ ] **Step 6: Update the view**

Replace the full contents of `resources/views/vehicles/index.blade.php` with:

```blade
@extends('layouts.app')
@section('title', 'Kendaraan')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-car-front me-2"></i>Kendaraan</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari no. polisi/rangka/mesin...',
        'searchValue' => $search,
        'branchFilterBranches' => null,
        'branchFilterSelected' => [],
        'extraFilterHtml' => view('vehicles._customer_filter_select', ['customers' => $customers, 'selectedCustomerId' => $selectedCustomerId])->render(),
        'actionsHtml' => auth()->user()->can('vehicle.create')
            ? '<a href="' . route('vehicles.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah Kendaraan</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Polisi</th>
                        <th>Customer</th>
                        <th>Kategori / Merk / Tipe</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vehicles as $vehicle)
                        <tr>
                            <td><code>{{ $vehicle->plate_number ?? '-' }}</code></td>
                            <td>{{ $vehicle->customer->name }}</td>
                            <td>{{ $vehicle->category->name }} / {{ $vehicle->brand->name }} / {{ $vehicle->type->name }}</td>
                            <td>
                                @if ($vehicle->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('vehicle.edit')
                                    <a href="{{ route('vehicles.edit', $vehicle) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-car-front',
                                    'title' => 'Belum ada kendaraan',
                                    'description' => 'Mulai dengan menambahkan kendaraan pertama.',
                                    'ctaRoute' => 'vehicles.create',
                                    'ctaLabel' => '+ Tambah Kendaraan Pertama',
                                    'ctaPermission' => 'vehicle.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $vehicles->links() }}
    </div>
@endsection
```

Note: the `onchange="this.form.submit()"` on the customer `<select>` (Step 4) means selecting a customer submits the whole `list-filter-bar` form immediately, matching the pre-retrofit behavior's intent (the old inline form had an explicit "Cari" submit button covering all three fields together; this retrofit keeps one shared "Terapkan" button for search+actions while the customer dropdown auto-submits on change, since forcing a user to also click "Terapkan" after picking a customer would be a regression in convenience — both paths submit the same GET form, so search text typed before selecting a customer is preserved either way).

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=VehicleManagementTest`
Expected: PASS (all tests, including the pre-existing ones).

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS — every test in the project passes with no regressions. Given this project's repeated history (three prior instances) of cross-feature text collisions between a newly-added screen's visible text and an existing `assertSee`/`assertDontSee` elsewhere in the suite, specifically grep the new empty-state titles ("Belum ada cabang", "Belum ada kendaraan") and the filter-bar placeholders against `tests/Feature/AppShellTest.php` and `tests/Feature/DashboardTest.php` for any accidental substring overlap before declaring this clean.

- [ ] **Step 9: Commit**

```bash
git add resources/views/partials/list-filter-bar.blade.php resources/views/vehicles/_customer_filter_select.blade.php app/Http/Controllers/VehicleController.php resources/views/vehicles/index.blade.php tests/Feature/VehicleManagementTest.php
git commit -m "feat: retrofit Kendaraan list to list-filter-bar/empty-state, fix ?q[]=x crash"
```

---

## Manual verification checklist (after all tasks complete)

1. Log in as `faiz_rahmat`. Load `/branches`, confirm the glassmorphism filter bar renders, search by code and by name, confirm results narrow correctly.
2. Load `/vehicles`, confirm the filter bar shows the search box, the customer dropdown (via the new `extraFilterHtml` slot), and the "Tambah Kendaraan" action button together in one bar. Select a customer and confirm the list auto-filters. Type a search term and submit, confirm results narrow.
3. Trigger both empty states: search for a nonsense term on each page, confirm the centered icon/title/description/CTA renders instead of an empty table.
4. Confirm `/branches?q[]=x` and `/vehicles?q[]=x` both return 200, not a 500 error page.
