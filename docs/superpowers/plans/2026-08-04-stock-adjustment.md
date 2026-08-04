# Sub-Proyek 008b — Stock Adjustment (Penyesuaian Stok) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Stock Adjustment module — the second module to write `sparepart_branch_stocks.on_hand_qty` and the second consumer of the `inventory_movements` ledger (both from 008a). Full CRUD while `DRAFT`, plus a 3-stage lifecycle (`submit` → `approve` → `post`) and a `cancel` action reachable from `DRAFT`/`PENDING_APPROVAL`/`APPROVED`. `post()` always recomputes the adjustment against **current** stock so the result is guaranteed to exactly match the physical count, even if stock drifted during the approval wait.

**Architecture:** `StockAdjustmentPolicy` mirrors `GoodsReceiptPolicy`'s shape but with 4 status-aware actions instead of 2 (`update`/`submit` both DRAFT-only, `approve` PENDING_APPROVAL-only, `post` APPROVED-only, `cancel` accepts DRAFT/PENDING_APPROVAL/APPROVED). Every status-changing action locks the header row first and re-verifies status inside the transaction before acting — applied identically to `submit`/`approve`/`post`/`cancel`, not just `post`, from the first draft. `post()` additionally locks `sparepart_branch_stocks` rows in `sparepart_branch_id` ascending order (via `->reorder()`, since `StockAdjustment::lines()` carries its own `orderBy('sort_order')`) and **recomputes the adjustment against current `on_hand_qty` at lock time**, not the `system_qty` snapshot taken when the line was created — this guarantees `on_hand_qty` after posting always exactly equals `physical_qty`, and any drift between what was approved and what actually gets applied is written into the ledger's `notes` column rather than silently absorbed.

**Tech Stack:** Laravel 8.75, PHP 7.4.33 (no PHP 8 syntax), MySQL 8.0, PHPUnit feature tests (`RefreshDatabase`).

## Global Constraints

