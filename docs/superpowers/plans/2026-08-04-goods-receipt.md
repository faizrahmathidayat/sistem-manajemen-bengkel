# Sub-Proyek 008a — Penerimaan Barang & Fondasi Kartu Stok Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Goods Receipt (Penerimaan Barang) module — the first module to ever write `sparepart_branch_stocks.on_hand_qty`, and the first user of the new `inventory_movements` ledger table. Full CRUD (create/list/detail/edit while `DRAFT`) plus a `post()` action that locks stock rows, increments `on_hand_qty`, and records ledger entries, plus a `cancel()` action restricted to `DRAFT` only (no reversal after posting exists in this scope).

**Architecture:** Three new tables (`goods_receipts` header, `goods_receipt_lines` detail, `inventory_movements` shared ledger). A new `GoodsReceiptPolicy` mirrors `WorkOrderPolicy`'s shape closely. `post()` applies the exact locking pattern already proven in migration 007 (`WorkOrder::confirm()`/`cancel()`): lock the header row and re-check status first, then lock stock rows in a deterministic order (`sparepart_branch_id` ascending, using `->reorder()` if the lines relation carries its own `orderBy`), then mutate. This plan builds that locking discipline in from the start, rather than retrofitting it after a bug is found (as migration 007 had to).

**Tech Stack:** Laravel 8.75, PHP 7.4.33 (no PHP 8 syntax), MySQL 8.0, PHPUnit feature tests (`RefreshDatabase`).

## Global Constraints

- PHP runtime is 7.4.33 — never use PHP 8-only syntax (nullsafe `?->`, named arguments, match expressions, enums, constructor property promotion, union types), including inside Blade `@php()` blocks.
- Laravel 8.75 pinned — never use `Request::integer()` or other Laravel 9+ `Request` helper methods.
- `bigint` PKs, never UUID. `->simplePaginate()` only, never `->paginate()`.
- No hard deletes of transactional documents — status field only (`draft`/`posted`/`cancelled`). Line-item child tables cascade-delete only as a DB-level safety net (the application never deletes a `goods_receipt` row in this scope).
- `receipt.view`, `receipt.create`, `receipt.post`, `receipt.cancel` are already seeded in `MenuPermissionSeeder` (menu code `persediaan.receipt`, `is_branch_scoped = true`) — do not add new permission codes. There is no `receipt.edit` code; editing a `DRAFT` receipt is gated on `receipt.create`.
- Branch-scoped permission codes must never be checked via a bare `$this->authorize('receipt.view')`/`Gate`-only call with no model argument — every check must go through `hasPermissionToInBranch($code, $branchId)` directly, or a Policy method receiving a model instance.
- **Every row-locking transaction must lock the header row (`goods_receipts`) FIRST, re-verify its status hasn't changed, THEN lock any `sparepart_branch_stocks` rows in `sparepart_branch_id` ascending order** — this exact order (header → stock rows, deterministically ordered) is what migration 007 had to learn the hard way across 3 review rounds. Apply it correctly in `update()` AND `post()` from the start; do not lock stock rows without first locking and re-checking the header row's status inside the same transaction.
- `line_total` on every line item is always computed server-side (`round($qty * $purchase_price, 2)`) and never trusted from client input.
- `inventory_movements` is an append-only ledger — nothing in this plan ever updates or deletes a row from it once created. `balance_after` is always the stock's `on_hand_qty` value AFTER the mutation is applied, not before.
- Search input sanitized once in the controller (`is_string(request('q')) ? trim(request('q')) : null`), never re-read raw in the Blade view (the `?q[]=x` array-crash bug class).
- Reuse the existing design system and shared partials: `partials.list-filter-bar`, `partials.empty-state`, `partials.branch-multiselect-filter` — do not hand-roll new list/filter/empty-state markup. Reuse `DocumentNumberGenerator::next($branch, 'PB')` for numbering (format: `PB/{BRANCH_CODE}/{YYYYMM}/{00001}`), the same generator already used for PKB with `'PKB'`.

---

### Task 1: Data model — migrations, models, support classes

**Files:**
- Create: `database/migrations/2026_08_04_000004_create_goods_receipts_table.php`
- Create: `database/migrations/2026_08_04_000005_create_goods_receipt_lines_table.php`
- Create: `database/migrations/2026_08_04_000006_create_inventory_movements_table.php`
- Create: `app/Support/GoodsReceiptStatus.php`
- Create: `app/Support/InventoryMovementType.php`
- Create: `app/Models/GoodsReceipt.php`
- Create: `app/Models/GoodsReceiptLine.php`
- Create: `app/Models/InventoryMovement.php`
- Test: `tests/Feature/GoodsReceiptModelTest.php`

**Interfaces:**
- Produces: `GoodsReceipt` (fields: `number`, `branch_id`, `receipt_date`, `reference_number`, `status`, `notes`; relations `branch()`, `lines()`); `GoodsReceiptLine` (fields: `goods_receipt_id`, `sparepart_branch_id`, `qty`, `purchase_price`, `line_total`, `sort_order`; relations `goodsReceipt()`, `sparepartBranch()`); `InventoryMovement` (fields: `movement_at`, `branch_id`, `sparepart_branch_id`, `movement_type`, `qty_in`, `qty_out`, `balance_after`, `reference_type`, `reference_id`, `notes`, `created_by`; relations `branch()`, `sparepartBranch()`); `GoodsReceiptStatus::DRAFT`/`POSTED`/`CANCELLED`; `InventoryMovementType::RECEIPT`. Every later task must use these constants, never bare string literals.

- [ ] **Step 1: Write the failing model tests**

