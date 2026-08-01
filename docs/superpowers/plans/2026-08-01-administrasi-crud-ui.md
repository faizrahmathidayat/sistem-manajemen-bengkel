# Administrasi CRUD UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task (inline execution — this plan is small/medium and touches only already-tested backend, per the project's token-budget process preference; do not use subagent-driven-development for this plan). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Foundation-phase backend (branches, users, branch assignment, permission assignment) a working, elegant, responsive Bootstrap 5 UI, and replace the current placeholder navbar-only layout with a real sidebar+topbar app shell that future modules build on.

**Architecture:** Standard Laravel MVC — plain Blade controllers/views, no new tables. Two list+form screens (Branches, Users) plus a tabbed User detail page (Profil / Cabang / Permission) where the Cabang and Permission tabs save via small fetch()-based AJAX calls (checkbox-per-row, no page-wide save button) against dedicated JSON endpoints.

**Tech Stack:** Laravel 8 (existing), Blade, Bootstrap 5.3.3 (CDN, already in use), Bootstrap Icons 1.11 (CDN, new), vanilla `fetch()` (no new JS dependency).

Design spec: `docs/superpowers/specs/2026-08-01-administrasi-crud-ui-design.md`.

## Global Constraints

- No new migrations/tables — every task reuses Foundation-phase models (`Branch`, `User`, `UserBranch`, `UserBranchService`, `Permission`, `Menu`, `UserPermission`, `AuthorizesByPermission`).
- Every list endpoint uses `->simplePaginate()`, never `->paginate()`.
- No hard delete anywhere — branches/users use `is_active` toggles only.
- Authorization: every controller action calls `$this->authorize('<permission.code>')` (no new permission codes — reuse `branch.view/create/edit`, `user.view/create/edit`, `user_branch.manage`, `user_permission.manage`, all already seeded by `MenuPermissionSeeder`).
- UI: Bootstrap 5 via CDN, mobile-first/responsive, sidebar shows only functional menu groups (Master Data → Cabang, Administrasi → Users) — no disabled/placeholder entries for unbuilt modules.
- TDD throughout: write the failing test first, confirm the failure reason, implement, confirm green — same rigor as the Foundation phase, executed inline (no subagent dispatch) per the project's token-budget process preference.
- Self-lockout guards: a user can never deactivate their own account via this UI (even via a crafted request bypassing the disabled checkbox); a user can never revoke `user_permission.manage` from their own account if doing so would leave zero other active users holding it. Revoking a *different* user's `user_permission.manage` is always allowed, regardless of remaining holders.

---

### Task 1: App shell — sidebar + topbar

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Create: `resources/views/partials/sidebar.blade.php`
- Test: `tests/Feature/AppShellTest.php`