- PHP runtime is 7.4.33 — never use PHP 8-only syntax (nullsafe `?->`, named arguments, match expressions, enums, constructor property promotion, union types), including inside Blade `@php()` blocks.
- Laravel 8.75 pinned — never use `Request::integer()` or other Laravel 9+ `Request` helper methods.
- `bigint` PKs, never UUID. `->simplePaginate()` only, never `->paginate()`.
- No hard deletes of transactional documents — status field only (`draft`/`pending_approval`/`approved`/`posted`/`cancelled`). Line-item child table cascade-deletes only as a DB-level safety net (the application never deletes a `stock_adjustment` row in this scope).
- `stock_adjustment.view`, `stock_adjustment.create`, `stock_adjustment.approve`, `stock_adjustment.post`, `stock_adjustment.cancel` are already seeded in `MenuPermissionSeeder` (menu code `persediaan.stock_adjustment`, `is_branch_scoped = true`) — do not add new permission codes. There is no `stock_adjustment.edit`/`.submit` code; editing and submitting a `DRAFT` are both gated on `stock_adjustment.create`.
- **This module deliberately uses 5 statuses (`DRAFT`, `PENDING_APPROVAL`, `APPROVED`, `POSTED`, `CANCELLED`), one more than the 4-status source document** (`Rencana_Migrasi_Database_Sistem_Bengkel.md` §11.2, which has no `APPROVED`) — confirmed explicitly with the user, because `stock_adjustment.approve` and `stock_adjustment.post` are two separate permission codes, implying two separate stages. Do not collapse them back to the source doc's 4 statuses.
- There is no "reject" action. An approver who disagrees with a `PENDING_APPROVAL`/`APPROVED` adjustment uses `cancel` (permission `stock_adjustment.cancel`, independent of who created/approved it) — this is a deliberate scope decision, not a gap.
- No segregation-of-duties restriction: the same user may hold both `stock_adjustment.create` and `stock_adjustment.approve` in a branch and use both on the same document.
- Branch-scoped permission codes must never be checked via a bare `$this->authorize('code')`/`Gate`-only call with no model argument — every check must go through `hasPermissionToInBranch($code, $branchId)` directly, or a Policy method receiving a model instance.
- **Every row-locking transaction must lock the header row (`stock_adjustments`) FIRST, re-verify its status hasn't changed, THEN (for `post()` only) lock any `sparepart_branch_stocks` rows in `sparepart_branch_id` ascending order** — apply this to `submit()`, `approve()`, `post()`, AND `cancel()` from the start, not just `post()`. This exact discipline is what migration 007 had to learn the hard way and what 008a successfully applied from the start; this plan continues that pattern to a fourth and fifth locking action.
- **`post()` always recomputes `system_qty` fresh from `sparepart_branch_stocks.on_hand_qty` at lock time — never trust the `system_qty`/`adjustment_qty` values stored on the line since creation for the actual stock mutation.** Those stored values are historical/informational only (what was shown to the approver). If the freshly-computed delta differs from the stored `adjustment_qty`, record the discrepancy in the `InventoryMovement.notes` column — never silently apply a different number than what was reviewed without a trace.
- `adjustment_qty` on a line may be negative (a shortage) — do not add a `CHECK >= 0` constraint on that column, unlike `system_qty`/`physical_qty` which are always non-negative.
- A `sparepart_branch_id` may appear **at most once** per `stock_adjustment` document (`UNIQUE(stock_adjustment_id, sparepart_branch_id)` at the DB level, plus a `distinct` validation rule at the HTTP layer) — unlike Goods Receipt, which deliberately allows the same sparepart on multiple lines.
- `inventory_movements` is append-only — nothing in this plan ever updates or deletes a row from it. A line whose recomputed delta is exactly zero at post time writes **no** ledger row for that line (the `qty_in > 0 OR qty_out > 0` CHECK constraint forbids a zero-delta row) — the document still reaches `POSTED` even if every line nets to zero.
- Search input sanitized once in the controller (`is_string(request('q')) ? trim(request('q')) : null`), never re-read raw in the Blade view.
- Reuse the existing design system and shared partials: `partials.list-filter-bar`, `partials.empty-state` — do not hand-roll new list/filter/empty-state markup. Reuse `DocumentNumberGenerator::next($branch, 'SA')` for numbering (format: `SA/{BRANCH_CODE}/{YYYYMM}/{00001}`).
- **Every `@json(...)` call must reference a single, comma-free plain variable** (shape data into a controller-side array/variable first with `@php($x = ...)`, then `@json($x)` in the view) — never `@json(some_function($arg1, $arg2))` or `@json(old('key', []))` directly. This exact bug class caused a Critical parse error in migration 006 and a silent XSS-hardening degradation discovered (but not fixed, out of scope) during 008a's final review — every `@json(` call site in this plan's own views must be scrutinized against this rule before being considered done.
- **The create form's validation-error round-trip (old-input replay + re-enabling the "add line" button) must be built correctly from the first draft**, not fixed in a later round — this was an Important finding in 008a's final review and the fix pattern is fully worked out in `resources/views/goods-receipts/create.blade.php` (read it before writing this module's `create.blade.php`).
- **The status badge markup must live in exactly one shared partial** (`stock-adjustments/_status_badge.blade.php`), included by both `index.blade.php` and `show.blade.php` — never duplicate the status→label/class mapping in two places. This directly prevents the exact bug migration 007's final review found (list page's badge logic had fewer branches than the detail page's and silently mis-rendered new statuses).

---

### Task 1: Data model — migrations, models, support classes

**Files:**
- Create: `database/migrations/2026_08_04_000007_create_stock_adjustments_table.php`
- Create: `database/migrations/2026_08_04_000008_create_stock_adjustment_lines_table.php`
- Create: `app/Support/StockAdjustmentStatus.php`
- Modify: `app/Support/InventoryMovementType.php`
- Create: `app/Models/StockAdjustment.php`
- Create: `app/Models/StockAdjustmentLine.php`
- Test: `tests/Feature/StockAdjustmentModelTest.php`

**Interfaces:**
- Produces: `StockAdjustment` (fields: `number`, `branch_id`, `adjustment_date`, `reason`, `status`, `approved_by`, `approved_at`, `notes`; relations `branch()`, `approvedBy()`, `lines()`); `StockAdjustmentLine` (fields: `stock_adjustment_id`, `sparepart_branch_id`, `system_qty`, `physical_qty`, `adjustment_qty`, `reason`, `sort_order`; relations `stockAdjustment()`, `sparepartBranch()`); `StockAdjustmentStatus::DRAFT`/`PENDING_APPROVAL`/`APPROVED`/`POSTED`/`CANCELLED`; `InventoryMovementType::ADJUSTMENT_IN`/`ADJUSTMENT_OUT` (added alongside the existing `RECEIPT`). Every later task must use these constants, never bare string literals.

- [ ] **Step 1: Write the failing model tests**

Create `tests/Feature/StockAdjustmentModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Support\InventoryMovementType;
use App\Support\StockAdjustmentStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentModelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSparepartBranch(Branch $branch, string $codeSuffix = ''): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => "OLI-01{$codeSuffix}", 'name' => 'Oli Mesin']);

        return SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
    }

    public function test_stock_adjustment_can_be_created_with_fillable_fields_and_defaults_to_draft(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001',
            'branch_id' => $branch->id,
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Stock opname bulanan',
        ]);

        $this->assertSame(StockAdjustmentStatus::DRAFT, $stockAdjustment->status);
        $this->assertSame($user->id, $stockAdjustment->created_by);
        $this->assertNull($stockAdjustment->approved_by);
        $this->assertNull($stockAdjustment->approved_at);
    }

    public function test_stock_adjustment_number_is_unique(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $attrs = ['number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname'];
        StockAdjustment::create($attrs);

        $this->expectException(QueryException::class);
        StockAdjustment::create($attrs);
    }

    public function test_stock_adjustment_line_belongs_to_adjustment_and_sparepart_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $stockAdjustment = StockAdjustment::create(['number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname']);
        $sparepartBranch = $this->makeSparepartBranch($branch);

        $line = StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id,
            'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10,
            'physical_qty' => 8,
            'adjustment_qty' => -2,
            'reason' => 'Rusak',
        ]);

        $this->assertSame($stockAdjustment->id, $line->stockAdjustment->id);
        $this->assertSame($sparepartBranch->id, $line->sparepartBranch->id);
        $this->assertCount(1, $stockAdjustment->lines);
    }

    public function test_deleting_stock_adjustment_cascades_to_its_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $stockAdjustment = StockAdjustment::create(['number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $line = StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 8, 'adjustment_qty' => -2, 'reason' => 'Rusak',
        ]);

        $stockAdjustment->delete();

        $this->assertDatabaseMissing('stock_adjustment_lines', ['id' => $line->id]);
    }

    public function test_stock_adjustment_line_rejects_duplicate_sparepart_branch_in_same_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $stockAdjustment = StockAdjustment::create(['number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 8, 'adjustment_qty' => -2, 'reason' => 'Rusak',
        ]);

        $this->expectException(QueryException::class);
        StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 9, 'adjustment_qty' => -1, 'reason' => 'Hilang',
        ]);
    }

    public function test_inventory_movement_can_be_created_with_adjustment_in_and_adjustment_out_types(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);

        $movementIn = InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::ADJUSTMENT_IN, 'qty_in' => 5, 'qty_out' => 0,
            'balance_after' => 15, 'reference_type' => 'stock_adjustment_line', 'reference_id' => 1,
        ]);
        $movementOut = InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::ADJUSTMENT_OUT, 'qty_in' => 0, 'qty_out' => 3,
            'balance_after' => 12, 'reference_type' => 'stock_adjustment_line', 'reference_id' => 2,
        ]);

        $this->assertSame(InventoryMovementType::ADJUSTMENT_IN, $movementIn->movement_type);
        $this->assertSame(InventoryMovementType::ADJUSTMENT_OUT, $movementOut->movement_type);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockAdjustmentModelTest`
Expected: FAIL — tables/classes don't exist yet.

- [ ] **Step 3: Create the support classes**

Create `app/Support/StockAdjustmentStatus.php`:

```php
<?php

namespace App\Support;

class StockAdjustmentStatus
{
    const DRAFT = 'draft';
    const PENDING_APPROVAL = 'pending_approval';
    const APPROVED = 'approved';
    const POSTED = 'posted';
    const CANCELLED = 'cancelled';
}
```

Modify `app/Support/InventoryMovementType.php` to add the two new constants (keep `RECEIPT`):

```php
<?php

namespace App\Support;

class InventoryMovementType
{
    const RECEIPT = 'receipt';
    const ADJUSTMENT_IN = 'adjustment_in';
    const ADJUSTMENT_OUT = 'adjustment_out';
}
```

- [ ] **Step 4: Create the migrations**

Create `database/migrations/2026_08_04_000007_create_stock_adjustments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStockAdjustmentsTable extends Migration
{
    public function up()
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->date('adjustment_date');
            $table->string('reason', 255);
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'adjustment_date', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('stock_adjustments');
    }
}
```

Create `database/migrations/2026_08_04_000008_create_stock_adjustment_lines_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateStockAdjustmentLinesTable extends Migration
{
    public function up()
    {
        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('sparepart_branch_id')->constrained('sparepart_branches');
            $table->decimal('system_qty', 18, 3);
            $table->decimal('physical_qty', 18, 3);
            $table->decimal('adjustment_qty', 18, 3);
            $table->string('reason', 255);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['stock_adjustment_id', 'sparepart_branch_id']);
        });

        DB::statement('ALTER TABLE stock_adjustment_lines ADD CONSTRAINT ck_stock_adjustment_lines_qty_nonnegative CHECK (system_qty >= 0 AND physical_qty >= 0)');
    }

    public function down()
    {
        Schema::dropIfExists('stock_adjustment_lines');
    }
}
```

Note: `adjustment_qty` is deliberately NOT constrained to `>= 0` — a shortage line has a negative value, and that's expected.

- [ ] **Step 5: Create the models**

Create `app/Models/StockAdjustment.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\StockAdjustmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'branch_id', 'adjustment_date', 'reason', 'status', 'approved_by', 'approved_at', 'notes',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
        'approved_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => StockAdjustmentStatus::DRAFT,
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines()
    {
        return $this->hasMany(StockAdjustmentLine::class)->orderBy('sort_order');
    }
}
```

Create `app/Models/StockAdjustmentLine.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustmentLine extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'stock_adjustment_id', 'sparepart_branch_id', 'system_qty', 'physical_qty', 'adjustment_qty', 'reason', 'sort_order',
    ];

    protected $casts = [
        'system_qty' => 'decimal:3',
        'physical_qty' => 'decimal:3',
        'adjustment_qty' => 'decimal:3',
    ];

    public function stockAdjustment()
    {
        return $this->belongsTo(StockAdjustment::class);
    }

    public function sparepartBranch()
    {
        return $this->belongsTo(SparepartBranch::class);
    }
}
```

- [ ] **Step 6: Run migrations and tests to verify they pass**

Run: `php artisan migrate` then `php artisan test --filter=StockAdjustmentModelTest`
Expected: 6 passed.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: 401 passed (395 baseline + 6 new).

- [ ] **Step 8: Commit**

```bash
git add app/Support/StockAdjustmentStatus.php app/Support/InventoryMovementType.php app/Models/StockAdjustment.php app/Models/StockAdjustmentLine.php database/migrations/2026_08_04_000007_create_stock_adjustments_table.php database/migrations/2026_08_04_000008_create_stock_adjustment_lines_table.php tests/Feature/StockAdjustmentModelTest.php
git commit -m "feat: add stock adjustment data model and extend inventory movement types"
```

---

### Task 2: Authorization — `StockAdjustmentPolicy`

**Files:**
- Create: `app/Policies/StockAdjustmentPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php` (add to `$policies` array)
- Test: `tests/Feature/StockAdjustmentAuthorizationTest.php`

**Interfaces:**
- Consumes: `StockAdjustment`, `StockAdjustmentStatus` (Task 1), `User::hasPermissionToInBranch()` (already exists).
- Produces: `StockAdjustmentPolicy::view/update/submit/approve/post/cancel(User, StockAdjustment): bool`, consumed by Task 3/4's controller via `$this->authorize(...)`.

- [ ] **Step 1: Write the failing Policy tests**

Create `tests/Feature/StockAdjustmentAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\StockAdjustmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentAuthorizationTest extends TestCase
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

    protected function makeStockAdjustment(Branch $branch, array $overrides = []): StockAdjustment
    {
        return StockAdjustment::create(array_merge([
            'number' => 'SA/JKT/202608/00001',
            'branch_id' => $branch->id,
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Stock opname',
        ], $overrides));
    }

    public function test_policy_grants_view_and_update_for_the_correct_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = $this->makeStockAdjustment($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $stockAdjustment));
        $this->assertTrue($reloaded->can('update', $stockAdjustment));
    }

    public function test_policy_denies_access_for_a_user_with_permission_in_a_different_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'stock_adjustment.view');
        $stockAdjustment = $this->makeStockAdjustment($branchB);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('view', $stockAdjustment));
    }

    public function test_policy_update_requires_create_code_not_just_view(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $stockAdjustment = $this->makeStockAdjustment($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $stockAdjustment));
        $this->assertFalse($reloaded->can('update', $stockAdjustment));
    }

    public function test_policy_grants_submit_for_a_draft_adjustment_with_create_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = $this->makeStockAdjustment($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('submit', $stockAdjustment));
    }

    public function test_policy_denies_submit_for_a_non_draft_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = $this->makeStockAdjustment($branch, ['status' => StockAdjustmentStatus::PENDING_APPROVAL]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('submit', $stockAdjustment));
    }

    public function test_policy_grants_approve_for_a_pending_approval_adjustment_with_approve_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $stockAdjustment = $this->makeStockAdjustment($branch, ['status' => StockAdjustmentStatus::PENDING_APPROVAL]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('approve', $stockAdjustment));
    }

    public function test_policy_denies_approve_for_a_draft_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $stockAdjustment = $this->makeStockAdjustment($branch);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('approve', $stockAdjustment));
    }

    public function test_policy_grants_post_for_an_approved_adjustment_with_post_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.post');
        $stockAdjustment = $this->makeStockAdjustment($branch, ['status' => StockAdjustmentStatus::APPROVED]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('post', $stockAdjustment));
    }

    public function test_policy_denies_post_for_a_pending_approval_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.post');
        $stockAdjustment = $this->makeStockAdjustment($branch, ['status' => StockAdjustmentStatus::PENDING_APPROVAL]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('post', $stockAdjustment));
    }

    public function test_policy_grants_cancel_for_draft_pending_approval_and_approved_statuses(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.cancel');

        foreach ([StockAdjustmentStatus::DRAFT, StockAdjustmentStatus::PENDING_APPROVAL, StockAdjustmentStatus::APPROVED] as $index => $status) {
            $stockAdjustment = $this->makeStockAdjustment($branch, ['number' => "SA/JKT/202608/0000{$index}9", 'status' => $status]);
            $reloaded = User::find($user->id);
            $this->assertTrue($reloaded->can('cancel', $stockAdjustment), "Expected cancel to be allowed from status {$status}");
        }
    }

    public function test_policy_denies_cancel_for_a_posted_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.cancel');
        $stockAdjustment = $this->makeStockAdjustment($branch, ['status' => StockAdjustmentStatus::POSTED]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('cancel', $stockAdjustment));
    }

    public function test_policy_denies_update_and_submit_for_a_posted_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = $this->makeStockAdjustment($branch, ['status' => StockAdjustmentStatus::POSTED]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('update', $stockAdjustment));
        $this->assertFalse($reloaded->can('submit', $stockAdjustment));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockAdjustmentAuthorizationTest`
Expected: FAIL — `StockAdjustmentPolicy` doesn't exist / isn't registered.

- [ ] **Step 3: Create the Policy**

Create `app/Policies/StockAdjustmentPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\StockAdjustmentStatus;

class StockAdjustmentPolicy
{
    public function view(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $user->hasPermissionToInBranch('stock_adjustment.view', $stockAdjustment->branch_id);
    }

    public function update(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $stockAdjustment->status === StockAdjustmentStatus::DRAFT
            && $user->hasPermissionToInBranch('stock_adjustment.create', $stockAdjustment->branch_id);
    }

    public function submit(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $stockAdjustment->status === StockAdjustmentStatus::DRAFT
            && $user->hasPermissionToInBranch('stock_adjustment.create', $stockAdjustment->branch_id);
    }

    public function approve(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $stockAdjustment->status === StockAdjustmentStatus::PENDING_APPROVAL
            && $user->hasPermissionToInBranch('stock_adjustment.approve', $stockAdjustment->branch_id);
    }

    public function post(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $stockAdjustment->status === StockAdjustmentStatus::APPROVED
            && $user->hasPermissionToInBranch('stock_adjustment.post', $stockAdjustment->branch_id);
    }

    public function cancel(User $user, StockAdjustment $stockAdjustment): bool
    {
        return in_array($stockAdjustment->status, [
            StockAdjustmentStatus::DRAFT,
            StockAdjustmentStatus::PENDING_APPROVAL,
            StockAdjustmentStatus::APPROVED,
        ], true)
            && $user->hasPermissionToInBranch('stock_adjustment.cancel', $stockAdjustment->branch_id);
    }
}
```

- [ ] **Step 4: Register the Policy**

In `app/Providers/AuthServiceProvider.php`, add to the `$policies` array:

```php
        \App\Models\StockAdjustment::class => \App\Policies\StockAdjustmentPolicy::class,
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=StockAdjustmentAuthorizationTest`
Expected: 12 passed.

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: 413 passed (401 baseline + 12 new).

- [ ] **Step 7: Commit**

```bash
git add app/Policies/StockAdjustmentPolicy.php app/Providers/AuthServiceProvider.php tests/Feature/StockAdjustmentAuthorizationTest.php
git commit -m "feat: add branch-scoped, status-aware StockAdjustmentPolicy"
```

---

### Task 3: CRUD controller, FormRequests, and views (create/edit/index/show, no lifecycle actions yet)

**Files:**
- Create: `app/Http/Requests/StoreStockAdjustmentRequest.php`
- Create: `app/Http/Requests/UpdateStockAdjustmentRequest.php`
- Create: `app/Http/Controllers/StockAdjustmentController.php` (index/create/store/show/edit/update/sparepartsByBranch only — `submit`/`approve`/`post`/`cancel` are added in Task 4)
- Create: `resources/views/stock-adjustments/index.blade.php`
- Create: `resources/views/stock-adjustments/no-access.blade.php`
- Create: `resources/views/stock-adjustments/create.blade.php`
- Create: `resources/views/stock-adjustments/edit.blade.php`
- Create: `resources/views/stock-adjustments/show.blade.php`
- Create: `resources/views/stock-adjustments/_line_item_scripts.blade.php`
- Create: `resources/views/stock-adjustments/_status_badge.blade.php`
- Modify: `routes/web.php` (add a new `stock-adjustments` route group — index/create/store/show/edit/update/lookup only)
- Test: `tests/Feature/StockAdjustmentManagementTest.php`

**Interfaces:**
- Consumes: `StockAdjustmentPolicy` (Task 2); `StockAdjustment`/`StockAdjustmentLine`/`StockAdjustmentStatus` (Task 1); `SparepartBranchStock` (already exists, has `on_hand_qty`/`available_qty`); `partials.list-filter-bar`, `partials.empty-state` (already exist); `DocumentNumberGenerator::next(Branch $branch, string $documentType): string` (already exists).
- Produces: routes `stock-adjustments.index/create/store/show/edit/update`, `stock-adjustments.lookup.spareparts`. Task 4 adds `submit`/`approve`/`post`/`cancel` to the same controller and route group. The `_status_badge.blade.php` partial (taking a `$status` variable) is consumed by both `index.blade.php` and `show.blade.php` in this task, and must not be duplicated inline anywhere.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/StockAdjustmentManagementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\StockAdjustmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentManagementTest extends TestCase
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

    protected function makeSparepartBranch(Branch $branch, string $codeSuffix = '', float $onHandQty = 0): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => "OLI-01{$codeSuffix}", 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        if ($onHandQty > 0) {
            $sparepartBranch->stock()->update(['on_hand_qty' => $onHandQty]);
        }

        return $sparepartBranch;
    }

    protected function baseStorePayload(Branch $branch, SparepartBranch $sparepartBranch, float $physicalQty = 8): array
    {
        return [
            'branch_id' => $branch->id,
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Stock opname bulanan',
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'physical_qty' => $physicalQty, 'reason' => 'Selisih hitung fisik'],
            ],
        ];
    }

    public function test_store_creates_stock_adjustment_with_lines_and_captures_system_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch, 8));

        $stockAdjustment = StockAdjustment::first();
        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $this->assertSame(StockAdjustmentStatus::DRAFT, $stockAdjustment->status);
        $this->assertStringStartsWith('SA/JKT/', $stockAdjustment->number);
        $this->assertCount(1, $stockAdjustment->lines);
        $line = $stockAdjustment->lines->first();
        $this->assertSame(10.0, (float) $line->system_qty);
        $this->assertSame(8.0, (float) $line->physical_qty);
        $this->assertSame(-2.0, (float) $line->adjustment_qty);

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(10.0, (float) $stock->on_hand_qty, 'Creating a DRAFT adjustment must not touch stock.');
    }

    public function test_store_ignores_client_supplied_system_qty_and_adjustment_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $payload = $this->baseStorePayload($branch, $sparepartBranch, 8);
        $payload['lines'][0]['system_qty'] = 999;
        $payload['lines'][0]['adjustment_qty'] = 999;

        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $payload);

        $line = StockAdjustment::first()->lines->first();
        $this->assertSame(10.0, (float) $line->system_qty);
        $this->assertSame(-2.0, (float) $line->adjustment_qty);
    }

    public function test_store_is_forbidden_without_stock_adjustment_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch));

        $response->assertForbidden();
    }

    public function test_store_rejects_an_adjustment_with_no_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-adjustments', [
            'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname', 'lines' => [],
        ]);

        $response->assertSessionHasErrors(['lines']);
    }

    public function test_store_rejects_duplicate_sparepart_in_same_document(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-adjustments', [
            'branch_id' => $branch->id,
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname',
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'physical_qty' => 8, 'reason' => 'Rusak'],
                ['sparepart_branch_id' => $sparepartBranch->id, 'physical_qty' => 9, 'reason' => 'Hilang'],
            ],
        ]);

        $response->assertSessionHasErrors(['lines.0.sparepart_branch_id', 'lines.1.sparepart_branch_id']);
    }

    public function test_store_rejects_sparepart_from_a_different_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $otherBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $otherSparepartBranch = $this->makeSparepartBranch($otherBranch, '-other');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $otherSparepartBranch));

        $response->assertSessionHasErrors(['lines.0.sparepart_branch_id']);
    }

    public function test_index_lists_adjustments_for_authorized_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepartBranchA = $this->makeSparepartBranch($branchA, '-a');
        $sparepartBranchB = $this->makeSparepartBranch($branchB, '-b');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'stock_adjustment.view');
        $this->grantBranchPermission($user, $branchA, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branchA, $sparepartBranchA));
        $this->grantBranchPermission($user, $branchB, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branchB, $sparepartBranchB));

        $response = $this->actingAs(User::find($user->id))->get('/stock-adjustments');

        $response->assertOk();
        $adjustmentA = StockAdjustment::where('branch_id', $branchA->id)->first();
        $adjustmentB = StockAdjustment::where('branch_id', $branchB->id)->first();
        $response->assertSee($adjustmentA->number);
        $response->assertDontSee($adjustmentB->number);
    }

    public function test_index_shows_no_access_page_without_any_stock_adjustment_view_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/stock-adjustments');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_index_shows_empty_state_when_no_adjustments_match(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');

        $response = $this->actingAs(User::find($user->id))->get('/stock-adjustments');

        $response->assertOk();
        $response->assertSee('Belum ada stock adjustment');
    }

    public function test_index_renders_all_five_status_badges_correctly(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $statuses = [
            StockAdjustmentStatus::DRAFT => 'Draft',
            StockAdjustmentStatus::PENDING_APPROVAL => 'Diajukan',
            StockAdjustmentStatus::APPROVED => 'Disetujui',
            StockAdjustmentStatus::POSTED => 'Diposting',
            StockAdjustmentStatus::CANCELLED => 'Dibatalkan',
        ];
        foreach (array_keys($statuses) as $index => $status) {
            StockAdjustment::create([
                'number' => "SA/JKT/202608/0000{$index}9",
                'branch_id' => $branch->id,
                'adjustment_date' => now()->format('Y-m-d'),
                'reason' => 'Opname',
                'status' => $status,
            ]);
        }

        $response = $this->actingAs(User::find($user->id))->get('/stock-adjustments');

        $response->assertOk();
        foreach ($statuses as $label) {
            $response->assertSee($label);
        }
    }

    public function test_create_form_renders_for_a_user_with_stock_adjustment_create_in_some_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');

        $response = $this->actingAs(User::find($user->id))->get('/stock-adjustments/create');

        $response->assertOk();
    }

    public function test_create_form_replays_old_lines_after_a_validation_error(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $payload = $this->baseStorePayload($branch, $sparepartBranch, 7);
        unset($payload['adjustment_date']);

        $this->from(route('stock-adjustments.create'))->actingAs(User::find($user->id))->post('/stock-adjustments', $payload);
        $response = $this->actingAs(User::find($user->id))->get(route('stock-adjustments.create'));

        $response->assertOk();
        $response->assertSee('oldLines', false);
        $response->assertSee('"physical_qty":7', false);
    }

    public function test_edit_form_renders_for_a_user_with_stock_adjustment_create_on_a_draft_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch));
        $stockAdjustment = StockAdjustment::first();

        $response = $this->actingAs(User::find($user->id))->get("/stock-adjustments/{$stockAdjustment->id}/edit");

        $response->assertOk();
    }

    public function test_update_successfully_replaces_lines_and_recomputes_system_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $newSparepartBranch = $this->makeSparepartBranch($branch, '-updated', 20);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch, 8));
        $stockAdjustment = StockAdjustment::first();

        $response = $this->actingAs(User::find($user->id))->put("/stock-adjustments/{$stockAdjustment->id}", [
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname revisi',
            'lines' => [
                ['sparepart_branch_id' => $newSparepartBranch->id, 'physical_qty' => 18, 'reason' => 'Selisih baru'],
            ],
        ]);

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertCount(1, $stockAdjustment->lines);
        $line = $stockAdjustment->lines->first();
        $this->assertSame($newSparepartBranch->id, $line->sparepart_branch_id);
        $this->assertSame(20.0, (float) $line->system_qty);
        $this->assertSame(-2.0, (float) $line->adjustment_qty);
    }

    public function test_update_can_change_header_fields(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch));
        $stockAdjustment = StockAdjustment::first();

        $this->actingAs(User::find($user->id))->put("/stock-adjustments/{$stockAdjustment->id}", [
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Alasan yang diperbarui',
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'physical_qty' => 8, 'reason' => 'Selisih hitung fisik'],
            ],
        ]);

        $stockAdjustment->refresh();
        $this->assertSame('Alasan yang diperbarui', $stockAdjustment->reason);
    }

    public function test_update_is_forbidden_for_a_non_draft_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($user->id))->put("/stock-adjustments/{$stockAdjustment->id}", [
            'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname',
            'lines' => [['sparepart_branch_id' => $sparepartBranch->id, 'physical_qty' => 1, 'reason' => 'x']],
        ]);

        $response->assertForbidden();
    }

    public function test_show_renders_status_badge_and_approval_info_when_approved(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $approver = User::factory()->create(['name' => 'Budi Approver']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
            'approved_by' => $approver->id, 'approved_at' => now(),
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-adjustments/{$stockAdjustment->id}");

        $response->assertOk();
        $response->assertSee('Disetujui');
        $response->assertSee('Budi Approver');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockAdjustmentManagementTest`
Expected: FAIL — controller/routes/views don't exist yet.

- [ ] **Step 3: Create the FormRequests**

Create `app/Http/Requests/StoreStockAdjustmentRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('stock_adjustment.create', $branchId);
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
            'adjustment_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.sparepart_branch_id' => ['required', 'integer', 'exists:sparepart_branches,id', 'distinct'],
            'lines.*.physical_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.reason' => ['required', 'string', 'max:255'],
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
                    $validator->errors()->add("lines.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang penyesuaian ini.');
                }
            }
        });
    }
}
```

Create `app/Http/Requests/UpdateStockAdjustmentRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStockAdjustmentRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('update', $this->route('stockAdjustment'));
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
            'adjustment_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.sparepart_branch_id' => ['required', 'integer', 'exists:sparepart_branches,id', 'distinct'],
            'lines.*.physical_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $branchId = (int) $this->route('stockAdjustment')->branch_id;

            foreach ($this->input('lines', []) as $index => $line) {
                $sparepartBranchId = $line['sparepart_branch_id'] ?? null;
                if (! $sparepartBranchId) {
                    continue;
                }
                $sparepartBranch = SparepartBranch::find($sparepartBranchId);
                if (! $sparepartBranch || ! $sparepartBranch->is_active || (int) $sparepartBranch->branch_id !== $branchId) {
                    $validator->errors()->add("lines.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang penyesuaian ini.');
                }
            }
        });
    }
}
```

Note: `branch_id` is absent from `UpdateStockAdjustmentRequest`'s rules — the adjustment's branch never changes after creation, matching PKB/Goods Receipt precedent. The `distinct` rule on `lines.*.sparepart_branch_id` is Laravel's built-in implicit array rule — it flags every wildcard entry that shares a value with another entry in the same array, no custom rule class needed.

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/StockAdjustmentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Http\Requests\UpdateStockAdjustmentRequest;
use App\Models\Branch;
use App\Models\SparepartBranch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Services\DocumentNumberGenerator;
use App\Support\StockAdjustmentStatus;
use Illuminate\Support\Facades\DB;

class StockAdjustmentController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('stock_adjustment.view');

        if ($permittedBranches->isEmpty()) {
            return view('stock-adjustments.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $stockAdjustments = StockAdjustment::with('branch')
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($search, function ($query, $q) {
                $escaped = '%' . addcslashes($q, '%_\\') . '%';
                $query->where(function ($query) use ($escaped) {
                    $query->where('number', 'like', $escaped)
                        ->orWhere('reason', 'like', $escaped);
                });
            })
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('stock-adjustments.index', compact('stockAdjustments'))
            ->with('branches', $permittedBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function create()
    {
        $branches = auth()->user()->branchesWithPermission('stock_adjustment.create');

        if ($branches->isEmpty()) {
            return view('stock-adjustments.no-access');
        }

        return view('stock-adjustments.create', compact('branches'));
    }

    public function store(StoreStockAdjustmentRequest $request)
    {
        $data = $request->validated();
        $branch = Branch::findOrFail($data['branch_id']);

        $stockAdjustment = DB::transaction(function () use ($data, $branch) {
            $stockAdjustment = StockAdjustment::create([
                'number' => (new DocumentNumberGenerator())->next($branch, 'SA'),
                'branch_id' => $branch->id,
                'adjustment_date' => $data['adjustment_date'],
                'reason' => $data['reason'],
                'status' => StockAdjustmentStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($stockAdjustment, $data['lines']);

            return $stockAdjustment;
        });

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil dibuat.');
    }

    public function show(StockAdjustment $stockAdjustment)
    {
        $this->authorize('view', $stockAdjustment);

        $stockAdjustment->load(['branch', 'approvedBy', 'lines.sparepartBranch.sparepart']);

        return view('stock-adjustments.show', compact('stockAdjustment'));
    }

    public function edit(StockAdjustment $stockAdjustment)
    {
        $this->authorize('update', $stockAdjustment);

        $stockAdjustment->load('lines');
        $sparepartBranches = SparepartBranch::with(['sparepart', 'stock'])
            ->where('branch_id', $stockAdjustment->branch_id)
            ->where('is_active', true)
            ->get();
        $missingIds = $stockAdjustment->lines->pluck('sparepart_branch_id')->unique()->diff($sparepartBranches->pluck('id'));
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
                'on_hand_qty' => (float) $sb->stock->on_hand_qty,
            ];
        })->values();

        $existingLines = $stockAdjustment->lines->map(function ($line) {
            return [
                'sparepart_branch_id' => $line->sparepart_branch_id,
                'physical_qty' => (float) $line->physical_qty,
                'reason' => $line->reason,
            ];
        })->values();

        return view('stock-adjustments.edit', compact('stockAdjustment', 'sparepartOptions', 'existingLines'));
    }

    public function update(UpdateStockAdjustmentRequest $request, StockAdjustment $stockAdjustment)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $stockAdjustment) {
            $fresh = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->first();
            if ($fresh->status !== StockAdjustmentStatus::DRAFT) {
                return;
            }

            $fresh->update([
                'adjustment_date' => $data['adjustment_date'],
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($fresh, $data['lines']);
        });

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil diperbarui.');
    }

    public function sparepartsByBranch(Branch $branch)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('stock_adjustment.create', $branch->id), 403);

        return response()->json(
            SparepartBranch::with(['sparepart', 'stock'])
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->get()
                ->map(function (SparepartBranch $sb) {
                    return [
                        'id' => $sb->id,
                        'code' => $sb->sparepart->code,
                        'name' => $sb->sparepart->name,
                        'on_hand_qty' => (float) $sb->stock->on_hand_qty,
                    ];
                })
                ->values()
        );
    }

    protected function syncLines(StockAdjustment $stockAdjustment, array $lines): void
    {
        $stockAdjustment->lines()->delete();

        foreach (array_values(array_filter($lines)) as $index => $line) {
            $physicalQty = (float) $line['physical_qty'];
            $stock = \App\Models\SparepartBranchStock::where('sparepart_branch_id', $line['sparepart_branch_id'])->first();
            $systemQty = $stock ? (float) $stock->on_hand_qty : 0.0;

            StockAdjustmentLine::create([
                'stock_adjustment_id' => $stockAdjustment->id,
                'sparepart_branch_id' => $line['sparepart_branch_id'],
                'system_qty' => $systemQty,
                'physical_qty' => $physicalQty,
                'adjustment_qty' => round($physicalQty - $systemQty, 3),
                'reason' => $line['reason'],
                'sort_order' => $index,
            ]);
        }
    }
}
```