Create `tests/Feature/GoodsReceiptModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Support\GoodsReceiptStatus;
use App\Support\InventoryMovementType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodsReceiptModelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSparepartBranch(Branch $branch): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);

        return SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
    }

    public function test_goods_receipt_can_be_created_with_fillable_fields_and_defaults_to_draft(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $goodsReceipt = GoodsReceipt::create([
            'number' => 'PB/JKT/202608/00001',
            'branch_id' => $branch->id,
            'receipt_date' => now()->format('Y-m-d'),
        ]);

        $this->assertSame(GoodsReceiptStatus::DRAFT, $goodsReceipt->status);
        $this->assertSame($user->id, $goodsReceipt->created_by);
    }

    public function test_goods_receipt_number_is_unique(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $attrs = ['number' => 'PB/JKT/202608/00001', 'branch_id' => $branch->id, 'receipt_date' => now()->format('Y-m-d')];
        GoodsReceipt::create($attrs);

        $this->expectException(QueryException::class);
        GoodsReceipt::create($attrs);
    }

    public function test_goods_receipt_line_belongs_to_receipt_and_sparepart_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $goodsReceipt = GoodsReceipt::create(['number' => 'PB/JKT/202608/00001', 'branch_id' => $branch->id, 'receipt_date' => now()->format('Y-m-d')]);
        $sparepartBranch = $this->makeSparepartBranch($branch);

        $line = GoodsReceiptLine::create([
            'goods_receipt_id' => $goodsReceipt->id,
            'sparepart_branch_id' => $sparepartBranch->id,
            'qty' => 10,
            'purchase_price' => 40000,
            'line_total' => 400000,
        ]);

        $this->assertSame($goodsReceipt->id, $line->goodsReceipt->id);
        $this->assertSame($sparepartBranch->id, $line->sparepartBranch->id);
        $this->assertCount(1, $goodsReceipt->lines);
    }

    public function test_deleting_goods_receipt_cascades_to_its_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $goodsReceipt = GoodsReceipt::create(['number' => 'PB/JKT/202608/00001', 'branch_id' => $branch->id, 'receipt_date' => now()->format('Y-m-d')]);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $line = GoodsReceiptLine::create([
            'goods_receipt_id' => $goodsReceipt->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'qty' => 10, 'purchase_price' => 40000, 'line_total' => 400000,
        ]);

        $goodsReceipt->delete();

        $this->assertDatabaseMissing('goods_receipt_lines', ['id' => $line->id]);
    }

    public function test_inventory_movement_can_be_created_with_fillable_fields(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);

        $movement = InventoryMovement::create([
            'movement_at' => now(),
            'branch_id' => $branch->id,
            'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT,
            'qty_in' => 10,
            'qty_out' => 0,
            'balance_after' => 10,
            'reference_type' => 'goods_receipt_line',
            'reference_id' => 1,
        ]);

        $this->assertSame(InventoryMovementType::RECEIPT, $movement->movement_type);
        $this->assertSame(10.0, (float) $movement->qty_in);
    }

    public function test_inventory_movement_rejects_qty_in_and_qty_out_both_positive(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);

        $this->expectException(QueryException::class);
        InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 5, 'qty_out' => 5,
            'balance_after' => 10, 'reference_type' => 'goods_receipt_line', 'reference_id' => 1,
        ]);
    }

    public function test_inventory_movement_rejects_both_qty_in_and_qty_out_zero(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);

        $this->expectException(QueryException::class);
        InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 0, 'qty_out' => 0,
            'balance_after' => 10, 'reference_type' => 'goods_receipt_line', 'reference_id' => 1,
        ]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=GoodsReceiptModelTest`
Expected: FAIL — tables/classes don't exist yet.

- [ ] **Step 3: Create the support classes**

Create `app/Support/GoodsReceiptStatus.php`:

```php
<?php

namespace App\Support;

class GoodsReceiptStatus
{
    const DRAFT = 'draft';
    const POSTED = 'posted';
    const CANCELLED = 'cancelled';
}
```

Create `app/Support/InventoryMovementType.php`:

```php
<?php

namespace App\Support;

class InventoryMovementType
{
    const RECEIPT = 'receipt';
}
```

- [ ] **Step 4: Create the migrations**

Create `database/migrations/2026_08_04_000004_create_goods_receipts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGoodsReceiptsTable extends Migration
{
    public function up()
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->date('receipt_date');
            $table->string('reference_number', 100)->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'receipt_date', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('goods_receipts');
    }
}
```

Create `database/migrations/2026_08_04_000005_create_goods_receipt_lines_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateGoodsReceiptLinesTable extends Migration
{
    public function up()
    {
        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('sparepart_branch_id')->constrained('sparepart_branches');
            $table->decimal('qty', 18, 3);
            $table->decimal('purchase_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT ck_goods_receipt_lines_qty_positive CHECK (qty > 0)');
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT ck_goods_receipt_lines_price_nonnegative CHECK (purchase_price >= 0 AND line_total >= 0)');
    }

    public function down()
    {
        Schema::dropIfExists('goods_receipt_lines');
    }
}
```

Create `database/migrations/2026_08_04_000006_create_inventory_movements_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateInventoryMovementsTable extends Migration
{
    public function up()
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->timestamp('movement_at');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('sparepart_branch_id')->constrained('sparepart_branches');
            $table->string('movement_type', 20);
            $table->decimal('qty_in', 18, 3)->default(0);
            $table->decimal('qty_out', 18, 3)->default(0);
            $table->decimal('balance_after', 18, 3);
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'sparepart_branch_id', 'movement_at']);
        });

        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT ck_inventory_movements_qty_nonnegative CHECK (qty_in >= 0 AND qty_out >= 0)');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT ck_inventory_movements_single_direction CHECK (NOT (qty_in > 0 AND qty_out > 0))');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT ck_inventory_movements_nonzero CHECK (qty_in > 0 OR qty_out > 0)');
    }

    public function down()
    {
        Schema::dropIfExists('inventory_movements');
    }
}
```

- [ ] **Step 5: Create the models**

Create `app/Models/GoodsReceipt.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\GoodsReceiptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'branch_id', 'receipt_date', 'reference_number', 'status', 'notes',
    ];

    protected $casts = [
        'receipt_date' => 'date',
    ];

    protected $attributes = [
        'status' => GoodsReceiptStatus::DRAFT,
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('sort_order');
    }
}
```

Create `app/Models/GoodsReceiptLine.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'goods_receipt_id', 'sparepart_branch_id', 'qty', 'purchase_price', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'purchase_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function sparepartBranch()
    {
        return $this->belongsTo(SparepartBranch::class);
    }
}
```

Create `app/Models/InventoryMovement.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'movement_at', 'branch_id', 'sparepart_branch_id', 'movement_type',
        'qty_in', 'qty_out', 'balance_after', 'reference_type', 'reference_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'movement_at' => 'datetime',
        'qty_in' => 'decimal:3',
        'qty_out' => 'decimal:3',
        'balance_after' => 'decimal:3',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function sparepartBranch()
    {
        return $this->belongsTo(SparepartBranch::class);
    }
}
```

Note: `InventoryMovement` does not use `HasAudit` (same reasoning as `InventoryReservation` in migration 007 — this table has `created_by` but no `updated_by` column; `HasAudit` sets both on every save and would fail on the missing column).

- [ ] **Step 6: Run migrations and tests to verify they pass**

