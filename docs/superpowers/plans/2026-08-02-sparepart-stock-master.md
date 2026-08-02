# Migration 005 — Sparepart & Saldo Stok Cabang — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build sparepart master data (identity separated from per-branch configuration and stock balance) with the project's first Policy-based, branch-scoped authorization, so future branch-scoped modules (PKB, invoice, etc.) have a working template to copy.

**Architecture:** Three new tables (`spareparts` identity, `sparepart_branches` per-branch config, `sparepart_branch_stocks` per-branch balance, the last two linked 1:1). A new `SparepartBranchPolicy` — the project's first Policy — enforces `hasPermissionToInBranch()` per record. A session-persisted branch switcher resolves "which branch" for every screen, since `sparepart.*` permissions only ever exist per-branch (no global fallback). Stock stays at zero and read-only until later migrations (007/008) give it a writer.

**Tech Stack:** Laravel 8.75, MySQL 8.0 (Laragon local), Blade + Bootstrap 5 (CDN, no build step), PHPUnit feature tests.

## Global Constraints

- Laravel 8.75 pinned — never use `Request::integer()` or other Laravel 9+ `Request` helper methods; cast manually (`(int) request('x')`).
- Every index/list endpoint uses `->simplePaginate()`, never `->paginate()`.
- `bigint` auto-increment PKs (`$table->id()` / `$table->foreignId()`), never UUID.
- No hard deletes anywhere — `is_active` boolean toggle only.
- No `roles` tables — permissions are direct-to-user (`user_permissions` global, `user_branch_permissions` branch-scoped), enforced via `Gate::before` + Policies.
- Reuse the existing design system: `.status-dot.status-active`/`.status-inactive` for status badges, `.card` (thin border, no shadow), `form-select`/`form-control` Bootstrap defaults, IBM Plex fonts already wired globally — do not hand-roll new component styles.
- `HasAudit` trait (`created_by`/`updated_by`) on every master/config table except pure balance/ledger rows.
- Test DB is the dedicated `bengkel_testing` MySQL database (configured via `phpunit.xml`), separate from the dev `laravel` DB — `RefreshDatabase` in every feature test handles this automatically; no manual DB setup needed to run the test suite.

---

### Task 1: Data model — migrations, models, auto-stock creation

**Files:**
- Create: `database/migrations/2026_08_02_000013_create_spareparts_table.php`
- Create: `database/migrations/2026_08_02_000014_create_sparepart_branches_table.php`
- Create: `database/migrations/2026_08_02_000015_create_sparepart_branch_stocks_table.php`
- Create: `app/Models/Sparepart.php`
- Create: `app/Models/SparepartBranch.php`
- Create: `app/Models/SparepartBranchStock.php`
- Test: `tests/Feature/SparepartModelTest.php`

**Interfaces:**
- Produces: `Sparepart` (fields: `code`, `name`, `is_active`; relation `sparepartBranches()`), `SparepartBranch` (fields: `sparepart_id`, `branch_id`, `rack_number`, `selling_price`, `minimum_stock`, `is_active`; relations `sparepart()`, `branch()`, `stock()`), `SparepartBranchStock` (PK `sparepart_branch_id`, fields `on_hand_qty`, `reserved_qty`, accessor `available_qty`). `SparepartBranch::create()` auto-creates a zeroed `SparepartBranchStock` row via a model event — every later task that creates a `SparepartBranch` relies on this and must never create the stock row itself.

- [ ] **Step 1: Write the failing model tests**

Create `tests/Feature/SparepartModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\SparepartBranchStock;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SparepartModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_sparepart_can_be_created_with_fillable_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);

        $this->assertSame('Ban Depan', $sparepart->name);
        $this->assertTrue($sparepart->is_active);
        $this->assertSame($user->id, $sparepart->created_by);
    }

    public function test_sparepart_code_is_unique(): void
    {
        Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);

        $this->expectException(QueryException::class);
        Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Belakang']);
    }

    public function test_sparepart_branches_rejects_duplicate_pair(): void
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $this->expectException(QueryException::class);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 120000]);
    }

    public function test_creating_sparepart_branch_automatically_creates_a_zeroed_stock_row(): void
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $this->assertDatabaseHas('sparepart_branch_stocks', [
            'sparepart_branch_id' => $sparepartBranch->id,
            'on_hand_qty' => 0,
            'reserved_qty' => 0,
        ]);
    }

    public function test_available_qty_accessor_computes_on_hand_minus_reserved(): void
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);
        DB::table('sparepart_branch_stocks')
            ->where('sparepart_branch_id', $sparepartBranch->id)
            ->update(['on_hand_qty' => 10, 'reserved_qty' => 3]);

        $stock = SparepartBranchStock::find($sparepartBranch->id);

        $this->assertEquals(7, $stock->available_qty);
    }

    public function test_deleting_sparepart_branch_cascades_to_its_stock_row(): void
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $sparepartBranch->delete();

        $this->assertDatabaseMissing('sparepart_branch_stocks', ['sparepart_branch_id' => $sparepartBranch->id]);
    }

    public function test_stock_check_constraint_rejects_negative_on_hand_qty(): void
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $this->expectException(QueryException::class);
        DB::table('sparepart_branch_stocks')
            ->where('sparepart_branch_id', $sparepartBranch->id)
            ->update(['on_hand_qty' => -1]);
    }

    public function test_stock_check_constraint_rejects_reserved_greater_than_on_hand(): void
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $this->expectException(QueryException::class);
        DB::table('sparepart_branch_stocks')
            ->where('sparepart_branch_id', $sparepartBranch->id)
            ->update(['on_hand_qty' => 5, 'reserved_qty' => 10]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SparepartModelTest`