Note: `system_qty` and `adjustment_qty` are computed here from the CURRENT stock every time `syncLines()` runs (both on `store()` and `update()`) — never trusted from the request, even though only `physical_qty`/`reason`/`sparepart_branch_id` are actually validated fields (there is no `system_qty`/`adjustment_qty` in the FormRequest rules at all, so any client-supplied value for those keys is simply never read).

- [ ] **Step 5: Add routes**

In `routes/web.php`, add a new group (placed near the `goods-receipts` group, inside the same authenticated middleware block):

```php
    Route::prefix('stock-adjustments')->name('stock-adjustments.')->group(function () {
        Route::get('/lookup/spareparts/{branch}', [StockAdjustmentController::class, 'sparepartsByBranch'])->name('lookup.spareparts');

        Route::get('/', [StockAdjustmentController::class, 'index'])->name('index');
        Route::get('/create', [StockAdjustmentController::class, 'create'])->name('create');
        Route::post('/', [StockAdjustmentController::class, 'store'])->name('store');
        Route::get('/{stockAdjustment}', [StockAdjustmentController::class, 'show'])->name('show');
        Route::get('/{stockAdjustment}/edit', [StockAdjustmentController::class, 'edit'])->name('edit');
        Route::put('/{stockAdjustment}', [StockAdjustmentController::class, 'update'])->name('update');
    });
```