**Interfaces:**
- Produces: `layouts.app` now includes `@stack('scripts')` before `</body>` (later tasks push AJAX `<script>` blocks via `@push('scripts')`), and Bootstrap Icons CDN CSS is loaded in `<head>`. `partials.sidebar` reads `auth()->user()` and gates each link with `@can('<code>')` — no new authorization mechanism.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_hides_links_the_user_has_no_permission_for(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Cabang');
        $response->assertDontSee('Users');
    }

    public function test_sidebar_shows_cabang_link_when_user_has_branch_view_permission(): void
    {
        $permission = Permission::create([
            'code' => 'branch.view',
            'resource' => 'branch',
            'action' => 'view',
            'description' => 'Melihat cabang',
        ]);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Cabang');
        $response->assertDontSee('Users');
    }

    public function test_sidebar_shows_users_link_when_user_has_user_view_permission(): void
    {
        $permission = Permission::create([
            'code' => 'user.view',
            'resource' => 'user',
            'action' => 'view',
            'description' => 'Melihat user',
        ]);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Users');
        $response->assertDontSee('Cabang');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AppShellTest`
Expected: FAIL — `resources/views/partials/sidebar.blade.php` doesn't exist yet, or the assertions about missing "Cabang"/"Users" text pass vacuously while the shell itself doesn't render a sidebar at all (the current layout has no sidebar markup).

- [ ] **Step 3: Rewrite `resources/views/layouts/app.blade.php`**

```blade
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sistem Manajemen Bengkel')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f5f7fa; }
        #sidebar { width: 260px; }
        #sidebar .nav-link { color: rgba(255,255,255,.75); }
        #sidebar .nav-link.active, #sidebar .nav-link:hover { color: #fff; background-color: rgba(255,255,255,.08); border-radius: .375rem; }
        #sidebar .sidebar-heading { color: rgba(255,255,255,.5); font-size: .75rem; letter-spacing: .05em; }
        @media (min-width: 992px) {
            .app-main { margin-left: 260px; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark px-3 d-flex align-items-center">
        <button class="btn btn-outline-light d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar">
            <i class="bi bi-list"></i>
        </button>
        <a class="navbar-brand" href="{{ route('dashboard') }}">
            <i class="bi bi-tools me-1"></i> Sistem Manajemen Bengkel
        </a>
        <div class="ms-auto d-flex align-items-center">
            @auth
                <span class="text-light small me-3 d-none d-sm-inline">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </form>
            @endauth
        </div>
    </nav>

    <div class="offcanvas-lg offcanvas-start bg-dark text-white" tabindex="-1" id="sidebar">
        <div class="offcanvas-header d-lg-none">
            <h5 class="offcanvas-title">Menu</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebar"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column p-3">
            @include('partials.sidebar')
        </div>
    </div>

    <main class="app-main py-4 px-3 px-lg-4">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @yield('content')
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
```

- [ ] **Step 4: Create `resources/views/partials/sidebar.blade.php`**

```blade
@php($user = auth()->user())

@if ($user && $user->can('branch.view'))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Master Data</div>
    <ul class="nav nav-pills flex-column mb-3">
        <li class="nav-item">
            <a href="{{ route('branches.index') }}" class="nav-link {{ request()->routeIs('branches.*') ? 'active' : '' }}">
                <i class="bi bi-shop me-2"></i> Cabang
            </a>
        </li>
    </ul>
@endif

@if ($user && $user->can('user.view'))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Administrasi</div>
    <ul class="nav nav-pills flex-column mb-3">
        <li class="nav-item">
            <a href="{{ route('users.index') }}" class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                <i class="bi bi-people me-2"></i> Users
            </a>
        </li>
    </ul>
@endif
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AppShellTest`
Expected: PASS (3 tests). Also run `php artisan test` for the full suite to confirm no regressions in the existing `AuthenticationTest`/`PermissionAuthorizationTest` (the dashboard view now renders more markup, but nothing they assert on changes).

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/views/partials/sidebar.blade.php tests/Feature/AppShellTest.php
git commit -m "feat: add sidebar app shell with permission-gated navigation"
```

---

### Task 2: Branches CRUD

**Files:**
- Create: `app/Http/Controllers/BranchController.php`
- Create: `resources/views/branches/_form.blade.php`
- Create: `resources/views/branches/index.blade.php`
- Create: `resources/views/branches/create.blade.php`
- Create: `resources/views/branches/edit.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/BranchManagementTest.php`

**Interfaces:**
- Consumes: `App\Models\Branch` (from Task 1 of the Foundation plan — `code`, `name`, `address`, `phone`, `email`, `is_active` fillable, `is_active` defaults `true`).
- Produces: named routes `branches.index`, `branches.create`, `branches.store`, `branches.edit`, `branches.update` — Task 1's sidebar links to `branches.index`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermissions(array $codes): User
    {
        $user = User::factory()->create();

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $code]
            );
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        return User::find($user->id);
    }

    public function test_index_lists_branches_for_authorized_user(): void
    {
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = $this->userWithPermissions(['branch.view']);

        $response = $this->actingAs($user)->get('/branches');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/branches');

        $response->assertForbidden();
    }

    public function test_store_creates_branch(): void
    {
        $user = $this->userWithPermissions(['branch.create']);

        $response = $this->actingAs($user)->post('/branches', [
            'code' => 'BDG',
            'name' => 'Cabang Bandung',
            'address' => 'Jl. Asia Afrika',
            'phone' => '022123456',
            'email' => 'bandung@bengkel.test',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/branches');
        $this->assertDatabaseHas('branches', ['code' => 'BDG', 'name' => 'Cabang Bandung']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithPermissions(['branch.create']);

        $response = $this->actingAs($user)->post('/branches', []);

        $response->assertSessionHasErrors(['code', 'name']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/branches', [
            'code' => 'BDG',
            'name' => 'Cabang Bandung',
        ]);

        $response->assertForbidden();
    }

    public function test_update_edits_branch_and_can_deactivate(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = $this->userWithPermissions(['branch.edit']);

        $response = $this->actingAs($user)->put("/branches/{$branch->id}", [
            'code' => 'JKT',
            'name' => 'Cabang Jakarta Pusat',
            'is_active' => '0',
        ]);

        $response->assertRedirect('/branches');
        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'name' => 'Cabang Jakarta Pusat',
            'is_active' => false,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BranchManagementTest`
Expected: FAIL — route `branches.index` etc. don't exist (`RouteNotFoundException` or 404).

- [ ] **Step 3: Create `app/Http/Controllers/BranchController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index()
    {
        $this->authorize('branch.view');

        $branches = Branch::orderBy('name')->simplePaginate(15);

        return view('branches.index', compact('branches'));
    }

    public function create()
    {
        $this->authorize('branch.create');

        $branch = new Branch();

        return view('branches.create', compact('branch'));
    }

    public function store(Request $request)
    {
        $this->authorize('branch.create');

        $data = $this->validateData($request);
        $data['is_active'] = $request->boolean('is_active');

        Branch::create($data);

        return redirect()->route('branches.index')->with('status', 'Cabang berhasil ditambahkan.');
    }

    public function edit(Branch $branch)
    {
        $this->authorize('branch.edit');

        return view('branches.edit', compact('branch'));
    }

    public function update(Request $request, Branch $branch)
    {
        $this->authorize('branch.edit');

        $data = $this->validateData($request, $branch);
        $data['is_active'] = $request->boolean('is_active');

        $branch->update($data);

        return redirect()->route('branches.index')->with('status', 'Cabang berhasil diperbarui.');
    }

    protected function validateData(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:branches,code,' . optional($branch)->id],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
    }
}
```

- [ ] **Step 4: Create `resources/views/branches/_form.blade.php`**

```blade
@csrf
@isset($method)
    @method($method)
@endisset

<div class="mb-3">
    <label for="code" class="form-label">Kode Cabang</label>
    <input type="text" name="code" id="code" value="{{ old('code', $branch->code) }}" class="form-control @error('code') is-invalid @enderror" maxlength="30" required>
    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="name" class="form-label">Nama Cabang</label>
    <input type="text" name="name" id="name" value="{{ old('name', $branch->name) }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="address" class="form-label">Alamat</label>
    <textarea name="address" id="address" class="form-control @error('address') is-invalid @enderror" rows="2">{{ old('address', $branch->address) }}</textarea>
    @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="phone" class="form-label">Telepon</label>
        <input type="text" name="phone" id="phone" value="{{ old('phone', $branch->phone) }}" class="form-control @error('phone') is-invalid @enderror" maxlength="50">
        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label for="email" class="form-label">Email</label>
        <input type="email" name="email" id="email" value="{{ old('email', $branch->email) }}" class="form-control @error('email') is-invalid @enderror" maxlength="255">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="form-check form-switch mb-4">
    <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $branch->exists ? $branch->is_active : true) ? 'checked' : '' }}>
    <label for="is_active" class="form-check-label">Aktif</label>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
