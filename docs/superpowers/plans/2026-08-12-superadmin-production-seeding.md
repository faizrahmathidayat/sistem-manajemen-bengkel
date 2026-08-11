# Superadmin Production Seeding & Account Protection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the local/testing-only 3-demo-user seeder with a production-safe seeder that creates exactly one branch (`CABANGUTAMA`) and one all-access superadmin account, and protect that account from being seen or modified by anyone else in the Users UI.

**Architecture:** No database migration. The app is deliberately "no-roles" (every check is a granular permission code via `Gate::before` → `$user->hasPermissionTo()`); the superadmin is identified purely by a configurable `username` match (`config('app.superadmin_username')`), exposed as `User::isSuperAdmin()`. `database/seeders/DemoUsersSeeder.php` is rewritten (same file/class, no new seeder file) to seed the single branch + superadmin instead of the old 3-user/3-branch demo data, with its environment guard removed so it can run in production. Protection is enforced the same way every other record-level check in this codebase is enforced: a new `UserPolicy` (`view`/`update`) registered in `AuthServiceProvider`, which the existing `Gate::before` already defers to whenever `authorize()` is called with a model argument (see `AuthServiceProvider.php:42-44` — this extension point already exists and is exercised by `WorkOrderPolicy`, `InvoicePolicy`, etc.). The policy is applied at `UserController::show()`/`index()`/`update()` (via `UpdateUserRequest::authorize()`) and reused across the three permission/branch-assignment controllers so a non-superadmin can neither see, edit, nor grant/revoke branches or permissions for the superadmin account.

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL, Blade.

## Global Constraints