**The `/lookup/spareparts/{branch}` route MUST be registered before the `/{stockAdjustment}` wildcard route** — otherwise Laravel's router would try to resolve `lookup` as a `{stockAdjustment}` ID. Add the matching `use App\Http\Controllers\StockAdjustmentController;` import at the top of `routes/web.php` alongside the other controller imports.

- [ ] **Step 6: Create the status badge partial**

Create `resources/views/stock-adjustments/_status_badge.blade.php`:

```blade
@if ($status === \App\Support\StockAdjustmentStatus::DRAFT)
    <span class="status-dot status-active">Draft</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::PENDING_APPROVAL)
    <span class="status-dot status-active">Diajukan</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::APPROVED)
    <span class="status-dot status-active">Disetujui</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::POSTED)
    <span class="status-dot status-active">Diposting</span>
@else
    <span class="status-dot status-inactive">Dibatalkan</span>
@endif
```

This partial is the ONLY place the status→label mapping is written. `index.blade.php` and `show.blade.php` both `@include` it — never copy this if/elseif chain inline into either page.

- [ ] **Step 7: Create the line-item scripts partial**

Create `resources/views/stock-adjustments/_line_item_scripts.blade.php`:

```blade
<template id="stockAdjustmentLineTemplate">
    <div class="row g-2 align-items-start mb-2 stock-adjustment-line">
        <div class="col-md-4">
            <select class="form-select stock-adjustment-sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" class="form-control stock-adjustment-system-qty" readonly tabindex="-1">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" min="0" class="form-control stock-adjustment-physical-qty">
        </div>
        <div class="col-md-3">
            <input type="text" class="form-control stock-adjustment-reason" placeholder="Alasan baris ini">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-stock-adjustment-line">&times;</button>
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
            option.dataset.onHandQty = item.on_hand_qty;
            select.appendChild(option);
        });
    }

    function addLine() {
        const template = document.getElementById('stockAdjustmentLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.stock-adjustment-line');
        const index = lineCount++;
        const select = wrapper.querySelector('.stock-adjustment-sparepart-select');
        select.name = `lines[${index}][sparepart_branch_id]`;
        wrapper.querySelector('.stock-adjustment-physical-qty').name = `lines[${index}][physical_qty]`;
        wrapper.querySelector('.stock-adjustment-reason').name = `lines[${index}][reason]`;
        fillSelect(select, sparepartOptionsCache, '-- Pilih Sparepart --');

        select.addEventListener('change', function () {
            const selectedOption = select.options[select.selectedIndex];
            wrapper.querySelector('.stock-adjustment-system-qty').value = selectedOption ? (selectedOption.dataset.onHandQty || '0') : '';
        });

        wrapper.querySelector('.remove-stock-adjustment-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('stockAdjustmentLines').appendChild(wrapper);
    }

    document.getElementById('addStockAdjustmentLine').addEventListener('click', addLine);

    window.StockAdjustmentLineItems = {
        setSparepartOptions: function (items) {
            sparepartOptionsCache = items;
            document.querySelectorAll('.stock-adjustment-sparepart-select').forEach(function (select) {
                const currentValue = select.value;
                fillSelect(select, items, '-- Pilih Sparepart --');
                select.value = currentValue;
                const selectedOption = select.options[select.selectedIndex];
                const row = select.closest('.stock-adjustment-line');
                if (row && selectedOption && selectedOption.value) {
                    row.querySelector('.stock-adjustment-system-qty').value = selectedOption.dataset.onHandQty || '0';
                }
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

The `system_qty` field is `readonly` and has NO `name` attribute — it is display-only in the browser (auto-filled from the selected sparepart's current `on_hand_qty` at selection time, purely for the user's visual reference) and is never submitted with the form. The server always recomputes `system_qty` itself (see `syncLines()` in Step 4).

- [ ] **Step 8: Create the index/no-access/create/edit/show views**

Create `resources/views/stock-adjustments/no-access.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Stock Adjustment')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-sliders me-2"></i>Stock Adjustment</h1>
    </div>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            Anda belum memiliki akses stock adjustment di cabang manapun. Hubungi admin untuk meminta akses.
        </div>
    </div>