<a href="{{ route('branches.index') }}" class="btn btn-outline-secondary">Batal</a>
```

- [ ] **Step 5: Create `resources/views/branches/index.blade.php`**

```blade
@extends('layouts.app')
@section('title', 'Cabang')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-shop me-2"></i>Cabang</h1>
        @can('branch.create')
            <a href="{{ route('branches.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Tambah Cabang
            </a>
        @endcan
    </div>

    <div class="card shadow-sm">
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
                            <td>{{ $branch->code }}</td>
                            <td>{{ $branch->name }}</td>
                            <td>{{ $branch->phone ?? '-' }}</td>
                            <td>
                                @if ($branch->is_active)
                                    <span class="badge bg-success">Aktif</span>
                                @else
                                    <span class="badge bg-secondary">Nonaktif</span>
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
                        <tr><td colspan="5" class="text-center text-muted py-4">Belum ada cabang.</td></tr>
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

- [ ] **Step 6: Create `resources/views/branches/create.blade.php`**

```blade
@extends('layouts.app')
@section('title', 'Tambah Cabang')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-shop me-2"></i>Tambah Cabang</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('branches.store') }}">
                @include('branches._form')
            </form>
        </div>
    </div>
@endsection
```

- [ ] **Step 7: Create `resources/views/branches/edit.blade.php`**

```blade
@extends('layouts.app')
@section('title', 'Ubah Cabang')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-shop me-2"></i>Ubah Cabang</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('branches.update', $branch) }}">
                @php($method = 'PUT')
                @include('branches._form')
            </form>
        </div>
    </div>
@endsection
```

- [ ] **Step 8: Add routes — modify `routes/web.php`**

Add `use App\Http\Controllers\BranchController;` near the top with the other `use` statements, and add these lines inside the existing `Route::middleware(['auth'])->group(function () { ... })` block (after the `/dashboard` route):

```php
    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::get('/branches/create', [BranchController::class, 'create'])->name('branches.create');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
    Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
```

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test --filter=BranchManagementTest`
Expected: PASS (6 tests). Then `php artisan test` for the full suite.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/BranchController.php resources/views/branches routes/web.php tests/Feature/BranchManagementTest.php
git commit -m "feat: add Branches CRUD screens"
```

---

### Task 3: Users — list, create, detail shell, Profil tab

**Files:**
- Create: `app/Http/Controllers/UserController.php`
- Create: `resources/views/users/index.blade.php`
- Create: `resources/views/users/create.blade.php`
- Create: `resources/views/users/show.blade.php`
- Create: `resources/views/users/_tab_profil.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/UserManagementTest.php`