Run: `php artisan migrate` then `php artisan test --filter=GoodsReceiptModelTest`
Expected: PASS.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all pre-existing tests still PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Support/GoodsReceiptStatus.php app/Support/InventoryMovementType.php app/Models/GoodsReceipt.php app/Models/GoodsReceiptLine.php app/Models/InventoryMovement.php database/migrations/2026_08_04_000004_create_goods_receipts_table.php database/migrations/2026_08_04_000005_create_goods_receipt_lines_table.php database/migrations/2026_08_04_000006_create_inventory_movements_table.php tests/Feature/GoodsReceiptModelTest.php
git commit -m "feat: add goods receipt data model and inventory_movements ledger"
```

---

### Task 2: Authorization — `GoodsReceiptPolicy`

**Files:**
- Create: `app/Policies/GoodsReceiptPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php` (add to `$policies` array)
- Test: `tests/Feature/GoodsReceiptAuthorizationTest.php`

**Interfaces:**
- Consumes: `GoodsReceipt`, `GoodsReceiptStatus` (Task 1), `User::hasPermissionToInBranch()` (already exists).
- Produces: `GoodsReceiptPolicy::view/update/post/cancel(User, GoodsReceipt): bool`, consumed by Task 3's controller via `$this->authorize(...)`.

- [ ] **Step 1: Write the failing Policy tests**

Create `tests/Feature/GoodsReceiptAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\GoodsReceiptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodsReceiptAuthorizationTest extends TestCase
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

    protected function makeGoodsReceipt(Branch $branch, array $overrides = []): GoodsReceipt
    {
        return GoodsReceipt::create(array_merge([
            'number' => 'PB/JKT/202608/00001',
            'branch_id' => $branch->id,
            'receipt_date' => now()->format('Y-m-d'),
        ], $overrides));
    }

    public function test_policy_grants_view_and_update_for_the_correct_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.view');
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $goodsReceipt = $this->makeGoodsReceipt($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $goodsReceipt));
        $this->assertTrue($reloaded->can('update', $goodsReceipt));
    }

    public function test_policy_denies_access_for_a_user_with_permission_in_a_different_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'receipt.view');
        $goodsReceipt = $this->makeGoodsReceipt($branchB);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('view', $goodsReceipt));
    }

    public function test_policy_update_requires_create_code_not_just_view(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.view');
        $goodsReceipt = $this->makeGoodsReceipt($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $goodsReceipt));
        $this->assertFalse($reloaded->can('update', $goodsReceipt));
    }

    public function test_policy_denies_update_post_and_cancel_for_a_posted_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->grantBranchPermission($user, $branch, 'receipt.cancel');
        $goodsReceipt = $this->makeGoodsReceipt($branch, ['status' => GoodsReceiptStatus::POSTED]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('update', $goodsReceipt));
        $this->assertFalse($reloaded->can('post', $goodsReceipt));
        $this->assertFalse($reloaded->can('cancel', $goodsReceipt));
    }

    public function test_policy_grants_post_for_a_draft_receipt_with_post_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $goodsReceipt = $this->makeGoodsReceipt($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('post', $goodsReceipt));
    }

    public function test_policy_grants_cancel_for_a_draft_receipt_with_cancel_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.cancel');
        $goodsReceipt = $this->makeGoodsReceipt($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('cancel', $goodsReceipt));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=GoodsReceiptAuthorizationTest`
Expected: FAIL — `GoodsReceiptPolicy` doesn't exist / isn't registered.

- [ ] **Step 3: Create the Policy**

Create `app/Policies/GoodsReceiptPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;
use App\Support\GoodsReceiptStatus;

class GoodsReceiptPolicy
{
    public function view(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $user->hasPermissionToInBranch('receipt.view', $goodsReceipt->branch_id);
    }

    public function update(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $goodsReceipt->status === GoodsReceiptStatus::DRAFT
            && $user->hasPermissionToInBranch('receipt.create', $goodsReceipt->branch_id);
    }

    public function post(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $goodsReceipt->status === GoodsReceiptStatus::DRAFT
            && $user->hasPermissionToInBranch('receipt.post', $goodsReceipt->branch_id);
    }