Expected: FAIL — classes `Sparepart`, `SparepartBranch`, `SparepartBranchStock` and their tables don't exist yet.

- [ ] **Step 3: Write the migrations**

Create `database/migrations/2026_08_02_000013_create_spareparts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSparepartsTable extends Migration
{
    public function up()
    {
        Schema::create('spareparts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
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
        Schema::dropIfExists('spareparts');
    }
}
```

Create `database/migrations/2026_08_02_000014_create_sparepart_branches_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSparepartBranchesTable extends Migration
{
    public function up()
    {
        Schema::create('sparepart_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sparepart_id')->constrained('spareparts')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('rack_number', 30)->nullable();
            $table->decimal('selling_price', 18, 2);
            $table->decimal('minimum_stock', 18, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['sparepart_id', 'branch_id']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('sparepart_branches');
    }
}
```

Create `database/migrations/2026_08_02_000015_create_sparepart_branch_stocks_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateSparepartBranchStocksTable extends Migration
{
    public function up()
    {
        Schema::create('sparepart_branch_stocks', function (Blueprint $table) {
            $table->foreignId('sparepart_branch_id')->primary();
            $table->decimal('on_hand_qty', 18, 3)->default(0);
            $table->decimal('reserved_qty', 18, 3)->default(0);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('sparepart_branch_id')->references('id')->on('sparepart_branches')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE sparepart_branch_stocks ADD CONSTRAINT ck_stock_nonnegative CHECK (on_hand_qty >= 0 AND reserved_qty >= 0 AND reserved_qty <= on_hand_qty)');
    }

    public function down()
    {
        Schema::dropIfExists('sparepart_branch_stocks');
    }
}
```

- [ ] **Step 4: Write the models**

Create `app/Models/Sparepart.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sparepart extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function sparepartBranches()
    {
        return $this->hasMany(SparepartBranch::class);
    }
}
```

Create `app/Models/SparepartBranch.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SparepartBranch extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['sparepart_id', 'branch_id', 'rack_number', 'selling_price', 'minimum_stock', 'is_active'];

    protected $casts = [
        'selling_price' => 'decimal:2',
        'minimum_stock' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted()
    {
        static::created(function (SparepartBranch $sparepartBranch) {
            SparepartBranchStock::create([
                'sparepart_branch_id' => $sparepartBranch->id,
                'on_hand_qty' => 0,
                'reserved_qty' => 0,
            ]);
        });
    }

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function stock()
    {
        return $this->hasOne(SparepartBranchStock::class);
    }
}
```

Create `app/Models/SparepartBranchStock.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SparepartBranchStock extends Model
{
    protected $primaryKey = 'sparepart_branch_id';

    public $incrementing = false;

    protected $keyType = 'int';

    const CREATED_AT = null;

    protected $fillable = ['sparepart_branch_id', 'on_hand_qty', 'reserved_qty'];

    protected $casts = [
        'on_hand_qty' => 'decimal:3',
        'reserved_qty' => 'decimal:3',
    ];

    public function sparepartBranch()
    {
        return $this->belongsTo(SparepartBranch::class);
    }

    public function getAvailableQtyAttribute()
    {
        return $this->on_hand_qty - $this->reserved_qty;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=SparepartModelTest`