@endsection
```

Create `resources/views/stock-adjustments/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Stock Adjustment')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-sliders me-2"></i>Stock Adjustment</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor atau alasan...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => auth()->user()->branchesWithPermission('stock_adjustment.create')->isNotEmpty()
            ? '<a href="' . route('stock-adjustments.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Adjustment Baru</a>'
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
                        <th>Alasan</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stockAdjustments as $stockAdjustment)
                        <tr>
                            <td><code>{{ $stockAdjustment->number }}</code></td>
                            <td>{{ $stockAdjustment->branch->name }}</td>
                            <td>{{ $stockAdjustment->adjustment_date->format('d/m/Y') }}</td>
                            <td>{{ $stockAdjustment->reason }}</td>
                            <td>@include('stock-adjustments._status_badge', ['status' => $stockAdjustment->status])</td>
                            <td class="text-end">
                                <a href="{{ route('stock-adjustments.show', $stockAdjustment) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-sliders',
                                    'title' => 'Belum ada stock adjustment',
                                    'description' => 'Mulai dengan membuat stock adjustment pertama.',
                                    'ctaRoute' => 'stock-adjustments.create',
                                    'ctaLabel' => '+ Buat Adjustment Pertama',
                                    'ctaVisible' => auth()->user()->branchesWithPermission('stock_adjustment.create')->isNotEmpty(),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $stockAdjustments->links() }}
    </div>