    public function cancel(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $goodsReceipt->status === GoodsReceiptStatus::DRAFT
            && $user->hasPermissionToInBranch('receipt.cancel', $goodsReceipt->branch_id);
    }
}
```

- [ ] **Step 4: Register the Policy**

In `app/Providers/AuthServiceProvider.php`, add to the `$policies` array:

```php
        \App\Models\GoodsReceipt::class => \App\Policies\GoodsReceiptPolicy::class,
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=GoodsReceiptAuthorizationTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Policies/GoodsReceiptPolicy.php app/Providers/AuthServiceProvider.php tests/Feature/GoodsReceiptAuthorizationTest.php
git commit -m "feat: add branch-scoped, status-aware GoodsReceiptPolicy"
```

---

### Task 3: CRUD controller, FormRequests, views, and posting logic

**Files:**
- Create: `app/Http/Requests/StoreGoodsReceiptRequest.php`
- Create: `app/Http/Requests/UpdateGoodsReceiptRequest.php`
- Create: `app/Http/Controllers/GoodsReceiptController.php`
- Create: `resources/views/goods-receipts/index.blade.php`
- Create: `resources/views/goods-receipts/no-access.blade.php`
- Create: `resources/views/goods-receipts/create.blade.php`
- Create: `resources/views/goods-receipts/edit.blade.php`
- Create: `resources/views/goods-receipts/show.blade.php`
- Modify: `routes/web.php` (add a new `goods-receipts` route group)
- Test: `tests/Feature/GoodsReceiptManagementTest.php`

**Interfaces:**
- Consumes: `GoodsReceiptPolicy` (Task 2); `GoodsReceipt`/`GoodsReceiptLine`/`InventoryMovement`/`GoodsReceiptStatus`/`InventoryMovementType` (Task 1); `partials.list-filter-bar`, `partials.empty-state` (already exist); `DocumentNumberGenerator::next(Branch $branch, string $documentType): string` (already exists, already proven with `'PKB'`).
- Produces: routes `goods-receipts.index/create/store/show/edit/update/post/cancel`. Nothing later in this plan consumes these beyond Task 4's sidebar link, which only needs the route name.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/GoodsReceiptManagementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\GoodsReceiptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodsReceiptManagementTest extends TestCase
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

    protected function makeSparepartBranch(Branch $branch, string $codeSuffix = ''): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => "OLI-01{$codeSuffix}", 'name' => 'Oli Mesin']);

        return SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
    }

    protected function baseStorePayload(Branch $branch, SparepartBranch $sparepartBranch): array
    {
        return [
            'branch_id' => $branch->id,
            'receipt_date' => now()->format('Y-m-d'),
            'reference_number' => 'NOTA-001',
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 10, 'purchase_price' => 40000],
            ],
        ];
    }

    public function test_store_creates_goods_receipt_with_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $response = $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));

        $goodsReceipt = GoodsReceipt::first();
        $response->assertRedirect(route('goods-receipts.show', $goodsReceipt));
        $this->assertSame(GoodsReceiptStatus::DRAFT, $goodsReceipt->status);
        $this->assertStringStartsWith('PB/JKT/', $goodsReceipt->number);
        $this->assertCount(1, $goodsReceipt->lines);
        $this->assertSame(400000.0, (float) $goodsReceipt->lines->first()->line_total);

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(0.0, (float) $stock->on_hand_qty, 'Creating a DRAFT receipt must not touch stock.');
    }

    public function test_store_recomputes_line_total_server_side(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $payload = $this->baseStorePayload($branch, $sparepartBranch);
        $payload['lines'][0]['line_total'] = 999999;

        $this->actingAs(User::find($user->id))->post('/goods-receipts', $payload);

        $goodsReceipt = GoodsReceipt::first();
        $this->assertSame(400000.0, (float) $goodsReceipt->lines->first()->line_total);
    }

    public function test_store_is_forbidden_without_receipt_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));

        $response->assertForbidden();
    }

    public function test_store_rejects_a_receipt_with_no_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $response = $this->actingAs(User::find($user->id))->post('/goods-receipts', [
            'branch_id' => $branch->id, 'receipt_date' => now()->format('Y-m-d'), 'lines' => [],
        ]);

        $response->assertSessionHasErrors(['lines']);
    }

    public function test_store_rejects_sparepart_from_a_different_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $otherBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $otherSparepartBranch = $this->makeSparepartBranch($otherBranch, '-other');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $response = $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $otherSparepartBranch));

        $response->assertSessionHasErrors(['lines.0.sparepart_branch_id']);
    }

    public function test_post_increases_stock_and_writes_ledger_entry(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $response = $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response->assertRedirect(route('goods-receipts.show', $goodsReceipt));
        $goodsReceipt->refresh();
        $this->assertSame(GoodsReceiptStatus::POSTED, $goodsReceipt->status);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(10.0, (float) $stock->on_hand_qty);
        $movement = \DB::table('inventory_movements')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame(10.0, (float) $movement->qty_in);
        $this->assertSame(0.0, (float) $movement->qty_out);
        $this->assertSame(10.0, (float) $movement->balance_after);
        $this->assertSame('receipt', $movement->movement_type);
    }

    public function test_post_with_two_lines_of_different_spareparts_increases_both_correctly(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranchA = $this->makeSparepartBranch($branch, '-a');
        $sparepartBranchB = $this->makeSparepartBranch($branch, '-b');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', [
            'branch_id' => $branch->id,
            'receipt_date' => now()->format('Y-m-d'),
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranchB->id, 'qty' => 5, 'purchase_price' => 20000],
                ['sparepart_branch_id' => $sparepartBranchA->id, 'qty' => 8, 'purchase_price' => 15000],
            ],
        ]);
        $goodsReceipt = GoodsReceipt::first();

        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $stockA = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchA->id)->first();
        $stockB = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchB->id)->first();
        $this->assertSame(8.0, (float) $stockA->on_hand_qty);
        $this->assertSame(5.0, (float) $stockB->on_hand_qty);
        $this->assertSame(2, \DB::table('inventory_movements')->count());
    }

    public function test_post_is_forbidden_without_receipt_post_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $response = $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response->assertForbidden();
    }

    public function test_post_is_forbidden_for_a_non_draft_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();
        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response = $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response->assertForbidden();
    }

    public function test_cancel_from_draft_sets_cancelled_with_no_stock_impact(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.cancel');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $response = $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/cancel");

        $response->assertRedirect(route('goods-receipts.show', $goodsReceipt));
        $goodsReceipt->refresh();
        $this->assertSame(GoodsReceiptStatus::CANCELLED, $goodsReceipt->status);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(0.0, (float) $stock->on_hand_qty);
        $this->assertSame(0, \DB::table('inventory_movements')->count());
    }

    public function test_cancel_is_forbidden_for_a_posted_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->grantBranchPermission($user, $branch, 'receipt.cancel');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();
        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response = $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/cancel");

        $response->assertForbidden();
    }

    public function test_update_is_forbidden_for_a_posted_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();
        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response = $this->actingAs(User::find($user->id))->put("/goods-receipts/{$goodsReceipt->id}", [
            'receipt_date' => now()->format('Y-m-d'),
            'lines' => [['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 1, 'purchase_price' => 1000]],
        ]);

        $response->assertForbidden();
    }

    public function test_index_lists_receipts_for_authorized_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepartBranchA = $this->makeSparepartBranch($branchA, '-a');
        $sparepartBranchB = $this->makeSparepartBranch($branchB, '-b');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'receipt.view');
        $this->grantBranchPermission($user, $branchA, 'receipt.create');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branchA, $sparepartBranchA));
        $this->grantBranchPermission($user, $branchB, 'receipt.create');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branchB, $sparepartBranchB));

        $response = $this->actingAs(User::find($user->id))->get('/goods-receipts');

        $response->assertOk();
        $receiptA = GoodsReceipt::where('branch_id', $branchA->id)->first();
        $receiptB = GoodsReceipt::where('branch_id', $branchB->id)->first();
        $response->assertSee($receiptA->number);
        $response->assertDontSee($receiptB->number);
    }

    public function test_index_shows_no_access_page_without_any_receipt_view_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/goods-receipts');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_index_shows_empty_state_when_no_receipts_match(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.view');

        $response = $this->actingAs(User::find($user->id))->get('/goods-receipts');

        $response->assertOk();
        $response->assertSee('Belum ada penerimaan barang');
    }

    public function test_show_renders_post_and_cancel_buttons_for_a_draft_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.view');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->grantBranchPermission($user, $branch, 'receipt.cancel');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $response = $this->actingAs(User::find($user->id))->get("/goods-receipts/{$goodsReceipt->id}");

        $response->assertOk();
        $response->assertSee(route('goods-receipts.post', $goodsReceipt), false);
        $response->assertSee(route('goods-receipts.cancel', $goodsReceipt), false);
    }

    public function test_show_hides_post_and_cancel_buttons_for_a_posted_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.view');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->grantBranchPermission($user, $branch, 'receipt.cancel');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();
        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response = $this->actingAs(User::find($user->id))->get("/goods-receipts/{$goodsReceipt->id}");

        $response->assertOk();
        $response->assertSee('Diposting');
        $response->assertDontSee(route('goods-receipts.post', $goodsReceipt), false);
        $response->assertDontSee(route('goods-receipts.cancel', $goodsReceipt), false);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=GoodsReceiptManagementTest`
Expected: FAIL — controller/routes/views don't exist yet.

- [ ] **Step 3: Create the FormRequests**