**Interfaces:**
- Consumes: `App\Models\User` (`username`, `name`, `password`, `is_active` fillable; `defaultBranch()` from the Foundation plan).
- Produces: named routes `users.index`, `users.create`, `users.store`, `users.show`, `users.update` — Task 1's sidebar links to `users.index`. `users.show` renders `users/show.blade.php`, which Tasks 4 and 5 will each modify to add one more tab. **Design decision (refines the design spec's wording):** self-deactivation is guarded by never reading `is_active` from the request when the target is the acting user (the field is silently ignored, not validated-and-rejected) — this is simpler and avoids blocking a self-editing user from saving their name/username just because their own `is_active` checkbox is disabled and therefore absent from the request. The outcome — a user's own account can never end up deactivated through this UI, even via a crafted request — is unchanged from the spec's intent.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermissions(array $codes): User
    {
        $user = User::factory()->create();

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $code]
            );
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        return User::find($user->id);
    }

    public function test_index_lists_users_for_authorized_user(): void
    {
        User::factory()->create(['name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users');

        $response->assertOk();
        $response->assertSee('Budi Santoso');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/users');

        $response->assertForbidden();
    }

    public function test_store_creates_user_with_hashed_password(): void
    {
        $user = $this->userWithPermissions(['user.create']);

        $response = $this->actingAs($user)->post('/users', [
            'name' => 'Romi Ramdani',
            'username' => 'romi_ramdani',
            'password' => 'romi_ramdani',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/users');
        $created = User::where('username', 'romi_ramdani')->firstOrFail();
        $this->assertTrue(Hash::check('romi_ramdani', $created->password));
        $this->assertTrue($created->is_active);
    }

    public function test_store_validates_required_and_unique_username(): void
    {
        User::factory()->create(['username' => 'existing']);
        $user = $this->userWithPermissions(['user.create']);

        $response = $this->actingAs($user)->post('/users', [
            'name' => 'Duplikat',
            'username' => 'existing',
            'password' => 'secret123',
        ]);

        $response->assertSessionHasErrors(['username']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/users', [
            'name' => 'X',
            'username' => 'x_user',
            'password' => 'secret123',
        ]);

        $response->assertForbidden();
    }

    public function test_show_renders_profil_tab_for_authorized_user(): void
    {
        $target = User::factory()->create(['name' => 'Syilawati']);
        $user = $this->userWithPermissions(['user.view', 'user.edit']);

        $response = $this->actingAs($user)->get("/users/{$target->id}");

        $response->assertOk();
        $response->assertSee('Syilawati');
    }

    public function test_update_edits_name_username_and_optionally_password(): void
    {
        $target = User::factory()->create(['name' => 'Old Name', 'username' => 'old_username']);
        $user = $this->userWithPermissions(['user.view', 'user.edit']);

        $response = $this->actingAs($user)->put("/users/{$target->id}", [
            'name' => 'New Name',
            'username' => 'new_username',
            'password' => 'newpassword',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('users.show', $target));
        $target->refresh();
        $this->assertSame('New Name', $target->name);
        $this->assertSame('new_username', $target->username);
        $this->assertTrue(Hash::check('newpassword', $target->password));
    }

    public function test_update_keeps_password_unchanged_when_left_blank(): void
    {
        $target = User::factory()->create();
        $originalHash = $target->password;
        $user = $this->userWithPermissions(['user.view', 'user.edit']);

        $this->actingAs($user)->put("/users/{$target->id}", [
            'name' => $target->name,
            'username' => $target->username,
            'password' => '',
            'is_active' => '1',
        ]);

        $target->refresh();
        $this->assertSame($originalHash, $target->password);
    }

    public function test_update_can_deactivate_a_different_user(): void
    {
        $target = User::factory()->create(['is_active' => true]);
        $user = $this->userWithPermissions(['user.view', 'user.edit']);

        $this->actingAs($user)->put("/users/{$target->id}", [
            'name' => $target->name,
            'username' => $target->username,
            'is_active' => '0',
        ]);

        $target->refresh();
        $this->assertFalse($target->is_active);
    }

    public function test_user_cannot_deactivate_own_account_even_via_crafted_request(): void
    {
        $user = $this->userWithPermissions(['user.view', 'user.edit']);

        $response = $this->actingAs($user)->put("/users/{$user->id}", [
            'name' => $user->name,
            'username' => $user->username,
            'is_active' => '0',
        ]);

        $response->assertRedirect(route('users.show', $user));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
    }

    public function test_update_is_forbidden_without_permission(): void
    {
        $target = User::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put("/users/{$target->id}", [
            'name' => 'X',
            'username' => $target->username,
        ]);

        $response->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserManagementTest`
Expected: FAIL — routes `users.index` etc. don't exist.

- [ ] **Step 3: Create `app/Http/Controllers/UserController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $this->authorize('user.view');

        $users = User::orderBy('name')->simplePaginate(15);

        return view('users.index', compact('users'));
    }

    public function create()
    {
        $this->authorize('user.create');

        return view('users.create');
    }

    public function store(Request $request)
    {
        $this->authorize('user.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'password' => Hash::make($data['password']),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('users.index')->with('status', 'User berhasil ditambahkan.');
    }

    public function show(User $user)
    {
        $this->authorize('user.view');

        $user->load('userBranches');
        $allBranches = Branch::orderBy('name')->get();
        $menus = Menu::with(['permissions' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($menu) => $menu->permissions->isNotEmpty());
        $grantedPermissionIds = $user->userPermissions()->pluck('permission_id')->all();

        return view('users.show', compact('user', 'allBranches', 'menus', 'grantedPermissionIds'));
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('user.edit');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6'],
        ]);

        $user->name = $data['name'];
        $user->username = $data['username'];

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->is_active = $user->id === $request->user()->id
            ? true
            : $request->boolean('is_active');

        $user->save();

        return redirect()->route('users.show', $user)->with('status', 'User berhasil diperbarui.');
    }
}
```

Note: `show()` already fetches `$allBranches`, `$menus`, and `$grantedPermissionIds` even though this task's version of `users/show.blade.php` only renders the Profil tab — Tasks 4 and 5 consume these same variables when they add the Cabang and Permission tabs, so the controller doesn't need to change again later. `$menus` filters out any menu with zero *active* permissions so an empty accordion panel can never render.

- [ ] **Step 4: Create `resources/views/users/_tab_profil.blade.php`**

```blade
<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ route('users.update', $user) }}">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="name" class="form-label">Nama Lengkap</label>
                <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" name="username" id="username" value="{{ old('username', $user->username) }}" class="form-control @error('username') is-invalid @enderror" maxlength="100" required>
                @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password Baru</label>
                <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" placeholder="Kosongkan jika tidak ingin mengubah">
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-check form-switch mb-4">
                <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input"
                    {{ old('is_active', $user->is_active) ? 'checked' : '' }}
                    {{ $user->id === auth()->id() ? 'disabled' : '' }}>
                <label for="is_active" class="form-check-label">Aktif</label>
                @if ($user->id === auth()->id())
                    <div class="form-text">Anda tidak dapat menonaktifkan akun sendiri.</div>
                @endif
            </div>

            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </form>
    </div>
</div>
```

- [ ] **Step 5: Create `resources/views/users/show.blade.php`**

```blade
@extends('layouts.app')
@section('title', 'Detail User')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-gear me-2"></i>{{ $user->name }}</h1>
        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Kembali
        </a>
    </div>

    @include('users._tab_profil')
@endsection
```

- [ ] **Step 6: Create `resources/views/users/index.blade.php`**

```blade
@extends('layouts.app')
@section('title', 'Users')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-people me-2"></i>Users</h1>
        @can('user.create')
            <a href="{{ route('users.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Tambah User
            </a>
        @endcan
    </div>

    <div class="card shadow-sm">
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
                            <td>{{ $user->username }}</td>
                            <td>{{ optional($user->defaultBranch())->name ?? '-' }}</td>
                            <td>
                                @if ($user->is_active)
                                    <span class="badge bg-success">Aktif</span>
                                @else
                                    <span class="badge bg-secondary">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('users.show', $user) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-gear"></i> Kelola
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Belum ada user.</td></tr>
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

- [ ] **Step 7: Create `resources/views/users/create.blade.php`**

