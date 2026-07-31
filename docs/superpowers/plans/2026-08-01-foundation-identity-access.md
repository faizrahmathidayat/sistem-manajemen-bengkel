# Foundation: Identity, Branch Access, Permission & Cross-Cutting Infra Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the foundation layer of Sistem Manajemen Bengkel — branches, users, per-user multi-branch access, direct (role-less) permissions, document numbering, and polymorphic attachments — plus a working login flow, so every later module (PKB, invoice, sparepart, payment) has something real to build on.

**Architecture:** Laravel 8 monolith, Blade views with light vanilla-JS/AJAX for interactive bits (no SPA framework), server renders everything. No `roles` / `role_permissions` tables — permissions are assigned directly to users. Backend-enforced branch scoping (never trust the menu/frontend). MySQL 8 as the only supported DBMS.

**Tech Stack:** Laravel 8 (PHP 7.3|8.0), MySQL 8, Blade, Bootstrap 5 (via CDN — no local build step), Laravel's built-in session auth (not Sanctum — Sanctum stays reserved for a future API/mobile phase), `doctrine/dbal` (needed once, to alter the `users` table).

Source specs: `Alur_Bisnis_Operasional_Sistem_Bengkel.md` (business flow) and `Rencana_Migrasi_Database_Sistem_Bengkel.md` (schema plan), plus decisions confirmed by the client in chat:
- Satu PKB → satu invoice (no multi-invoice/termin).
- Satu PKB → satu mekanik (no junction table).
- DBMS: MySQL (not the doc's suggested PostgreSQL).
- Primary key: `bigint` auto-increment (Laravel default `id()`), not UUID.
- PDF: `barryvdh/laravel-dompdf` (used starting the invoice/PKB printing phase, not this one).
- Invoice email: Laravel queue (used starting the invoice phase, not this one).
- Attachments: one polymorphic `attachments` table reused by every module that needs file evidence.
- Permission storage: `user_permissions` table, no roles.

## Global Constraints

- DBMS is MySQL 8.0+ only. Do not use PostgreSQL-only syntax (native `ENUM` type via `CREATE TYPE`, partial unique indexes via `WHERE`) — use MySQL-compatible equivalents (inline `ENUM`/`VARCHAR`, or generated-column tricks for partial-unique behavior).
- Primary keys are `bigint` auto-increment (Laravel's default `$table->id()`). Never introduce `uuid` primary keys.
- Table names: `snake_case`, plural.
- Money columns (future phases): `decimal(18,2)`, never `float`/`double`.
- Quantity columns (future phases): `decimal(18,3)`.
- Timestamps: Laravel's default `timestamp` columns, application timezone stays `UTC` (`config/app.php` `timezone` is already `UTC` — do not change it).
- Audit columns (`created_by`, `updated_by`) are added to master and mutable-document tables via the shared `HasAudit` trait — not to `users` itself (per the DB spec, `users` only tracks `last_login_at`).
- Never hard-delete transactional/document rows in later phases (use status `VOID`/`CANCELLED`); master rows use `is_active = false` instead of delete. This phase has no document tables yet, but `branches` follows the `is_active` convention.
- Every list/index endpoint uses `->simplePaginate()`. Never use `->paginate()` anywhere in this codebase.
- No `roles` or `role_permissions` tables, ever. Authorization is `user_permissions` only, checked through Laravel's `Gate`/`can()`.
- Branch-scoping and permission checks happen in the backend (controllers/policies/services), never only in Blade `@if`/menu visibility.
- UI is Bootstrap 5 (CDN), mobile-first/responsive (use Bootstrap's grid + utility classes, not fixed pixel widths), and favors clear, low-friction forms over dense tables — every screen must be usable on a narrow viewport, not just desktop.

---

## Before Task 1: point PHPUnit at a real MySQL test database

Laravel's `RefreshDatabase` trait will run every migration against whatever `DB_DATABASE` the test environment resolves to. This plan relies on MySQL-only behavior (a generated column in Task 3), so tests must run against MySQL, not SQLite.

- [ ] **Step 1: Create a dedicated MySQL database for tests**

Run:
```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS bengkel_testing;"
```

- [ ] **Step 2: Point PHPUnit's test environment at it**

Edit `phpunit.xml`, inside the existing `<php>` block, add two `<server>` entries (keep everything else as-is):

```xml
<server name="DB_CONNECTION" value="mysql"/>
<server name="DB_DATABASE" value="bengkel_testing"/>
```

- [ ] **Step 3: Commit**

```bash
git add phpunit.xml
git commit -m "chore: point test suite at a dedicated MySQL testing database"
```

(If this project is not yet a git repository, run `git init` first and let the user know — do not do this without telling them.)

---

### Task 1: Audit trait + `branches`

**Files:**
- Create: `app/Models/Concerns/HasAudit.php`
- Create: `database/migrations/2026_08_01_000001_create_branches_table.php`
- Create: `app/Models/Branch.php`
- Test: `tests/Feature/BranchFoundationTest.php`

**Interfaces:**
- Produces: `App\Models\Concerns\HasAudit` trait (usable by any Eloquent model with `created_by`/`updated_by` columns) with `creator()`/`updater()` relations. `App\Models\Branch` with fillable `code, name, address, phone, email, is_active` and cast `is_active => boolean`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_branch_while_authenticated_stamps_created_by_and_updated_by(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::create([
            'code' => 'JKT',
            'name' => 'Cabang Jakarta',
        ]);

        $this->assertSame($user->id, $branch->created_by);
        $this->assertSame($user->id, $branch->updated_by);
        $this->assertTrue($branch->is_active);
    }

    public function test_updating_branch_stamps_updated_by_with_current_user(): void
    {
        $creator = User::factory()->create();
        $this->actingAs($creator);
        $branch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);

        $editor = User::factory()->create();
        $this->actingAs($editor);
        $branch->update(['name' => 'Cabang Bandung Kota']);

        $this->assertSame($creator->id, $branch->created_by);
        $this->assertSame($editor->id, $branch->updated_by);
    }

    public function test_branch_code_must_be_unique(): void
    {
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta 2']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BranchFoundationTest`
Expected: FAIL (class `App\Models\Branch` not found / table `branches` doesn't exist).

- [ ] **Step 3: Write the `HasAudit` trait**

```php
<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait HasAudit
{
    public static function bootHasAudit()
    {
        static::creating(function ($model) {
            if (Auth::check()) {
                if (! $model->created_by) {
                    $model->created_by = Auth::id();
                }
                if (! $model->updated_by) {
                    $model->updated_by = Auth::id();
                }
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
```

- [ ] **Step 4: Write the `branches` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBranchesTable extends Migration
{
    public function up()
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->text('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('branches');
    }
}
```

- [ ] **Step 5: Write the `Branch` model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'code', 'name', 'address', 'phone', 'email', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
```

- [ ] **Step 6: Run migrations and the test**

Run: `php artisan migrate && php artisan test --filter=BranchFoundationTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Models/Concerns/HasAudit.php app/Models/Branch.php database/migrations/2026_08_01_000001_create_branches_table.php tests/Feature/BranchFoundationTest.php
git commit -m "feat: add branches table with audit trait"
```

---

### Task 2: Extend `users` (username, is_active, last_login_at)

**Files:**
- Modify: `database/migrations/2014_10_12_000000_create_users_table.php` → do NOT edit this file (it already ran conceptually); instead create a new migration.
- Create: `database/migrations/2026_08_01_000002_add_identity_columns_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Feature/UserAccountTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `users.username` (unique, required), `users.is_active` (bool, default true), `users.last_login_at` (nullable datetime). `User::factory()` now always yields a valid unique `username` and `is_active = true`.

- [ ] **Step 1: Install `doctrine/dbal`** (needed for `->change()` on an existing column)

```bash
composer require doctrine/dbal:^3.1
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_username_is_required_and_unique(): void
    {
        User::factory()->create(['username' => 'budi']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        User::factory()->create(['username' => 'budi']);
    }

    public function test_user_is_active_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->is_active);
        $this->assertNull($user->last_login_at);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=UserAccountTest`
Expected: FAIL (`username` column doesn't exist yet, or factory doesn't set it).

- [ ] **Step 4: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIdentityColumnsToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 100)->unique()->after('id');
            $table->boolean('is_active')->default(true)->after('password');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 255)->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 255)->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'is_active', 'last_login_at']);
        });
    }
}
```

- [ ] **Step 5: Update the `User` model**

Edit `app/Models/User.php`, replace the `$fillable` and `$casts` arrays:

```php
    protected $fillable = [
        'username',
        'name',
        'email',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];
```

- [ ] **Step 6: Update `UserFactory` so every factory-created user has a valid username**

Edit `database/factories/UserFactory.php`, add to the `definition()` array:

```php
            'username' => $this->faker->unique()->userName(),
            'is_active' => true,
```

(Full `definition()` now returns `name`, `username`, `email`, `email_verified_at`, `password`, `remember_token`, `is_active`.)

- [ ] **Step 7: Run migration and the test**

Run: `php artisan migrate && php artisan test --filter=UserAccountTest`
Expected: PASS (2 tests). Also re-run Task 1's test to confirm nothing broke: `php artisan test --filter=BranchFoundationTest` → PASS.

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock database/migrations/2026_08_01_000002_add_identity_columns_to_users_table.php app/Models/User.php database/factories/UserFactory.php tests/Feature/UserAccountTest.php
git commit -m "feat: add username, is_active, last_login_at to users"
```

---

### Task 3: `user_branches` (multi-branch access per user)

**Files:**
- Create: `database/migrations/2026_08_01_000003_create_user_branches_table.php`
- Create: `app/Models/UserBranch.php`
- Create: `app/Services/UserBranchService.php`
- Modify: `app/Models/User.php` (add `userBranches()`, `branches()`, `hasAccessToBranch()`, `defaultBranch()`)
- Modify: `app/Models/Branch.php` (add `userBranches()`)
- Test: `tests/Feature/UserBranchAssignmentTest.php`

**Interfaces:**
- Consumes: `App\Models\Branch` (Task 1), `App\Models\User` (Task 2).
- Produces: `App\Models\UserBranch`. `App\Services\UserBranchService::assign(User $user, Branch $branch, bool $makeDefault = false): UserBranch` and `::setDefault(User $user, Branch $branch): void` (throws `ModelNotFoundException` if the user has no active link to that branch). `User::hasAccessToBranch(int $branchId): bool`, `User::defaultBranch(): ?Branch`.

This is the table every later branch-scoped module (PKB, invoice, sparepart, …) will call `hasAccessToBranch()` against. MySQL has no partial unique index, so "only one active default branch per user" is enforced with a generated column + unique index — read the DDL comments below before changing it.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserBranch;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserBranchAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_a_branch_to_a_user_creates_an_active_link(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $service = new UserBranchService();

        $service->assign($user, $branch);

        $this->assertTrue($user->hasAccessToBranch($branch->id));
    }

    public function test_setting_default_branch_unsets_previous_default(): void
    {
        $user = User::factory()->create();
        $jakarta = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $bandung = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $service = new UserBranchService();

        $service->assign($user, $jakarta, true);
        $service->assign($user, $bandung, true);

        $this->assertSame($bandung->id, $user->defaultBranch()->id);
        $this->assertSame(
            1,
            UserBranch::where('user_id', $user->id)->where('is_default', true)->count()
        );
    }

    public function test_setting_default_to_a_branch_the_user_is_not_assigned_to_fails(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $service = new UserBranchService();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->setDefault($user, $branch);
    }

    public function test_database_rejects_two_active_default_branches_for_the_same_user(): void
    {
        $user = User::factory()->create();
        $jakarta = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $bandung = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);

        UserBranch::create(['user_id' => $user->id, 'branch_id' => $jakarta->id, 'is_default' => true]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        UserBranch::create(['user_id' => $user->id, 'branch_id' => $bandung->id, 'is_default' => true]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserBranchAssignmentTest`
Expected: FAIL (nothing exists yet).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateUserBranchesTable extends Migration
{
    public function up()
    {
        Schema::create('user_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'branch_id']);
        });

        // MySQL has no partial unique index ("WHERE is_default = true"), so we emulate
        // it with a generated column that is NULL unless the row is an active default —
        // MySQL unique indexes allow unlimited NULLs, so only one non-NULL (i.e. one
        // active default) per user is permitted.
        DB::statement(
            'ALTER TABLE user_branches ADD COLUMN default_marker TINYINT(1) '
            . 'GENERATED ALWAYS AS (CASE WHEN is_default = 1 AND is_active = 1 THEN 1 ELSE NULL END) STORED'
        );
        DB::statement(
            'ALTER TABLE user_branches ADD UNIQUE INDEX uq_user_default_branch (user_id, default_marker)'
        );
    }

    public function down()
    {
        Schema::dropIfExists('user_branches');
    }
}
```

- [ ] **Step 4: Write the `UserBranch` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserBranch extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'branch_id', 'is_default', 'is_active'];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
```

- [ ] **Step 5: Add relations/helpers to `User` and `Branch`**

Edit `app/Models/User.php`, add these methods to the class body:

```php
    public function userBranches()
    {
        return $this->hasMany(UserBranch::class);
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'user_branches')
            ->withPivot(['is_default', 'is_active'])
            ->wherePivot('is_active', true);
    }

    public function hasAccessToBranch(int $branchId): bool
    {
        return $this->userBranches()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->exists();
    }

    public function defaultBranch(): ?Branch
    {
        return $this->branches()->wherePivot('is_default', true)->first();
    }
```

Edit `app/Models/Branch.php`, add inside the class body:

```php
    public function userBranches()
    {
        return $this->hasMany(UserBranch::class);
    }
```

- [ ] **Step 6: Write `UserBranchService`**

```php
<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserBranch;
use Illuminate\Support\Facades\DB;

class UserBranchService
{
    public function assign(User $user, Branch $branch, bool $makeDefault = false): UserBranch
    {
        return DB::transaction(function () use ($user, $branch, $makeDefault) {
            $userBranch = UserBranch::firstOrCreate(
                ['user_id' => $user->id, 'branch_id' => $branch->id],
                ['is_active' => true]
            );

            if (! $userBranch->is_active) {
                $userBranch->is_active = true;
                $userBranch->save();
            }

            if ($makeDefault) {
                $this->setDefault($user, $branch);
            }

            return $userBranch->refresh();
        });
    }

    public function setDefault(User $user, Branch $branch): void
    {
        DB::transaction(function () use ($user, $branch) {
            UserBranch::where('user_id', $user->id)
                ->where('is_default', true)
                ->lockForUpdate()
                ->update(['is_default' => false]);

            $userBranch = UserBranch::where('user_id', $user->id)
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $userBranch->update(['is_default' => true]);
        });
    }
}
```

- [ ] **Step 7: Run migration and the test**

Run: `php artisan migrate && php artisan test --filter=UserBranchAssignmentTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_01_000003_create_user_branches_table.php app/Models/UserBranch.php app/Models/User.php app/Models/Branch.php app/Services/UserBranchService.php tests/Feature/UserBranchAssignmentTest.php
git commit -m "feat: add user_branches with single-active-default enforcement"
```

---

### Task 4: `menus` + `permissions` + seed data

**Files:**
- Create: `database/migrations/2026_08_01_000004_create_menus_and_permissions_tables.php`
- Create: `app/Models/Menu.php`
- Create: `app/Models/Permission.php`
- Create: `database/seeders/MenuPermissionSeeder.php`
- Test: `tests/Feature/MenuPermissionSeederTest.php`

**Interfaces:**
- Produces: `App\Models\Menu`, `App\Models\Permission` (with `menu()` relation). `Database\Seeders\MenuPermissionSeeder` — idempotent, seeds every menu/permission code listed in §2.2 and §3 of the business-flow doc. Later phases append their own permission codes to this seeder's `definitions()` array rather than creating a new seeder.

Per the migration doc (§2): "Migration ... tidak melakukan seed permission/desain menu secara otomatis pada production tanpa review" — so this seeder is NOT auto-run from `DatabaseSeeder`. It must be run explicitly: `php artisan db:seed --class=MenuPermissionSeeder`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use Database\Seeders\MenuPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_expected_menus_and_permissions(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $this->assertDatabaseHas('permissions', ['code' => 'pkb.create']);
        $this->assertDatabaseHas('permissions', ['code' => 'invoice.post']);

        $permission = Permission::where('code', 'invoice.post')->first();
        $this->assertSame('operasional.invoice', $permission->menu->code);
    }

    public function test_seeder_is_idempotent_when_run_twice(): void
    {
        $this->seed(MenuPermissionSeeder::class);
        $this->seed(MenuPermissionSeeder::class);

        $this->assertSame(1, Permission::where('code', 'pkb.create')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MenuPermissionSeederTest`
Expected: FAIL (nothing exists yet).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMenusAndPermissionsTables extends Migration
{
    public function up()
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('menus')->nullOnDelete();
            $table->string('code', 100)->unique();
            $table->string('name', 150);
            $table->string('route', 150)->nullable();
            $table->string('icon', 100)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->nullable()->constrained('menus')->nullOnDelete();
            $table->string('code', 150)->unique();
            $table->string('resource', 100);
            $table->string('action', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('menus');
    }
}
```

- [ ] **Step 4: Write the `Menu` and `Permission` models**

`app/Models/Menu.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    protected $fillable = ['parent_id', 'code', 'name', 'route', 'icon', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function parent()
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Menu::class, 'parent_id');
    }

    public function permissions()
    {
        return $this->hasMany(Permission::class);
    }
}
```

`app/Models/Permission.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['menu_id', 'code', 'resource', 'action', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }
}
```

- [ ] **Step 5: Write the seeder**

```php
<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class MenuPermissionSeeder extends Seeder
{
    public function run()
    {
        foreach ($this->definitions() as $sortOrder => $menuDefinition) {
            $menu = Menu::updateOrCreate(
                ['code' => $menuDefinition['code']],
                [
                    'name' => $menuDefinition['name'],
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                ]
            );

            foreach ($menuDefinition['permissions'] as $permission) {
                Permission::updateOrCreate(
                    ['code' => $permission['code']],
                    [
                        'menu_id' => $menu->id,
                        'resource' => $permission['resource'],
                        'action' => $permission['action'],
                        'description' => $permission['description'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    protected function definitions(): array
    {
        return [
            [
                'code' => 'operasional.pkb',
                'name' => 'Perintah Kerja Bengkel',
                'permissions' => [
                    ['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB'],
                    ['code' => 'pkb.create', 'resource' => 'pkb', 'action' => 'create', 'description' => 'Membuat PKB'],
                    ['code' => 'pkb.edit', 'resource' => 'pkb', 'action' => 'edit', 'description' => 'Mengubah PKB'],
                    ['code' => 'pkb.confirm', 'resource' => 'pkb', 'action' => 'confirm', 'description' => 'Mengonfirmasi PKB'],
                    ['code' => 'pkb.cancel', 'resource' => 'pkb', 'action' => 'cancel', 'description' => 'Membatalkan PKB'],
                    ['code' => 'pkb.override_stock_shortage', 'resource' => 'pkb', 'action' => 'override_stock_shortage', 'description' => 'Override kekurangan stok pada PKB'],
                    ['code' => 'pkb.print', 'resource' => 'pkb', 'action' => 'print', 'description' => 'Cetak PKB'],
                ],
            ],
            [
                'code' => 'operasional.invoice',
                'name' => 'Invoice',
                'permissions' => [
                    ['code' => 'invoice.view', 'resource' => 'invoice', 'action' => 'view', 'description' => 'Melihat invoice'],
                    ['code' => 'invoice.create', 'resource' => 'invoice', 'action' => 'create', 'description' => 'Membuat invoice'],
                    ['code' => 'invoice.edit', 'resource' => 'invoice', 'action' => 'edit', 'description' => 'Mengubah invoice draft'],
                    ['code' => 'invoice.post', 'resource' => 'invoice', 'action' => 'post', 'description' => 'Posting invoice'],
                    ['code' => 'invoice.void', 'resource' => 'invoice', 'action' => 'void', 'description' => 'Void invoice'],
                    ['code' => 'invoice.print', 'resource' => 'invoice', 'action' => 'print', 'description' => 'Cetak invoice'],
                    ['code' => 'invoice.email', 'resource' => 'invoice', 'action' => 'email', 'description' => 'Kirim invoice via email'],
                ],
            ],
            [
                'code' => 'operasional.payment',
                'name' => 'Penerimaan Pembayaran',
                'permissions' => [
                    ['code' => 'payment.view', 'resource' => 'payment', 'action' => 'view', 'description' => 'Melihat pembayaran'],
                    ['code' => 'payment.create', 'resource' => 'payment', 'action' => 'create', 'description' => 'Mencatat pembayaran'],
                    ['code' => 'payment.void', 'resource' => 'payment', 'action' => 'void', 'description' => 'Void pembayaran'],
                    ['code' => 'payment.print', 'resource' => 'payment', 'action' => 'print', 'description' => 'Cetak bukti pembayaran'],
                ],
            ],
            [
                'code' => 'persediaan.sparepart',
                'name' => 'Master Sparepart',
                'permissions' => [
                    ['code' => 'sparepart.view', 'resource' => 'sparepart', 'action' => 'view', 'description' => 'Melihat sparepart'],
                    ['code' => 'sparepart.create', 'resource' => 'sparepart', 'action' => 'create', 'description' => 'Membuat sparepart'],
                    ['code' => 'sparepart.edit', 'resource' => 'sparepart', 'action' => 'edit', 'description' => 'Mengubah sparepart'],
                    ['code' => 'sparepart.delete', 'resource' => 'sparepart', 'action' => 'delete', 'description' => 'Menonaktifkan sparepart'],
                ],
            ],
            [
                'code' => 'persediaan.receipt',
                'name' => 'Penerimaan Barang',
                'permissions' => [
                    ['code' => 'receipt.view', 'resource' => 'receipt', 'action' => 'view', 'description' => 'Melihat penerimaan barang'],
                    ['code' => 'receipt.create', 'resource' => 'receipt', 'action' => 'create', 'description' => 'Membuat penerimaan barang'],
                    ['code' => 'receipt.post', 'resource' => 'receipt', 'action' => 'post', 'description' => 'Posting penerimaan barang'],
                    ['code' => 'receipt.cancel', 'resource' => 'receipt', 'action' => 'cancel', 'description' => 'Membatalkan penerimaan barang'],
                ],
            ],
            [
                'code' => 'persediaan.stock_adjustment',
                'name' => 'Stock Adjustment',
                'permissions' => [
                    ['code' => 'stock_adjustment.view', 'resource' => 'stock_adjustment', 'action' => 'view', 'description' => 'Melihat stock adjustment'],
                    ['code' => 'stock_adjustment.create', 'resource' => 'stock_adjustment', 'action' => 'create', 'description' => 'Membuat stock adjustment'],
                    ['code' => 'stock_adjustment.approve', 'resource' => 'stock_adjustment', 'action' => 'approve', 'description' => 'Menyetujui stock adjustment'],
                    ['code' => 'stock_adjustment.post', 'resource' => 'stock_adjustment', 'action' => 'post', 'description' => 'Posting stock adjustment'],
                    ['code' => 'stock_adjustment.cancel', 'resource' => 'stock_adjustment', 'action' => 'cancel', 'description' => 'Membatalkan stock adjustment'],
                ],
            ],
            [
                'code' => 'persediaan.stock_transfer',
                'name' => 'Transfer Stock',
                'permissions' => [
                    ['code' => 'stock_transfer.view', 'resource' => 'stock_transfer', 'action' => 'view', 'description' => 'Melihat transfer stock'],
                    ['code' => 'stock_transfer.create', 'resource' => 'stock_transfer', 'action' => 'create', 'description' => 'Membuat transfer stock'],
                    ['code' => 'stock_transfer.approve', 'resource' => 'stock_transfer', 'action' => 'approve', 'description' => 'Menyetujui transfer stock'],
                    ['code' => 'stock_transfer.dispatch', 'resource' => 'stock_transfer', 'action' => 'dispatch', 'description' => 'Mengirim transfer stock'],
                    ['code' => 'stock_transfer.receive', 'resource' => 'stock_transfer', 'action' => 'receive', 'description' => 'Menerima transfer stock'],
                    ['code' => 'stock_transfer.cancel', 'resource' => 'stock_transfer', 'action' => 'cancel', 'description' => 'Membatalkan transfer stock'],
                ],
            ],
            [
                'code' => 'master.branch',
                'name' => 'Cabang',
                'permissions' => [
                    ['code' => 'branch.view', 'resource' => 'branch', 'action' => 'view', 'description' => 'Melihat cabang'],
                    ['code' => 'branch.create', 'resource' => 'branch', 'action' => 'create', 'description' => 'Membuat cabang'],
                    ['code' => 'branch.edit', 'resource' => 'branch', 'action' => 'edit', 'description' => 'Mengubah cabang'],
                ],
            ],
            [
                'code' => 'master.customer',
                'name' => 'Customer',
                'permissions' => [
                    ['code' => 'customer.view', 'resource' => 'customer', 'action' => 'view', 'description' => 'Melihat customer'],
                    ['code' => 'customer.create', 'resource' => 'customer', 'action' => 'create', 'description' => 'Membuat customer'],
                    ['code' => 'customer.edit', 'resource' => 'customer', 'action' => 'edit', 'description' => 'Mengubah customer'],
                ],
            ],
            [
                'code' => 'master.vehicle',
                'name' => 'Kendaraan',
                'permissions' => [
                    ['code' => 'vehicle.view', 'resource' => 'vehicle', 'action' => 'view', 'description' => 'Melihat kendaraan'],
                    ['code' => 'vehicle.create', 'resource' => 'vehicle', 'action' => 'create', 'description' => 'Membuat kendaraan'],
                    ['code' => 'vehicle.edit', 'resource' => 'vehicle', 'action' => 'edit', 'description' => 'Mengubah kendaraan'],
                ],
            ],
            [
                'code' => 'master.mechanic',
                'name' => 'Mekanik',
                'permissions' => [
                    ['code' => 'mechanic.view', 'resource' => 'mechanic', 'action' => 'view', 'description' => 'Melihat mekanik'],
                    ['code' => 'mechanic.create', 'resource' => 'mechanic', 'action' => 'create', 'description' => 'Membuat mekanik'],
                    ['code' => 'mechanic.edit', 'resource' => 'mechanic', 'action' => 'edit', 'description' => 'Mengubah mekanik'],
                ],
            ],
            [
                'code' => 'master.service',
                'name' => 'Jasa Service',
                'permissions' => [
                    ['code' => 'service.view', 'resource' => 'service', 'action' => 'view', 'description' => 'Melihat jasa service'],
                    ['code' => 'service.create', 'resource' => 'service', 'action' => 'create', 'description' => 'Membuat jasa service'],
                    ['code' => 'service.edit', 'resource' => 'service', 'action' => 'edit', 'description' => 'Mengubah jasa service'],
                ],
            ],
            [
                'code' => 'administrasi.users',
                'name' => 'Users',
                'permissions' => [
                    ['code' => 'user.view', 'resource' => 'user', 'action' => 'view', 'description' => 'Melihat user'],
                    ['code' => 'user.create', 'resource' => 'user', 'action' => 'create', 'description' => 'Membuat user'],
                    ['code' => 'user.edit', 'resource' => 'user', 'action' => 'edit', 'description' => 'Mengubah user'],
                ],
            ],
            [
                'code' => 'administrasi.user_branches',
                'name' => 'User Branches',
                'permissions' => [
                    ['code' => 'user_branch.manage', 'resource' => 'user_branch', 'action' => 'manage', 'description' => 'Mengelola cabang milik user'],
                ],
            ],
            [
                'code' => 'administrasi.user_permissions',
                'name' => 'User Permissions',
                'permissions' => [
                    ['code' => 'user_permission.manage', 'resource' => 'user_permission', 'action' => 'manage', 'description' => 'Mengelola permission milik user'],
                ],
            ],
            [
                'code' => 'administrasi.audit_log',
                'name' => 'Audit Log',
                'permissions' => [
                    ['code' => 'audit_log.view', 'resource' => 'audit_log', 'action' => 'view', 'description' => 'Melihat audit log'],
                ],
            ],
            [
                'code' => 'reporting.pkb',
                'name' => 'Laporan PKB',
                'permissions' => [
                    ['code' => 'report.pkb.view', 'resource' => 'report', 'action' => 'pkb.view', 'description' => 'Melihat laporan PKB'],
                ],
            ],
            [
                'code' => 'reporting.invoice',
                'name' => 'Laporan Invoice',
                'permissions' => [
                    ['code' => 'report.invoice.view', 'resource' => 'report', 'action' => 'invoice.view', 'description' => 'Melihat laporan invoice'],
                ],
            ],
            [
                'code' => 'reporting.receivable',
                'name' => 'Laporan Piutang',
                'permissions' => [
                    ['code' => 'report.receivable.view', 'resource' => 'report', 'action' => 'receivable.view', 'description' => 'Melihat laporan piutang'],
                ],
            ],
            [
                'code' => 'reporting.pkb_invoice_gap',
                'name' => 'PKB vs Invoice',
                'permissions' => [
                    ['code' => 'report.invoice_pkb_gap.view', 'resource' => 'report', 'action' => 'invoice_pkb_gap.view', 'description' => 'Melihat laporan selisih PKB vs invoice'],
                ],
            ],
            [
                'code' => 'reporting.sparepart',
                'name' => 'Laporan Sparepart',
                'permissions' => [
                    ['code' => 'report.sparepart.view', 'resource' => 'report', 'action' => 'sparepart.view', 'description' => 'Melihat laporan sparepart'],
                    ['code' => 'report.export', 'resource' => 'report', 'action' => 'export', 'description' => 'Mengekspor laporan'],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 6: Run migration and the test**

Run: `php artisan migrate && php artisan test --filter=MenuPermissionSeederTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_01_000004_create_menus_and_permissions_tables.php app/Models/Menu.php app/Models/Permission.php database/seeders/MenuPermissionSeeder.php tests/Feature/MenuPermissionSeederTest.php
git commit -m "feat: add menus/permissions tables with full permission catalog seeder"
```

---

### Task 5: `user_permissions` + Gate wiring

**Files:**
- Create: `database/migrations/2026_08_01_000005_create_user_permissions_table.php`
- Create: `app/Models/UserPermission.php`
- Create: `app/Models/Concerns/AuthorizesByPermission.php`
- Modify: `app/Models/User.php` (use the trait)
- Modify: `app/Providers/AuthServiceProvider.php`
- Test: `tests/Feature/PermissionAuthorizationTest.php`

**Interfaces:**
- Consumes: `App\Models\Permission` (Task 4).
- Produces: `User::hasPermissionTo(string $code): bool`, and — because it's wired into `Gate::before` — every standard Laravel authorization entrypoint also works: `$user->can('pkb.view')`, `@can('pkb.view')` in Blade, `Route::middleware('can:pkb.view')`, `$this->authorize('pkb.view')` in controllers. This is what every later module's controllers/policies will call.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_only_perform_actions_they_have_been_granted(): void
    {
        $permission = Permission::create([
            'code' => 'pkb.view',
            'resource' => 'pkb',
            'action' => 'view',
            'description' => 'Melihat PKB',
        ]);
        $user = User::factory()->create();

        $this->assertFalse($user->can('pkb.view'));

        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $reloaded = User::find($user->id);
        $this->assertTrue($reloaded->can('pkb.view'));
        $this->assertFalse($reloaded->can('invoice.void'));
    }

    public function test_inactive_permission_is_not_granted_even_if_assigned(): void
    {
        $permission = Permission::create([
            'code' => 'invoice.void',
            'resource' => 'invoice',
            'action' => 'void',
            'description' => 'Void invoice',
            'is_active' => false,
        ]);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $this->assertFalse($user->can('invoice.void'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PermissionAuthorizationTest`
Expected: FAIL.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserPermissionsTable extends Migration
{
    public function up()
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'permission_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_permissions');
    }
}
```

- [ ] **Step 4: Write the `UserPermission` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPermission extends Model
{
    protected $fillable = ['user_id', 'permission_id', 'granted_by'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }

    public function granter()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
```

- [ ] **Step 5: Write the `AuthorizesByPermission` trait**

```php
<?php

namespace App\Models\Concerns;

use App\Models\UserPermission;

trait AuthorizesByPermission
{
    protected $permissionCodesCache = null;

    public function userPermissions()
    {
        return $this->hasMany(UserPermission::class);
    }

    public function permissionCodes(): array
    {
        if ($this->permissionCodesCache === null) {
            $this->permissionCodesCache = $this->userPermissions()
                ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
                ->where('permissions.is_active', true)
                ->pluck('permissions.code')
                ->all();
        }

        return $this->permissionCodesCache;
    }

    public function hasPermissionTo(string $code): bool
    {
        return in_array($code, $this->permissionCodes(), true);
    }
}
```

- [ ] **Step 6: Wire the trait and the Gate into the app**

Edit `app/Models/User.php`: add `use App\Models\Concerns\AuthorizesByPermission;` to the imports and `AuthorizesByPermission` to the class's `use` trait list (alongside `HasApiTokens, HasFactory, Notifiable`).

Edit `app/Providers/AuthServiceProvider.php`:

```php
<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    public function boot()
    {
        $this->registerPolicies();

        Gate::before(function ($user, $ability) {
            if (! method_exists($user, 'hasPermissionTo')) {
                return null;
            }

            return $user->hasPermissionTo($ability) ? true : null;
        });
    }
}
```

- [ ] **Step 7: Run migration and the test**

Run: `php artisan migrate && php artisan test --filter=PermissionAuthorizationTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_01_000005_create_user_permissions_table.php app/Models/UserPermission.php app/Models/Concerns/AuthorizesByPermission.php app/Models/User.php app/Providers/AuthServiceProvider.php tests/Feature/PermissionAuthorizationTest.php
git commit -m "feat: add user_permissions and wire Gate::before to it"
```

---

### Task 6: Document number sequences

**Files:**
- Create: `database/migrations/2026_08_01_000006_create_document_number_sequences_table.php`
- Create: `app/Models/DocumentNumberSequence.php`
- Create: `app/Services/DocumentNumberGenerator.php`
- Test: `tests/Feature/DocumentNumberGeneratorTest.php`

**Interfaces:**
- Consumes: `App\Models\Branch` (Task 1).
- Produces: `App\Services\DocumentNumberGenerator::next(Branch $branch, string $documentType, string $format = '{type}/{branch}/{period}/{number:5}'): string`. This is what PKB/invoice/payment/transfer/receipt/adjustment number generation will call in later phases — one shared, race-safe generator instead of `MAX(id)+1` per module.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Services\DocumentNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_sequential_formatted_numbers_per_branch_and_type(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $generator = new DocumentNumberGenerator();

        $first = $generator->next($branch, 'PKB');
        $second = $generator->next($branch, 'PKB');

        $period = now()->format('Ym');
        $this->assertSame("PKB/JKT/{$period}/00001", $first);
        $this->assertSame("PKB/JKT/{$period}/00002", $second);
    }

    public function test_sequences_are_isolated_per_branch(): void
    {
        $jakarta = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $bandung = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $generator = new DocumentNumberGenerator();

        $generator->next($jakarta, 'PKB');
        $bandungFirst = $generator->next($bandung, 'PKB');

        $period = now()->format('Ym');
        $this->assertSame("PKB/BDG/{$period}/00001", $bandungFirst);
    }

    public function test_sequences_are_isolated_per_document_type(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $generator = new DocumentNumberGenerator();

        $generator->next($branch, 'PKB');
        $invoiceFirst = $generator->next($branch, 'INV');

        $period = now()->format('Ym');
        $this->assertSame("INV/JKT/{$period}/00001", $invoiceFirst);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DocumentNumberGeneratorTest`
Expected: FAIL.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDocumentNumberSequencesTable extends Migration
{
    public function up()
    {
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('document_type', 50);
            $table->string('period', 20);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'document_type', 'period']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('document_number_sequences');
    }
}
```

- [ ] **Step 4: Write the `DocumentNumberSequence` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentNumberSequence extends Model
{
    protected $fillable = ['branch_id', 'document_type', 'period', 'last_number'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
```

- [ ] **Step 5: Write `DocumentNumberGenerator`**

```php
<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DocumentNumberSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DocumentNumberGenerator
{
    public function next(Branch $branch, string $documentType, string $format = '{type}/{branch}/{period}/{number:5}'): string
    {
        return DB::transaction(function () use ($branch, $documentType, $format) {
            $period = now()->format('Ym');

            $sequence = DocumentNumberSequence::where('branch_id', $branch->id)
                ->where('document_type', $documentType)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                try {
                    DocumentNumberSequence::create([
                        'branch_id' => $branch->id,
                        'document_type' => $documentType,
                        'period' => $period,
                        'last_number' => 0,
                    ]);
                } catch (QueryException $e) {
                    // Another concurrent transaction created the row first — fall through and lock it below.
                }

                $sequence = DocumentNumberSequence::where('branch_id', $branch->id)
                    ->where('document_type', $documentType)
                    ->where('period', $period)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $sequence->last_number += 1;
            $sequence->save();

            return $this->format($format, $branch, $documentType, $period, $sequence->last_number);
        });
    }

    protected function format(string $format, Branch $branch, string $documentType, string $period, int $number): string
    {
        $formatted = preg_replace_callback('/\{number(?::(\d+))?\}/', function ($matches) use ($number) {
            $pad = isset($matches[1]) ? (int) $matches[1] : 5;
            return str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
        }, $format);

        return strtr($formatted, [
            '{type}' => strtoupper($documentType),
            '{branch}' => strtoupper($branch->code),
            '{period}' => $period,
        ]);
    }
}
```

- [ ] **Step 6: Run migration and the test**

Run: `php artisan migrate && php artisan test --filter=DocumentNumberGeneratorTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_01_000006_create_document_number_sequences_table.php app/Models/DocumentNumberSequence.php app/Services/DocumentNumberGenerator.php tests/Feature/DocumentNumberGeneratorTest.php
git commit -m "feat: add race-safe per-branch document number generator"
```

---

### Task 7: Polymorphic `attachments`

**Files:**
- Create: `database/migrations/2026_08_01_000007_create_attachments_table.php`
- Create: `app/Models/Attachment.php`
- Create: `app/Models/Concerns/HasAttachments.php`
- Modify: `app/Models/Branch.php` (first real consumer of the trait)
- Test: `tests/Feature/AttachmentTest.php`

**Interfaces:**
- Produces: `App\Models\Attachment` (polymorphic, `attachable_type` + `attachable_id`). `App\Models\Concerns\HasAttachments` trait providing `attachments()` (morphMany) and `addAttachment(UploadedFile $file, $uploadedBy = null, string $disk = 'local'): Attachment`. Later phases (stock adjustment evidence, etc.) add `use HasAttachments;` to their own models — no new table needed.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_can_have_attachments_uploaded_and_linked_polymorphically(): void
    {
        Storage::fake('local');
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $file = UploadedFile::fake()->create('izin-usaha.pdf', 100, 'application/pdf');

        $attachment = $branch->addAttachment($file);

        $this->assertCount(1, $branch->attachments()->get());
        $this->assertSame('izin-usaha.pdf', $attachment->original_name);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_attachments_from_different_branches_do_not_leak_into_each_other(): void
    {
        Storage::fake('local');
        $jakarta = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $bandung = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);

        $jakarta->addAttachment(UploadedFile::fake()->create('a.pdf'));

        $this->assertCount(1, $jakarta->attachments()->get());
        $this->assertCount(0, $bandung->attachments()->get());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttachmentTest`
Expected: FAIL.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAttachmentsTable extends Migration
{
    public function up()
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('disk', 50)->default('local');
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('attachments');
    }
}
```

- [ ] **Step 4: Write the `Attachment` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    protected $fillable = ['disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by'];

    public function attachable()
    {
        return $this->morphTo();
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
```

- [ ] **Step 5: Write the `HasAttachments` trait**

```php
<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Http\UploadedFile;

trait HasAttachments
{
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function addAttachment(UploadedFile $file, $uploadedBy = null, string $disk = 'local'): Attachment
    {
        $path = $file->store($this->attachmentStoragePath(), $disk);

        return $this->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $uploadedBy,
        ]);
    }

    protected function attachmentStoragePath(): string
    {
        return strtolower(class_basename($this)) . 's/' . $this->getKey();
    }
}
```

- [ ] **Step 6: Apply the trait to `Branch`**

Edit `app/Models/Branch.php`: add `use App\Models\Concerns\HasAttachments;` to imports and `HasAttachments` to the class's `use` trait list.

- [ ] **Step 7: Run migration and the test**

Run: `php artisan migrate && php artisan test --filter=AttachmentTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_01_000007_create_attachments_table.php app/Models/Attachment.php app/Models/Concerns/HasAttachments.php app/Models/Branch.php tests/Feature/AttachmentTest.php
git commit -m "feat: add polymorphic attachments shared across modules"
```

---

### Task 8: Login/logout + active-user enforcement

**Files:**
- Create: `app/Http/Controllers/Auth/LoginController.php`
- Create: `app/Http/Middleware/EnsureUserIsActive.php`
- Modify: `app/Http/Kernel.php` (register `active` middleware alias)
- Modify: `app/Providers/RouteServiceProvider.php` (`HOME` → `/dashboard`)
- Modify: `routes/web.php`
- Create: `resources/views/layouts/guest.blade.php`
- Create: `resources/views/layouts/app.blade.php`
- Create: `resources/views/auth/login.blade.php`
- Create: `resources/views/dashboard.blade.php`
- Test: `tests/Feature/AuthenticationTest.php`

**Interfaces:**
- Consumes: `User.username`, `User.is_active`, `User.last_login_at` (Task 2).
- Produces: `POST /login`, `POST /logout`, `GET /dashboard` (route name `dashboard`), the `active` route-middleware alias every later authenticated route group will use alongside `auth`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_correct_username_and_password(): void
    {
        $user = User::factory()->create([
            'username' => 'budi',
            'password' => bcrypt('rahasia123'),
        ]);

        $response = $this->post('/login', [
            'username' => 'budi',
            'password' => 'rahasia123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['username' => 'budi', 'password' => bcrypt('rahasia123')]);

        $response = $this->post('/login', [
            'username' => 'budi',
            'password' => 'salah',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'username' => 'budi',
            'password' => bcrypt('rahasia123'),
            'is_active' => false,
        ]);

        $response = $this->post('/login', [
            'username' => 'budi',
            'password' => 'rahasia123',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/logout');

        $this->assertGuest();
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $user->update(['is_active' => false]);

        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
        $this->assertGuest();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AuthenticationTest`
Expected: FAIL (routes/controller don't exist yet).

- [ ] **Step 3: Write `LoginController`**

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $credentials['username'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return back()->withErrors(['username' => 'Username atau password salah.'])->onlyInput('username');
        }

        if (! $user->is_active) {
            return back()->withErrors(['username' => 'Akun tidak aktif.'])->onlyInput('username');
        }

        Auth::login($user);
        $user->forceFill(['last_login_at' => now()])->save();
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
```

- [ ] **Step 4: Write `EnsureUserIsActive` middleware**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class EnsureUserIsActive
{
    public function handle($request, Closure $next)
    {
        $user = Auth::user();

        if ($user && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect('/login')->withErrors(['username' => 'Akun tidak aktif.']);
        }

        return $next($request);
    }
}
```

Edit `app/Http/Kernel.php`, add to `$routeMiddleware`:

```php
        'active' => \App\Http\Middleware\EnsureUserIsActive::class,
```

- [ ] **Step 5: Point post-login redirect at `/dashboard`**

Edit `app/Providers/RouteServiceProvider.php`, change:

```php
    public const HOME = '/dashboard';
```

- [ ] **Step 6: Write routes**

Replace `routes/web.php` contents:

```php
<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});
```

- [ ] **Step 7: Write the Blade views**

`resources/views/layouts/guest.blade.php`:

```blade
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sistem Manajemen Bengkel')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    @yield('content')

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
```

`resources/views/layouts/app.blade.php`:

```blade
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sistem Manajemen Bengkel')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="{{ route('dashboard') }}">Sistem Manajemen Bengkel</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarMain">
                @auth
                    <ul class="navbar-nav ms-auto align-items-lg-center">
                        <li class="nav-item">
                            <span class="nav-link text-light">{{ auth()->user()->name }}</span>
                        </li>
                        <li class="nav-item">
                            <form method="POST" action="{{ route('logout') }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-outline-light btn-sm">Logout</button>
                            </form>
                        </li>
                    </ul>
                @endauth
            </div>
        </div>
    </nav>

    <main class="container-fluid py-4">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @yield('content')
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
```

`resources/views/auth/login.blade.php`:

```blade
@extends('layouts.guest')

@section('content')
    <div class="container">
        <div class="row justify-content-center align-items-center" style="min-height: 100vh;">
            <div class="col-12 col-sm-8 col-md-6 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h1 class="h4 mb-3 text-center">Sistem Manajemen Bengkel</h1>
                        <p class="text-muted text-center mb-4">Masuk untuk melanjutkan</p>

                        @if ($errors->any())
                            <div class="alert alert-danger">
                                <ul class="mb-0 ps-3">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('login') }}">
                            @csrf
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input id="username" type="text" name="username" value="{{ old('username') }}" class="form-control" required autofocus>
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input id="password" type="password" name="password" class="form-control" required>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Masuk</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
```

`resources/views/dashboard.blade.php`:

```blade
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1 class="h3 mb-4">Dashboard</h1>
    <div class="card">
        <div class="card-body">
            <p class="mb-0">Selamat datang, {{ auth()->user()->name }}.</p>
        </div>
    </div>
@endsection
```

- [ ] **Step 8: Run the test**

Run: `php artisan test --filter=AuthenticationTest`
Expected: PASS (5 tests).

- [ ] **Step 9: Run the full test suite to confirm no regressions**

Run: `php artisan test`
Expected: all tests across Tasks 1–8 PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Auth/LoginController.php app/Http/Middleware/EnsureUserIsActive.php app/Http/Kernel.php app/Providers/RouteServiceProvider.php routes/web.php resources/views/layouts resources/views/auth resources/views/dashboard.blade.php tests/Feature/AuthenticationTest.php
git commit -m "feat: add login/logout flow with active-user enforcement"
```

---

## Self-Review

**Spec coverage** (against migration doc §5 "Migration 002" and the confirmed decisions):
- `branches` ✅ Task 1. `users` extensions ✅ Task 2. `user_branches` incl. single-default enforcement ✅ Task 3. `menus`/`permissions` ✅ Task 4. `user_permissions` + Gate wiring ✅ Task 5. No `roles`/`role_permissions` tables anywhere ✅. Document numbering (cross-cutting decision) ✅ Task 6. Polymorphic attachments (cross-cutting decision) ✅ Task 7. Working login so the foundation is actually usable end-to-end ✅ Task 8.

**Explicitly out of scope for this plan** (next plan(s) to write):
- Administrasi CRUD screens (Branches / Users / User Branches / User Permissions Blade+AJAX UI) — the backend this UI will sit on is now fully built and tested, but the screens themselves are a separate, independently reviewable increment.
- `audit_logs` table and audit writing (migration doc §14.1) — deferred until there are real actions worth auditing (post/void invoice, etc. — those modules don't exist yet).
- Migrations 003–011 (customer/vehicle, mechanic/service, sparepart/inventory, PKB, invoice, payment) — each becomes its own plan, per the Scope Check in the writing-plans skill.

**Placeholder scan:** none found — every step has real, complete code.

**Type/name consistency check:** `DocumentNumberGenerator::next()` signature matches its only caller pattern used in tests; `UserBranchService::assign()/setDefault()` names match across Task 3's test and implementation; `HasAttachments::attachments()/addAttachment()` names match across Task 7's test and Branch usage; `hasPermissionTo()`/`hasAccessToBranch()`/`defaultBranch()` names are consistent between where they're defined (Tasks 3 & 5) and how the plan describes later phases will call them.

---

Plan complete and saved to `docs/superpowers/plans/2026-08-01-foundation-identity-access.md`. Two execution options:

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