Create `app/Http/Requests/StoreGoodsReceiptRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('receipt.create', $branchId);
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'lines' => array_values(array_filter($this->input('lines', []), function ($line) {
                return ! empty($line['sparepart_branch_id']);
            })),
        ]);
    }

    public function rules()
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'receipt_date' => ['required', 'date'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.sparepart_branch_id' => ['required', 'integer', 'exists:sparepart_branches,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
            'lines.*.purchase_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $branchId = (int) $this->input('branch_id');

            foreach ($this->input('lines', []) as $index => $line) {
                $sparepartBranchId = $line['sparepart_branch_id'] ?? null;
                if (! $sparepartBranchId) {
                    continue;
                }
                $sparepartBranch = SparepartBranch::find($sparepartBranchId);
                if (! $sparepartBranch || ! $sparepartBranch->is_active || (int) $sparepartBranch->branch_id !== $branchId) {
                    $validator->errors()->add("lines.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang penerimaan ini.');
                }
            }
        });
    }
}
```

Create `app/Http/Requests/UpdateGoodsReceiptRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGoodsReceiptRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('update', $this->route('goodsReceipt'));
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'lines' => array_values(array_filter($this->input('lines', []), function ($line) {
                return ! empty($line['sparepart_branch_id']);
            })),
        ]);
    }

    public function rules()
    {
        return [
            'receipt_date' => ['required', 'date'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.sparepart_branch_id' => ['required', 'integer', 'exists:sparepart_branches,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
            'lines.*.purchase_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $branchId = (int) $this->route('goodsReceipt')->branch_id;

            foreach ($this->input('lines', []) as $index => $line) {
                $sparepartBranchId = $line['sparepart_branch_id'] ?? null;
                if (! $sparepartBranchId) {
                    continue;
                }
                $sparepartBranch = SparepartBranch::find($sparepartBranchId);
                if (! $sparepartBranch || ! $sparepartBranch->is_active || (int) $sparepartBranch->branch_id !== $branchId) {
                    $validator->errors()->add("lines.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang penerimaan ini.');
                }
            }
        });
    }
}
```

Note: `branch_id` is absent from `UpdateGoodsReceiptRequest`'s rules — the receipt's branch never changes after creation, matching the PKB precedent.

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/GoodsReceiptController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGoodsReceiptRequest;
use App\Http\Requests\UpdateGoodsReceiptRequest;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\SparepartBranch;
use App\Models\SparepartBranchStock;
use App\Services\DocumentNumberGenerator;
use App\Support\GoodsReceiptStatus;
use App\Support\InventoryMovementType;
use Illuminate\Support\Facades\DB;

