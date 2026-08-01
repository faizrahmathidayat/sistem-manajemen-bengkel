# Branch-Scoped Permissions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task — this plan touches core, already-shipped authorization infrastructure (auth-critical), so per the project's process preference it runs through the subagent review loop rather than inline execution, using the cheap model configuration (Haiku implementer/reviewer per task, Sonnet final whole-branch review). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user hold a different permission set per branch for Operasional/Persediaan/Reporting permissions, while Administrasi/Master Data permissions keep working exactly as they do today (global, unchanged mechanism).

**Architecture:** New `user_branch_permissions` table + model, additive to the existing `user_permissions` table (not a replacement — the existing global mechanism is untouched). A new `menus.is_branch_scoped` flag marks which 12 of the 21 menus are branch-scoped. `AuthorizesByPermission` gains `hasPermissionToInBranch()` alongside the existing `hasPermissionTo()`. The Permission tab UI splits into a per-branch-sub-tab section (branch-scoped menus) and an unchanged flat section (global menus).

**Tech Stack:** Laravel 8 (existing), Blade, Bootstrap 5 (existing design system — reuse `.status-dot`, `.card`, accordion/tab patterns already established).

Design spec: `docs/superpowers/specs/2026-08-02-branch-scoped-permissions-design.md`.

## Global Constraints

- No changes to `user_permissions`, `UserPermission`, `hasPermissionTo(string $code): bool`, or `Gate::before` — these stay exactly as shipped. Every existing test in `PermissionAuthorizationTest.php` and `UserPermissionTabTest.php` must keep passing unmodified.
- `user_branch_permissions.branch_id` is `NOT NULL` (this table only ever holds branch-scoped grants) — no nullable-branch-with-generated-column trick needed.
- Primary keys `bigint` auto-increment, `snake_case` plural table names, no hard delete — same conventions as every other table in this project.
- `menus.is_branch_scoped` is explicit per-menu seed data, never inferred from the menu code string at runtime.
- 12 branch-scoped menus: `operasional.pkb`, `operasional.invoice`, `operasional.payment`, `persediaan.sparepart`, `persediaan.receipt`, `persediaan.stock_adjustment`, `persediaan.stock_transfer`, `reporting.pkb`, `reporting.invoice`, `reporting.receivable`, `reporting.pkb_invoice_gap`, `reporting.sparepart`. The other 9 (`master.*` ×5, `administrasi.*` ×4) stay global.
- TDD throughout: write the failing test first, confirm the failure reason, implement, confirm green.

---

### Task 1: Migrations, models, seeder flags, demo data

**Files:**
- Create: `database/migrations/2026_08_02_000001_add_is_branch_scoped_to_menus_table.php`
- Create: `database/migrations/2026_08_02_000002_create_user_branch_permissions_table.php`
- Create: `app/Models/UserBranchPermission.php`
- Modify: `app/Models/Menu.php`
- Modify: `database/seeders/MenuPermissionSeeder.php`
- Modify: `database/seeders/DemoUsersSeeder.php`
- Test: `tests/Feature/UserBranchPermissionModelTest.php`
- Test: `tests/Feature/MenuPermissionSeederTest.php` (add a method, don't touch existing ones)
- Test: `tests/Feature/DemoUsersSeederTest.php`

**Interfaces:**
- Produces: `App\Models\UserBranchPermission` with `user()`, `branch()`, `permission()`, `granter()` relations, fillable `user_id, branch_id, permission_id, granted_by`. `menus.is_branch_scoped` column (boolean, default false), added to `Menu::$fillable`/`$casts`. Table `user_branch_permissions` with unique `(user_id, branch_id, permission_id)`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/UserBranchPermissionModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserBranchPermissionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_with_valid_relations(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);

        $grant = UserBranchPermission::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'permission_id' => $permission->id,
            'granted_by' => $user->id,
        ]);

        $this->assertSame($user->id, $grant->user->id);
        $this->assertSame($branch->id, $grant->branch->id);
        $this->assertSame($permission->id, $grant->permission->id);
        $this->assertSame($user->id, $grant->granter->id);
    }

    public function test_same_permission_can_be_granted_in_two_different_branches(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);

        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branchA->id, 'permission_id' => $permission->id]);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branchB->id, 'permission_id' => $permission->id]);

        $this->assertSame(2, UserBranchPermission::where('user_id', $user->id)->count());
    }

    public function test_duplicate_grant_for_same_user_branch_permission_is_rejected(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);

        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $this->expectException(QueryException::class);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }
}
```

Add to `tests/Feature/MenuPermissionSeederTest.php` (append this method inside the existing class, do not modify `test_seeder_creates_expected_menus_and_permissions` or `test_seeder_is_idempotent_when_run_twice`):

```php
    public function test_seeder_marks_operational_menus_as_branch_scoped_and_others_as_global(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $this->assertDatabaseHas('menus', ['code' => 'operasional.pkb', 'is_branch_scoped' => true]);
        $this->assertDatabaseHas('menus', ['code' => 'persediaan.sparepart', 'is_branch_scoped' => true]);
        $this->assertDatabaseHas('menus', ['code' => 'reporting.pkb', 'is_branch_scoped' => true]);
        $this->assertDatabaseHas('menus', ['code' => 'master.branch', 'is_branch_scoped' => false]);
        $this->assertDatabaseHas('menus', ['code' => 'administrasi.users', 'is_branch_scoped' => false]);
    }