Expected: PASS (all 7 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_02_000013_create_spareparts_table.php database/migrations/2026_08_02_000014_create_sparepart_branches_table.php database/migrations/2026_08_02_000015_create_sparepart_branch_stocks_table.php app/Models/Sparepart.php app/Models/SparepartBranch.php app/Models/SparepartBranchStock.php tests/Feature/SparepartModelTest.php
git commit -m "feat: add sparepart, sparepart_branches, and stock tables"
```

---

### Task 2: Authorization primitive — Policy + branch-permission helper

**Files:**
- Create: `app/Policies/SparepartBranchPolicy.php`
- Modify: `app/Models/User.php` — add `branchesWithPermission(string $code)`
- Modify: `app/Providers/AuthServiceProvider.php` — register the policy
- Test: `tests/Feature/SparepartAuthorizationTest.php`

**Interfaces:**
- Consumes: `SparepartBranch` (Task 1), `User::hasPermissionToInBranch(string $code, int $branchId): bool` and `User::branches()` (both pre-existing).
- Produces: `User::branchesWithPermission(string $code): \Illuminate\Support\Collection` (of `Branch` models) — used by Task 3's branch switcher and Task 5's sidebar check. `SparepartBranchPolicy::view/update/delete(User, SparepartBranch): bool` — used by every controller action in Tasks 3-4 via `$this->authorize()` / `@can` in views.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SparepartAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SparepartAuthorizationTest extends TestCase
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

    public function test_branches_with_permission_returns_only_branches_where_the_code_is_granted(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        (new UserBranchService())->assign($user, $branchB);

        $reloaded = User::find($user->id);
        $result = $reloaded->branchesWithPermission('sparepart.view');

        $this->assertCount(1, $result);
        $this->assertSame($branchA->id, $result->first()->id);
    }

    public function test_branches_with_permission_returns_empty_collection_without_any_grant(): void
    {
        $user = User::factory()->create();
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $this->assertTrue($user->branchesWithPermission('sparepart.view')->isEmpty());
    }

    public function test_policy_grants_view_and_update_for_the_correct_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.edit');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $sparepartBranch));
        $this->assertTrue($reloaded->can('update', $sparepartBranch));
    }

    public function test_policy_denies_access_for_a_user_with_permission_in_a_different_branch(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $sparepartBranchInB = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchB->id, 'selling_price' => 100000]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('view', $sparepartBranchInB));
    }

    public function test_policy_delete_requires_the_delete_code_not_just_edit(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.edit');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('update', $sparepartBranch));
        $this->assertFalse($reloaded->can('delete', $sparepartBranch));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SparepartAuthorizationTest`
Expected: FAIL — `branchesWithPermission()` doesn't exist and the Policy isn't registered, so `can('view', ...)`/`can('update', ...)`/`can('delete', ...)` all resolve false regardless of grants.

- [ ] **Step 3: Add `branchesWithPermission` to the User model**

In `app/Models/User.php`, add this method (after `hasAccessToBranch`):

```php
    public function branchesWithPermission(string $code)
    {
        return $this->branches->filter(fn (Branch $branch) => $this->hasPermissionToInBranch($code, $branch->id))->values();
    }
```

- [ ] **Step 4: Write the Policy**

Create `app/Policies/SparepartBranchPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\SparepartBranch;
use App\Models\User;

class SparepartBranchPolicy
{
    public function view(User $user, SparepartBranch $sparepartBranch): bool
    {
        return $user->hasPermissionToInBranch('sparepart.view', $sparepartBranch->branch_id);
    }

    public function update(User $user, SparepartBranch $sparepartBranch): bool
    {
        return $user->hasPermissionToInBranch('sparepart.edit', $sparepartBranch->branch_id);
    }

    public function delete(User $user, SparepartBranch $sparepartBranch): bool
    {
        return $user->hasPermissionToInBranch('sparepart.delete', $sparepartBranch->branch_id);
    }
}
```

- [ ] **Step 5: Register the Policy**

In `app/Providers/AuthServiceProvider.php`, update the `$policies` array:

```php
    protected $policies = [
        \App\Models\SparepartBranch::class => \App\Policies\SparepartBranchPolicy::class,
    ];
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=SparepartAuthorizationTest`
Expected: PASS (all 5 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Policies/SparepartBranchPolicy.php app/Models/User.php app/Providers/AuthServiceProvider.php tests/Feature/SparepartAuthorizationTest.php
git commit -m "feat: add SparepartBranchPolicy and branch-permission listing helper"
```

---

### Task 3: Index screen + branch switcher + create flows

**Files:**
- Create: `app/Http/Controllers/SparepartBranchController.php`
- Create: `app/Http/Requests/StoreSparepartRequest.php`
- Create: `app/Http/Requests/StoreSparepartToBranchRequest.php`
- Create: `resources/views/sparepart-branches/index.blade.php`
- Create: `resources/views/sparepart-branches/no-access.blade.php`
- Create: `resources/views/sparepart-branches/create.blade.php`
- Create: `resources/views/sparepart-branches/create-existing.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/SparepartBranchIndexAndCreateTest.php`

**Interfaces:**
- Consumes: `Sparepart`, `SparepartBranch` (Task 1), `User::branchesWithPermission()`, `SparepartBranchPolicy` (Task 2).
- Produces: `SparepartBranchController::resolveCurrentBranch(User $user): ?Branch` (protected) — Task 4's `edit`/`update`/`deactivate`/`activate` actions do NOT need it (they resolve branch from the route-bound `SparepartBranch` itself via the Policy), but it must stay on this controller since Task 4 adds methods to the same class. Routes named `sparepart-branches.index`, `.create`, `.store`, `.createExisting`, `.storeExisting`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SparepartBranchIndexAndCreateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SparepartBranchIndexAndCreateTest extends TestCase
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

    public function test_index_shows_no_access_page_for_user_without_any_branch_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/sparepart-branches');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_index_lists_configs_for_the_current_branch_only(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $this->grantBranchPermission($user, $branchB, 'sparepart.view');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan Jakarta']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchA->id, 'selling_price' => 100000]);
        $sparepartB = Sparepart::create(['code' => 'BAN-02', 'name' => 'Ban Depan Bandung']);
        SparepartBranch::create(['sparepart_id' => $sparepartB->id, 'branch_id' => $branchB->id, 'selling_price' => 90000]);

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches?branch_id=' . $branchA->id);

        $response->assertOk();
        $response->assertSee('Ban Depan Jakarta');
        $response->assertDontSee('Ban Depan Bandung');
    }

    public function test_index_search_filters_by_code_or_name(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $ban = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        SparepartBranch::create(['sparepart_id' => $ban->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);
        $oli = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $oli->id, 'branch_id' => $branch->id, 'selling_price' => 50000]);

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches?q=Oli');

        $response->assertOk();
        $response->assertSee('Oli Mesin');
        $response->assertDontSee('Ban Depan');
    }

    public function test_create_new_sparepart_creates_identity_branch_config_and_zeroed_stock(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');
        $this->actingAs(User::find($user->id))->get('/sparepart-branches'); // establishes session branch context

        $response = $this->post('/sparepart-branches', [
            'code' => 'BAN-01',
            'name' => 'Ban Depan',
            'rack_number' => 'A1',
            'selling_price' => 150000,
            'minimum_stock' => 2,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $this->assertDatabaseHas('spareparts', ['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $sparepartBranch = SparepartBranch::whereHas('sparepart', fn ($q) => $q->where('code', 'BAN-01'))->first();
        $this->assertNotNull($sparepartBranch);
        $this->assertSame($branch->id, $sparepartBranch->branch_id);
        $this->assertDatabaseHas('sparepart_branch_stocks', ['sparepart_branch_id' => $sparepartBranch->id, 'on_hand_qty' => 0]);
    }

    public function test_create_new_sparepart_requires_sparepart_create_permission_in_current_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response = $this->post('/sparepart-branches', [
            'code' => 'BAN-01', 'name' => 'Ban Depan', 'selling_price' => 150000,
        ]);

        $response->assertForbidden();
    }

    public function test_create_new_sparepart_validates_global_code_uniqueness(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');
        Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan Lama']);
        $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response = $this->post('/sparepart-branches', [
            'code' => 'BAN-01', 'name' => 'Ban Depan Baru', 'selling_price' => 150000,
        ]);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_create_existing_lists_only_spareparts_not_yet_configured_for_current_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');
        $alreadyConfigured = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Sudah Ada']);
        SparepartBranch::create(['sparepart_id' => $alreadyConfigured->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);
        $notYetConfigured = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Belum Ada']);
        $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response = $this->get('/sparepart-branches/create-existing');

        $response->assertOk();
        $response->assertSee('Oli Belum Ada');
        $response->assertDontSee('Ban Sudah Ada');
    }

    public function test_store_existing_attaches_sparepart_to_branch_with_new_config_and_stock(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response = $this->post('/sparepart-branches/existing', [
            'sparepart_id' => $sparepart->id,
            'rack_number' => 'B2',
            'selling_price' => 60000,
            'minimum_stock' => 5,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $sparepartBranch = SparepartBranch::where('sparepart_id', $sparepart->id)->where('branch_id', $branch->id)->first();
        $this->assertNotNull($sparepartBranch);
        $this->assertDatabaseHas('sparepart_branch_stocks', ['sparepart_branch_id' => $sparepartBranch->id, 'on_hand_qty' => 0]);
    }

    public function test_store_existing_rejects_sparepart_already_configured_for_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);
        $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response = $this->post('/sparepart-branches/existing', [
            'sparepart_id' => $sparepart->id, 'selling_price' => 100000,
        ]);

        $response->assertSessionHasErrors(['sparepart_id']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest`
Expected: FAIL — route `/sparepart-branches` doesn't exist (404).

- [ ] **Step 3: Write the Form Requests**

Create `app/Http/Requests/StoreSparepartRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSparepartRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = session('current_sparepart_branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('sparepart.create', (int) $branchId);
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:30', 'unique:spareparts,code'],
            'name' => ['required', 'string', 'max:150'],
            'rack_number' => ['nullable', 'string', 'max:30'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
```

Create `app/Http/Requests/StoreSparepartToBranchRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class StoreSparepartToBranchRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = session('current_sparepart_branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('sparepart.create', (int) $branchId);
    }

    public function rules()
    {
        return [
            'sparepart_id' => ['required', 'integer', 'exists:spareparts,id'],
            'rack_number' => ['nullable', 'string', 'max:30'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $branchId = (int) session('current_sparepart_branch_id');

            if ($this->sparepart_id && SparepartBranch::where('sparepart_id', $this->sparepart_id)->where('branch_id', $branchId)->exists()) {
                $validator->errors()->add('sparepart_id', 'Sparepart ini sudah terkonfigurasi di cabang ini.');
            }
        });
    }
}
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/SparepartBranchController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSparepartRequest;
use App\Http\Requests\StoreSparepartToBranchRequest;
use App\Models\Branch;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SparepartBranchController extends Controller
{
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

        $sparepartBranches = SparepartBranch::with(['sparepart', 'stock'])
            ->where('branch_id', $currentBranch->id)
            ->when(request('q'), function ($query, $q) {
                $query->whereHas('sparepart', function ($inner) use ($q) {
                    $inner->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->orderBy('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('sparepart-branches.index', compact('sparepartBranches', 'allowedBranches', 'currentBranch'));
    }

    public function create()
    {
        $branch = $this->resolveCurrentBranch(auth()->user());

        if (! $branch || ! auth()->user()->hasPermissionToInBranch('sparepart.create', $branch->id)) {
            abort(403);
        }

        return view('sparepart-branches.create', compact('branch'));
    }

    public function store(StoreSparepartRequest $request)
    {
        $branch = $this->resolveCurrentBranch(auth()->user());
        $data = $request->validated();

        DB::transaction(function () use ($data, $branch) {
            $sparepart = Sparepart::create([
                'code' => $data['code'],
                'name' => $data['name'],
            ]);

            SparepartBranch::create([
                'sparepart_id' => $sparepart->id,
                'branch_id' => $branch->id,
                'rack_number' => $data['rack_number'] ?? null,
                'selling_price' => $data['selling_price'],
                'minimum_stock' => $data['minimum_stock'] ?? 0,
            ]);
        });

        return redirect()->route('sparepart-branches.index')->with('status', 'Sparepart berhasil ditambahkan.');
    }

    public function createExisting()
    {
        $branch = $this->resolveCurrentBranch(auth()->user());

        if (! $branch || ! auth()->user()->hasPermissionToInBranch('sparepart.create', $branch->id)) {
            abort(403);
        }

        $availableSpareparts = Sparepart::where('is_active', true)
            ->whereDoesntHave('sparepartBranches', function ($query) use ($branch) {
                $query->where('branch_id', $branch->id);
            })
            ->orderBy('name')
            ->get();

        return view('sparepart-branches.create-existing', compact('availableSpareparts', 'branch'));
    }

    public function storeExisting(StoreSparepartToBranchRequest $request)
    {
        $branch = $this->resolveCurrentBranch(auth()->user());
        $data = $request->validated();

        DB::transaction(function () use ($data, $branch) {
            SparepartBranch::create([
                'sparepart_id' => $data['sparepart_id'],
                'branch_id' => $branch->id,
                'rack_number' => $data['rack_number'] ?? null,
                'selling_price' => $data['selling_price'],
                'minimum_stock' => $data['minimum_stock'] ?? 0,
            ]);
        });

        return redirect()->route('sparepart-branches.index')->with('status', 'Sparepart berhasil ditambahkan ke cabang ini.');
    }

    protected function resolveCurrentBranch(User $user): ?Branch
    {
        $allowedBranches = $user->branchesWithPermission('sparepart.view');

        if ($allowedBranches->isEmpty()) {
            return null;
        }

        return $allowedBranches->firstWhere('id', session('current_sparepart_branch_id'))
            ?? $allowedBranches->first();
    }
}
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\SparepartBranchController;
```

Add this route group inside the existing `Route::middleware(['auth'])->group(...)` block, near the other master-data groups:

```php
    Route::prefix('sparepart-branches')->name('sparepart-branches.')->group(function () {
        Route::get('/', [SparepartBranchController::class, 'index'])->name('index');
        Route::get('/create', [SparepartBranchController::class, 'create'])->name('create');
        Route::post('/', [SparepartBranchController::class, 'store'])->name('store');
        Route::get('/create-existing', [SparepartBranchController::class, 'createExisting'])->name('createExisting');
        Route::post('/existing', [SparepartBranchController::class, 'storeExisting'])->name('storeExisting');
    });
```

- [ ] **Step 6: Write the views**

Create `resources/views/sparepart-branches/no-access.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Master Sparepart')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i>Master Sparepart</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body text-center text-muted py-5">
            Anda belum memiliki akses sparepart di cabang manapun. Hubungi admin untuk meminta akses.
        </div>
    </div>
@endsection
```

Create `resources/views/sparepart-branches/index.blade.php`:

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

    <div class="row mb-3">
        <div class="col-md-4">
            <form method="GET" action="{{ route('sparepart-branches.index') }}">
                <label for="branch_id" class="form-label">Cabang</label>
                <select name="branch_id" id="branch_id" class="form-select" onchange="this.form.submit()">
                    @foreach ($allowedBranches as $branch)
                        <option value="{{ $branch->id }}" {{ $branch->id === $currentBranch->id ? 'selected' : '' }}>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="col-md-8">
            <form method="GET" action="{{ route('sparepart-branches.index') }}">
                <input type="hidden" name="branch_id" value="{{ $currentBranch->id }}">
                <label for="q" class="form-label">Cari</label>
                <input type="text" name="q" id="q" value="{{ request('q') }}" class="form-control" placeholder="Kode atau nama sparepart">
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
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
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">Belum ada sparepart di cabang ini.</td></tr>
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

Note: the `@can('update', ...)` "Ubah" link and the activate/deactivate buttons are added together in Task 4 once `edit`/`update`/`deactivate`/`activate` routes exist — the link above will 404 until then, which is expected and fixed by Task 4, not this task.

Create `resources/views/sparepart-branches/create.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Sparepart Baru')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i>Sparepart Baru — {{ $branch->name }}</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('sparepart-branches.store') }}">
                @csrf
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

Create `resources/views/sparepart-branches/create-existing.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Tambah Sparepart dari Cabang Lain')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-link-45deg me-2"></i>Tambah Sparepart ke {{ $branch->name }}</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('sparepart-branches.storeExisting') }}">
                @csrf
                <div class="mb-3">
                    <label for="sparepart_id" class="form-label">Sparepart</label>
                    <select name="sparepart_id" id="sparepart_id" class="form-select @error('sparepart_id') is-invalid @enderror" required>
                        <option value="">-- Pilih Sparepart --</option>
                        @foreach ($availableSpareparts as $sparepart)
                            <option value="{{ $sparepart->id }}" {{ (int) old('sparepart_id') === $sparepart->id ? 'selected' : '' }}>
                                {{ $sparepart->code }} — {{ $sparepart->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('sparepart_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
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

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest`
Expected: PASS (all 9 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/SparepartBranchController.php app/Http/Requests/StoreSparepartRequest.php app/Http/Requests/StoreSparepartToBranchRequest.php resources/views/sparepart-branches/index.blade.php resources/views/sparepart-branches/no-access.blade.php resources/views/sparepart-branches/create.blade.php resources/views/sparepart-branches/create-existing.blade.php routes/web.php tests/Feature/SparepartBranchIndexAndCreateTest.php
git commit -m "feat: add sparepart branch index, switcher, and create flows"
```

---

### Task 4: Edit + activate/deactivate

**Files:**
- Create: `app/Http/Requests/UpdateSparepartBranchRequest.php`
- Create: `resources/views/sparepart-branches/edit.blade.php`
- Modify: `app/Http/Controllers/SparepartBranchController.php` — add `edit`, `update`, `deactivate`, `activate`
- Modify: `resources/views/sparepart-branches/index.blade.php` — add activate/deactivate buttons
- Modify: `routes/web.php`
- Test: `tests/Feature/SparepartBranchEditAndDeactivateTest.php`

**Interfaces:**
- Consumes: `SparepartBranchController::resolveCurrentBranch()` is NOT needed here — `edit`/`update`/`deactivate`/`activate` authorize directly against the route-bound `SparepartBranch` via `SparepartBranchPolicy` (Task 2), independent of session branch context. `sparepart.edit` gates field edits (rack/price/min-stock); `sparepart.delete` gates the is_active toggle — these are deliberately different permissions, matching the two distinct codes seeded in migration 002.
- Produces: routes `sparepart-branches.edit`, `.update`, `.deactivate`, `.activate`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SparepartBranchEditAndDeactivateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SparepartBranchEditAndDeactivateTest extends TestCase
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

    protected function makeSparepartBranch(Branch $branch): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);

        return SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);
    }

    public function test_edit_shows_branch_config_fields_for_authorized_user(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.edit');

        $response = $this->actingAs(User::find($user->id))->get("/sparepart-branches/{$sparepartBranch->id}/edit");

        $response->assertOk();
        $response->assertSee('Ban Depan');
    }

    public function test_edit_is_forbidden_for_user_with_permission_in_a_different_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $otherBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $otherBranch, 'sparepart.edit');

        $response = $this->actingAs(User::find($user->id))->get("/sparepart-branches/{$sparepartBranch->id}/edit");

        $response->assertForbidden();
    }

    public function test_update_saves_rack_price_minimum_stock_without_touching_is_active(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.edit');

        $response = $this->actingAs(User::find($user->id))->put("/sparepart-branches/{$sparepartBranch->id}", [
            'rack_number' => 'C3',
            'selling_price' => 175000,
            'minimum_stock' => 4,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $this->assertDatabaseHas('sparepart_branches', [
            'id' => $sparepartBranch->id,
            'rack_number' => 'C3',
            'selling_price' => 175000,
            'is_active' => true,
        ]);
    }

    public function test_deactivate_sets_is_active_false_and_requires_sparepart_delete_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.delete');

        $response = $this->actingAs(User::find($user->id))->patch("/sparepart-branches/{$sparepartBranch->id}/deactivate");

        $response->assertRedirect('/sparepart-branches');
        $this->assertDatabaseHas('sparepart_branches', ['id' => $sparepartBranch->id, 'is_active' => false]);
    }

    public function test_activate_sets_is_active_true(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $sparepartBranch->update(['is_active' => false]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.delete');

        $response = $this->actingAs(User::find($user->id))->patch("/sparepart-branches/{$sparepartBranch->id}/activate");

        $response->assertRedirect('/sparepart-branches');
        $this->assertDatabaseHas('sparepart_branches', ['id' => $sparepartBranch->id, 'is_active' => true]);
    }

    public function test_deactivate_is_forbidden_with_only_edit_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.edit');

        $response = $this->actingAs(User::find($user->id))->patch("/sparepart-branches/{$sparepartBranch->id}/deactivate");

        $response->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SparepartBranchEditAndDeactivateTest`
Expected: FAIL — routes don't exist (404).

- [ ] **Step 3: Write the Form Request**

Create `app/Http/Requests/UpdateSparepartBranchRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSparepartBranchRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('update', $this->route('sparepartBranch'));
    }

    public function rules()
    {
        return [
            'rack_number' => ['nullable', 'string', 'max:30'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
```

- [ ] **Step 4: Add the controller methods**

In `app/Http/Controllers/SparepartBranchController.php`, add these imports:

```php
use App\Http\Requests\UpdateSparepartBranchRequest;
```

Add these methods to the class (after `storeExisting`, before `resolveCurrentBranch`):

```php
    public function edit(SparepartBranch $sparepartBranch)
    {
        $this->authorize('update', $sparepartBranch);

        $sparepartBranch->load('sparepart');

        return view('sparepart-branches.edit', compact('sparepartBranch'));
    }

    public function update(UpdateSparepartBranchRequest $request, SparepartBranch $sparepartBranch)
    {
        $sparepartBranch->update($request->validated());

        return redirect()->route('sparepart-branches.index')->with('status', 'Konfigurasi sparepart berhasil diperbarui.');
    }

    public function deactivate(SparepartBranch $sparepartBranch)
    {
        $this->authorize('delete', $sparepartBranch);

        $sparepartBranch->update(['is_active' => false]);

        return redirect()->route('sparepart-branches.index')->with('status', 'Sparepart dinonaktifkan di cabang ini.');
    }

    public function activate(SparepartBranch $sparepartBranch)
    {
        $this->authorize('delete', $sparepartBranch);

        $sparepartBranch->update(['is_active' => true]);

        return redirect()->route('sparepart-branches.index')->with('status', 'Sparepart diaktifkan kembali di cabang ini.');
    }
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, add these lines inside the `sparepart-branches` route group, after `storeExisting`:

```php
        Route::get('/{sparepartBranch}/edit', [SparepartBranchController::class, 'edit'])->name('edit');
        Route::put('/{sparepartBranch}', [SparepartBranchController::class, 'update'])->name('update');
        Route::patch('/{sparepartBranch}/deactivate', [SparepartBranchController::class, 'deactivate'])->name('deactivate');
        Route::patch('/{sparepartBranch}/activate', [SparepartBranchController::class, 'activate'])->name('activate');
```

- [ ] **Step 6: Write the edit view**

Create `resources/views/sparepart-branches/edit.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Ubah Konfigurasi Sparepart')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i>Ubah {{ $sparepartBranch->sparepart->name }}</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('sparepart-branches.update', $sparepartBranch) }}">
                @csrf
                @method('PUT')
                <div class="mb-3">
                    <label class="form-label">Kode Sparepart</label>
                    <input type="text" value="{{ $sparepartBranch->sparepart->code }}" class="form-control" disabled>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="rack_number" class="form-label">Rak</label>
                        <input type="text" name="rack_number" id="rack_number" value="{{ old('rack_number', $sparepartBranch->rack_number) }}" class="form-control @error('rack_number') is-invalid @enderror" maxlength="30">
                        @error('rack_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="selling_price" class="form-label">Harga Jual</label>
                        <input type="number" step="0.01" min="0" name="selling_price" id="selling_price" value="{{ old('selling_price', $sparepartBranch->selling_price) }}" class="form-control @error('selling_price') is-invalid @enderror" required>
                        @error('selling_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="minimum_stock" class="form-label">Stok Minimum</label>
                        <input type="number" step="0.001" min="0" name="minimum_stock" id="minimum_stock" value="{{ old('minimum_stock', $sparepartBranch->minimum_stock) }}" class="form-control @error('minimum_stock') is-invalid @enderror">
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

In `resources/views/sparepart-branches/index.blade.php`, replace the existing `<td class="text-end">` action cell (which only has the "Ubah" link) with:

```blade
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
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=SparepartBranchEditAndDeactivateTest`
Expected: PASS (all 6 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/UpdateSparepartBranchRequest.php app/Http/Controllers/SparepartBranchController.php resources/views/sparepart-branches/edit.blade.php resources/views/sparepart-branches/index.blade.php routes/web.php tests/Feature/SparepartBranchEditAndDeactivateTest.php
git commit -m "feat: add sparepart branch edit and activate/deactivate actions"
```

---

### Task 5: Sidebar wiring + full-suite verification

**Files:**
- Modify: `resources/views/partials/sidebar.blade.php`
- Modify: `tests/Feature/AppShellTest.php`

**Interfaces:**
- Consumes: `User::branchesWithPermission()` (Task 2), route `sparepart-branches.index` (Task 3).

- [ ] **Step 1: Write the failing test**

In `tests/Feature/AppShellTest.php`, add this import at the top alongside the existing ones:

```php
use App\Models\Branch;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
```

Add this test method to the `AppShellTest` class:

```php
    public function test_sidebar_shows_master_sparepart_link_only_for_branch_scoped_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create([
            'code' => 'sparepart.view',
            'resource' => 'sparepart',
            'action' => 'view',
            'description' => 'Melihat sparepart',
        ]);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('sparepart-branches.index'), false);
    }

    public function test_sidebar_hides_master_sparepart_link_without_any_branch_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee(route('sparepart-branches.index'), false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AppShellTest`
Expected: The two new tests FAIL (no "Persediaan" section or link exists in the sidebar yet); the pre-existing tests in this file still pass.

- [ ] **Step 3: Update the sidebar**

In `resources/views/partials/sidebar.blade.php`, add this new section after the closing `@endif` of the "Master Data" block (before the "Administrasi" `@if`):

```blade
@if ($user && $user->branchesWithPermission('sparepart.view')->isNotEmpty())
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Persediaan</div>
    <ul class="nav flex-column mb-3">
        <li class="nav-item">
            <a href="{{ route('sparepart-branches.index') }}" class="nav-link {{ request()->routeIs('sparepart-branches.*') ? 'active' : '' }}">
                <i class="bi bi-box-seam me-2"></i> Master Sparepart
            </a>
        </li>
    </ul>
@endif
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AppShellTest`
Expected: PASS (all tests in the file, including the two new ones).

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS — every test in the project, including all sparepart tests from Tasks 1-4 and every pre-existing test, passes with no regressions.

- [ ] **Step 6: Commit**

```bash
git add resources/views/partials/sidebar.blade.php tests/Feature/AppShellTest.php
git commit -m "feat: wire sparepart branch link into Persediaan sidebar section"
```

---

## Manual verification checklist (after all tasks complete)

1. `php artisan migrate` on the dev `laravel` database (not `bengkel_testing` — check `.env` `DB_DATABASE` first, per project memory).
2. Log in as `faiz_rahmat` (all branches/all permissions per `DemoUsersSeeder`). Confirm the sidebar shows "Persediaan → Master Sparepart".
3. Create a new sparepart in Branch A via "Sparepart Baru"; confirm it appears in the list with 0 available stock.
4. Switch the branch dropdown to Branch B; confirm the Branch A sparepart does NOT appear.
5. Use "Tambah dari Cabang Lain" in Branch B to attach the same sparepart with different rack/price; confirm both branches now have independent configs for the same identity.
6. Edit a config's price/rack/minimum stock; confirm it saves without touching its active status.
7. Deactivate a config; confirm its status badge flips and an "Aktifkan" button appears in its place.
8. Log in as `romi_ramdani` (BENGKEL1 only, no sparepart permissions per `DemoUsersSeeder`) and confirm the Persediaan section is entirely absent from the sidebar, and `/sparepart-branches` shows the no-access page directly.