class GoodsReceiptController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('receipt.view');

        if ($permittedBranches->isEmpty()) {
            return view('goods-receipts.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $goodsReceipts = GoodsReceipt::with('branch')
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($search, function ($query, $q) {
                $query->where('number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('goods-receipts.index', compact('goodsReceipts'))
            ->with('branches', $permittedBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function create()
    {
        $branches = auth()->user()->branchesWithPermission('receipt.create');

        if ($branches->isEmpty()) {
            return view('goods-receipts.no-access');
        }

        return view('goods-receipts.create', compact('branches'));
    }

    public function store(StoreGoodsReceiptRequest $request)
    {
        $data = $request->validated();
        $branch = Branch::findOrFail($data['branch_id']);

        $goodsReceipt = DB::transaction(function () use ($data, $branch) {
            $goodsReceipt = GoodsReceipt::create([
                'number' => (new DocumentNumberGenerator())->next($branch, 'PB'),
                'branch_id' => $branch->id,
                'receipt_date' => $data['receipt_date'],
                'reference_number' => $data['reference_number'] ?? null,
                'status' => GoodsReceiptStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($goodsReceipt, $data['lines']);

            return $goodsReceipt;
        });

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', 'Penerimaan barang berhasil dibuat.');
    }

    public function show(GoodsReceipt $goodsReceipt)
    {
        $this->authorize('view', $goodsReceipt);

        $goodsReceipt->load(['branch', 'lines.sparepartBranch.sparepart']);

        return view('goods-receipts.show', compact('goodsReceipt'));
    }

    public function edit(GoodsReceipt $goodsReceipt)
    {
        $this->authorize('update', $goodsReceipt);

        $goodsReceipt->load('lines');
        $sparepartBranches = SparepartBranch::with(['sparepart', 'stock'])
            ->where('branch_id', $goodsReceipt->branch_id)
            ->where('is_active', true)
            ->get();
        $missingIds = $goodsReceipt->lines->pluck('sparepart_branch_id')->unique()->diff($sparepartBranches->pluck('id'));
        if ($missingIds->isNotEmpty()) {
            $sparepartBranches = $sparepartBranches->concat(
                SparepartBranch::with(['sparepart', 'stock'])->whereIn('id', $missingIds)->get()
            );
        }

        $sparepartOptions = $sparepartBranches->map(function ($sb) {
            return [
                'id' => $sb->id,
                'code' => $sb->sparepart->code,
                'name' => $sb->sparepart->name,
            ];
        })->values();

        $existingLines = $goodsReceipt->lines->map(function ($line) {
            return [
                'sparepart_branch_id' => $line->sparepart_branch_id,
                'qty' => (float) $line->qty,
                'purchase_price' => (float) $line->purchase_price,
            ];
        })->values();

        return view('goods-receipts.edit', compact('goodsReceipt', 'sparepartOptions', 'existingLines'));
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $goodsReceipt) {
            $fresh = GoodsReceipt::whereKey($goodsReceipt->id)->lockForUpdate()->first();
            if ($fresh->status !== GoodsReceiptStatus::DRAFT) {
                return;
            }

            $fresh->update([
                'receipt_date' => $data['receipt_date'],
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($fresh, $data['lines']);
        });

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', 'Penerimaan barang berhasil diperbarui.');
    }

    public function post(GoodsReceipt $goodsReceipt)
    {
        $this->authorize('post', $goodsReceipt);

        DB::transaction(function () use ($goodsReceipt) {
            $fresh = GoodsReceipt::whereKey($goodsReceipt->id)->lockForUpdate()->first();
            if ($fresh->status !== GoodsReceiptStatus::DRAFT) {
                return;
            }

            $lines = $fresh->lines()->reorder()->orderBy('sparepart_branch_id')->get();

            foreach ($lines as $line) {
                $stock = SparepartBranchStock::where('sparepart_branch_id', $line->sparepart_branch_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $stock->on_hand_qty += $line->qty;
                $stock->save();

                InventoryMovement::create([
                    'movement_at' => now(),
                    'branch_id' => $fresh->branch_id,
                    'sparepart_branch_id' => $line->sparepart_branch_id,
                    'movement_type' => InventoryMovementType::RECEIPT,
                    'qty_in' => $line->qty,
                    'qty_out' => 0,
                    'balance_after' => $stock->on_hand_qty,
                    'reference_type' => 'goods_receipt_line',
                    'reference_id' => $line->id,
                    'created_by' => auth()->id(),
                ]);
            }

            $fresh->status = GoodsReceiptStatus::POSTED;
            $fresh->save();
        });

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', 'Penerimaan barang berhasil diposting.');
    }

    public function cancel(GoodsReceipt $goodsReceipt)
    {
        $this->authorize('cancel', $goodsReceipt);

        DB::transaction(function () use ($goodsReceipt) {
            $fresh = GoodsReceipt::whereKey($goodsReceipt->id)->lockForUpdate()->first();
            if ($fresh->status !== GoodsReceiptStatus::DRAFT) {
                return;
            }

            $fresh->status = GoodsReceiptStatus::CANCELLED;
            $fresh->save();
        });

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', 'Penerimaan barang berhasil dibatalkan.');
    }

    protected function syncLines(GoodsReceipt $goodsReceipt, array $lines): void
    {
        $goodsReceipt->lines()->delete();

        foreach (array_values(array_filter($lines)) as $index => $line) {
            $qty = (float) $line['qty'];
            $purchasePrice = (float) $line['purchase_price'];
            GoodsReceiptLine::create([
                'goods_receipt_id' => $goodsReceipt->id,
                'sparepart_branch_id' => $line['sparepart_branch_id'],
                'qty' => $qty,
                'purchase_price' => $purchasePrice,
                'line_total' => round($qty * $purchasePrice, 2),
                'sort_order' => $index,
            ]);
        }
    }
}
```

Note the `update()`/`post()`/`cancel()` methods all lock-and-recheck the `GoodsReceipt` row FIRST, exactly per this plan's global constraint — this is the pattern migration 007 only arrived at after 3 rounds of review; this plan builds it in from Task 3's first draft.

Note also `->authorize(...)` is called on the ROUTE-BOUND `$goodsReceipt` in `post()`/`cancel()` (consistent with `confirm()`/`cancel()` in `WorkOrderController`) — the in-transaction re-check (`if ($fresh->status !== ...) { return; }`) is the defense against the race window between that authorization check and the transaction actually running, not a replacement for it.

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add `use App\Http\Controllers\GoodsReceiptController;` to the `use` block, and add this new route group inside the `Route::middleware(['auth'])->group(function () { ... })` block, after the existing `work-orders` group and before `users`:

```php
    Route::prefix('goods-receipts')->name('goods-receipts.')->group(function () {
        Route::get('/', [GoodsReceiptController::class, 'index'])->name('index');
        Route::get('/create', [GoodsReceiptController::class, 'create'])->name('create');
        Route::post('/', [GoodsReceiptController::class, 'store'])->name('store');
        Route::get('/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->name('show');
        Route::get('/{goodsReceipt}/edit', [GoodsReceiptController::class, 'edit'])->name('edit');
        Route::put('/{goodsReceipt}', [GoodsReceiptController::class, 'update'])->name('update');
        Route::patch('/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->name('post');
        Route::patch('/{goodsReceipt}/cancel', [GoodsReceiptController::class, 'cancel'])->name('cancel');
    });
```

- [ ] **Step 6: Create the index and no-access views**

Create `resources/views/goods-receipts/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Penerimaan Barang')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-truck me-2"></i>Penerimaan Barang</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor penerimaan...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => auth()->user()->branchesWithPermission('receipt.create')->isNotEmpty()
            ? '<a href="' . route('goods-receipts.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Penerimaan Baru</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nomor</th>
                        <th>Cabang</th>
                        <th>Tanggal</th>
                        <th>No. Referensi</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($goodsReceipts as $goodsReceipt)
                        <tr>
                            <td><code>{{ $goodsReceipt->number }}</code></td>
                            <td>{{ $goodsReceipt->branch->name }}</td>
                            <td>{{ $goodsReceipt->receipt_date->format('d/m/Y') }}</td>
                            <td>{{ $goodsReceipt->reference_number ?? '-' }}</td>
                            <td>
                                @if ($goodsReceipt->status === \App\Support\GoodsReceiptStatus::DRAFT)
                                    <span class="status-dot status-active">Draft</span>
                                @elseif ($goodsReceipt->status === \App\Support\GoodsReceiptStatus::POSTED)
                                    <span class="status-dot status-active">Diposting</span>
                                @else
                                    <span class="status-dot status-inactive">Dibatalkan</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('goods-receipts.show', $goodsReceipt) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-truck',
                                    'title' => 'Belum ada penerimaan barang',
                                    'description' => 'Mulai dengan membuat penerimaan barang pertama.',
                                    'ctaRoute' => 'goods-receipts.create',
                                    'ctaLabel' => '+ Buat Penerimaan Pertama',
                                    'ctaVisible' => auth()->user()->branchesWithPermission('receipt.create')->isNotEmpty(),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $goodsReceipts->links() }}
    </div>
@endsection
```

Create `resources/views/goods-receipts/no-access.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Penerimaan Barang')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-truck me-2"></i>Penerimaan Barang</h1>
    </div>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            Anda belum memiliki akses penerimaan barang di cabang manapun. Hubungi admin untuk meminta akses.
        </div>
    </div>
@endsection
```

- [ ] **Step 7: Create a shared line-item scripts partial**

Create `resources/views/goods-receipts/_line_item_scripts.blade.php` (shared by `create.blade.php`/`edit.blade.php`, following the same `<template>`-cloning approach already established for PKB's `work-orders/_line_item_scripts.blade.php`):

```blade
<template id="goodsReceiptLineTemplate">
    <div class="row g-2 align-items-start mb-2 goods-receipt-line">
        <div class="col-md-5">
            <select class="form-select goods-receipt-sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
        </div>
        <div class="col-md-3">
            <input type="number" step="0.001" min="0.001" class="form-control goods-receipt-qty" value="1">
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" min="0" class="form-control goods-receipt-purchase-price">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-goods-receipt-line">&times;</button>
        </div>
    </div>
</template>

@push('scripts')
<script>
(function () {
    let lineCount = 0;
    let sparepartOptionsCache = [];

    function fillSelect(select, items, placeholder) {
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        items.forEach(function (item) {
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.code + ' — ' + item.name;
            select.appendChild(option);
        });
    }

    function addLine() {
        const template = document.getElementById('goodsReceiptLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.goods-receipt-line');
        const index = lineCount++;
        const select = wrapper.querySelector('.goods-receipt-sparepart-select');
        select.name = `lines[${index}][sparepart_branch_id]`;
        wrapper.querySelector('.goods-receipt-qty').name = `lines[${index}][qty]`;
        wrapper.querySelector('.goods-receipt-purchase-price').name = `lines[${index}][purchase_price]`;
        fillSelect(select, sparepartOptionsCache, '-- Pilih Sparepart --');
        wrapper.querySelector('.remove-goods-receipt-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('goodsReceiptLines').appendChild(wrapper);
    }

    document.getElementById('addGoodsReceiptLine').addEventListener('click', addLine);

    window.GoodsReceiptLineItems = {
        setSparepartOptions: function (items) {
            sparepartOptionsCache = items;
            document.querySelectorAll('.goods-receipt-sparepart-select').forEach(function (select) {
                const currentValue = select.value;
                fillSelect(select, items, '-- Pilih Sparepart --');
                select.value = currentValue;
            });
        },
        addLine: addLine,
        fetchJson: async function (url) {
            const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
            return response.json();
        },
    };
})();
</script>
@endpush
```

- [ ] **Step 8: Create the create view**

Create `resources/views/goods-receipts/create.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Penerimaan Barang Baru')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-truck me-2"></i>Penerimaan Barang Baru</h1>
    </div>
    <form method="POST" action="{{ route('goods-receipts.store') }}" id="goodsReceiptForm">
        @csrf
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Cabang</label>
                        <select name="branch_id" id="branchSelect" class="form-select @error('branch_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Cabang --</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" {{ (int) old('branch_id') === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tanggal Penerimaan</label>
                        <input type="date" name="receipt_date" value="{{ old('receipt_date', now()->format('Y-m-d')) }}" class="form-control @error('receipt_date') is-invalid @enderror" required>
                        @error('receipt_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">No. Referensi</label>
                        <input type="text" name="reference_number" value="{{ old('reference_number') }}" class="form-control @error('reference_number') is-invalid @enderror" maxlength="100">
                        @error('reference_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes') }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Sparepart</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addGoodsReceiptLine" disabled>+ Tambah Sparepart</button>
                </div>
                <div id="goodsReceiptLines"></div>
                @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('goods-receipts.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('goods-receipts._line_item_scripts')

    @push('scripts')
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const addButton = document.getElementById('addGoodsReceiptLine');

        branchSelect.addEventListener('change', async function () {
            addButton.disabled = true;
            if (!this.value) {
                return;
            }
            const spareparts = await GoodsReceiptLineItems.fetchJson(`/goods-receipts/lookup/spareparts/${this.value}`);
            GoodsReceiptLineItems.setSparepartOptions(spareparts);
            addButton.disabled = false;
        });
    })();
    </script>
    @endpush
@endsection
```

Note: this view calls a lookup endpoint `/goods-receipts/lookup/spareparts/{branch}` that does NOT exist yet in this plan's routes — **add it now**: in `app/Http/Controllers/GoodsReceiptController.php`, add one more public method:

```php
    public function sparepartsByBranch(Branch $branch)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('receipt.create', $branch->id), 403);

        return response()->json(
            SparepartBranch::with('sparepart')
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->get()
                ->map(function (SparepartBranch $sb) {
                    return ['id' => $sb->id, 'code' => $sb->sparepart->code, 'name' => $sb->sparepart->name];
                })
                ->values()
        );
    }
```

And add its route in `routes/web.php`, inside the `goods-receipts` group, BEFORE the `/{goodsReceipt}` route (route ordering matters — a literal `/lookup/...` segment must be registered before the `/{goodsReceipt}` wildcard, or the wildcard will swallow it):

```php
        Route::get('/lookup/spareparts/{branch}', [GoodsReceiptController::class, 'sparepartsByBranch'])->name('lookup.spareparts');
```

Add a test for this lookup endpoint to `tests/Feature/GoodsReceiptManagementTest.php`:

```php
    public function test_lookup_spareparts_by_branch_returns_only_active_configs_for_that_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $response = $this->actingAs(User::find($user->id))->getJson("/goods-receipts/lookup/spareparts/{$branch->id}");

        $response->assertOk();
        $response->assertJsonFragment(['id' => $sparepartBranch->id]);
    }

    public function test_lookup_spareparts_by_branch_is_forbidden_without_receipt_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson("/goods-receipts/lookup/spareparts/{$branch->id}");

        $response->assertForbidden();
    }
```

- [ ] **Step 9: Create the edit view**

Create `resources/views/goods-receipts/edit.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Ubah Penerimaan Barang')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-truck me-2"></i>Ubah {{ $goodsReceipt->number }} — {{ $goodsReceipt->branch->name }}</h1>
    </div>
    <form method="POST" action="{{ route('goods-receipts.update', $goodsReceipt) }}" id="goodsReceiptForm">
        @csrf
        @method('PUT')
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Tanggal Penerimaan</label>
                        <input type="date" name="receipt_date" value="{{ old('receipt_date', $goodsReceipt->receipt_date->format('Y-m-d')) }}" class="form-control @error('receipt_date') is-invalid @enderror" required>
                        @error('receipt_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">No. Referensi</label>
                        <input type="text" name="reference_number" value="{{ old('reference_number', $goodsReceipt->reference_number) }}" class="form-control @error('reference_number') is-invalid @enderror" maxlength="100">
                        @error('reference_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes', $goodsReceipt->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Sparepart</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addGoodsReceiptLine">+ Tambah Sparepart</button>
                </div>
                <div id="goodsReceiptLines"></div>
                @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('goods-receipts.show', $goodsReceipt) }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('goods-receipts._line_item_scripts')

    @push('scripts')
    <script>
    (function () {
        const existingSparepartOptions = @json($sparepartOptions);
        GoodsReceiptLineItems.setSparepartOptions(existingSparepartOptions);

        const existingLines = @json($existingLines);
        existingLines.forEach(function (line) {
            GoodsReceiptLineItems.addLine();
            const rows = document.querySelectorAll('#goodsReceiptLines .goods-receipt-line');
            const row = rows[rows.length - 1];
            row.querySelector('.goods-receipt-sparepart-select').value = line.sparepart_branch_id;
            row.querySelector('.goods-receipt-qty').value = line.qty;
            row.querySelector('.goods-receipt-purchase-price').value = line.purchase_price;
        });
    })();
    </script>
    @endpush
@endsection
```

Note: `@json($sparepartOptions)` and `@json($existingLines)` are both single, comma-free variable references — this is the SAFE form of `@json()`, not the multi-line-closure-with-commas form that broke migration 007's Task 4 (`@json($collection->map(function ($x) { ... }))`). The data-shaping already happened in the controller's `edit()` method (Step 4), producing plain arrays before the view ever sees them — do not move the `->map(...)` calls into this view.

- [ ] **Step 10: Create the show (detail) view**

Create `resources/views/goods-receipts/show.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Detail Penerimaan Barang')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-truck me-2"></i>{{ $goodsReceipt->number }}</h1>
        <div class="d-flex gap-2">
            @can('update', $goodsReceipt)
                <a href="{{ route('goods-receipts.edit', $goodsReceipt) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
            @can('post', $goodsReceipt)
                <form method="POST" action="{{ route('goods-receipts.post', $goodsReceipt) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Posting</button>
                </form>
            @endcan
            @can('cancel', $goodsReceipt)
                <form method="POST" action="{{ route('goods-receipts.cancel', $goodsReceipt) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-outline-danger btn-sm">Batalkan</button>
                </form>
            @endcan
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><strong>Cabang</strong><div>{{ $goodsReceipt->branch->name }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $goodsReceipt->receipt_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3"><strong>No. Referensi</strong><div>{{ $goodsReceipt->reference_number ?? '-' }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>
                        @if ($goodsReceipt->status === \App\Support\GoodsReceiptStatus::DRAFT)
                            <span class="status-dot status-active">Draft</span>
                        @elseif ($goodsReceipt->status === \App\Support\GoodsReceiptStatus::POSTED)
                            <span class="status-dot status-active">Diposting</span>
                        @else
                            <span class="status-dot status-inactive">Dibatalkan</span>
                        @endif
                    </div>
                </div>
                <div class="col-md-12"><strong>Catatan</strong><div>{{ $goodsReceipt->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Baris Sparepart</h2>
            <table class="table table-sm">
                <thead><tr><th>Kode</th><th>Nama</th><th>Qty</th><th>Harga Beli</th><th>Total</th></tr></thead>
                <tbody>
                    @forelse ($goodsReceipt->lines as $line)
                        <tr>
                            <td><code>{{ $line->sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $line->sparepartBranch->sparepart->name }}</td>
                            <td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->purchase_price, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">Tidak ada baris sparepart.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <a href="{{ route('goods-receipts.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
```

- [ ] **Step 11: Run tests to verify they pass**

Run: `php artisan test --filter=GoodsReceiptManagementTest`
Expected: PASS.

- [ ] **Step 12: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS.

- [ ] **Step 13: Commit**

```bash
git add app/Http/Requests/StoreGoodsReceiptRequest.php app/Http/Requests/UpdateGoodsReceiptRequest.php app/Http/Controllers/GoodsReceiptController.php resources/views/goods-receipts routes/web.php tests/Feature/GoodsReceiptManagementTest.php
git commit -m "feat: implement goods receipt CRUD, posting (writes on_hand_qty + ledger), and cancel"
```

---

### Task 4: Sidebar wiring and full-suite verification

**Files:**
- Modify: `resources/views/partials/sidebar.blade.php`
- Test: `tests/Feature/AppShellTest.php` (comment-only update, if applicable)

**Interfaces:**
- Consumes: `route('goods-receipts.index')` (Task 3).
- Produces: nothing consumed by later tasks — this is the final task in the plan.

- [ ] **Step 1: Write the failing sidebar test**

Check `tests/Feature/AppShellTest.php` for the existing "Penerimaan Barang" placeholder test(s) — it should look like:
```php
    public function test_sidebar_shows_penerimaan_barang_placeholder_when_user_has_receipt_view_permission_in_a_branch(): void
    {
        // ... grants receipt.view, asserts assertSee('Penerimaan Barang', false) ...
    }
```
Add a new assertion to that existing test (do NOT rename the method): `$response->assertSee(route('goods-receipts.index'), false);` — this pins the fix as a real link, not just matching text (mirroring the lesson from migration 007's final review, which found the exact same gap for PKB's sidebar link).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AppShellTest`
Expected: the modified test FAILS (the route URL doesn't appear yet — the placeholder is still a disabled `<span>`).

- [ ] **Step 3: Swap the sidebar placeholder**

In `resources/views/partials/sidebar.blade.php`, find the block:

```blade
        @if ($user->branchesWithPermission('receipt.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-truck me-2"></i> Penerimaan Barang
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
```

Replace with:

```blade
        @if ($user->branchesWithPermission('receipt.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('goods-receipts.index') }}" class="nav-link {{ request()->routeIs('goods-receipts.*') ? 'active' : '' }}">
                <i class="bi bi-truck me-2"></i> Penerimaan Barang
            </a>
        </li>
        @endif
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AppShellTest`
Expected: PASS.

- [ ] **Step 5: Run the full suite and the text-collision grep**

Run: `php artisan test`
Expected: all tests PASS.

Run: `grep -rn "Belum ada penerimaan barang\|Buat Penerimaan Pertama\|Penerimaan Baru\|Cari nomor penerimaan" tests/Feature/AppShellTest.php tests/Feature/DashboardTest.php`
Expected: no unexpected matches beyond the "Penerimaan Barang" occurrence already reviewed in Step 1. If a match is found on a NEW string, verify whether it collides with an unrelated assertion, and if so, narrow the colliding assertion the same way prior collisions in this project were resolved (unique icon class or route check).

- [ ] **Step 6: Commit**

```bash
git add resources/views/partials/sidebar.blade.php tests/Feature/AppShellTest.php
git commit -m "feat: wire up Penerimaan Barang sidebar link"
```

---

## Self-Review Notes

- **Spec coverage:** every in-scope item from the design spec is covered — data model (Task 1), Policy (Task 2), CRUD controller/FormRequests/views/posting logic (Task 3), sidebar wiring (Task 4). Explicitly out-of-scope items (Stock Adjustment, Transfer Stock, cancel-after-POSTED, other `movement_type` values, Dashboard button) are untouched by every task.
- **Placeholder scan:** none found — every code block is complete and copy-ready.
- **Type consistency:** `GoodsReceipt`/`GoodsReceiptLine`/`InventoryMovement` field names and relation method names (`lines()`, `branch()`, `sparepartBranch()`, `goodsReceipt()`) introduced in Task 1 are used identically in every later task. `GoodsReceiptStatus`/`InventoryMovementType` constants are referenced the same way everywhere (controller, Policy, views).
- **Scope check:** 4 tasks, similar size to migration 006 but slightly smaller (1 line-item type instead of 2, 1-level cascading dropdown instead of 4) — appropriate for the sub-project decomposition agreed with the user.
- **Concurrency discipline:** unlike migration 007, which had to retrofit the "lock header row first, re-check status, then lock stock rows in `sparepart_branch_id` order" pattern across 3 review rounds, this plan's Task 3 controller code (Step 4) already applies that exact pattern from its first draft in `update()`, `post()`, and implicitly documents why in the inline note — directly incorporating the migration 007 lesson rather than waiting for a reviewer to find it again.