```

`tests/Feature/DemoUsersSeederTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_faiz_gets_global_permissions_globally_and_branch_scoped_permissions_in_every_branch(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $faiz = User::where('username', 'faiz_rahmat')->firstOrFail();
        $bengkel1 = Branch::where('code', 'BENGKEL1')->firstOrFail();
        $bengkel2 = Branch::where('code', 'BENGKEL2')->firstOrFail();

        $this->assertTrue($faiz->userPermissions()->whereHas('permission', fn ($q) => $q->where('code', 'user.view'))->exists());
        $this->assertTrue(UserBranchPermission::where('user_id', $faiz->id)->where('branch_id', $bengkel1->id)
            ->whereHas('permission', fn ($q) => $q->where('code', 'pkb.view'))->exists());
        $this->assertTrue(UserBranchPermission::where('user_id', $faiz->id)->where('branch_id', $bengkel2->id)
            ->whereHas('permission', fn ($q) => $q->where('code', 'pkb.view'))->exists());
    }

    public function test_romi_gets_pkb_and_laporan_permissions_scoped_to_bengkel1_only(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $romi = User::where('username', 'romi_ramdani')->firstOrFail();
        $bengkel1 = Branch::where('code', 'BENGKEL1')->firstOrFail();

        $this->assertTrue(UserBranchPermission::where('user_id', $romi->id)->where('branch_id', $bengkel1->id)
            ->whereHas('permission', fn ($q) => $q->where('code', 'pkb.view'))->exists());
        $this->assertTrue(UserBranchPermission::where('user_id', $romi->id)->where('branch_id', $bengkel1->id)
            ->whereHas('permission', fn ($q) => $q->where('code', 'report.pkb.view'))->exists());
        $this->assertSame(0, UserPermission::where('user_id', $romi->id)
            ->whereHas('permission', fn ($q) => $q->where('code', 'pkb.view'))->count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=UserBranchPermissionModelTest`
Expected: FAIL — class `App\Models\UserBranchPermission` and table `user_branch_permissions` don't exist.

Run: `php artisan test --filter=MenuPermissionSeederTest`
Expected: the new `test_seeder_marks_operational_menus_as_branch_scoped_and_others_as_global` FAILs (column `is_branch_scoped` doesn't exist); the two pre-existing tests still PASS.

Run: `php artisan test --filter=DemoUsersSeederTest`
Expected: FAIL — `App\Models\UserBranchPermission` doesn't exist yet.

- [ ] **Step 3: Create the `menus.is_branch_scoped` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsBranchScopedToMenusTable extends Migration
{
    public function up()
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->boolean('is_branch_scoped')->default(false)->after('sort_order');
        });
    }

    public function down()
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('is_branch_scoped');
        });
    }
}
```

- [ ] **Step 4: Create the `user_branch_permissions` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserBranchPermissionsTable extends Migration
{
    public function up()
    {
        Schema::create('user_branch_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'branch_id', 'permission_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_branch_permissions');
    }
}
```

- [ ] **Step 5: Create `app/Models/UserBranchPermission.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBranchPermission extends Model
{
    protected $fillable = ['user_id', 'branch_id', 'permission_id', 'granted_by'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
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

- [ ] **Step 6: Modify `app/Models/Menu.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    protected $fillable = ['parent_id', 'code', 'name', 'route', 'icon', 'sort_order', 'is_active', 'is_branch_scoped'];

    protected $casts = ['is_active' => 'boolean', 'is_branch_scoped' => 'boolean'];

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

- [ ] **Step 7: Replace `database/seeders/MenuPermissionSeeder.php` in full**

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
                    'is_branch_scoped' => $menuDefinition['is_branch_scoped'],
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
                'is_branch_scoped' => true,
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
                'is_branch_scoped' => true,
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
                'is_branch_scoped' => true,
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
                'is_branch_scoped' => true,
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
                'is_branch_scoped' => true,
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
                'is_branch_scoped' => true,
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
                'is_branch_scoped' => true,
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
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'branch.view', 'resource' => 'branch', 'action' => 'view', 'description' => 'Melihat cabang'],
                    ['code' => 'branch.create', 'resource' => 'branch', 'action' => 'create', 'description' => 'Membuat cabang'],
                    ['code' => 'branch.edit', 'resource' => 'branch', 'action' => 'edit', 'description' => 'Mengubah cabang'],
                ],
            ],
            [
                'code' => 'master.customer',
                'name' => 'Customer',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'customer.view', 'resource' => 'customer', 'action' => 'view', 'description' => 'Melihat customer'],
                    ['code' => 'customer.create', 'resource' => 'customer', 'action' => 'create', 'description' => 'Membuat customer'],
                    ['code' => 'customer.edit', 'resource' => 'customer', 'action' => 'edit', 'description' => 'Mengubah customer'],
                ],
            ],
            [
                'code' => 'master.vehicle',
                'name' => 'Kendaraan',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'vehicle.view', 'resource' => 'vehicle', 'action' => 'view', 'description' => 'Melihat kendaraan'],
                    ['code' => 'vehicle.create', 'resource' => 'vehicle', 'action' => 'create', 'description' => 'Membuat kendaraan'],
                    ['code' => 'vehicle.edit', 'resource' => 'vehicle', 'action' => 'edit', 'description' => 'Mengubah kendaraan'],
                ],
            ],
            [
                'code' => 'master.mechanic',
                'name' => 'Mekanik',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'mechanic.view', 'resource' => 'mechanic', 'action' => 'view', 'description' => 'Melihat mekanik'],
                    ['code' => 'mechanic.create', 'resource' => 'mechanic', 'action' => 'create', 'description' => 'Membuat mekanik'],
                    ['code' => 'mechanic.edit', 'resource' => 'mechanic', 'action' => 'edit', 'description' => 'Mengubah mekanik'],
                ],
            ],
            [
                'code' => 'master.service',
                'name' => 'Jasa Service',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'service.view', 'resource' => 'service', 'action' => 'view', 'description' => 'Melihat jasa service'],
                    ['code' => 'service.create', 'resource' => 'service', 'action' => 'create', 'description' => 'Membuat jasa service'],
                    ['code' => 'service.edit', 'resource' => 'service', 'action' => 'edit', 'description' => 'Mengubah jasa service'],
                ],
            ],
            [
                'code' => 'administrasi.users',
                'name' => 'Users',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'user.view', 'resource' => 'user', 'action' => 'view', 'description' => 'Melihat user'],
                    ['code' => 'user.create', 'resource' => 'user', 'action' => 'create', 'description' => 'Membuat user'],
                    ['code' => 'user.edit', 'resource' => 'user', 'action' => 'edit', 'description' => 'Mengubah user'],
                ],
            ],
            [
                'code' => 'administrasi.user_branches',
                'name' => 'User Branches',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'user_branch.manage', 'resource' => 'user_branch', 'action' => 'manage', 'description' => 'Mengelola cabang milik user'],
                ],
            ],
            [
                'code' => 'administrasi.user_permissions',
                'name' => 'User Permissions',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'user_permission.manage', 'resource' => 'user_permission', 'action' => 'manage', 'description' => 'Mengelola permission milik user'],
                ],
            ],
            [
                'code' => 'administrasi.audit_log',
                'name' => 'Audit Log',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'audit_log.view', 'resource' => 'audit_log', 'action' => 'view', 'description' => 'Melihat audit log'],
                ],
            ],
            [
                'code' => 'reporting.pkb',
                'name' => 'Laporan PKB',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'report.pkb.view', 'resource' => 'report', 'action' => 'pkb.view', 'description' => 'Melihat laporan PKB'],
                ],
            ],
            [
                'code' => 'reporting.invoice',
                'name' => 'Laporan Invoice',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'report.invoice.view', 'resource' => 'report', 'action' => 'invoice.view', 'description' => 'Melihat laporan invoice'],
                ],
            ],
            [
                'code' => 'reporting.receivable',
                'name' => 'Laporan Piutang',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'report.receivable.view', 'resource' => 'report', 'action' => 'receivable.view', 'description' => 'Melihat laporan piutang'],
                ],
            ],
            [
                'code' => 'reporting.pkb_invoice_gap',
                'name' => 'PKB vs Invoice',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'report.invoice_pkb_gap.view', 'resource' => 'report', 'action' => 'invoice_pkb_gap.view', 'description' => 'Melihat laporan selisih PKB vs invoice'],
                ],
            ],
            [
                'code' => 'reporting.sparepart',
                'name' => 'Laporan Sparepart',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'report.sparepart.view', 'resource' => 'report', 'action' => 'sparepart.view', 'description' => 'Melihat laporan sparepart'],
                    ['code' => 'report.export', 'resource' => 'report', 'action' => 'export', 'description' => 'Mengekspor laporan'],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 8: Replace `database/seeders/DemoUsersSeeder.php` in full**

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
        if (! app()->environment(['local', 'testing'])) {
            $this->command->error('DemoUsersSeeder only runs in local/testing environments (demo accounts use weak, guessable passwords).');

            return;
        }

        $this->call(MenuPermissionSeeder::class);

        $branches = $this->seedBranches();
        $users = $this->seedUsers();

        $branchService = new UserBranchService();

        $globalCodes = Permission::whereHas('menu', fn ($query) => $query->where('is_branch_scoped', false))->pluck('code')->all();
        $branchScopedCodes = Permission::whereHas('menu', fn ($query) => $query->where('is_branch_scoped', true))->pluck('code')->all();

        // Faiz: all access, all branches, all permissions — global codes granted once,
        // every branch-scoped code granted in every branch he's assigned to.
        foreach ($branches as $index => $branch) {
            $branchService->assign($users['faiz'], $branch, $index === 0);
            $this->grantBranchPermissions($users['faiz'], $branch, $branchScopedCodes);
        }
        $this->grantPermissions($users['faiz'], $globalCodes);

        // Romi: Bengkel 1 only, PKB view/create + view all laporan, scoped to Bengkel 1.
        $branchService->assign($users['romi'], $branches->first(), true);
        $this->grantBranchPermissions($users['romi'], $branches->first(), array_merge([
            'pkb.view',
            'pkb.create',
        ], $this->laporanCodes()));

        // Syilawati: Bengkel 1 only, invoice view/create + view all laporan, scoped to Bengkel 1.
        $branchService->assign($users['syilawati'], $branches->first(), true);
        $this->grantBranchPermissions($users['syilawati'], $branches->first(), array_merge([
            'invoice.view',
            'invoice.create',
        ], $this->laporanCodes()));

        $this->command->info('Demo users seeded (local/testing only): faiz_rahmat, romi_ramdani, syilawati_rn — password sama dengan username.');
    }

    protected function seedBranches()
    {
        $definitions = [
            ['code' => 'BENGKEL1', 'name' => 'Bengkel 1'],
            ['code' => 'BENGKEL2', 'name' => 'Bengkel 2'],
            ['code' => 'BENGKEL3', 'name' => 'Bengkel 3'],
        ];

        return collect($definitions)->map(function ($definition) {
            return Branch::updateOrCreate(
                ['code' => $definition['code']],
                ['name' => $definition['name'], 'is_active' => true]
            );
        });
    }

    protected function seedUsers()
    {
        $definitions = [
            'faiz' => ['username' => 'faiz_rahmat', 'name' => 'Faiz Rahmat Hidayat'],
            'romi' => ['username' => 'romi_ramdani', 'name' => 'Romi Ramdani'],
            'syilawati' => ['username' => 'syilawati_rn', 'name' => 'Syilawati'],
        ];

        $users = [];

        foreach ($definitions as $key => $definition) {
            $users[$key] = User::updateOrCreate(
                ['username' => $definition['username']],
                [
                    'name' => $definition['name'],
                    'password' => Hash::make($definition['username']),
                    'is_active' => true,
                ]
            );
        }

        return $users;
    }

    protected function laporanCodes(): array
    {
        return [
            'report.pkb.view',
            'report.invoice.view',
            'report.receivable.view',
            'report.invoice_pkb_gap.view',
            'report.sparepart.view',
        ];
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

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test --filter=UserBranchPermissionModelTest` — expect 3 passing.
Run: `php artisan test --filter=MenuPermissionSeederTest` — expect 3 passing (2 pre-existing + 1 new).
Run: `php artisan test --filter=DemoUsersSeederTest` — expect 2 passing.
Then `php artisan test` for the full suite — every pre-existing test (62 as of the last merge to `master`) must still pass.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_08_02_000001_add_is_branch_scoped_to_menus_table.php database/migrations/2026_08_02_000002_create_user_branch_permissions_table.php app/Models/UserBranchPermission.php app/Models/Menu.php database/seeders/MenuPermissionSeeder.php database/seeders/DemoUsersSeeder.php tests/Feature/UserBranchPermissionModelTest.php tests/Feature/MenuPermissionSeederTest.php tests/Feature/DemoUsersSeederTest.php
git commit -m "feat: add user_branch_permissions table and menus.is_branch_scoped flag"
```

---

### Task 2: `AuthorizesByPermission` trait — branch-scoped checks

**Files:**
- Modify: `app/Models/Concerns/AuthorizesByPermission.php`
- Test: `tests/Feature/BranchScopedPermissionAuthorizationTest.php`

**Interfaces:**
- Consumes: `App\Models\UserBranchPermission` (Task 1).
- Produces: `hasPermissionToInBranch(string $code, int $branchId): bool` on any model using this trait (currently only `User`) — the primitive future Policies (PKB, invoice, etc.) will call. `hasPermissionTo(string $code): bool` (existing, global) is untouched.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchScopedPermissionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_permission_only_in_the_branch_it_was_granted_for(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $permission = Permission::create(['code' => 'invoice.create', 'resource' => 'invoice', 'action' => 'create', 'description' => 'Membuat invoice']);

        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branchA->id, 'permission_id' => $permission->id]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->hasPermissionToInBranch('invoice.create', $branchA->id));
        $this->assertFalse($reloaded->hasPermissionToInBranch('invoice.create', $branchB->id));
    }

    public function test_user_without_any_grant_does_not_have_the_permission_in_any_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $this->assertFalse($user->hasPermissionToInBranch('invoice.create', $branch->id));
    }

    public function test_inactive_permission_is_not_granted_even_if_assigned_to_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create([
            'code' => 'invoice.void',
            'resource' => 'invoice',
            'action' => 'void',
            'description' => 'Void invoice',
            'is_active' => false,
        ]);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $this->assertFalse($user->hasPermissionToInBranch('invoice.void', $branch->id));
    }

    public function test_deactivated_user_does_not_have_branch_permission_even_if_granted(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'invoice.create', 'resource' => 'invoice', 'action' => 'create', 'description' => 'Membuat invoice']);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $this->assertFalse($user->hasPermissionToInBranch('invoice.create', $branch->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BranchScopedPermissionAuthorizationTest`
Expected: FAIL — `hasPermissionToInBranch()` doesn't exist on `User`.

- [ ] **Step 3: Modify `app/Models/Concerns/AuthorizesByPermission.php`** (replace the whole file)

```php
<?php

namespace App\Models\Concerns;

use App\Models\UserBranchPermission;
use App\Models\UserPermission;

trait AuthorizesByPermission
{
    protected $permissionCodesCache = null;

    protected $branchPermissionCodesCache = [];

    public function userPermissions()
    {
        return $this->hasMany(UserPermission::class);
    }

    public function userBranchPermissions()
    {
        return $this->hasMany(UserBranchPermission::class);
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

    public function branchPermissionCodes(int $branchId): array
    {
        if (! array_key_exists($branchId, $this->branchPermissionCodesCache)) {
            $this->branchPermissionCodesCache[$branchId] = $this->userBranchPermissions()
                ->where('branch_id', $branchId)
                ->join('permissions', 'permissions.id', '=', 'user_branch_permissions.permission_id')
                ->where('permissions.is_active', true)
                ->pluck('permissions.code')
                ->all();
        }

        return $this->branchPermissionCodesCache[$branchId];
    }

    public function hasPermissionTo(string $code): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return in_array($code, $this->permissionCodes(), true);
    }

    public function hasPermissionToInBranch(string $code, int $branchId): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return in_array($code, $this->branchPermissionCodes($branchId), true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BranchScopedPermissionAuthorizationTest`
Expected: PASS (4 tests). Then run `php artisan test --filter=PermissionAuthorizationTest` to confirm the pre-existing global-permission tests are still green (this file wasn't supposed to change their behavior).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Concerns/AuthorizesByPermission.php tests/Feature/BranchScopedPermissionAuthorizationTest.php
git commit -m "feat: add hasPermissionToInBranch() alongside the existing global hasPermissionTo()"
```

---

### Task 3: Branch-scoped permission assignment endpoints

**Files:**
- Create: `app/Http/Controllers/UserBranchPermissionAssignmentController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/UserBranchPermissionTabControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\UserBranchPermission` (Task 1).
- Produces: named routes `users.branchPermissions.store` (POST), `users.branchPermissions.destroy` (DELETE), URL shape `/users/{user}/branches/{branch}/permissions/{permission}`, both returning JSON `{message: string}`, both gated by `user_permission.manage`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserBranchPermissionTabControllerTest extends TestCase
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

    public function test_granting_a_branch_scoped_permission_creates_the_row(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        $admin = $this->userWithPermissions(['user_permission.manage']);

        $response = $this->actingAs($admin)->postJson("/users/{$target->id}/branches/{$branch->id}/permissions/{$permission->id}");

        $response->assertOk();
        $this->assertDatabaseHas('user_branch_permissions', [
            'user_id' => $target->id,
            'branch_id' => $branch->id,
            'permission_id' => $permission->id,
            'granted_by' => $admin->id,
        ]);
    }

    public function test_revoking_a_branch_scoped_permission_removes_only_that_branchs_row(): void
    {
        $target = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        UserBranchPermission::create(['user_id' => $target->id, 'branch_id' => $branchA->id, 'permission_id' => $permission->id]);
        UserBranchPermission::create(['user_id' => $target->id, 'branch_id' => $branchB->id, 'permission_id' => $permission->id]);
        $admin = $this->userWithPermissions(['user_permission.manage']);

        $response = $this->actingAs($admin)->deleteJson("/users/{$target->id}/branches/{$branchA->id}/permissions/{$permission->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_branch_permissions', ['user_id' => $target->id, 'branch_id' => $branchA->id, 'permission_id' => $permission->id]);
        $this->assertDatabaseHas('user_branch_permissions', ['user_id' => $target->id, 'branch_id' => $branchB->id, 'permission_id' => $permission->id]);
    }

    public function test_endpoints_are_forbidden_without_permission(): void
    {
        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/users/{$target->id}/branches/{$branch->id}/permissions/{$permission->id}")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserBranchPermissionTabControllerTest`
Expected: FAIL — routes don't exist yet.

- [ ] **Step 3: Create `app/Http/Controllers/UserBranchPermissionAssignmentController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use Illuminate\Http\Request;

class UserBranchPermissionAssignmentController extends Controller
{
    public function store(Request $request, User $user, Branch $branch, Permission $permission)
    {
        $this->authorize('user_permission.manage');

        UserBranchPermission::firstOrCreate(
            ['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id],
            ['granted_by' => $request->user()->id]
        );

        return response()->json(['message' => 'Permission berhasil diberikan untuk cabang ini.']);
    }

    public function destroy(User $user, Branch $branch, Permission $permission)
    {
        $this->authorize('user_permission.manage');

        UserBranchPermission::where('user_id', $user->id)
            ->where('branch_id', $branch->id)
            ->where('permission_id', $permission->id)
            ->delete();

        return response()->json(['message' => 'Permission berhasil dicabut dari cabang ini.']);
    }
}
```

- [ ] **Step 4: Add routes — modify `routes/web.php`**

Add `use App\Http\Controllers\UserBranchPermissionAssignmentController;` near the top with the other controller imports, and inside the `Route::prefix('users')->name('users.')->group(...)` block, immediately after the existing `Route::prefix('{user}/branches')->name('branches.')->group(...)` block (before the `{user}/permissions` block):

```php
        Route::prefix('{user}/branches/{branch}/permissions')->name('branchPermissions.')->group(function () {
            Route::post('/{permission}', [UserBranchPermissionAssignmentController::class, 'store'])->name('store');
            Route::delete('/{permission}', [UserBranchPermissionAssignmentController::class, 'destroy'])->name('destroy');
        });
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=UserBranchPermissionTabControllerTest`
Expected: PASS (3 tests). Then `php artisan test` for the full suite.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/UserBranchPermissionAssignmentController.php routes/web.php tests/Feature/UserBranchPermissionTabControllerTest.php
git commit -m "feat: add branch-scoped permission grant/revoke endpoints"
```

---

### Task 4: Permission tab UI — branch sub-tabs + global section

**Files:**
- Modify: `app/Http/Controllers/UserController.php`
- Modify: `resources/views/users/_tab_permission.blade.php`
- Test: `tests/Feature/BranchScopedPermissionTabRenderingTest.php`

**Interfaces:**
- Consumes: `Menu::$is_branch_scoped` (Task 1), `users.branchPermissions.store`/`.destroy` routes (Task 3), `User::branches()` (existing, Foundation phase — active-branch relation), `User::userBranchPermissions()` (Task 2).
- Produces: `UserController::show()` now passes `assignedBranches`, `branchScopedMenus`, `globalMenus`, `grantedPermissionIds` (unchanged, global), `grantedBranchPermissionIds` (new — a `Collection` keyed by `branch_id` => array of granted permission IDs in that branch) to `users.show`. `resources/views/users/_tab_permission.blade.php` no longer receives/uses a flat `$menus` variable.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchScopedPermissionTabRenderingTest extends TestCase
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

    public function test_permission_tab_shows_a_sub_tab_per_assigned_branch(): void
    {
        $target = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        (new UserBranchService())->assign($target, $branchA, true);
        (new UserBranchService())->assign($target, $branchB);
        $admin = $this->userWithPermissions(['user.view', 'user_permission.manage']);

        $response = $this->actingAs($admin)->get("/users/{$target->id}");

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
        $response->assertSee('Cabang Bandung');
    }

    public function test_permission_tab_shows_message_when_user_has_no_assigned_branches(): void
    {
        $target = User::factory()->create();
        $admin = $this->userWithPermissions(['user.view', 'user_permission.manage']);

        $response = $this->actingAs($admin)->get("/users/{$target->id}");

        $response->assertOk();
        $response->assertSee('Tetapkan cabang dulu di tab Cabang');
    }

    public function test_branch_scoped_menu_appears_under_branch_sub_tab(): void
    {
        $branchMenu = Menu::create(['code' => 'operasional.test', 'name' => 'Menu Operasional Uji', 'sort_order' => 1, 'is_branch_scoped' => true]);
        Permission::create(['menu_id' => $branchMenu->id, 'code' => 'test_op.view', 'resource' => 'test_op', 'action' => 'view', 'description' => 'Lihat Uji Operasional']);

        $target = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        (new UserBranchService())->assign($target, $branch, true);
        $admin = $this->userWithPermissions(['user.view', 'user_permission.manage']);

        $response = $this->actingAs($admin)->get("/users/{$target->id}");

        $response->assertOk();
        $response->assertSee('Menu Operasional Uji');
        $response->assertSee('Lihat Uji Operasional');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BranchScopedPermissionTabRenderingTest`
Expected: 2 of 3 FAIL under the current (pre-Task-4) code — `test_permission_tab_shows_a_sub_tab_per_assigned_branch` fails because the flat accordion never prints branch names anywhere; `test_permission_tab_shows_message_when_user_has_no_assigned_branches` fails because that message doesn't exist yet. `test_branch_scoped_menu_appears_under_branch_sub_tab` PASSES even before the change — the current controller passes every menu (branch-scoped or not) into one flat `$menus` list, so the test's menu and permission text are already visible, just not yet inside a branch-specific sub-tab. That's expected: this third test only proves the content renders somewhere; it isn't a strict red/green signal for this task on its own, the other two are. Don't be alarmed that it's already green before Step 3-4.

- [ ] **Step 3: Modify `app/Http/Controllers/UserController.php`** (replace the `show()` method body; every other method in the file is unchanged)

```php
    public function show(User $user)
    {
        $this->authorize('user.view');

        $user->load('userBranches');
        $allBranches = Branch::orderBy('name')->get();
        $assignedBranches = $user->branches;

        $branchScopedMenus = Menu::with(['permissions' => fn ($query) => $query->where('is_active', true)])
            ->where('is_branch_scoped', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($menu) => $menu->permissions->isNotEmpty());

        $globalMenus = Menu::with(['permissions' => fn ($query) => $query->where('is_active', true)])
            ->where('is_branch_scoped', false)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($menu) => $menu->permissions->isNotEmpty());

        $grantedPermissionIds = $user->userPermissions()->pluck('permission_id')->all();

        $grantedBranchPermissionIds = $user->userBranchPermissions()
            ->get()
            ->groupBy('branch_id')
            ->map(fn ($rows) => $rows->pluck('permission_id')->all());

        return view('users.show', compact(
            'user', 'allBranches', 'assignedBranches', 'branchScopedMenus', 'globalMenus',
            'grantedPermissionIds', 'grantedBranchPermissionIds'
        ));
    }
```

- [ ] **Step 4: Replace `resources/views/users/_tab_permission.blade.php` in full**

```blade
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h6 mb-3"><i class="bi bi-shop me-1"></i> Permission Operasional per Cabang</h2>

        @if ($assignedBranches->isEmpty())
            <p class="text-muted small mb-0">Tetapkan cabang dulu di tab Cabang sebelum mengatur permission operasional.</p>
        @else
            <ul class="nav nav-tabs mb-3" role="tablist">
                @foreach ($assignedBranches as $index => $branch)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link {{ $index === 0 ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#branch-perm-{{ $branch->id }}" type="button" role="tab">
                            {{ $branch->name }}
                        </button>
                    </li>
                @endforeach
            </ul>

            <div class="tab-content">
                @foreach ($assignedBranches as $index => $branch)
                    @php($grantedForBranch = $grantedBranchPermissionIds->get($branch->id, []))
                    <div class="tab-pane fade {{ $index === 0 ? 'show active' : '' }}" id="branch-perm-{{ $branch->id }}" role="tabpanel">
                        <div class="accordion" id="branchPermAccordion{{ $branch->id }}">
                            @foreach ($branchScopedMenus as $menu)
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#branch-{{ $branch->id }}-menu-{{ $menu->id }}">
                                            {{ $menu->name }}
                                            <span class="badge bg-secondary ms-2 menu-count" data-menu-id="{{ $branch->id }}-{{ $menu->id }}">
                                                {{ $menu->permissions->whereIn('id', $grantedForBranch)->count() }}/{{ $menu->permissions->count() }}
                                            </span>
                                        </button>
                                    </h2>
                                    <div id="branch-{{ $branch->id }}-menu-{{ $menu->id }}" class="accordion-collapse collapse" data-bs-parent="#branchPermAccordion{{ $branch->id }}">
                                        <div class="accordion-body">
                                            <div class="form-check mb-2">
                                                <input type="checkbox" class="form-check-input branch-menu-select-all" id="branch-{{ $branch->id }}-menu-all-{{ $menu->id }}" data-menu-key="{{ $branch->id }}-{{ $menu->id }}">
                                                <label class="form-check-label fw-semibold" for="branch-{{ $branch->id }}-menu-all-{{ $menu->id }}">Pilih semua</label>
                                            </div>
                                            <hr>
                                            @foreach ($menu->permissions as $permission)
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input branch-permission-toggle" id="branch-{{ $branch->id }}-permission-{{ $permission->id }}"
                                                        data-branch-id="{{ $branch->id }}" data-permission-id="{{ $permission->id }}" data-menu-key="{{ $branch->id }}-{{ $menu->id }}"
                                                        {{ in_array($permission->id, $grantedForBranch) ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="branch-{{ $branch->id }}-permission-{{ $permission->id }}">
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
                    </div>
                @endforeach
            </div>
        @endif

        <div id="branch-permission-feedback" class="small mt-3"></div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h6 mb-3"><i class="bi bi-shield-check me-1"></i> Permission Administrasi &amp; Master Data</h2>
        <p class="text-muted small">Permission ini berlaku global, tidak tergantung cabang.</p>

        <div class="accordion" id="permissionAccordion">
            @foreach ($globalMenus as $menu)
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

    function send(url, method) {
        return fetch(url, {
            method,
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        }).then(async (response) => {
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.message || 'Terjadi kesalahan.');
            }
            return data;
        });
    }

    // Global (Administrasi/Master Data) permissions — unchanged mechanism.
    const globalFeedback = document.getElementById('permission-feedback');

    function showGlobalFeedback(message, isError) {
        globalFeedback.textContent = message;
        globalFeedback.className = 'small mt-3 ' + (isError ? 'text-danger' : 'text-success');
    }

    function updateMenuCount(menuId) {
        const badge = document.querySelector('.menu-count[data-menu-id="' + menuId + '"]');
        const checkboxes = document.querySelectorAll('.permission-toggle[data-menu-id="' + menuId + '"]');
        const checked = document.querySelectorAll('.permission-toggle[data-menu-id="' + menuId + '"]:checked');
        badge.textContent = checked.length + '/' + checkboxes.length;
    }

    document.querySelectorAll('.permission-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const permissionId = this.dataset.permissionId;
            const menuId = this.dataset.menuId;
            const request = this.checked
                ? send(`/users/${userId}/permissions/${permissionId}`, 'POST')
                : send(`/users/${userId}/permissions/${permissionId}`, 'DELETE');
            request.then((data) => {
                showGlobalFeedback(data.message, false);
                updateMenuCount(menuId);
            }).catch((error) => {
                this.checked = !this.checked;
                showGlobalFeedback(error.message, true);
            });
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

    // Branch-scoped (Operasional/Persediaan/Laporan) permissions.
    const branchFeedback = document.getElementById('branch-permission-feedback');

    function showBranchFeedback(message, isError) {
        branchFeedback.textContent = message;
        branchFeedback.className = 'small mt-3 ' + (isError ? 'text-danger' : 'text-success');
    }

    function updateBranchMenuCount(menuKey) {
        const badge = document.querySelector('.menu-count[data-menu-id="' + menuKey + '"]');
        const checkboxes = document.querySelectorAll('.branch-permission-toggle[data-menu-key="' + menuKey + '"]');
        const checked = document.querySelectorAll('.branch-permission-toggle[data-menu-key="' + menuKey + '"]:checked');
        badge.textContent = checked.length + '/' + checkboxes.length;
    }

    document.querySelectorAll('.branch-permission-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const branchId = this.dataset.branchId;
            const permissionId = this.dataset.permissionId;
            const menuKey = this.dataset.menuKey;
            const request = this.checked
                ? send(`/users/${userId}/branches/${branchId}/permissions/${permissionId}`, 'POST')
                : send(`/users/${userId}/branches/${branchId}/permissions/${permissionId}`, 'DELETE');
            request.then((data) => {
                showBranchFeedback(data.message, false);
                updateBranchMenuCount(menuKey);
            }).catch((error) => {
                this.checked = !this.checked;
                showBranchFeedback(error.message, true);
            });
        });
    });

    document.querySelectorAll('.branch-menu-select-all').forEach(function (selectAll) {
        selectAll.addEventListener('change', function () {
            const menuKey = this.dataset.menuKey;
            document.querySelectorAll('.branch-permission-toggle[data-menu-key="' + menuKey + '"]').forEach(function (checkbox) {
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

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=BranchScopedPermissionTabRenderingTest`
Expected: PASS (3 tests). Then run `php artisan test --filter=UserPermissionTabTest` to confirm `test_show_page_renders_permission_tab_grouped_by_menu` (which creates a menu with no explicit `is_branch_scoped`, defaulting to `false`/global) still passes unmodified — it should render in the new global section exactly as before. Then `php artisan test` for the full suite.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/UserController.php resources/views/users/_tab_permission.blade.php tests/Feature/BranchScopedPermissionTabRenderingTest.php
git commit -m "feat: split Permission tab into per-branch sub-tabs and a global section"
```

---

## Final manual verification (after Task 4)

- [ ] Re-run `php artisan migrate` and `php artisan db:seed --class=DemoUsersSeeder` against the dev database (not `bengkel_testing`) to pick up the schema change and updated demo grants.
- [ ] Log in as `faiz_rahmat`, open a user's detail page, Permission tab: confirm branch sub-tabs appear (Bengkel 1/2/3), each showing only the 12 branch-scoped menus; confirm the global section below shows only the 9 unscoped menus.
- [ ] Grant `invoice.create` to a test user in Bengkel 1 only (not Bengkel 2) via the UI; confirm via `php artisan tinker` that `$user->hasPermissionToInBranch('invoice.create', $bengkel1Id)` is `true` and `hasPermissionToInBranch('invoice.create', $bengkel2Id)` is `false`.
- [ ] Confirm the existing global Administrasi section (e.g. granting `user.view`) still works exactly as before — same AJAX pattern, same self-lockout guard on `user_permission.manage`.