- PHP 7.4 syntax only (no named arguments, no `match`, no nullsafe `?->` — use `optional()->`).
- No new migration. Superadmin identity is `username === config('app.superadmin_username')`, never a stored boolean.
- `DemoUsersSeeder.php` keeps its existing class/file name (per explicit instruction — not renamed, not replaced by a new file) but no longer runs only in `local`/`testing`; the environment guard is removed entirely so it is safe and required in production.
- `DashboardPermissionSeeder.php` is **not modified and not called** from the new seeder — `dashboard.view` is already one of the global (`is_branch_scoped = false`) permission codes defined in `MenuPermissionSeeder`, so the superadmin receives it automatically from the blanket "grant every global code" step. `DashboardPermissionSeeder`'s job (backfilling `dashboard.view` onto pre-existing users) simply doesn't apply to a fresh production seed.
- Password fallback: `config('app.superadmin_password')` defaults to a literal string committed to the repo (per explicit instruction). Every task that touches this must not weaken it further; deployment guidance to override via `.env` belongs in Task 4's summary, not in code.
- Every task ends with `php artisan test --filter=<relevant>`, then a commit. Task 4 runs the full suite and must be 100% green before the final commit.
- Follow existing test conventions exactly: `RefreshDatabase`, a local `userWithPermissions()`/`grantBranchPermission()`-style helper copy-pasted per file (this codebase's established pattern — see `tests/Feature/UserManagementTest.php:18-32`), `$this->actingAs($user)->get/post/put(...)`, `assertOk()`/`assertForbidden()`/`assertSee()`/`assertDontSee()`.

---

## File Structure

- **Modify:** `config/app.php` — add `superadmin_username` and `superadmin_password` keys.
- **Modify:** `app/Models/User.php` — add `isSuperAdmin(): bool`.
- **Modify:** `database/seeders/DemoUsersSeeder.php` — full rewrite: single branch + single superadmin, no environment guard.
- **Modify:** `tests/Feature/DemoUsersSeederTest.php` — full rewrite to match new seeder behavior.
- **Create:** `app/Policies/UserPolicy.php` — `view()`/`update()`, deny non-superadmin acting on the superadmin account.
- **Modify:** `app/Providers/AuthServiceProvider.php` — register `UserPolicy`.
- **Modify:** `app/Http/Controllers/UserController.php` — `index()` excludes superadmin from non-superadmin viewers; `show()` authorizes via policy.
- **Modify:** `app/Http/Requests/UpdateUserRequest.php` — `authorize()` also checks the update policy.
- **Modify:** `app/Http/Controllers/UserBranchAssignmentController.php` — policy check in `store()`/`destroy()`/`setDefault()`.
- **Modify:** `app/Http/Controllers/UserBranchPermissionAssignmentController.php` — policy check in `store()`/`destroy()`.
- **Modify:** `app/Http/Controllers/UserPermissionAssignmentController.php` — policy check in `store()`/`destroy()`.
- **Create:** `tests/Feature/SuperAdminProtectionTest.php` — cross-cutting coverage for all of the above.

---

### Task 1: Config, `User::isSuperAdmin()`, and production seeder rewrite

**Files:**
- Modify: `config/app.php`
- Modify: `app/Models/User.php`
- Modify: `database/seeders/DemoUsersSeeder.php`
- Test: `tests/Feature/DemoUsersSeederTest.php`
- Test: `tests/Feature/UserAccountTest.php`

**Interfaces:**
- Produces: `config('app.superadmin_username')` (default `'superadmin'`), `config('app.superadmin_password')` (default `'Rahasiaku109!'`), `User::isSuperAdmin(): bool` — consumed by Task 2 and Task 3's policy/controllers.

- [ ] **Step 1: Write failing tests for `User::isSuperAdmin()`**

Add to `tests/Feature/UserAccountTest.php`, after `test_user_is_active_by_default()`:

```php
    public function test_is_super_admin_matches_configured_username(): void
    {
        $superAdmin = User::factory()->create(['username' => config('app.superadmin_username')]);
        $regular = User::factory()->create(['username' => 'someone_else']);

        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertFalse($regular->isSuperAdmin());
    }
```

- [ ] **Step 2: Write failing tests for the rewritten seeder**

Replace the entire contents of `tests/Feature/DemoUsersSeederTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_single_branch_cabangutama(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $this->assertDatabaseHas('branches', ['code' => 'CABANGUTAMA', 'name' => 'CABANGUTAMA', 'is_active' => true]);
        $this->assertSame(1, Branch::count());
    }

    public function test_seeds_superadmin_with_every_global_and_branch_scoped_permission(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $superAdmin = User::where('username', config('app.superadmin_username'))->firstOrFail();
        $branch = Branch::where('code', 'CABANGUTAMA')->firstOrFail();

        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertTrue($superAdmin->is_active);
        $this->assertTrue($superAdmin->branches->contains('id', $branch->id));

        $globalIds = Permission::whereHas('menu', fn ($q) => $q->where('is_branch_scoped', false))->pluck('id');
        $this->assertSame(
            $globalIds->count(),
            UserPermission::where('user_id', $superAdmin->id)->whereIn('permission_id', $globalIds)->count()
        );

        $branchScopedIds = Permission::whereHas('menu', fn ($q) => $q->where('is_branch_scoped', true))->pluck('id');
        $this->assertSame(
            $branchScopedIds->count(),
            UserBranchPermission::where('user_id', $superAdmin->id)->where('branch_id', $branch->id)
                ->whereIn('permission_id', $branchScopedIds)->count()
        );
    }

    public function test_superadmin_has_dashboard_view_without_running_dashboard_permission_seeder(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $superAdmin = User::where('username', config('app.superadmin_username'))->firstOrFail();

        $this->assertTrue($superAdmin->userPermissions()->whereHas('permission', fn ($q) => $q->where('code', 'dashboard.view'))->exists());
    }

    public function test_seeder_is_idempotent_when_run_twice(): void
    {
        $this->seed(DemoUsersSeeder::class);
        $this->seed(DemoUsersSeeder::class);

        $this->assertSame(1, User::where('username', config('app.superadmin_username'))->count());
        $this->assertSame(1, Branch::where('code', 'CABANGUTAMA')->count());
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --filter=UserAccountTest`
Expected: FAIL — `isSuperAdmin` method does not exist.

Run: `php artisan test --filter=DemoUsersSeederTest`
Expected: FAIL — old seeder still creates `faiz_rahmat`/3 branches, `config('app.superadmin_username')` key doesn't exist yet either.

- [ ] **Step 4: Add config keys**

In `config/app.php`, after the existing `business_timezone` block (ends around line 86), add:

```php
    /*
    |--------------------------------------------------------------------------
    | Superadmin Account
    |--------------------------------------------------------------------------
    |
    | Identifies the single bootstrap admin account by username — this app is
    | deliberately role-less (every other check is a granular permission
    | code), so there is no separate "is_admin" column. Used to hide this
    | account from the Users list and block direct access to it by anyone
    | else (see App\Policies\UserPolicy). The password fallback below is only
    | used if SUPERADMIN_PASSWORD isn't set in .env — set it there and change
    | the password via the UI immediately after first login in production.
    |
    */

    'superadmin_username' => env('SUPERADMIN_USERNAME', 'superadmin'),
    'superadmin_password' => env('SUPERADMIN_PASSWORD', 'Rahasiaku109!'),
```

- [ ] **Step 5: Add `User::isSuperAdmin()`**

In `app/Models/User.php`, add after `defaultBranch()`:

```php
    public function isSuperAdmin(): bool
    {
        return $this->username === config('app.superadmin_username');
    }
```

- [ ] **Step 6: Rewrite the seeder**

Replace the entire contents of `database/seeders/DemoUsersSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    public function run()
    {
        $this->call(MenuPermissionSeeder::class);

        $branch = Branch::updateOrCreate(
            ['code' => 'CABANGUTAMA'],
            ['name' => 'CABANGUTAMA', 'is_active' => true]
        );

        $superAdmin = User::updateOrCreate(
            ['username' => config('app.superadmin_username')],
            [
                'name' => 'Super Admin',
                'password' => Hash::make(config('app.superadmin_password')),
                'is_active' => true,
            ]
        );

        (new UserBranchService())->assign($superAdmin, $branch, true);

        $globalCodes = Permission::whereHas('menu', fn ($query) => $query->where('is_branch_scoped', false))->pluck('code')->all();
        $branchScopedCodes = Permission::whereHas('menu', fn ($query) => $query->where('is_branch_scoped', true))->pluck('code')->all();

        $this->grantPermissions($superAdmin, $globalCodes);
        $this->grantBranchPermissions($superAdmin, $branch, $branchScopedCodes);

        $this->command->info("Superadmin seeded: {$superAdmin->username} — cabang {$branch->code} — semua permission diberikan.");
    }

    protected function grantPermissions(User $user, array $codes)
    {
        $permissionIds = Permission::whereIn('code', $codes)->pluck('id', 'code');

        foreach ($codes as $code) {
            if (! isset($permissionIds[$code])) {
                $this->command->warn("Permission code not found, skipped: {$code}");

                continue;
            }

            UserPermission::firstOrCreate([
                'user_id' => $user->id,
                'permission_id' => $permissionIds[$code],
            ]);
        }
    }

    protected function grantBranchPermissions(User $user, Branch $branch, array $codes)
    {
        $permissionIds = Permission::whereIn('code', $codes)->pluck('id', 'code');

        foreach ($codes as $code) {
            if (! isset($permissionIds[$code])) {
                $this->command->warn("Permission code not found, skipped: {$code}");

                continue;
            }

            UserBranchPermission::firstOrCreate([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'permission_id' => $permissionIds[$code],
            ]);
        }
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=UserAccountTest`
Expected: PASS (3 tests)

Run: `php artisan test --filter=DemoUsersSeederTest`
Expected: PASS (4 tests)

- [ ] **Step 8: Commit**

```bash
git add config/app.php app/Models/User.php database/seeders/DemoUsersSeeder.php tests/Feature/DemoUsersSeederTest.php tests/Feature/UserAccountTest.php
git commit -m "feat: seed a single superadmin + CABANGUTAMA branch for production instead of demo users"
```

---

### Task 2: `UserPolicy` and `UserController` wiring

**Files:**
- Create: `app/Policies/UserPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Modify: `app/Http/Controllers/UserController.php`
- Modify: `app/Http/Requests/UpdateUserRequest.php`
- Test: `tests/Feature/SuperAdminProtectionTest.php` (new file)

**Interfaces:**
- Consumes: `User::isSuperAdmin()` (Task 1).
- Produces: `UserPolicy::view(User $actingUser, User $targetUser): bool`, `UserPolicy::update(User $actingUser, User $targetUser): bool` — reused as-is by Task 3's assignment controllers via `$this->authorize('update', $user)`.

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/SuperAdminProtectionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminProtectionTest extends TestCase
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

    protected function makeSuperAdmin(array $codes = []): User
    {
        $superAdmin = User::factory()->create(['username' => config('app.superadmin_username'), 'name' => 'Super Admin']);

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $code]
            );
            UserPermission::create(['user_id' => $superAdmin->id, 'permission_id' => $permission->id]);
        }

        return User::find($superAdmin->id);
    }

    public function test_index_hides_superadmin_from_non_superadmin_viewer(): void
    {
        $this->makeSuperAdmin();
        $viewer = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($viewer)->get('/users');

        $response->assertOk();
        $response->assertDontSee('Super Admin');
    }

    public function test_index_shows_superadmin_to_superadmin_viewer(): void
    {
        $superAdmin = $this->makeSuperAdmin(['user.view']);

        $response = $this->actingAs($superAdmin)->get('/users');

        $response->assertOk();
        $response->assertSee('Super Admin');
    }

    public function test_show_is_forbidden_for_non_superadmin_viewing_superadmin(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $viewer = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($viewer)->get("/users/{$superAdmin->id}");

        $response->assertForbidden();
    }

    public function test_show_is_allowed_for_superadmin_viewing_self(): void
    {
        $superAdmin = $this->makeSuperAdmin(['user.view']);

        $response = $this->actingAs($superAdmin)->get("/users/{$superAdmin->id}");

        $response->assertOk();
    }

    public function test_show_still_allowed_for_non_superadmin_viewing_a_regular_user(): void
    {
        $target = User::factory()->create(['name' => 'Budi Santoso']);
        $viewer = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($viewer)->get("/users/{$target->id}");

        $response->assertOk();
    }

    public function test_update_is_forbidden_for_non_superadmin_updating_superadmin(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $actor = $this->userWithPermissions(['user.edit']);

        $response = $this->actingAs($actor)->put("/users/{$superAdmin->id}", [
            'name' => 'Hacked Name',
            'username' => $superAdmin->username,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'name' => 'Super Admin']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SuperAdminProtectionTest`
Expected: FAIL — `test_index_hides_superadmin_from_non_superadmin_viewer` and the `_forbidden_` tests fail (superadmin currently visible/editable by anyone with `user.view`/`user.edit`); the two "still allowed" sanity tests should already pass.

- [ ] **Step 3: Create `UserPolicy`**

Create `app/Policies/UserPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view(User $actingUser, User $targetUser): bool
    {
        return $actingUser->isSuperAdmin() || ! $targetUser->isSuperAdmin();
    }

    public function update(User $actingUser, User $targetUser): bool
    {
        return $actingUser->isSuperAdmin() || ! $targetUser->isSuperAdmin();
    }
}
```

- [ ] **Step 4: Register the policy**

In `app/Providers/AuthServiceProvider.php`, add to `$policies`:

```php
    protected $policies = [
        \App\Models\SparepartBranch::class => \App\Policies\SparepartBranchPolicy::class,
        \App\Models\WorkOrder::class => \App\Policies\WorkOrderPolicy::class,
        \App\Models\GoodsReceipt::class => \App\Policies\GoodsReceiptPolicy::class,
        \App\Models\StockAdjustment::class => \App\Policies\StockAdjustmentPolicy::class,
        \App\Models\StockTransfer::class => \App\Policies\StockTransferPolicy::class,
        \App\Models\Invoice::class => \App\Policies\InvoicePolicy::class,
        \App\Models\PaymentReceipt::class => \App\Policies\PaymentReceiptPolicy::class,
        \App\Models\User::class => \App\Policies\UserPolicy::class,
    ];
```

- [ ] **Step 5: Wire `index()` and `show()`**

In `app/Http/Controllers/UserController.php`, `index()` — add a `when()` clause right after the existing `->when($branchIds, ...)` line:

```php
        $users = User::orderBy('name')
            ->when($search, function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('username', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            ->when($branchIds, fn ($query) => $query->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds)))
            ->when(! auth()->user()->isSuperAdmin(), fn ($query) => $query->where('username', '!=', config('app.superadmin_username')))
            ->simplePaginate(15)
            ->withQueryString();
```

In `show()`, add right after `$this->authorize('user.view');`:

```php
    public function show(User $user)
    {
        $this->authorize('user.view');
        $this->authorize('view', $user);
```

- [ ] **Step 6: Wire `update()` via the form request**

In `app/Http/Requests/UpdateUserRequest.php`:

```php
    public function authorize()
    {
        return $this->user()->can('user.edit') && $this->user()->can('update', $this->route('user'));
    }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=SuperAdminProtectionTest`
Expected: PASS (6 tests)

Run: `php artisan test --filter=UserManagementTest`
Expected: PASS — no regression on existing user CRUD tests.

- [ ] **Step 8: Commit**

```bash
git add app/Policies/UserPolicy.php app/Providers/AuthServiceProvider.php app/Http/Controllers/UserController.php app/Http/Requests/UpdateUserRequest.php tests/Feature/SuperAdminProtectionTest.php
git commit -m "feat: hide superadmin from Users list and block direct view/edit access via UserPolicy"
```

---

### Task 3: Protect branch and permission assignment endpoints

**Files:**
- Modify: `app/Http/Controllers/UserBranchAssignmentController.php`
- Modify: `app/Http/Controllers/UserBranchPermissionAssignmentController.php`
- Modify: `app/Http/Controllers/UserPermissionAssignmentController.php`
- Test: `tests/Feature/SuperAdminProtectionTest.php` (extend)

**Interfaces:**
- Consumes: `UserPolicy::update()` (Task 2), reused verbatim via `$this->authorize('update', $user)` — no new policy methods needed since granting/revoking branches or permissions is itself a form of "updating" the target user's access.

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/SuperAdminProtectionTest.php`, after `test_update_is_forbidden_for_non_superadmin_updating_superadmin()`:

```php
    public function test_branch_assignment_is_forbidden_for_non_superadmin_targeting_superadmin(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $actor = $this->userWithPermissions(['user_branch.manage']);
        $branch = \App\Models\Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $response = $this->actingAs($actor)->post("/users/{$superAdmin->id}/branches/{$branch->id}");

        $response->assertForbidden();
    }

    public function test_branch_default_assignment_is_forbidden_for_non_superadmin_targeting_superadmin(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $actor = $this->userWithPermissions(['user_branch.manage']);
        $branch = \App\Models\Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $response = $this->actingAs($actor)->put("/users/{$superAdmin->id}/branches/{$branch->id}/default");

        $response->assertForbidden();
    }

    public function test_branch_removal_is_forbidden_for_non_superadmin_targeting_superadmin(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $actor = $this->userWithPermissions(['user_branch.manage']);
        $branch = \App\Models\Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $response = $this->actingAs($actor)->delete("/users/{$superAdmin->id}/branches/{$branch->id}");

        $response->assertForbidden();
    }

    public function test_branch_permission_grant_is_forbidden_for_non_superadmin_targeting_superadmin(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $actor = $this->userWithPermissions(['user_permission.manage']);
        $branch = \App\Models\Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::firstOrCreate(['code' => 'pkb.view'], ['resource' => 'pkb', 'action' => 'view', 'description' => 'pkb.view']);

        $response = $this->actingAs($actor)->post("/users/{$superAdmin->id}/branches/{$branch->id}/permissions/{$permission->id}");

        $response->assertForbidden();
    }

    public function test_branch_permission_revoke_is_forbidden_for_non_superadmin_targeting_superadmin(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $actor = $this->userWithPermissions(['user_permission.manage']);
        $branch = \App\Models\Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::firstOrCreate(['code' => 'pkb.view'], ['resource' => 'pkb', 'action' => 'view', 'description' => 'pkb.view']);

        $response = $this->actingAs($actor)->delete("/users/{$superAdmin->id}/branches/{$branch->id}/permissions/{$permission->id}");

        $response->assertForbidden();
    }

    public function test_global_permission_grant_is_forbidden_for_non_superadmin_targeting_superadmin(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $actor = $this->userWithPermissions(['user_permission.manage']);
        $permission = Permission::firstOrCreate(['code' => 'report.pkb.view'], ['resource' => 'report', 'action' => 'pkb.view', 'description' => 'report.pkb.view']);

        $response = $this->actingAs($actor)->post("/users/{$superAdmin->id}/permissions/{$permission->id}");

        $response->assertForbidden();
    }

    public function test_global_permission_revoke_is_forbidden_for_non_superadmin_targeting_superadmin(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $actor = $this->userWithPermissions(['user_permission.manage']);
        $permission = Permission::firstOrCreate(['code' => 'report.pkb.view'], ['resource' => 'report', 'action' => 'pkb.view', 'description' => 'report.pkb.view']);

        $response = $this->actingAs($actor)->delete("/users/{$superAdmin->id}/permissions/{$permission->id}");

        $response->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SuperAdminProtectionTest`
Expected: FAIL — the 7 new tests fail (currently any `user_branch.manage`/`user_permission.manage` holder can act on the superadmin).

- [ ] **Step 3: Wire `UserBranchAssignmentController`**

In `app/Http/Controllers/UserBranchAssignmentController.php`, add `$this->authorize('update', $user);` right after each existing `$this->authorize('user_branch.manage');` call (in `store()`, `destroy()`, and `setDefault()`):

```php
    public function store(User $user, Branch $branch, UserBranchService $service)
    {
        $this->authorize('user_branch.manage');
        $this->authorize('update', $user);

        $service->assign($user, $branch);

        return response()->json(['message' => 'Cabang berhasil ditambahkan.']);
    }

    public function destroy(User $user, Branch $branch)
    {
        $this->authorize('user_branch.manage');
        $this->authorize('update', $user);

        UserBranch::where('user_id', $user->id)
            ->where('branch_id', $branch->id)
            ->update(['is_active' => false, 'is_default' => false]);

        return response()->json(['message' => 'Cabang berhasil dihapus dari user.']);
    }

    public function setDefault(User $user, Branch $branch, UserBranchService $service)
    {
        $this->authorize('user_branch.manage');
        $this->authorize('update', $user);

        if (! $user->hasAccessToBranch($branch->id)) {
            return response()->json(['message' => 'User belum memiliki akses ke cabang ini.'], 422);
        }

        $service->setDefault($user, $branch);

        return response()->json(['message' => 'Cabang default berhasil diubah.']);
    }
```

- [ ] **Step 4: Wire `UserBranchPermissionAssignmentController`**

In `app/Http/Controllers/UserBranchPermissionAssignmentController.php`, add `$this->authorize('update', $user);` right after each existing `$this->authorize('user_permission.manage');` call (in `store()` and `destroy()`):

```php
    public function store(Request $request, User $user, Branch $branch, Permission $permission)
    {
        $this->authorize('user_permission.manage');
        $this->authorize('update', $user);

        if (! optional($permission->menu)->is_branch_scoped) {
```

```php
    public function destroy(User $user, Branch $branch, Permission $permission)
    {
        $this->authorize('user_permission.manage');
        $this->authorize('update', $user);

        UserBranchPermission::where('user_id', $user->id)
```

- [ ] **Step 5: Wire `UserPermissionAssignmentController`**

In `app/Http/Controllers/UserPermissionAssignmentController.php`, add `$this->authorize('update', $user);` right after each existing `$this->authorize('user_permission.manage');` call (in `store()` and `destroy()`):

```php
    public function store(Request $request, User $user, Permission $permission)
    {
        $this->authorize('user_permission.manage');
        $this->authorize('update', $user);

        UserPermission::firstOrCreate(
```

```php
    public function destroy(Request $request, User $user, Permission $permission)
    {
        $this->authorize('user_permission.manage');
        $this->authorize('update', $user);

        if ($this->wouldStripLastSelfManagePermission($request->user(), $user, $permission)) {
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=SuperAdminProtectionTest`
Expected: PASS (13 tests total)

Run: `php artisan test --filter=UserBranchPermissionTabControllerTest,BranchScopedPermissionTabRenderingTest,UserPermissionTabTest`
Expected: PASS — no regression on existing branch/permission tab tests (these exercise the same controllers for a superadmin-acting-on-a-regular-user scenario, which is unaffected).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/UserBranchAssignmentController.php app/Http/Controllers/UserBranchPermissionAssignmentController.php app/Http/Controllers/UserPermissionAssignmentController.php tests/Feature/SuperAdminProtectionTest.php
git commit -m "feat: block branch/permission assignment endpoints from targeting the superadmin account"
```

---

### Task 4: Full regression and deployment notes

**Files:** None (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: 100% green, no regressions. If a failure appears in an unrelated file, re-run just that file in isolation first (this codebase has occasional `random_int()` collision flakiness in unrelated seed-data helpers, seen and confirmed harmless in prior milestones) before treating it as a real regression.

- [ ] **Step 2: Manual sanity check (local)**

Run `php artisan migrate:fresh && php artisan db:seed --class=DemoUsersSeeder` locally, confirm via `php artisan tinker`:
```php
\App\Models\User::where('username', config('app.superadmin_username'))->first()->isSuperAdmin(); // true
\App\Models\Branch::count(); // 1
```

- [ ] **Step 3: Commit any final cleanup, then hand off**

No code changes expected at this point; if `php artisan test` surfaced anything, fix and commit before finishing. Otherwise this task is verification-only — no commit needed.

**Deployment note to relay to the user (not a code change — just report this back once Task 4 passes):**
On the production server, run in order:
```bash
git pull origin master
php artisan config:clear
php artisan db:seed --class=DemoUsersSeeder --force
```
This is safe to run even if branches/users already exist in that database — it only creates/updates `CABANGUTAMA` and the `superadmin` account via `updateOrCreate`, nothing else is touched or deleted. Recommend setting `SUPERADMIN_PASSWORD` in the production `.env` before running it for the first time (otherwise the committed default `Rahasiaku109!` is used), and changing the password via the Users UI immediately after first login regardless.
