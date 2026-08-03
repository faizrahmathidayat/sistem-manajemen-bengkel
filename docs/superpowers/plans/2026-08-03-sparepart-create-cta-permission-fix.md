# Sparepart Create-CTA Permission Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix three visibility gates that lead to the "Sparepart Baru" create form — the Dashboard button, the Master Sparepart header link, and the empty-state CTA — so they check whether the user has `sparepart.create` in *any* branch, instead of the wrong permission (`sparepart.view`) or the wrong scope (current-branch-only).

**Architecture:** Three Blade-only permission-check swaps across two view files. No controller, route, or model changes. `auth()->user()->branchesWithPermission('sparepart.create')->isNotEmpty()` replaces the faulty checks; `User::branchesWithPermission()` already exists and is already used elsewhere with `sparepart.view`.

**Tech Stack:** Laravel 8.75, PHP 7.4.33 (no PHP 8 syntax), Blade, PHPUnit + `RefreshDatabase`, MySQL 8.0 (`bengkel_testing` for tests).

## Global Constraints

- PHP runtime is 7.4.33 — never use PHP 8-only syntax (nullsafe `?->`, named arguments, match expressions, enums, constructor property promotion, union types), including inside Blade `@php()` blocks.
- `createExisting()`, `storeExisting()`, `resolveCurrentBranch()`, and `resources/views/sparepart-branches/create-existing.blade.php` are untouched — the "Tambah dari Cabang Lain" link keeps its existing current-branch-only check (`hasPermissionToInBranch('sparepart.create', $currentBranch->id)`), unchanged by this plan.
- `store()`, `StoreSparepartRequest`, and `SparepartBranchController::create()` (the branch-dropdown logic from the prior fix, commit `0229461`) are untouched — this plan only changes the *visibility* of links leading to that already-correct form, nothing about the form or its authorization.
- No new permission codes, no schema changes.

---

### Task 1: Fix Sparepart create-CTA permission gating (Dashboard button, header link, empty-state CTA)

**Files:**
- Modify: `resources/views/dashboard/index.blade.php:13`
- Modify: `resources/views/sparepart-branches/index.blade.php:6-15` (header links) and `:90` (empty-state `ctaVisible`)
- Test: `tests/Feature/SparepartBranchIndexAndCreateTest.php` (modify one existing test, append new tests)
- Test: `tests/Feature/DashboardTest.php` (append new tests)

**Interfaces:**
- Consumes: `User::branchesWithPermission(string $code): Collection` (`app/Models/User.php:76-79`, already exists — returns the subset of `$this->branches` where `hasPermissionToInBranch($code, $branch->id)` is true). This plan's callers pass `'sparepart.create'`, the same method already used elsewhere in the codebase with `'sparepart.view'`.
- Produces: nothing consumed by later tasks — this plan has only one task.

- [ ] **Step 1: Modify the existing test that encodes the old (buggy) behavior**

In `tests/Feature/SparepartBranchIndexAndCreateTest.php`, find this existing test:

```php
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
```

Replace it with (renamed, assertion flipped):

```php
    public function test_empty_state_cta_shown_when_user_has_create_permission_in_a_different_branch(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        // sparepart.create granted only in branch B, not the current branch (A).
        // The CTA must still show, since the create form now lets the user pick branch B.
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches?branch_id=' . $branchA->id);

        $response->assertOk();
        $response->assertSee('Sparepart Baru');
    }
```

- [ ] **Step 2: Write the remaining failing tests**

Append these methods to `tests/Feature/SparepartBranchIndexAndCreateTest.php`:

```php
    public function test_empty_state_cta_hidden_when_user_has_no_create_permission_in_any_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response->assertOk();
        $response->assertDontSee('Sparepart Baru');
    }

    public function test_header_link_shown_when_user_has_create_permission_in_a_different_branch(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches?branch_id=' . $branchA->id);

        $response->assertOk();
        $response->assertSee('Sparepart Baru');
    }

    public function test_header_link_hidden_when_user_has_no_create_permission_in_any_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response->assertOk();
        $response->assertDontSee('Sparepart Baru');
    }

    public function test_tambah_dari_cabang_lain_link_stays_hidden_when_create_permission_is_in_a_different_branch(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches?branch_id=' . $branchA->id);

        $response->assertOk();
        $response->assertDontSee('Tambah dari Cabang Lain');
    }
```

Append these methods to `tests/Feature/DashboardTest.php` (reuses the existing `protected function grantBranchPermission(User $user, Branch $branch, string $code): void` helper already in this file at `tests/Feature/DashboardTest.php:19-28` — no new imports needed, `Branch` and `User` are already imported):