```blade
@extends('layouts.app')
@section('title', 'Tambah User')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-plus me-2"></i>Tambah User</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('users.store') }}">
                @csrf

                <div class="mb-3">
                    <label for="name" class="form-label">Nama Lengkap</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" name="username" id="username" value="{{ old('username') }}" class="form-control @error('username') is-invalid @enderror" maxlength="100" required>
                    @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" required>
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-check form-switch mb-4">
                    <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" checked>
                    <label for="is_active" class="form-check-label">Aktif</label>
                </div>

                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
                <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Batal</a>
            </form>
        </div>
    </div>
@endsection
```

- [ ] **Step 8: Add routes — modify `routes/web.php`**

Add `use App\Http\Controllers\UserController;` near the top, and inside the `Route::middleware(['auth'])->group(...)` block:

```php
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
```

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test --filter=UserManagementTest`
Expected: PASS (11 tests). Then `php artisan test` for the full suite.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/UserController.php resources/views/users routes/web.php tests/Feature/UserManagementTest.php
git commit -m "feat: add Users list, create, and Profil tab"
```

---

### Task 4: Cabang tab (branch assignment, AJAX)

**Files:**
- Create: `app/Http/Controllers/UserBranchAssignmentController.php`
- Create: `resources/views/users/_tab_cabang.blade.php`
- Modify: `resources/views/users/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/UserBranchTabTest.php`

**Interfaces:**
- Consumes: `App\Services\UserBranchService::assign(User $user, Branch $branch, bool $makeDefault = false): UserBranch` and `::setDefault(User $user, Branch $branch): void` (Foundation plan, Task 3); `User::hasAccessToBranch(int $branchId): bool` (same task).
- Produces: named routes `users.branches.store` (POST), `users.branches.destroy` (DELETE), `users.branches.setDefault` (PUT), each returning JSON `{message: string}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranch;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserBranchTabTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermissions(array $codes): User
    {
        $user = User::factory()->create();

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $code]
            );
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        return User::find($user->id);
    }

    public function test_assigning_a_branch_creates_active_link(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $admin = $this->userWithPermissions(['user_branch.manage']);

        $response = $this->actingAs($admin)->postJson("/users/{$target->id}/branches/{$branch->id}");

        $response->assertOk();
        $this->assertDatabaseHas('user_branches', [
            'user_id' => $target->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    public function test_unassigning_a_branch_deactivates_the_link(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        UserBranch::create(['user_id' => $target->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $admin = $this->userWithPermissions(['user_branch.manage']);

        $response = $this->actingAs($admin)->deleteJson("/users/{$target->id}/branches/{$branch->id}");

        $response->assertOk();
        $this->assertDatabaseHas('user_branches', [
            'user_id' => $target->id,
            'branch_id' => $branch->id,
            'is_active' => false,
        ]);
    }

    public function test_setting_default_branch_requires_existing_access(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $admin = $this->userWithPermissions(['user_branch.manage']);

        $response = $this->actingAs($admin)->putJson("/users/{$target->id}/branches/{$branch->id}/default");

        $response->assertStatus(422);
    }

    public function test_setting_default_branch_succeeds_when_assigned(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        UserBranch::create(['user_id' => $target->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $admin = $this->userWithPermissions(['user_branch.manage']);

        $response = $this->actingAs($admin)->putJson("/users/{$target->id}/branches/{$branch->id}/default");

        $response->assertOk();
        $this->assertDatabaseHas('user_branches', [
            'user_id' => $target->id,
            'branch_id' => $branch->id,
            'is_default' => true,
        ]);
    }

    public function test_branch_endpoints_are_forbidden_without_permission(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/users/{$target->id}/branches/{$branch->id}")->assertForbidden();
    }

    public function test_show_page_renders_cabang_tab_when_authorized(): void
    {
        $target = User::factory()->create();
        $admin = $this->userWithPermissions(['user.view', 'user_branch.manage']);

        $response = $this->actingAs($admin)->get("/users/{$target->id}");

        $response->assertOk();
        $response->assertSee('Cabang');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserBranchTabTest`
Expected: FAIL — routes don't exist yet.

- [ ] **Step 3: Create `app/Http/Controllers/UserBranchAssignmentController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserBranch;
use App\Services\UserBranchService;

class UserBranchAssignmentController extends Controller
{
    public function store(User $user, Branch $branch, UserBranchService $service)
    {
        $this->authorize('user_branch.manage');

        $service->assign($user, $branch);

        return response()->json(['message' => 'Cabang berhasil ditambahkan.']);
    }

    public function destroy(User $user, Branch $branch)
    {
        $this->authorize('user_branch.manage');

        UserBranch::where('user_id', $user->id)
            ->where('branch_id', $branch->id)
            ->update(['is_active' => false, 'is_default' => false]);

        return response()->json(['message' => 'Cabang berhasil dihapus dari user.']);
    }

    public function setDefault(User $user, Branch $branch, UserBranchService $service)
    {
        $this->authorize('user_branch.manage');

        if (! $user->hasAccessToBranch($branch->id)) {
            return response()->json(['message' => 'User belum memiliki akses ke cabang ini.'], 422);
        }

        $service->setDefault($user, $branch);

        return response()->json(['message' => 'Cabang default berhasil diubah.']);
    }
}
```

- [ ] **Step 4: Create `resources/views/users/_tab_cabang.blade.php`**

