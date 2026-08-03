# Administrasi (Users) Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retrofit the Users list screen (`/users`) with the design system's standard `list-filter-bar`/`empty-state` pattern — real search (name/username) + multi-branch filter — matching the pattern already shipped for Customer, Cabang, Kendaraan, Jasa Service, Mekanik, and Master Sparepart. This is the sixth and final application of this pattern, closing out the UI redesign track.

**Architecture:** Add search/branch-filter query logic to `UserController::index()` (copy-and-substitute from `MechanicController::index()`, substituting `User::branches()` for `mechanicBranches`), then retrofit `users/index.blade.php` to use the shared `partials.list-filter-bar` and `partials.empty-state` partials in place of the bare table header and empty row. The Users detail page (`users/show.blade.php`) is untouched.

**Tech Stack:** Laravel 8.75, PHP 7.4.33 (no PHP 8 syntax — no nullsafe `?->`, no arrow-fn edge cases beyond what's already used elsewhere in this codebase), Blade, PHPUnit + `RefreshDatabase`, MySQL 8.0 (`bengkel_testing` for tests).

## Global Constraints

- PHP runtime is 7.4.33 — never use PHP 8-only syntax (nullsafe `?->`, named arguments, match expressions, enums, constructor property promotion, union types), including inside Blade `@php()` blocks.
- Every list/index endpoint uses `->simplePaginate()`, never `->paginate()`.
- Search input must be sanitized once in the controller: `is_string(request('q')) ? trim(request('q')) : null`, passed to the view via `->with('search', $search)` — never re-read `request('q')` raw in the Blade view (this is the `?q[]=x` array-crash bug class; not applicable here since Users has no pre-existing search to regress, but the pattern must still be followed correctly from the start).
- LIKE-query values must be escaped with `addcslashes($q, '%_\\')` before interpolation into `'like', "%{$q}%"'`.
- Branch multi-select filter values must be sanitized via `collect(request('branch_ids', []))->map(fn ($id) => (int) $id)->intersect(auth()->user()->branches->pluck('id'))->values()->all()` — silently drops branch IDs the acting user isn't assigned to.
- `User::branches()` (`app/Models/User.php:55-60`) already scopes to active pivot rows (`wherePivot('is_active', true)`) — do NOT add a redundant `where('is_active', true)` inside the `whereHas('branches', ...)` closure (unlike `mechanicBranches`/`customerBranches`, which are pivot-model relations without that built-in scope).
- Reference column as `branches.id` (not bare `id`) inside the `whereHas('branches', ...)` closure to avoid ambiguity with `users.id` in the generated join.

---

### Task 1: Users — real search + branch filter + list-filter-bar + empty-state

**Files:**
- Modify: `app/Http/Controllers/UserController.php:14-21` (the `index()` method)
- Modify: `resources/views/users/index.blade.php` (full file)
- Test: `tests/Feature/UserManagementTest.php` (append new test methods)

**Interfaces:**
- Consumes: `partials.list-filter-bar` (params: `searchPlaceholder`, `searchValue`, `branchFilterBranches`, `branchFilterSelected`, `actionsHtml`), `partials.empty-state` (params: `icon`, `title`, `description`, `ctaRoute`, `ctaLabel`, `ctaPermission`) — both already exist and are unchanged by this task.
- Produces: nothing consumed by later tasks — this is the last task in the plan.

- [ ] **Step 1: Write the failing tests**

Append these methods to `tests/Feature/UserManagementTest.php` (add `use App\Models\Branch;` and `use App\Services\UserBranchService;` to the existing `use` block at the top of the file):

```php
    public function test_index_search_by_name_filters_results(): void
    {
        User::factory()->create(['name' => 'Agus Setiawan']);
        User::factory()->create(['name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users?q=Agus');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }

    public function test_index_search_by_username_filters_results(): void
    {
        User::factory()->create(['name' => 'Agus Setiawan', 'username' => 'agus_setiawan']);
        User::factory()->create(['name' => 'Budi Santoso', 'username' => 'budi_santoso']);
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users?q=agus_setiawan');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        User::factory()->create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users?q[]=Agus');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
    }

    public function test_index_branch_filter_scopes_to_selected_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $targetA = User::factory()->create(['name' => 'Agus Setiawan']);
        $targetB = User::factory()->create(['name' => 'Budi Santoso']);
        $admin = $this->userWithPermissions(['user.view']);
        (new UserBranchService())->assign($targetA, $branchA);
        (new UserBranchService())->assign($targetB, $branchB);
        (new UserBranchService())->assign($admin, $branchA);
        (new UserBranchService())->assign($admin, $branchB);

        $response = $this->actingAs(User::find($admin->id))->get("/users?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }

    public function test_index_branch_filter_drops_branch_ids_the_user_is_not_assigned_to(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $targetA = User::factory()->create(['name' => 'Agus Setiawan']);
        $targetB = User::factory()->create(['name' => 'Budi Santoso']);
        $admin = $this->userWithPermissions(['user.view']);
        (new UserBranchService())->assign($targetA, $branchA);
        (new UserBranchService())->assign($targetB, $branchB);
        (new UserBranchService())->assign($admin, $branchA);

        $response = $this->actingAs(User::find($admin->id))->get("/users?branch_ids[]={$branchB->id}");

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertSee('Budi Santoso');
    }

    public function test_index_shows_empty_state_when_no_users_match(): void
    {
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users?q=NoSuchUserXYZ');

        $response->assertOk();
        $response->assertSee('Belum ada user');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['user.view', 'user.create']);

        $response = $this->actingAs($user)->get('/users?q=NoSuchUserXYZ');

        $response->assertOk();
        $response->assertSee('Tambah User Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users?q=NoSuchUserXYZ');

        $response->assertOk();
        $response->assertDontSee('Tambah User Pertama');
    }

    public function test_index_renders_filter_bar(): void
    {
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari nama atau username...');
    }
```

Note: `test_index_shows_empty_state_when_no_users_match` and the two CTA tests use `?q=NoSuchUserXYZ` rather than relying on an empty database, because `test_index_lists_users_for_authorized_user` (already in this file) creates a user with no search filter — an empty-database scenario isn't guaranteed across the whole test file, so filtering to a guaranteed-zero-result search string is the reliable way to trigger the empty state.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=UserManagementTest`
Expected: the 9 new tests FAIL (search/branch filter/empty-state/filter-bar behavior not yet implemented — the search tests will fail because `q` is currently ignored, the branch filter tests will fail similarly, and the empty-state/filter-bar tests will fail because the view doesn't render `partials.list-filter-bar`/`partials.empty-state` yet). The pre-existing tests in the file should still PASS.

- [ ] **Step 3: Implement the controller**

Replace `app/Http/Controllers/UserController.php:14-21`:

```php
    public function index()
    {
        $this->authorize('user.view');

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect(auth()->user()->branches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $users = User::orderBy('name')
            ->when($search, function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('username', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            ->when($branchIds, fn ($query) => $query->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds)))
            ->simplePaginate(15)
            ->withQueryString();

        $userBranches = auth()->user()->branches;

        return view('users.index', compact('users'))
            ->with('branches', $userBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }
```

- [ ] **Step 4: Implement the view retrofit**

Replace the full contents of `resources/views/users/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Users')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-people me-2"></i>Users</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nama atau username...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => auth()->user()->can('user.create')
            ? '<a href="' . route('users.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah User</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Cabang Default</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td><code>{{ $user->username }}</code></td>
                            <td>{{ optional($user->defaultBranch())->name ?? '-' }}</td>
                            <td>
                                @if ($user->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('users.show', $user) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-gear"></i> Kelola
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-people',
                                    'title' => 'Belum ada user',
                                    'description' => 'Mulai dengan menambahkan user pertama.',
                                    'ctaRoute' => 'users.create',
                                    'ctaLabel' => '+ Tambah User Pertama',
                                    'ctaPermission' => 'user.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $users->links() }}
    </div>
@endsection
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=UserManagementTest`
Expected: all tests in the file PASS (the 9 new tests plus the pre-existing ones).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (256 pre-existing + 9 new = 265).

Before declaring this clean, grep the two new user-facing strings against the app-shell/dashboard text-collision-prone test files, per this track's established practice:

Run: `grep -rn "Belum ada user\|Cari nama atau username" tests/Feature/AppShellTest.php tests/Feature/DashboardTest.php`
Expected: no matches (these strings are Users-page-specific and don't appear in sidebar/dashboard assertions). If a match is found, use the existing mitigation — assert on a unique icon class (e.g. `bi-people` is already used elsewhere for the sidebar menu label "Users", so check that assertion specifically) instead of the bare string, in whichever test is affected.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/UserController.php resources/views/users/index.blade.php tests/Feature/UserManagementTest.php
git commit -m "feat: add real search and branch filter to Users list, retrofit to list-filter-bar/empty-state"
```

---

## Self-Review Notes

- **Spec coverage:** the spec's single in-scope item (Users list retrofit: search + branch filter + empty-state) is fully covered by Task 1. The explicit out-of-scope item (Users detail/show page) is untouched by this plan — no task modifies `users/show.blade.php` or `UserController::show()`/`update()`.
- **Placeholder scan:** none found — all code blocks are complete and copy-ready.
- **Type consistency:** `User::branches()` (verified at `app/Models/User.php:55-60`) already exists and matches the shape assumed in the spec and this plan; `UserBranchService::assign(User $user, Branch $branch, bool $makeDefault = false)` (verified at `app/Services/UserBranchService.php:12`) matches the test usage above.
- **Scope check:** single task is appropriately sized — this is a mechanical, sixth repetition of an established pattern with no new architectural wrinkle (unlike Master Sparepart's branch-switcher/empty-state extension in group 2, which needed two tasks).