```php
    public function test_sparepart_baru_button_shown_when_user_has_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Sparepart Baru');
    }

    public function test_sparepart_baru_button_hidden_when_user_only_has_view_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Sparepart Baru');
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest`
Expected:
- `test_empty_state_cta_shown_when_user_has_create_permission_in_a_different_branch` FAILS (current code hides the CTA in this scenario — that's the bug).
- `test_empty_state_cta_hidden_when_user_has_no_create_permission_in_any_branch` — should already PASS today (current-branch-only check also hides it when there's no create permission anywhere). Confirm it passes; if it fails, stop and investigate before continuing.
- `test_header_link_shown_when_user_has_create_permission_in_a_different_branch` FAILS (current code hides the link in this scenario).
- `test_header_link_hidden_when_user_has_no_create_permission_in_any_branch` — should already PASS today. Confirm before continuing.
- `test_tambah_dari_cabang_lain_link_stays_hidden_when_create_permission_is_in_a_different_branch` — should already PASS today (this link's gate isn't changing). Confirm before continuing.

Run: `php artisan test --filter=DashboardTest`
Expected:
- `test_sparepart_baru_button_shown_when_user_has_create_permission` FAILS (current code checks `sparepart.view`, not granted here, so the button is hidden today).
- `test_sparepart_baru_button_hidden_when_user_only_has_view_permission` — this is the exact regression case; today the button INCORRECTLY SHOWS (current code checks `sparepart.view`, which IS granted), so this test should FAIL today with the assertion reversed (i.e. `assertDontSee` fails because the button is actually visible). This failure is the bug this task fixes.

The pre-existing tests in both files should still PASS.

- [ ] **Step 4: Implement the Dashboard button fix**

In `resources/views/dashboard/index.blade.php`, line 13, replace:

```blade
            @if (auth()->user()->branchesWithPermission('sparepart.view')->isNotEmpty())
```

with:

```blade
            @if (auth()->user()->branchesWithPermission('sparepart.create')->isNotEmpty())
```

- [ ] **Step 5: Implement the header-link split and empty-state CTA fix**

In `resources/views/sparepart-branches/index.blade.php`, replace lines 6-15:

```blade
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
```

with:

```blade
        <div class="d-flex gap-2">
            @if (auth()->user()->hasPermissionToInBranch('sparepart.create', $currentBranch->id))
                <a href="{{ route('sparepart-branches.createExisting') }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-link-45deg"></i> Tambah dari Cabang Lain
                </a>
            @endif
            @if (auth()->user()->branchesWithPermission('sparepart.create')->isNotEmpty())
                <a href="{{ route('sparepart-branches.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> Sparepart Baru
                </a>
            @endif
        </div>
```

Then, in the same file, line 90, replace:

```blade
                                    'ctaVisible' => auth()->user()->hasPermissionToInBranch('sparepart.create', $currentBranch->id),
```

with:

```blade
                                    'ctaVisible' => auth()->user()->branchesWithPermission('sparepart.create')->isNotEmpty(),
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest`
Run: `php artisan test --filter=DashboardTest`
Expected: all tests in both files PASS.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (271 pre-existing + 6 new = 277).

Grep the changed/new visible strings against the text-collision-prone test files, per this project's established practice:

Run: `grep -rn "Sparepart Baru\|Tambah dari Cabang Lain" tests/Feature/AppShellTest.php`
Expected: no matches (already confirmed clean during this plan's research — re-confirm at implementation time).

- [ ] **Step 8: Commit**

```bash
git add resources/views/dashboard/index.blade.php resources/views/sparepart-branches/index.blade.php tests/Feature/SparepartBranchIndexAndCreateTest.php tests/Feature/DashboardTest.php
git commit -m "fix: gate Sparepart create CTAs on create permission in any branch, not view or current-branch-only"
```

---

## Self-Review Notes

- **Spec coverage:** all three gating fixes from the spec (Dashboard button, header link split, empty-state CTA) are covered by Task 1's Steps 4-5, with the modified/new tests in Steps 1-2 covering both the fixed behavior and the deliberately-unchanged "Tambah dari Cabang Lain" gate.
- **Placeholder scan:** none found — all code blocks are complete and copy-ready.
- **Type consistency:** `User::branchesWithPermission(string $code): Collection` (verified at `app/Models/User.php:76-79`) is called identically in all three fix sites and matches its existing usage elsewhere in the codebase (`index()`'s `sparepart.view` calls, `DashboardController.php:64`).
- **Scope check:** single task is appropriately sized — three small Blade permission-check swaps across two files, no new architectural pattern, smaller in scope than the prior branch-selection fix.