@endsection
```

Create `resources/views/stock-adjustments/create.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Stock Adjustment Baru')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-sliders me-2"></i>Stock Adjustment Baru</h1>
    </div>
    <form method="POST" action="{{ route('stock-adjustments.store') }}" id="stockAdjustmentForm">
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
                        <label class="form-label">Tanggal Penyesuaian</label>
                        <input type="date" name="adjustment_date" value="{{ old('adjustment_date', now()->format('Y-m-d')) }}" class="form-control @error('adjustment_date') is-invalid @enderror" required>
                        @error('adjustment_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Alasan</label>
                        <input type="text" name="reason" value="{{ old('reason') }}" class="form-control @error('reason') is-invalid @enderror" maxlength="255" required>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                    <h2 class="h6 mb-0">Baris Penyesuaian</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addStockAdjustmentLine" disabled>+ Tambah Sparepart</button>
                </div>
                <div class="row g-2 mb-1 text-muted small">
                    <div class="col-md-4">Sparepart</div>
                    <div class="col-md-2">Qty Sistem</div>
                    <div class="col-md-2">Qty Fisik</div>
                    <div class="col-md-3">Alasan Baris</div>
                </div>
                <div id="stockAdjustmentLines"></div>
                @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('stock-adjustments.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('stock-adjustments._line_item_scripts')

    @php($oldLines = old('lines', []))
    @push('scripts')
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const addButton = document.getElementById('addStockAdjustmentLine');

        async function handleBranchChange(branchId) {
            addButton.disabled = true;
            if (!branchId) {
                return;
            }
            const spareparts = await StockAdjustmentLineItems.fetchJson(`/stock-adjustments/lookup/spareparts/${branchId}`);
            StockAdjustmentLineItems.setSparepartOptions(spareparts);
            addButton.disabled = false;
        }

        branchSelect.addEventListener('change', function () {
            handleBranchChange(this.value);
        });

        // Validation-error round-trip: replay the line rows submitted before the
        // failed validation. These rows only exist in JS-managed DOM state (added
        // via <template> cloning), so without this the user would have to retype
        // every line from scratch after any validation error. Built in from the
        // start here — this exact gap was an Important finding in the sibling
        // Goods Receipt module's final review.
        function replayOldLines() {
            const oldLines = @json($oldLines);
            oldLines.forEach(function (line) {
                StockAdjustmentLineItems.addLine();
                const rows = document.querySelectorAll('#stockAdjustmentLines .stock-adjustment-line');
                const row = rows[rows.length - 1];
                if (line.sparepart_branch_id) {
                    const select = row.querySelector('.stock-adjustment-sparepart-select');
                    select.value = line.sparepart_branch_id;
                    select.dispatchEvent(new Event('change'));
                }
                row.querySelector('.stock-adjustment-physical-qty').value = line.physical_qty || '';
                row.querySelector('.stock-adjustment-reason').value = line.reason || '';
            });
        }

        // Validation-error round-trip: old('branch_id') re-selects the branch
        // option but does not fire a native `change` event, so the sparepart
        // cascade and add-line button would otherwise stay empty/disabled.
        // handleBranchChange is async, so replayOldLines is chained onto its
        // promise rather than called eagerly — replayed rows need the sparepart
        // options to already be populated before they can select a value.
        if (branchSelect.value) {
            handleBranchChange(branchSelect.value).then(replayOldLines);
        } else {
            replayOldLines();
        }
    })();
    </script>
    @endpush
@endsection
```

Create `resources/views/stock-adjustments/edit.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Ubah Stock Adjustment')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-sliders me-2"></i>Ubah {{ $stockAdjustment->number }} — {{ $stockAdjustment->branch->name }}</h1>
    </div>
    <form method="POST" action="{{ route('stock-adjustments.update', $stockAdjustment) }}" id="stockAdjustmentForm">
        @csrf
        @method('PUT')
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Tanggal Penyesuaian</label>
                        <input type="date" name="adjustment_date" value="{{ old('adjustment_date', $stockAdjustment->adjustment_date->format('Y-m-d')) }}" class="form-control @error('adjustment_date') is-invalid @enderror" required>
                        @error('adjustment_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Alasan</label>
                        <input type="text" name="reason" value="{{ old('reason', $stockAdjustment->reason) }}" class="form-control @error('reason') is-invalid @enderror" maxlength="255" required>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes', $stockAdjustment->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Penyesuaian</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addStockAdjustmentLine">+ Tambah Sparepart</button>
                </div>
                <div class="row g-2 mb-1 text-muted small">
                    <div class="col-md-4">Sparepart</div>
                    <div class="col-md-2">Qty Sistem</div>
                    <div class="col-md-2">Qty Fisik</div>
                    <div class="col-md-3">Alasan Baris</div>
                </div>
                <div id="stockAdjustmentLines"></div>
                @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('stock-adjustments.show', $stockAdjustment) }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('stock-adjustments._line_item_scripts')

    @push('scripts')
    <script>
    (function () {
        const existingSparepartOptions = @json($sparepartOptions);
        StockAdjustmentLineItems.setSparepartOptions(existingSparepartOptions);

        const existingLines = @json($existingLines);
        existingLines.forEach(function (line) {
            StockAdjustmentLineItems.addLine();
            const rows = document.querySelectorAll('#stockAdjustmentLines .stock-adjustment-line');
            const row = rows[rows.length - 1];
            const select = row.querySelector('.stock-adjustment-sparepart-select');
            select.value = line.sparepart_branch_id;
            select.dispatchEvent(new Event('change'));
            row.querySelector('.stock-adjustment-physical-qty').value = line.physical_qty;
            row.querySelector('.stock-adjustment-reason').value = line.reason;
        });
    })();
    </script>
    @endpush
