# Sparepart Baru — Branch Selection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the "Sparepart Baru" create form's implicit single-branch assumption (silently uses whatever branch is active in the index page's session switcher, hard `abort(403)`s if that branch lacks `sparepart.create`) with an explicit `<select>` dropdown listing every branch the user actually has `sparepart.create` in.

**Architecture:** `SparepartBranchController::create()` computes the branch list via the existing `User::branchesWithPermission('sparepart.create')` method (already used elsewhere with a different permission code) instead of `resolveCurrentBranch()` + a separate abort check. The view renders a `<select>` in place of the current hidden `branch_id` input, defaulting to the session's current branch when that branch is in the list. `store()` and `StoreSparepartRequest` are untouched — the existing `authorize()` re-validation already covers a dropdown-submitted `branch_id` identically to a hidden-field one.

**Tech Stack:** Laravel 8.75, PHP 7.4.33 (no PHP 8 syntax), Blade, PHPUnit + `RefreshDatabase`, MySQL 8.0 (`bengkel_testing` for tests).

## Global Constraints

- PHP runtime is 7.4.33 — never use PHP 8-only syntax (nullsafe `?->`, named arguments, match expressions, enums, constructor property promotion, union types), including inside Blade `@php()` blocks.
- `store()` and `StoreSparepartRequest` (`app/Http/Requests/StoreSparepartRequest.php`) are unchanged — `authorize()` already does `$this->user()->hasPermissionToInBranch('sparepart.create', $branchId)` against whatever `branch_id` is submitted, which is exactly the same check whether it arrived via a hidden field or a `<select>`.
- After a successful create, `session('current_sparepart_branch_id')` must NOT change, even if the user picked a branch in the dropdown different from the one already active in that session key. This was explicitly confirmed with the user — do not "helpfully" switch the session context.
- `createExisting()` / `resources/views/sparepart-branches/create-existing.blade.php` ("Tambah dari Cabang Lain") is explicitly out of scope — do not touch it. `resolveCurrentBranch()` (used by `createExisting()`) must not be deleted, only left unused by the modified `create()` action.
- `index()`, `storeExisting()`, `edit()`, `update()`, `deactivate()`, `activate()` on `SparepartBranchController` are untouched.

---

### Task 1: Branch-selectable "Sparepart Baru" create form

**Files:**
- Modify: `app/Http/Controllers/SparepartBranchController.php:51-60` (the `create()` method)
- Modify: `resources/views/sparepart-branches/create.blade.php` (full file)
- Test: `tests/Feature/SparepartBranchIndexAndCreateTest.php` (append new test methods; reuses the existing `grantBranchPermission()` protected helper already in this file)

**Interfaces:**
- Consumes: `User::branchesWithPermission(string $code): Collection` (`app/Models/User.php:76-79`, already exists and is generic — returns the subset of `$this->branches` where `hasPermissionToInBranch($code, $branch->id)` is true).
- Produces: nothing consumed by later tasks — this plan has only one task.

- [ ] **Step 1: Write the failing tests**

Append these methods to `tests/Feature/SparepartBranchIndexAndCreateTest.php` (no new `use` imports needed — `Branch`, `User`, and the `grantBranchPermission()` helper are already in this file):

```php
    public function test_create_shows_no_access_page_for_user_without_create_permission_in_any_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs($user)->get('/sparepart-branches/create');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_create_lists_every_branch_the_user_can_create_in(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.create');
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');

        $response = $this->actingAs($user)->get('/sparepart-branches/create');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
        $response->assertSee('Cabang Bandung');
    }

    public function test_create_is_reachable_when_session_branch_lacks_create_permission_but_another_branch_has_it(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        // User has sparepart.view (only) in branch A, so the index page's session
        // switcher parks them on branch A. They have sparepart.create only in branch B.
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');
        $this->actingAs($user)->get('/sparepart-branches'); // establishes session on branch A

        $response = $this->get('/sparepart-branches/create');

        $response->assertOk();
        $response->assertSee('Cabang Bandung');
        $response->assertDontSee('403');
    }

    public function test_create_defaults_select_to_session_branch_when_it_is_a_valid_option(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'sparepart.create');
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');
        $this->actingAs($user)->get('/sparepart-branches?branch_id=' . $branchB->id); // session -> branch B

        $response = $this->get('/sparepart-branches/create');

        $response->assertOk();
        $response->assertSee('<option value="' . $branchB->id . '" selected', false);
    }

    public function test_store_still_rejects_branch_id_the_user_cannot_create_in(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.create');
        // User has no sparepart.create grant in branch B.
        $this->actingAs($user)->get('/sparepart-branches');

        $response = $this->post('/sparepart-branches', [
            'branch_id' => $branchB->id, 'code' => 'BAN-01', 'name' => 'Ban Depan', 'selling_price' => 150000,
        ]);

        $response->assertForbidden();
    }

    public function test_create_does_not_change_session_branch_after_successful_store_into_a_different_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $this->grantBranchPermission($user, $branchA, 'sparepart.create');
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');
        $this->actingAs($user)->get('/sparepart-branches'); // session -> branch A (first allowed)

        $response = $this->post('/sparepart-branches', [
            'branch_id' => $branchB->id,
            'code' => 'BAN-01',
            'name' => 'Ban Depan',
            'selling_price' => 150000,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $this->assertSame($branchA->id, session('current_sparepart_branch_id'), 'Creating into a different branch must not switch the session context.');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest`