```blade
<div class="card shadow-sm">
    <div class="card-body">
        <p class="text-muted small">Centang cabang yang boleh diakses user ini. Pilih salah satu sebagai cabang default.</p>

        <div id="branch-list">
            @foreach ($allBranches as $branch)
                @php($userBranch = $user->userBranches->firstWhere('branch_id', $branch->id))
                <div class="d-flex align-items-center justify-content-between border-bottom py-2" data-branch-row="{{ $branch->id }}">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input branch-toggle" id="branch-{{ $branch->id }}"
                            data-branch-id="{{ $branch->id }}"
                            {{ $userBranch && $userBranch->is_active ? 'checked' : '' }}>
                        <label class="form-check-label" for="branch-{{ $branch->id }}">{{ $branch->name }}</label>
                    </div>
                    <div class="form-check">
                        <input type="radio" class="form-check-input branch-default" name="default_branch"
                            data-branch-id="{{ $branch->id }}"
                            {{ $userBranch && $userBranch->is_default ? 'checked' : '' }}
                            {{ ! ($userBranch && $userBranch->is_active) ? 'disabled' : '' }}>
                        <label class="form-check-label small text-muted">Default</label>
                    </div>
                </div>
            @endforeach
        </div>

        <div id="branch-feedback" class="small mt-3"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const userId = {{ $user->id }};
    const feedback = document.getElementById('branch-feedback');

    function showFeedback(message, isError) {
        feedback.textContent = message;
        feedback.className = 'small mt-3 ' + (isError ? 'text-danger' : 'text-success');
    }

    async function send(url, method) {
        const response = await fetch(url, {
            method,
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.message || 'Terjadi kesalahan.');
        }
        return data;
    }

    document.querySelectorAll('.branch-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', async function () {
            const branchId = this.dataset.branchId;
            const row = document.querySelector('[data-branch-row="' + branchId + '"]');
            const defaultRadio = row.querySelector('.branch-default');
            try {
                if (this.checked) {
                    const data = await send(`/users/${userId}/branches/${branchId}`, 'POST');
                    defaultRadio.disabled = false;
                    showFeedback(data.message, false);
                } else {
                    const data = await send(`/users/${userId}/branches/${branchId}`, 'DELETE');
                    defaultRadio.disabled = true;
                    defaultRadio.checked = false;
                    showFeedback(data.message, false);
                }
            } catch (error) {
                this.checked = !this.checked;
                showFeedback(error.message, true);
            }
        });
    });

    document.querySelectorAll('.branch-default').forEach(function (radio) {
        radio.addEventListener('change', async function () {
            const branchId = this.dataset.branchId;
            try {
                const data = await send(`/users/${userId}/branches/${branchId}/default`, 'PUT');
                showFeedback(data.message, false);
            } catch (error) {
                this.checked = false;
                showFeedback(error.message, true);
            }
        });
    });
})();
</script>
@endpush
```

- [ ] **Step 5: Modify `resources/views/users/show.blade.php`** (replace the whole file with this tabbed version)

```blade
@extends('layouts.app')
@section('title', 'Detail User')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-gear me-2"></i>{{ $user->name }}</h1>
        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Kembali
        </a>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#profil-pane" type="button" role="tab">
                <i class="bi bi-person me-1"></i> Profil
            </button>
        </li>
        @can('user_branch.manage')
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#cabang-pane" type="button" role="tab">
                <i class="bi bi-shop me-1"></i> Cabang
            </button>
        </li>
        @endcan
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="profil-pane" role="tabpanel">
            @include('users._tab_profil')
        </div>
        @can('user_branch.manage')
        <div class="tab-pane fade" id="cabang-pane" role="tabpanel">
            @include('users._tab_cabang')
        </div>
        @endcan
    </div>
@endsection
```

- [ ] **Step 6: Add routes — modify `routes/web.php`**

Add `use App\Http\Controllers\UserBranchAssignmentController;` near the top, and inside the `Route::middleware(['auth'])->group(...)` block:

```php
    Route::post('/users/{user}/branches/{branch}', [UserBranchAssignmentController::class, 'store'])->name('users.branches.store');
    Route::delete('/users/{user}/branches/{branch}', [UserBranchAssignmentController::class, 'destroy'])->name('users.branches.destroy');
    Route::put('/users/{user}/branches/{branch}/default', [UserBranchAssignmentController::class, 'setDefault'])->name('users.branches.setDefault');
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=UserBranchTabTest`
Expected: PASS (6 tests). Then `php artisan test` for the full suite — `UserManagementTest::test_show_renders_profil_tab_for_authorized_user` must still pass since Profil tab content is unchanged, just re-wrapped.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/UserBranchAssignmentController.php resources/views/users/_tab_cabang.blade.php resources/views/users/show.blade.php routes/web.php tests/Feature/UserBranchTabTest.php
git commit -m "feat: add Cabang tab with AJAX branch assignment"
```

---

### Task 5: Permission tab (permission assignment, AJAX)

**Files:**
- Create: `app/Http/Controllers/UserPermissionAssignmentController.php`
- Create: `resources/views/users/_tab_permission.blade.php`
- Modify: `resources/views/users/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/UserPermissionTabTest.php`

**Interfaces:**
- Consumes: `Menu`, `Permission`, `UserPermission` models; `$menus` and `$grantedPermissionIds` already passed into the `users.show` view by `UserController::show()` (Task 3).
- Produces: named routes `users.permissions.store` (POST), `users.permissions.destroy` (DELETE), each returning JSON `{message: string}`. Self-lockout guard lives in `UserPermissionAssignmentController::wouldStripLastSelfManagePermission()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPermissionTabTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermissions(array $codes): User
    {
        $user = User::factory()->create();

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $code]
            );
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        return User::find($user->id);
    }

    public function test_granting_a_permission_creates_user_permission_with_granter(): void
    {
        $target = User::factory()->create();
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        $admin = $this->userWithPermissions(['user_permission.manage']);

        $response = $this->actingAs($admin)->postJson("/users/{$target->id}/permissions/{$permission->id}");

        $response->assertOk();
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $target->id,
            'permission_id' => $permission->id,
            'granted_by' => $admin->id,
        ]);
    }

    public function test_revoking_a_permission_removes_it(): void
    {
        $target = User::factory()->create();
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        UserPermission::create(['user_id' => $target->id, 'permission_id' => $permission->id]);
        $admin = $this->userWithPermissions(['user_permission.manage']);

        $response = $this->actingAs($admin)->deleteJson("/users/{$target->id}/permissions/{$permission->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $target->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_permission_endpoints_are_forbidden_without_permission(): void
    {
        $target = User::factory()->create();
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/users/{$target->id}/permissions/{$permission->id}")->assertForbidden();
    }

    public function test_cannot_revoke_own_last_user_permission_manage(): void
    {
        $managePermission = Permission::create([
            'code' => 'user_permission.manage',
            'resource' => 'user_permission',
            'action' => 'manage',
            'description' => 'Mengelola permission user',
        ]);
        $admin = User::factory()->create();
        UserPermission::create(['user_id' => $admin->id, 'permission_id' => $managePermission->id]);
        $admin = User::find($admin->id);

        $response = $this->actingAs($admin)->deleteJson("/users/{$admin->id}/permissions/{$managePermission->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $admin->id,
            'permission_id' => $managePermission->id,
        ]);
    }

    public function test_can_revoke_own_user_permission_manage_when_another_active_holder_exists(): void
    {
        $managePermission = Permission::create([
            'code' => 'user_permission.manage',
            'resource' => 'user_permission',
            'action' => 'manage',
            'description' => 'Mengelola permission user',
        ]);
        $otherAdmin = User::factory()->create(['is_active' => true]);
        UserPermission::create(['user_id' => $otherAdmin->id, 'permission_id' => $managePermission->id]);

        $admin = User::factory()->create();
        UserPermission::create(['user_id' => $admin->id, 'permission_id' => $managePermission->id]);
        $admin = User::find($admin->id);

        $response = $this->actingAs($admin)->deleteJson("/users/{$admin->id}/permissions/{$managePermission->id}");

        $response->assertOk();
    }

    public function test_can_revoke_user_permission_manage_from_a_different_user(): void
    {
        $managePermission = Permission::create([
            'code' => 'user_permission.manage',
            'resource' => 'user_permission',
            'action' => 'manage',
            'description' => 'Mengelola permission user',
        ]);
        $target = User::factory()->create();
        UserPermission::create(['user_id' => $target->id, 'permission_id' => $managePermission->id]);

        $admin = $this->userWithPermissions(['user_permission.manage']);

        $response = $this->actingAs($admin)->deleteJson("/users/{$target->id}/permissions/{$managePermission->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $target->id,
            'permission_id' => $managePermission->id,
        ]);
    }

    public function test_show_page_renders_permission_tab_grouped_by_menu(): void
    {
        $menu = Menu::create(['code' => 'test.menu', 'name' => 'Menu Uji', 'sort_order' => 1]);
        Permission::create(['menu_id' => $menu->id, 'code' => 'test.view', 'resource' => 'test', 'action' => 'view', 'description' => 'Lihat Uji']);
        $target = User::factory()->create();
        $admin = $this->userWithPermissions(['user.view', 'user_permission.manage']);

        $response = $this->actingAs($admin)->get("/users/{$target->id}");

        $response->assertOk();
        $response->assertSee('Menu Uji');
        $response->assertSee('Lihat Uji');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserPermissionTabTest`
Expected: FAIL — routes don't exist yet.

- [ ] **Step 3: Create `app/Http/Controllers/UserPermissionAssignmentController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Http\Request;

class UserPermissionAssignmentController extends Controller
{
    public function store(Request $request, User $user, Permission $permission)
    {
        $this->authorize('user_permission.manage');

        UserPermission::firstOrCreate(
            ['user_id' => $user->id, 'permission_id' => $permission->id],
            ['granted_by' => $request->user()->id]
        );

        return response()->json(['message' => 'Permission berhasil diberikan.']);
    }

    public function destroy(Request $request, User $user, Permission $permission)
    {
        $this->authorize('user_permission.manage');

        if ($this->wouldStripLastSelfManagePermission($request->user(), $user, $permission)) {
            return response()->json([
                'message' => 'Tidak dapat mencabut permission ini dari akun sendiri karena tidak akan ada user aktif lain yang memilikinya.',
            ], 422);
        }

        UserPermission::where('user_id', $user->id)
            ->where('permission_id', $permission->id)
            ->delete();

        return response()->json(['message' => 'Permission berhasil dicabut.']);
    }

    protected function wouldStripLastSelfManagePermission(User $actingUser, User $targetUser, Permission $permission): bool
    {
        if ($permission->code !== 'user_permission.manage' || $targetUser->id !== $actingUser->id) {
            return false;
        }

        return ! UserPermission::where('permission_id', $permission->id)
            ->where('user_id', '!=', $targetUser->id)
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->exists();
    }
}
```

- [ ] **Step 4: Create `resources/views/users/_tab_permission.blade.php`**

```blade
<div class="card shadow-sm">
    <div class="card-body">
        <p class="text-muted small">Centang permission yang diberikan ke user ini, dikelompokkan per menu.</p>

        <div class="accordion" id="permissionAccordion">
            @foreach ($menus as $menu)
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#menu-{{ $menu->id }}">
                            {{ $menu->name }}
                            <span class="badge bg-secondary ms-2 menu-count" data-menu-id="{{ $menu->id }}">
                                {{ $menu->permissions->whereIn('id', $grantedPermissionIds)->count() }}/{{ $menu->permissions->count() }}
                            </span>
                        </button>
                    </h2>
                    <div id="menu-{{ $menu->id }}" class="accordion-collapse collapse" data-bs-parent="#permissionAccordion">
                        <div class="accordion-body">
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input menu-select-all" id="menu-all-{{ $menu->id }}" data-menu-id="{{ $menu->id }}">
                                <label class="form-check-label fw-semibold" for="menu-all-{{ $menu->id }}">Pilih semua</label>
                            </div>
                            <hr>
                            @foreach ($menu->permissions as $permission)
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input permission-toggle" id="permission-{{ $permission->id }}"
                                        data-permission-id="{{ $permission->id }}" data-menu-id="{{ $menu->id }}"
                                        {{ in_array($permission->id, $grantedPermissionIds) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="permission-{{ $permission->id }}">
                                        {{ $permission->description }}
                                        <code class="text-muted small">{{ $permission->code }}</code>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div id="permission-feedback" class="small mt-3"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const userId = {{ $user->id }};
    const feedback = document.getElementById('permission-feedback');

    function showFeedback(message, isError) {
        feedback.textContent = message;
        feedback.className = 'small mt-3 ' + (isError ? 'text-danger' : 'text-success');
    }

    function updateMenuCount(menuId) {
        const badge = document.querySelector('.menu-count[data-menu-id="' + menuId + '"]');
        const checkboxes = document.querySelectorAll('.permission-toggle[data-menu-id="' + menuId + '"]');
        const checked = document.querySelectorAll('.permission-toggle[data-menu-id="' + menuId + '"]:checked');
        badge.textContent = checked.length + '/' + checkboxes.length;
    }

    async function send(url, method) {
        const response = await fetch(url, {
            method,
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.message || 'Terjadi kesalahan.');
        }
        return data;
    }

    document.querySelectorAll('.permission-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', async function () {
            const permissionId = this.dataset.permissionId;
            const menuId = this.dataset.menuId;
            try {
                const data = this.checked
                    ? await send(`/users/${userId}/permissions/${permissionId}`, 'POST')
                    : await send(`/users/${userId}/permissions/${permissionId}`, 'DELETE');
                showFeedback(data.message, false);
                updateMenuCount(menuId);
            } catch (error) {
                this.checked = !this.checked;
                showFeedback(error.message, true);
            }
        });
    });

    document.querySelectorAll('.menu-select-all').forEach(function (selectAll) {
        selectAll.addEventListener('change', function () {
            const menuId = this.dataset.menuId;
            document.querySelectorAll('.permission-toggle[data-menu-id="' + menuId + '"]').forEach(function (checkbox) {
                if (checkbox.checked !== selectAll.checked) {
                    checkbox.checked = selectAll.checked;
                    checkbox.dispatchEvent(new Event('change'));
                }
            });
        });
    });
})();
</script>
@endpush
```

- [ ] **Step 5: Modify `resources/views/users/show.blade.php`** (replace the whole file with this three-tab version)

```blade
@extends('layouts.app')
@section('title', 'Detail User')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-gear me-2"></i>{{ $user->name }}</h1>
        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Kembali
        </a>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#profil-pane" type="button" role="tab">
                <i class="bi bi-person me-1"></i> Profil
            </button>
        </li>
        @can('user_branch.manage')
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#cabang-pane" type="button" role="tab">
                <i class="bi bi-shop me-1"></i> Cabang
            </button>
        </li>
        @endcan
        @can('user_permission.manage')
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#permission-pane" type="button" role="tab">
                <i class="bi bi-shield-check me-1"></i> Permission
            </button>
        </li>
        @endcan
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="profil-pane" role="tabpanel">
            @include('users._tab_profil')
        </div>
        @can('user_branch.manage')
        <div class="tab-pane fade" id="cabang-pane" role="tabpanel">
            @include('users._tab_cabang')
        </div>
        @endcan
        @can('user_permission.manage')
        <div class="tab-pane fade" id="permission-pane" role="tabpanel">
            @include('users._tab_permission')
        </div>
        @endcan
    </div>