@endsection
```

`@json($sparepartOptions)` and `@json($existingLines)` are both bare, comma-free variable references — safe from the start.

Create `resources/views/stock-adjustments/show.blade.php` (Task 4 will add lifecycle action buttons alongside the existing "Ubah" button — do not add them yet):

```blade
@extends('layouts.app')
@section('title', 'Detail Stock Adjustment')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-sliders me-2"></i>{{ $stockAdjustment->number }}</h1>
        <div class="d-flex gap-2">
            @can('update', $stockAdjustment)
                <a href="{{ route('stock-adjustments.edit', $stockAdjustment) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><strong>Cabang</strong><div>{{ $stockAdjustment->branch->name }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $stockAdjustment->adjustment_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>@include('stock-adjustments._status_badge', ['status' => $stockAdjustment->status])</div>
                </div>
                <div class="col-md-3">
                    <strong>Disetujui</strong>
                    <div>
                        @if ($stockAdjustment->approved_at)
                            {{ $stockAdjustment->approvedBy->name ?? '-' }} pada {{ $stockAdjustment->approved_at->format('d/m/Y H:i') }}
                        @else
                            -
                        @endif
                    </div>
                </div>
                <div class="col-md-12"><strong>Alasan</strong><div>{{ $stockAdjustment->reason }}</div></div>
                <div class="col-md-12"><strong>Catatan</strong><div>{{ $stockAdjustment->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Baris Penyesuaian</h2>
            <table class="table table-sm">
                <thead><tr><th>Kode</th><th>Nama</th><th>Qty Sistem</th><th>Qty Fisik</th><th>Selisih</th><th>Alasan</th></tr></thead>
                <tbody>
                    @forelse ($stockAdjustment->lines as $line)
                        <tr>
                            <td><code>{{ $line->sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $line->sparepartBranch->sparepart->name }}</td>
                            <td>{{ number_format($line->system_qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->physical_qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->adjustment_qty, 0, ',', '.') }}</td>
                            <td>{{ $line->reason }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">Tidak ada baris penyesuaian.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <a href="{{ route('stock-adjustments.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test --filter=StockAdjustmentManagementTest`
Expected: 17 passed.

- [ ] **Step 10: Run the full suite**

Run: `php artisan test`
Expected: 430 passed (413 baseline + 17 new).

- [ ] **Step 11: Commit**

```bash
git add app/Http/Requests/StoreStockAdjustmentRequest.php app/Http/Requests/UpdateStockAdjustmentRequest.php app/Http/Controllers/StockAdjustmentController.php resources/views/stock-adjustments routes/web.php tests/Feature/StockAdjustmentManagementTest.php
git commit -m "feat: implement stock adjustment CRUD (create/edit/list/detail)"
```

---

### Task 4: Lifecycle actions — submit, approve, post, cancel

**Files:**
- Modify: `app/Http/Controllers/StockAdjustmentController.php` (add `submit`/`approve`/`post`/`cancel`)
- Modify: `routes/web.php` (add `submit`/`approve`/`post`/`cancel` routes to the existing `stock-adjustments` group)
- Modify: `resources/views/stock-adjustments/show.blade.php` (add the 4 conditional action buttons)
- Test: `tests/Feature/StockAdjustmentManagementTest.php` (extend with lifecycle tests)

**Interfaces:**
- Consumes: `StockAdjustmentPolicy::submit/approve/post/cancel` (Task 2); `SparepartBranchStock`, `InventoryMovement`, `InventoryMovementType::ADJUSTMENT_IN`/`ADJUSTMENT_OUT` (Task 1/existing).
- Produces: routes `stock-adjustments.submit/approve/post/cancel`. Nothing later in this plan consumes these beyond Task 5's sidebar link, which only needs `stock-adjustments.index`.

- [ ] **Step 1: Write the failing lifecycle tests**

Append these test methods to `tests/Feature/StockAdjustmentManagementTest.php` (inside the existing class, before the final closing `}`):

```php
    public function test_submit_moves_draft_to_pending_approval(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch));
        $stockAdjustment = StockAdjustment::first();

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/submit");

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::PENDING_APPROVAL, $stockAdjustment->status);
    }

    public function test_submit_is_forbidden_without_stock_adjustment_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname',
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/submit");

        $response->assertForbidden();
    }

    public function test_submit_is_forbidden_for_a_non_draft_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/submit");

        $response->assertForbidden();
    }

    public function test_approve_moves_pending_approval_to_approved_and_records_approver(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $approver = User::factory()->create();
        $this->grantBranchPermission($approver, $branch, 'stock_adjustment.approve');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($approver->id))->patch("/stock-adjustments/{$stockAdjustment->id}/approve");

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::APPROVED, $stockAdjustment->status);
        $this->assertSame($approver->id, $stockAdjustment->approved_by);
        $this->assertNotNull($stockAdjustment->approved_at);
    }

    public function test_approve_is_forbidden_without_stock_adjustment_approve_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/approve");

        $response->assertForbidden();
    }

    public function test_approve_is_forbidden_for_a_draft_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname',
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/approve");

        $response->assertForbidden();
    }

    public function test_post_increases_stock_when_physical_qty_is_higher_and_writes_adjustment_in_movement(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 15, 'adjustment_qty' => 5, 'reason' => 'Ditemukan lebih',
        ]);

        $response = $this->actingAs(User::find($poster->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::POSTED, $stockAdjustment->status);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(15.0, (float) $stock->on_hand_qty);
        $movement = \DB::table('inventory_movements')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame('adjustment_in', $movement->movement_type);
        $this->assertSame(5.0, (float) $movement->qty_in);
        $this->assertSame(0.0, (float) $movement->qty_out);
        $this->assertSame(15.0, (float) $movement->balance_after);
        $this->assertNull($movement->notes);
    }

    public function test_post_decreases_stock_when_physical_qty_is_lower_and_writes_adjustment_out_movement(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 6, 'adjustment_qty' => -4, 'reason' => 'Rusak',
        ]);

        $this->actingAs(User::find($poster->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(6.0, (float) $stock->on_hand_qty);
        $movement = \DB::table('inventory_movements')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame('adjustment_out', $movement->movement_type);
        $this->assertSame(0.0, (float) $movement->qty_in);
        $this->assertSame(4.0, (float) $movement->qty_out);
        $this->assertSame(6.0, (float) $movement->balance_after);
    }

    public function test_post_skips_ledger_entry_when_recomputed_delta_is_zero_but_still_marks_posted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 10, 'adjustment_qty' => 0, 'reason' => 'Sesuai',
        ]);

        $response = $this->actingAs(User::find($poster->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::POSTED, $stockAdjustment->status);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(10.0, (float) $stock->on_hand_qty);
        $this->assertSame(0, \DB::table('inventory_movements')->where('sparepart_branch_id', $sparepartBranch->id)->count());
    }

    public function test_post_recomputes_against_current_stock_and_notes_the_drift_when_stock_changed_since_submission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 20);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        // At creation/approval time, system_qty was 20 and physical count was 15 (adjustment_qty = -5).
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 20, 'physical_qty' => 15, 'adjustment_qty' => -5, 'reason' => 'Rusak',
        ]);
        // Simulate another movement (e.g. a Goods Receipt) landing between approval and posting.
        $sparepartBranch->stock()->update(['on_hand_qty' => 22]);

        $this->actingAs(User::find($poster->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(15.0, (float) $stock->on_hand_qty, 'Final on_hand_qty must exactly equal physical_qty regardless of drift.');
        $movement = \DB::table('inventory_movements')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame('adjustment_out', $movement->movement_type);
        $this->assertSame(7.0, (float) $movement->qty_out, 'Recomputed delta must be 15 - 22 = -7, not the stale -5.');
        $this->assertSame(15.0, (float) $movement->balance_after);
        $this->assertNotNull($movement->notes);
        $this->assertStringContainsString('bergeser', $movement->notes);
    }

    public function test_post_is_forbidden_without_stock_adjustment_post_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $response->assertForbidden();
    }

    public function test_post_is_forbidden_for_a_pending_approval_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $response->assertForbidden();
    }

    public function test_cancel_from_draft_sets_cancelled_with_no_stock_impact(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.cancel');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname',
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/cancel");

        $response->assertRedirect(route('stock-adjustments.show', $stockAdjustment));
        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::CANCELLED, $stockAdjustment->status);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(10.0, (float) $stock->on_hand_qty);
    }

    public function test_cancel_from_pending_approval_sets_cancelled_with_no_stock_impact(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.cancel');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/cancel");

        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::CANCELLED, $stockAdjustment->status);
    }

    public function test_cancel_from_approved_sets_cancelled_with_no_stock_impact(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $approver = User::factory()->create();
        $this->grantBranchPermission($approver, $branch, 'stock_adjustment.cancel');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
            'approved_by' => $approver->id, 'approved_at' => now(),
        ]);

        $response = $this->actingAs(User::find($approver->id))->patch("/stock-adjustments/{$stockAdjustment->id}/cancel");

        $stockAdjustment->refresh();
        $this->assertSame(StockAdjustmentStatus::CANCELLED, $stockAdjustment->status);
        $this->assertNotNull($stockAdjustment->approved_by, 'approved_by must remain as historical trace after cancellation.');
    }

    public function test_cancel_is_forbidden_for_a_posted_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.cancel');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::POSTED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-adjustments/{$stockAdjustment->id}/cancel");

        $response->assertForbidden();
    }

    public function test_show_renders_submit_button_for_a_draft_adjustment_with_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'), 'reason' => 'Opname',
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-adjustments/{$stockAdjustment->id}");

        $response->assertOk();
        $response->assertSee(route('stock-adjustments.submit', $stockAdjustment), false);
    }

    public function test_show_renders_approve_button_for_a_pending_approval_adjustment_with_approve_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::PENDING_APPROVAL,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-adjustments/{$stockAdjustment->id}");

        $response->assertOk();
        $response->assertSee(route('stock-adjustments.approve', $stockAdjustment), false);
    }

    public function test_show_renders_post_button_for_an_approved_adjustment_with_post_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-adjustments/{$stockAdjustment->id}");

        $response->assertOk();
        $response->assertSee(route('stock-adjustments.post', $stockAdjustment), false);
    }

    public function test_show_hides_all_action_buttons_for_a_posted_adjustment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.view');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.approve');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.post');
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.cancel');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::POSTED,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-adjustments/{$stockAdjustment->id}");

        $response->assertOk();
        $response->assertDontSee(route('stock-adjustments.submit', $stockAdjustment), false);
        $response->assertDontSee(route('stock-adjustments.approve', $stockAdjustment), false);
        $response->assertDontSee(route('stock-adjustments.post', $stockAdjustment), false);
        $response->assertDontSee(route('stock-adjustments.cancel', $stockAdjustment), false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockAdjustmentManagementTest`
Expected: the 17 pre-existing tests still PASS, the 20 new ones FAIL (routes/methods don't exist yet).

- [ ] **Step 3: Add the lifecycle routes**

In `routes/web.php`, extend the existing `stock-adjustments` group from Task 3 by adding these 4 lines inside it (after the `update` route):

```php
        Route::patch('/{stockAdjustment}/submit', [StockAdjustmentController::class, 'submit'])->name('submit');
        Route::patch('/{stockAdjustment}/approve', [StockAdjustmentController::class, 'approve'])->name('approve');
        Route::patch('/{stockAdjustment}/post', [StockAdjustmentController::class, 'post'])->name('post');
        Route::patch('/{stockAdjustment}/cancel', [StockAdjustmentController::class, 'cancel'])->name('cancel');
```

- [ ] **Step 4: Add the lifecycle controller methods**

In `app/Http/Controllers/StockAdjustmentController.php`, add these imports at the top (alongside the existing ones):

```php
use App\Models\InventoryMovement;
use App\Models\SparepartBranchStock;
use App\Support\InventoryMovementType;
```

Add these 4 methods to the class (place them after `update()` and before `sparepartsByBranch()`):

```php
    public function submit(StockAdjustment $stockAdjustment)
    {
        $this->authorize('submit', $stockAdjustment);

        DB::transaction(function () use ($stockAdjustment) {
            $fresh = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->first();
            if ($fresh->status !== StockAdjustmentStatus::DRAFT) {
                return;
            }

            $fresh->status = StockAdjustmentStatus::PENDING_APPROVAL;
            $fresh->save();
        });

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil diajukan untuk persetujuan.');
    }

    public function approve(StockAdjustment $stockAdjustment)
    {
        $this->authorize('approve', $stockAdjustment);

        DB::transaction(function () use ($stockAdjustment) {
            $fresh = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->first();
            if ($fresh->status !== StockAdjustmentStatus::PENDING_APPROVAL) {
                return;
            }

            $fresh->status = StockAdjustmentStatus::APPROVED;
            $fresh->approved_by = auth()->id();
            $fresh->approved_at = now();
            $fresh->save();
        });

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil disetujui.');
    }

    public function post(StockAdjustment $stockAdjustment)
    {
        $this->authorize('post', $stockAdjustment);

        DB::transaction(function () use ($stockAdjustment) {
            $fresh = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->first();
            if ($fresh->status !== StockAdjustmentStatus::APPROVED) {
                return;
            }

            $lines = $fresh->lines()->reorder()->orderBy('sparepart_branch_id')->get();

            foreach ($lines as $line) {
                $stock = SparepartBranchStock::where('sparepart_branch_id', $line->sparepart_branch_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $currentOnHandQty = (float) $stock->on_hand_qty;
                $physicalQty = (float) $line->physical_qty;
                $delta = round($physicalQty - $currentOnHandQty, 3);

                if (abs($delta) < 0.0005) {
                    continue;
                }

                $stock->on_hand_qty = $physicalQty;
                $stock->save();

                $recordedDelta = round((float) $line->adjustment_qty, 3);
                $notes = null;
                if (abs($recordedDelta - $delta) >= 0.0005) {
                    $notes = sprintf(
                        'Tercatat saat diajukan: %+.3f, diterapkan saat posting: %+.3f (stok bergeser sejak diajukan).',
                        $recordedDelta,
                        $delta
                    );
                }

                InventoryMovement::create([
                    'movement_at' => now(),
                    'branch_id' => $fresh->branch_id,
                    'sparepart_branch_id' => $line->sparepart_branch_id,
                    'movement_type' => $delta > 0 ? InventoryMovementType::ADJUSTMENT_IN : InventoryMovementType::ADJUSTMENT_OUT,
                    'qty_in' => $delta > 0 ? $delta : 0,
                    'qty_out' => $delta < 0 ? abs($delta) : 0,
                    'balance_after' => $physicalQty,
                    'reference_type' => 'stock_adjustment_line',
                    'reference_id' => $line->id,
                    'notes' => $notes,
                    'created_by' => auth()->id(),
                ]);
            }

            $fresh->status = StockAdjustmentStatus::POSTED;
            $fresh->save();
        });

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil diposting.');
    }

    public function cancel(StockAdjustment $stockAdjustment)
    {
        $this->authorize('cancel', $stockAdjustment);

        DB::transaction(function () use ($stockAdjustment) {
            $fresh = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->first();
            $cancellableStatuses = [StockAdjustmentStatus::DRAFT, StockAdjustmentStatus::PENDING_APPROVAL, StockAdjustmentStatus::APPROVED];
            if (! in_array($fresh->status, $cancellableStatuses, true)) {
                return;
            }

            $fresh->status = StockAdjustmentStatus::CANCELLED;
            $fresh->save();
        });

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil dibatalkan.');
    }
```

Note the `abs($delta) < 0.0005` / `abs($recordedDelta - $delta) >= 0.0005` epsilon comparisons instead of `=== 0.0` / `!==` — both `$physicalQty` and `$currentOnHandQty` come from `decimal:3`-cast model attributes, and comparing floats for exact equality is unsafe even when both values are expected to carry only 3 decimal places. `0.0005` is half of the smallest representable unit at 3 decimal places, so it correctly treats "no real difference" as zero while still detecting a genuine drift as small as `0.001`.

- [ ] **Step 5: Add the action buttons to `show.blade.php`**

In `resources/views/stock-adjustments/show.blade.php`, extend the `<div class="d-flex gap-2">` block (which currently only contains the "Ubah" button from Task 3) to also include:

```blade
            @can('submit', $stockAdjustment)
                <form method="POST" action="{{ route('stock-adjustments.submit', $stockAdjustment) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-outline-primary btn-sm">Ajukan</button>
                </form>
            @endcan
            @can('approve', $stockAdjustment)
                <form method="POST" action="{{ route('stock-adjustments.approve', $stockAdjustment) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Setujui</button>
                </form>
            @endcan
            @can('post', $stockAdjustment)
                <form method="POST" action="{{ route('stock-adjustments.post', $stockAdjustment) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Posting</button>
                </form>
            @endcan
            @can('cancel', $stockAdjustment)
                <form method="POST" action="{{ route('stock-adjustments.cancel', $stockAdjustment) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-outline-danger btn-sm">Batalkan</button>
                </form>
            @endcan
```

The resulting block should have "Ubah", "Ajukan", "Setujui", "Posting", "Batalkan" as 5 independently-gated `@can` blocks — on any single adjustment, at most 2 of these are ever simultaneously visible to a fully-permissioned user (e.g. "Ajukan" + "Batalkan" on a DRAFT), since each one's Policy method already enforces its own status.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=StockAdjustmentManagementTest`
Expected: 37 passed.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: 450 passed (430 baseline + 20 new).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/StockAdjustmentController.php routes/web.php resources/views/stock-adjustments/show.blade.php tests/Feature/StockAdjustmentManagementTest.php
git commit -m "feat: implement stock adjustment submit/approve/post/cancel lifecycle"
```

---

### Task 5: Sidebar wiring and full-suite verification

**Files:**
- Modify: `resources/views/partials/sidebar.blade.php`
- Test: `tests/Feature/AppShellTest.php` (extend or add, depending on what already exists — see Step 1)

**Interfaces:**
- Consumes: `route('stock-adjustments.index')` (Task 3).
- Produces: nothing consumed by later tasks — this is the final task in the plan.

- [ ] **Step 1: Check for an existing positive placeholder test, write one if missing**

Search `tests/Feature/AppShellTest.php` for a test asserting the "Stock Adjustment" sidebar item is VISIBLE when a user has `stock_adjustment.view` granted (something like `test_sidebar_shows_stock_adjustment_placeholder_when_user_has_stock_adjustment_view_permission_in_a_branch`).

**Do not assume it exists** — the equivalent assumption for the Goods Receipt module's sidebar task (008a Task 4) turned out to be wrong: only a negative "hides all placeholders without any permission" test existed, no positive one. If the same is true here, write the missing positive test yourself, modeled on whichever sibling test (PKB's or Goods Receipt's) already does this correctly: grant `stock_adjustment.view` in some branch, hit a page that renders the sidebar (e.g. `/dashboard`), and assert both:
```php
$response->assertSee('Stock Adjustment', false);
$response->assertSee(route('stock-adjustments.index'), false);
```
The second assertion is the one that actually pins this to a real link rather than matching stale placeholder text — do not skip it.

If a positive test already exists, just add the `assertSee(route('stock-adjustments.index'), false)` line to it (don't rename the method).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AppShellTest`
Expected: the relevant test FAILS (the route URL doesn't appear yet — the placeholder is still a disabled `<span>`).

- [ ] **Step 3: Swap the sidebar placeholder**

In `resources/views/partials/sidebar.blade.php`, find the block:

```blade
        @if ($user->branchesWithPermission('stock_adjustment.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-sliders me-2"></i> Stock Adjustment
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
```

Replace with:

```blade
        @if ($user->branchesWithPermission('stock_adjustment.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('stock-adjustments.index') }}" class="nav-link {{ request()->routeIs('stock-adjustments.*') ? 'active' : '' }}">
                <i class="bi bi-sliders me-2"></i> Stock Adjustment
            </a>
        </li>
        @endif
```

If the actual existing block's exact whitespace differs slightly, match the real file content — read it first, don't blind-replace.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AppShellTest`
Expected: PASS.

- [ ] **Step 5: Run the full suite and the text-collision grep**

Run: `php artisan test`
Expected: all tests PASS — 450 or 451 depending on whether Step 1 added a new test method or extended an existing one.

Run: `grep -rn "Belum ada stock adjustment\|Buat Adjustment Pertama\|Adjustment Baru\|Cari nomor atau alasan" tests/Feature/AppShellTest.php tests/Feature/DashboardTest.php`
Expected: no unexpected matches beyond the "Stock Adjustment" occurrence already reviewed in Step 1.

- [ ] **Step 6: Commit**

```bash
git add resources/views/partials/sidebar.blade.php tests/Feature/AppShellTest.php
git commit -m "feat: wire up Stock Adjustment sidebar link"
```

---

## Self-Review Notes

- **Spec coverage:** every in-scope item from the design spec is covered — data model (Task 1), Policy (Task 2), CRUD controller/FormRequests/views (Task 3), lifecycle actions/posting math (Task 4), sidebar wiring (Task 5). Explicitly out-of-scope items (segregation of duties, a "reject" action, Transfer Stock) are untouched by every task.
- **Placeholder scan:** none found — every code block is complete and copy-ready.
- **Type consistency:** `StockAdjustment`/`StockAdjustmentLine` field names and relation method names (`lines()`, `branch()`, `approvedBy()`, `stockAdjustment()`, `sparepartBranch()`) introduced in Task 1 are used identically in every later task. `StockAdjustmentStatus`/`InventoryMovementType` constants are referenced the same way everywhere (controller, Policy, views, tests).
- **Scope check:** 5 tasks — one more than Goods Receipt's 4, because this module has a 3-stage lifecycle (`submit`/`approve`/`post`) instead of Goods Receipt's single `post`, matching PKB's (migration 006) 5-task shape for comparable lifecycle complexity.
- **Concurrency discipline:** every status-changing action (`submit`/`approve`/`post`/`cancel`) locks the header row and re-verifies status inside its transaction from the first draft — extending the pattern proven in 008a to two additional lifecycle actions (`submit`/`approve`) that 008a didn't need. `post()`'s stock-row locking reuses the exact `->reorder()->orderBy('sparepart_branch_id')` pattern already proven correct in 008a.
- **Posting math correctness:** `post()` always recomputes against current `on_hand_qty`, never the stored `system_qty`/`adjustment_qty` snapshot — Task 4's Step 4 test (`test_post_recomputes_against_current_stock_and_notes_the_drift_when_stock_changed_since_submission`) exercises this directly by mutating stock between line-creation and posting, and the zero-delta test (`test_post_skips_ledger_entry_when_recomputed_delta_is_zero_but_still_marks_posted`) exercises the CHECK-constraint-driven "skip the ledger row" edge case.
- **`@json()` safety:** both `@json(` call sites in this plan (`create.blade.php`'s `$oldLines`, `edit.blade.php`'s `$sparepartOptions`/`$existingLines`) are bare, comma-free variable references, matching the mandatory pattern from the Global Constraints.