Expected: the 6 new tests FAIL. Specifically:
- `test_create_shows_no_access_page_for_user_without_create_permission_in_any_branch` fails because the current `create()` calls `resolveCurrentBranch()` (which uses `sparepart.view`, not `sparepart.create`) and would 403 rather than show the no-access page.
- `test_create_lists_every_branch_the_user_can_create_in` fails because the current view renders a hidden input, not a `<select>` — neither branch name text is guaranteed to appear (the current `<h1>` only shows one branch's name).
- `test_create_is_reachable_when_session_branch_lacks_create_permission_but_another_branch_has_it` fails with a 403 (this is the exact bug this task fixes).
- `test_create_defaults_select_to_session_branch_when_it_is_a_valid_option` fails — no `<select>` exists yet.
- `test_store_still_rejects_branch_id_the_user_cannot_create_in` — this one should already PASS (it exercises unchanged `store()`/`StoreSparepartRequest` behavior); it's included as a pinning regression test, not new behavior.
- `test_create_does_not_change_session_branch_after_successful_store_into_a_different_branch` should already PASS today too, for the same reason — `store()` never touches the session. Confirm both pass already; if either fails, treat it as a genuine surprise and stop to investigate before continuing (don't paper over an unexpected RED on code this task isn't supposed to change).

The pre-existing tests in the file should still PASS.

- [ ] **Step 3: Implement the controller change**

Replace `app/Http/Controllers/SparepartBranchController.php:51-60` (the `create()` method):

```php
    public function create()
    {
        $branches = auth()->user()->branchesWithPermission('sparepart.create');

        if ($branches->isEmpty()) {
            return view('sparepart-branches.no-access');
        }

        $currentBranchId = session('current_sparepart_branch_id');
        $selectedBranch = $branches->firstWhere('id', $currentBranchId) ?? $branches->first();

        return view('sparepart-branches.create', compact('branches', 'selectedBranch'));
    }
```

Do not remove or modify `resolveCurrentBranch()` (`SparepartBranchController.php:155-165`) — it is still used by `createExisting()` (`SparepartBranchController.php:85-101`), which this task does not touch.

- [ ] **Step 4: Implement the view change**

Replace the full contents of `resources/views/sparepart-branches/create.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Sparepart Baru')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i>Sparepart Baru</h1>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('sparepart-branches.store') }}">
                @csrf
                <div class="mb-3">
                    <label for="branch_id" class="form-label">Cabang</label>
                    <select name="branch_id" id="branch_id" class="form-select @error('branch_id') is-invalid @enderror" required>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" {{ (int) old('branch_id', $selectedBranch->id) === $branch->id ? 'selected' : '' }}>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label for="code" class="form-label">Kode Sparepart</label>
                    <input type="text" name="code" id="code" value="{{ old('code') }}" class="form-control @error('code') is-invalid @enderror" maxlength="30" required>
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label for="name" class="form-label">Nama Sparepart</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="rack_number" class="form-label">Rak</label>
                        <input type="text" name="rack_number" id="rack_number" value="{{ old('rack_number') }}" class="form-control @error('rack_number') is-invalid @enderror" maxlength="30">
                        @error('rack_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="selling_price" class="form-label">Harga Jual</label>
                        <input type="number" step="0.01" min="0" name="selling_price" id="selling_price" value="{{ old('selling_price') }}" class="form-control @error('selling_price') is-invalid @enderror" required>
                        @error('selling_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="minimum_stock" class="form-label">Stok Minimum</label>
                        <input type="number" step="0.001" min="0" name="minimum_stock" id="minimum_stock" value="{{ old('minimum_stock', 0) }}" class="form-control @error('minimum_stock') is-invalid @enderror">
                        @error('minimum_stock')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
                <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary">Batal</a>
            </form>
        </div>
    </div>
@endsection
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest`
Expected: all tests in the file PASS (the 6 new tests plus every pre-existing test in this file, including `test_create_new_sparepart_creates_identity_branch_config_and_zeroed_stock` and `test_create_new_sparepart_writes_to_authorized_branch_even_when_view_permission_fallback_differs`, both of which exercise `store()` directly and must be unaffected by the `create()`/view changes).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (265 pre-existing + 6 new = 271).

Grep the new user-facing strings against the text-collision-prone test files, per this project's established practice:

Run: `grep -rn "Cabang Jakarta\|Cabang Bandung" tests/Feature/AppShellTest.php tests/Feature/DashboardTest.php`
Expected: no matches (these are test-fixture branch names created inline in the new tests, not screen copy, so they're unlikely to appear in either file — but confirm before declaring the task clean, per standing project practice).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/SparepartBranchController.php resources/views/sparepart-branches/create.blade.php tests/Feature/SparepartBranchIndexAndCreateTest.php
git commit -m "feat: let Sparepart Baru form select from any branch the user can create in"
```

---

## Self-Review Notes

- **Spec coverage:** the spec's single in-scope item (branch-selectable `create()` form, `createExisting()` untouched, no session-context switch after `store()`) is fully covered by Task 1's controller change, view change, and the 6 new tests (no-access, multi-branch listing, the exact bug-fix regression case, default-selection, and two pinning tests proving `store()`/session behavior is unchanged).
- **Placeholder scan:** none found — all code blocks are complete and copy-ready.
- **Type consistency:** `User::branchesWithPermission(string $code)` (verified at `app/Models/User.php:76-79`) returns a `Collection` of `Branch` models with `->firstWhere('id', ...)` and `->first()` both valid `Collection` methods — matches usage in this task and in the pre-existing `index()`/`resolveCurrentBranch()` callers.
- **Scope check:** single task is appropriately sized — smaller in scope than any prior sub-project in the UI redesign track (one controller method, one view, no new shared partial, no new permission code).