@endsection
```

- [ ] **Step 6: Add routes — modify `routes/web.php`**

Add `use App\Http\Controllers\UserPermissionAssignmentController;` near the top, and inside the `Route::middleware(['auth'])->group(...)` block:

```php
    Route::post('/users/{user}/permissions/{permission}', [UserPermissionAssignmentController::class, 'store'])->name('users.permissions.store');
    Route::delete('/users/{user}/permissions/{permission}', [UserPermissionAssignmentController::class, 'destroy'])->name('users.permissions.destroy');
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=UserPermissionTabTest`
Expected: PASS (7 tests). Then `php artisan test` for the full suite — every test from every earlier task must still pass.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/UserPermissionAssignmentController.php resources/views/users/_tab_permission.blade.php resources/views/users/show.blade.php routes/web.php tests/Feature/UserPermissionTabTest.php
git commit -m "feat: add Permission tab with AJAX grant/revoke and self-lockout guard"
```

---

## Final manual verification (after Task 5)

- [ ] Start the dev server (`.claude/launch.json` → `php artisan serve --port=8010`) and log in as `faiz_rahmat` (from `DemoUsersSeeder`).
- [ ] Confirm the sidebar shows Cabang and Users (Faiz holds all permissions).
- [ ] Create a branch, edit it, toggle it inactive and back.
- [ ] Open a user's detail page, switch all three tabs, toggle a branch checkbox and a permission checkbox, confirm the inline feedback message appears and the change persists after a page reload.
- [ ] Log in as `romi_ramdani` (no Administrasi permissions) and confirm the sidebar shows neither Cabang nor Users, and `/branches` / `/users` return 403 if visited directly.
- [ ] Resize the browser to a narrow (mobile) width and confirm the sidebar collapses into the offcanvas drawer, toggled by the hamburger button.
