# Sub-Proyek 008c — Transfer Stock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Transfer Stock module — the third and final part of migration 008, and the first module in this project where a single document spans TWO branches. Full CRUD while `DRAFT`, a 4-stage lifecycle (`approve` → `dispatch` → `receive`), and a `cancel` action reachable from `DRAFT`/`APPROVED` only. `dispatch()` decreases stock at the origin branch; `receive()` increases stock at the destination branch — the two mutations happen in two separate transactions, at two different points in time, at two different branches.

**Architecture:** `StockTransferPolicy` checks permissions in a DIFFERENT branch depending on the action — `create`/`approve`/`dispatch`/`cancel` all check the ORIGIN branch, `receive` checks the DESTINATION branch, and `view` accepts either — the first Policy in this project with this shape. `dispatch()` applies the exact same "validate every line under lock before mutating any of them" reserved_qty guard that migration 008b (Stock Adjustment) had to discover as a Critical bug during review — this plan builds it in from the first draft instead. Lines reference `sparepart_id` (the sparepart's global identity), not `sparepart_branch_id` — the first module in this project where a line references something other than a per-branch config row, because a transfer necessarily resolves to two DIFFERENT `SparepartBranch` records (one at each branch) for the same underlying sparepart.

**Tech Stack:** Laravel 8.75, PHP 7.4.33 (no PHP 8 syntax), MySQL 8.0, PHPUnit feature tests (`RefreshDatabase`).

## Global Constraints

- PHP runtime is 7.4.33 — never use PHP 8-only syntax (nullsafe `?->`, named arguments, match expressions, enums, constructor property promotion, union types), including inside Blade `@php()` blocks.
- Laravel 8.75 pinned — never use `Request::integer()` or other Laravel 9+ `Request` helper methods.
- `bigint` PKs, never UUID. `->simplePaginate()` only, never `->paginate()`.
- No hard deletes of transactional documents — status field only (`draft`/`approved`/`dispatched`/`received`/`cancelled`). Line-item child table cascade-deletes only as a DB-level safety net.
- `stock_transfer.view`, `stock_transfer.create`, `stock_transfer.approve`, `stock_transfer.dispatch`, `stock_transfer.receive`, `stock_transfer.cancel` are already seeded in `MenuPermissionSeeder` (menu code `persediaan.stock_transfer`, `is_branch_scoped = true`) — do not add new permission codes. There is no `stock_transfer.edit` code; editing a `DRAFT` is gated on `stock_transfer.create`.
- **This module uses the SAME 5 statuses as the source migration document** (`DRAFT`, `APPROVED`, `DISPATCHED`, `RECEIVED`, `CANCELLED`) — no deviation this time, unlike migration 008b which added an extra status.
- **Permission is checked in a DIFFERENT branch depending on the action** — this is new in this project:
  - `create`/`update`/`approve`/`dispatch`/`cancel` all check the code in `from_branch_id` (the branch initiating and releasing the transfer).
  - `receive` checks the code in `to_branch_id` (the branch accepting the goods).
  - `view` accepts the code in EITHER `from_branch_id` OR `to_branch_id` — both parties have a legitimate interest in seeing the document.
- `from_branch_id` is immutable after creation (absent from `UpdateStockTransferRequest`'s rules entirely) — it's the branch whose permission was checked to create the document. `to_branch_id` MAY be changed while still `DRAFT` (nothing has committed to any branch yet).
- `from_branch_id` must never equal `to_branch_id` — enforced at the DB level (`CHECK`) AND at the FormRequest level (Laravel's built-in `different:from_branch_id` rule on `to_branch_id`).
- A `sparepart_id` may appear **at most once** per document (`UNIQUE(stock_transfer_id, sparepart_id)` + a `distinct` validation rule) — same reasoning as migration 008b: one real quantity to move per item, not a running total.
- A sparepart is only transferable if it has an ACTIVE `SparepartBranch` record at BOTH `from_branch_id` AND `to_branch_id` — validated at `store()`/`update()` time with two distinct error messages (one per branch), and RE-validated at `dispatch()`/`receive()` time respectively (config can change between creation and execution).
- **`dispatch()` must validate ALL lines under lock before mutating ANY of them (all-or-nothing)** — specifically checking that `on_hand_qty - qty >= reserved_qty` for every line at the origin branch, exactly mirroring the two-pass pattern already proven in migration 008b's `StockAdjustmentController::post()` (read that method before writing this one). This guard was a Critical bug discovered during 008b's review, not part of its original plan — this plan builds it in from the first draft instead of waiting for a reviewer to find it again.
- `dispatch()` and `receive()` are TWO SEPARATE database transactions, run at two different times (never one transaction spanning both branches) — this is a deliberate design choice, not an oversight, and it means the module never needs to hold locks in two branches' `sparepart_branch_stocks` rows simultaneously.
- Every status-changing action (`approve`/`dispatch`/`receive`/`cancel`) must lock the header row (`stock_transfers`) FIRST, re-verify its status hasn't changed, THEN act — applied to all 4 actions from the start.
- `qty` on a line is trusted directly from client input after validation (`numeric`, `min:0.001`) — unlike other modules, there is no price or computed total to recompute server-side here, only a quantity to move.
- Use the `session('error')`/`alert-danger` flash convention (added 2026-08-05, see `resources/views/layouts/app.blade.php`) for BOTH `dispatch()`'s reserved_qty-violation rejection AND `receive()`'s destination-config-violation rejection — these are genuine user errors, not "already processed" no-ops (which continue to use `session('status')`, matching every other module).
- **Every `@json(...)` call must reference a single, comma-free plain variable** (shape data into a controller-side array/variable first with `@php($x = ...)`, then `@json($x)` in the view) — this bug class has already caused a Critical parse error in one sibling module and a silent XSS-hardening degradation in another.
- **The create form's validation-error round-trip (old-input replay + re-enabling the "add line" button) must be built correctly from the first draft** — the reference pattern is `resources/views/stock-adjustments/create.blade.php` (read it before writing this module's `create.blade.php`).
- **The status badge markup must live in exactly one shared partial** (`stock-transfers/_status_badge.blade.php`), included by both `index.blade.php` and `show.blade.php` — never duplicate the status→label/class mapping in two places. Also apply the 008b-cleanup lesson: the final `@else` branch must be an explicit `@elseif (status === CANCELLED)` with a genuinely distinct "unknown status" fallback, not a silent catch-all — do not regress to the pattern that had to be fixed in 008b.
- Reuse `DocumentNumberGenerator::next($branch, 'ST')` for numbering (format: `ST/{FROM_BRANCH_CODE}/{YYYYMM}/{00001}`), based on the ORIGIN branch. Reuse `partials.list-filter-bar`/`partials.empty-state` — do not hand-roll new list/filter/empty-state markup.

---

### Task 1: Data model — migrations, models, support classes

**Files:**
- Create: `database/migrations/2026_08_05_000001_create_stock_transfers_table.php`
- Create: `database/migrations/2026_08_05_000002_create_stock_transfer_lines_table.php`
- Create: `app/Support/TransferStatus.php`
- Modify: `app/Support/InventoryMovementType.php`
- Create: `app/Models/StockTransfer.php`
- Create: `app/Models/StockTransferLine.php`
- Test: `tests/Feature/StockTransferModelTest.php`

**Interfaces:**
- Produces: `StockTransfer` (fields: `number`, `from_branch_id`, `to_branch_id`, `transfer_date`, `status`, `approved_by`, `approved_at`, `dispatched_by`, `dispatched_at`, `received_by`, `received_at`, `notes`; relations `fromBranch()`, `toBranch()`, `approvedBy()`, `dispatchedBy()`, `receivedBy()`, `lines()`); `StockTransferLine` (fields: `stock_transfer_id`, `sparepart_id`, `qty`, `sort_order`; relations `stockTransfer()`, `sparepart()`); `TransferStatus::DRAFT`/`APPROVED`/`DISPATCHED`/`RECEIVED`/`CANCELLED`; `InventoryMovementType::TRANSFER_OUT`/`TRANSFER_IN`. Every later task must use these constants, never bare string literals.

- [ ] **Step 1: Write the failing model tests**

Create `tests/Feature/StockTransferModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Sparepart;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Support\InventoryMovementType;
use App\Support\TransferStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferModelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSparepart(string $codeSuffix = ''): Sparepart
    {
        return Sparepart::create(['code' => "OLI-01{$codeSuffix}", 'name' => 'Oli Mesin']);
    }

    public function test_stock_transfer_can_be_created_with_fillable_fields_and_defaults_to_draft(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        $fromBranch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $toBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);

        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001',
            'from_branch_id' => $fromBranch->id,
            'to_branch_id' => $toBranch->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $this->assertSame(TransferStatus::DRAFT, $stockTransfer->status);
        $this->assertSame($user->id, $stockTransfer->created_by);
        $this->assertNull($stockTransfer->approved_by);
        $this->assertNull($stockTransfer->dispatched_by);
        $this->assertNull($stockTransfer->received_by);
    }

    public function test_stock_transfer_number_is_unique(): void
    {
        $fromBranch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $toBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $attrs = ['number' => 'ST/JKT/202608/00001', 'from_branch_id' => $fromBranch->id, 'to_branch_id' => $toBranch->id, 'transfer_date' => now()->format('Y-m-d')];
        StockTransfer::create($attrs);

        $this->expectException(QueryException::class);
        StockTransfer::create($attrs);
    }

    public function test_stock_transfer_rejects_same_from_and_to_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $this->expectException(QueryException::class);
        StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $branch->id, 'to_branch_id' => $branch->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);
    }

    public function test_stock_transfer_line_belongs_to_transfer_and_sparepart(): void
    {
        $fromBranch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $toBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $fromBranch->id, 'to_branch_id' => $toBranch->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);
        $sparepart = $this->makeSparepart();

        $line = StockTransferLine::create([
            'stock_transfer_id' => $stockTransfer->id,
            'sparepart_id' => $sparepart->id,
            'qty' => 5,
        ]);

        $this->assertSame($stockTransfer->id, $line->stockTransfer->id);
        $this->assertSame($sparepart->id, $line->sparepart->id);
        $this->assertCount(1, $stockTransfer->lines);
    }

    public function test_deleting_stock_transfer_cascades_to_its_lines(): void
    {
        $fromBranch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $toBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $fromBranch->id, 'to_branch_id' => $toBranch->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);
        $sparepart = $this->makeSparepart();
        $line = StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 5]);

        $stockTransfer->delete();

        $this->assertDatabaseMissing('stock_transfer_lines', ['id' => $line->id]);
    }

    public function test_stock_transfer_line_rejects_duplicate_sparepart_in_same_transfer(): void
    {
        $fromBranch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $toBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $fromBranch->id, 'to_branch_id' => $toBranch->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);
        $sparepart = $this->makeSparepart();
        StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 5]);

        $this->expectException(QueryException::class);
        StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 3]);
    }

    public function test_stock_transfer_line_rejects_nonpositive_qty(): void
    {
        $fromBranch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $toBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $fromBranch->id, 'to_branch_id' => $toBranch->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);
        $sparepart = $this->makeSparepart();

        $this->expectException(QueryException::class);
        StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 0]);
    }

    public function test_inventory_movement_can_be_created_with_transfer_out_and_transfer_in_types(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = $this->makeSparepart();
        $sparepartBranch = \App\Models\SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        $movementOut = InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::TRANSFER_OUT, 'qty_in' => 0, 'qty_out' => 5,
            'balance_after' => 5, 'reference_type' => 'stock_transfer_line', 'reference_id' => 1,
        ]);
        $movementIn = InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::TRANSFER_IN, 'qty_in' => 5, 'qty_out' => 0,
            'balance_after' => 10, 'reference_type' => 'stock_transfer_line', 'reference_id' => 2,
        ]);

        $this->assertSame(InventoryMovementType::TRANSFER_OUT, $movementOut->movement_type);
        $this->assertSame(InventoryMovementType::TRANSFER_IN, $movementIn->movement_type);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockTransferModelTest`
Expected: FAIL — tables/classes don't exist yet.

- [ ] **Step 3: Create the support classes**

Create `app/Support/TransferStatus.php`:

```php
<?php

namespace App\Support;

class TransferStatus
{
    const DRAFT = 'draft';
    const APPROVED = 'approved';
    const DISPATCHED = 'dispatched';
    const RECEIVED = 'received';
    const CANCELLED = 'cancelled';
}
```

Modify `app/Support/InventoryMovementType.php` to add the two new constants (keep `RECEIPT`, `ADJUSTMENT_IN`, `ADJUSTMENT_OUT` unchanged):

```php
<?php

namespace App\Support;

class InventoryMovementType
{
    const RECEIPT = 'receipt';
    const ADJUSTMENT_IN = 'adjustment_in';
    const ADJUSTMENT_OUT = 'adjustment_out';
    const TRANSFER_OUT = 'transfer_out';
    const TRANSFER_IN = 'transfer_in';
}
```

- [ ] **Step 4: Create the migrations**

Create `database/migrations/2026_08_05_000001_create_stock_transfers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateStockTransfersTable extends Migration
{
    public function up()
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('to_branch_id')->constrained('branches');
            $table->date('transfer_date');
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('dispatched_by')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('dispatched_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['from_branch_id', 'transfer_date', 'status']);
            $table->index(['to_branch_id', 'transfer_date', 'status']);
        });

        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT ck_stock_transfers_branches_differ CHECK (from_branch_id <> to_branch_id)');
    }

    public function down()
    {
        Schema::dropIfExists('stock_transfers');
    }
}
```

Create `database/migrations/2026_08_05_000002_create_stock_transfer_lines_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateStockTransferLinesTable extends Migration
{
    public function up()
    {
        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('sparepart_id')->constrained('spareparts');
            $table->decimal('qty', 18, 3);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['stock_transfer_id', 'sparepart_id'], 'st_lines_st_id_sp_id_unique');
        });

        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT ck_stock_transfer_lines_qty_positive CHECK (qty > 0)');
    }

    public function down()
    {
        Schema::dropIfExists('stock_transfer_lines');
    }
}
```

Note: the unique index is given an explicit short name (`st_lines_st_id_sp_id_unique`) — MySQL's default auto-generated name for this constraint would exceed 64 characters, the same reason migration 008b's line table also uses an explicit name.

- [ ] **Step 5: Create the models**

Create `app/Models/StockTransfer.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\TransferStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'from_branch_id', 'to_branch_id', 'transfer_date', 'status',
        'approved_by', 'approved_at', 'dispatched_by', 'dispatched_at', 'received_by', 'received_at', 'notes',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'approved_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => TransferStatus::DRAFT,
    ];

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dispatchedBy()
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function lines()
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('sort_order');
    }
}
```

Create `app/Models/StockTransferLine.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransferLine extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'stock_transfer_id', 'sparepart_id', 'qty', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
    ];

    public function stockTransfer()
    {
        return $this->belongsTo(StockTransfer::class);
    }

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class);
    }
}
```

- [ ] **Step 6: Run migrations and tests to verify they pass**

Run: `php artisan migrate` then `php artisan test --filter=StockTransferModelTest`
Expected: 8 passed.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: 466 passed (458 baseline + 8 new).

- [ ] **Step 8: Commit**

```bash
git add app/Support/TransferStatus.php app/Support/InventoryMovementType.php app/Models/StockTransfer.php app/Models/StockTransferLine.php database/migrations/2026_08_05_000001_create_stock_transfers_table.php database/migrations/2026_08_05_000002_create_stock_transfer_lines_table.php tests/Feature/StockTransferModelTest.php
git commit -m "feat: add stock transfer data model and extend inventory movement types"
```

---

### Task 2: Authorization — `StockTransferPolicy`

**Files:**
- Create: `app/Policies/StockTransferPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php` (add to `$policies` array)
- Test: `tests/Feature/StockTransferAuthorizationTest.php`

**Interfaces:**
- Consumes: `StockTransfer`, `TransferStatus` (Task 1), `User::hasPermissionToInBranch()` (already exists).
- Produces: `StockTransferPolicy::view/update/approve/dispatch/receive/cancel(User, StockTransfer): bool`, consumed by Task 3/4's controller via `$this->authorize(...)`.

- [ ] **Step 1: Write the failing Policy tests**

Create `tests/Feature/StockTransferAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\TransferStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferAuthorizationTest extends TestCase
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

    protected function makeStockTransfer(Branch $from, Branch $to, array $overrides = []): StockTransfer
    {
        return StockTransfer::create(array_merge([
            'number' => 'ST/JKT/202608/00001',
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ], $overrides));
    }

    public function test_policy_grants_view_for_a_user_with_permission_in_the_from_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertTrue(User::find($user->id)->can('view', $stockTransfer));
    }

    public function test_policy_grants_view_for_a_user_with_permission_in_the_to_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.view');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertTrue(User::find($user->id)->can('view', $stockTransfer));
    }

    public function test_policy_denies_view_for_a_user_with_permission_in_neither_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $other = Branch::create(['code' => 'SBY', 'name' => 'Cabang Surabaya']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $other, 'stock_transfer.view');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertFalse(User::find($user->id)->can('view', $stockTransfer));
    }

    public function test_policy_grants_update_for_a_draft_transfer_with_create_code_in_from_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertTrue(User::find($user->id)->can('update', $stockTransfer));
    }

    public function test_policy_denies_update_for_a_user_with_create_code_only_in_to_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.create');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertFalse(User::find($user->id)->can('update', $stockTransfer));
    }

    public function test_policy_grants_approve_for_a_draft_transfer_with_approve_code_in_from_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertTrue(User::find($user->id)->can('approve', $stockTransfer));
    }

    public function test_policy_denies_approve_for_a_non_draft_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $stockTransfer = $this->makeStockTransfer($from, $to, ['status' => TransferStatus::APPROVED]);

        $this->assertFalse(User::find($user->id)->can('approve', $stockTransfer));
    }

    public function test_policy_grants_dispatch_for_an_approved_transfer_with_dispatch_code_in_from_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.dispatch');
        $stockTransfer = $this->makeStockTransfer($from, $to, ['status' => TransferStatus::APPROVED]);

        $this->assertTrue(User::find($user->id)->can('dispatch', $stockTransfer));
    }

    public function test_policy_denies_dispatch_for_a_draft_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.dispatch');
        $stockTransfer = $this->makeStockTransfer($from, $to);

        $this->assertFalse(User::find($user->id)->can('dispatch', $stockTransfer));
    }

    public function test_policy_grants_receive_for_a_dispatched_transfer_with_receive_code_in_to_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.receive');
        $stockTransfer = $this->makeStockTransfer($from, $to, ['status' => TransferStatus::DISPATCHED]);

        $this->assertTrue(User::find($user->id)->can('receive', $stockTransfer));
    }

    public function test_policy_denies_receive_for_a_user_with_receive_code_only_in_from_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.receive');
        $stockTransfer = $this->makeStockTransfer($from, $to, ['status' => TransferStatus::DISPATCHED]);

        $this->assertFalse(User::find($user->id)->can('receive', $stockTransfer));
    }

    public function test_policy_grants_cancel_for_draft_and_approved_statuses(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.cancel');

        foreach ([TransferStatus::DRAFT, TransferStatus::APPROVED] as $index => $status) {
            $stockTransfer = $this->makeStockTransfer($from, $to, ['number' => "ST/JKT/202608/0000{$index}9", 'status' => $status]);
            $this->assertTrue(User::find($user->id)->can('cancel', $stockTransfer), "Expected cancel to be allowed from status {$status}");
        }
    }

    public function test_policy_denies_cancel_for_a_dispatched_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.cancel');
        $stockTransfer = $this->makeStockTransfer($from, $to, ['status' => TransferStatus::DISPATCHED]);

        $this->assertFalse(User::find($user->id)->can('cancel', $stockTransfer));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockTransferAuthorizationTest`
Expected: FAIL — `StockTransferPolicy` doesn't exist / isn't registered.

- [ ] **Step 3: Create the Policy**

Create `app/Policies/StockTransferPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;
use App\Support\TransferStatus;

class StockTransferPolicy
{
    public function view(User $user, StockTransfer $stockTransfer): bool
    {
        return $user->hasPermissionToInBranch('stock_transfer.view', $stockTransfer->from_branch_id)
            || $user->hasPermissionToInBranch('stock_transfer.view', $stockTransfer->to_branch_id);
    }

    public function update(User $user, StockTransfer $stockTransfer): bool
    {
        return $stockTransfer->status === TransferStatus::DRAFT
            && $user->hasPermissionToInBranch('stock_transfer.create', $stockTransfer->from_branch_id);
    }

    public function approve(User $user, StockTransfer $stockTransfer): bool
    {
        return $stockTransfer->status === TransferStatus::DRAFT
            && $user->hasPermissionToInBranch('stock_transfer.approve', $stockTransfer->from_branch_id);
    }

    public function dispatch(User $user, StockTransfer $stockTransfer): bool
    {
        return $stockTransfer->status === TransferStatus::APPROVED
            && $user->hasPermissionToInBranch('stock_transfer.dispatch', $stockTransfer->from_branch_id);
    }

    public function receive(User $user, StockTransfer $stockTransfer): bool
    {
        return $stockTransfer->status === TransferStatus::DISPATCHED
            && $user->hasPermissionToInBranch('stock_transfer.receive', $stockTransfer->to_branch_id);
    }

    public function cancel(User $user, StockTransfer $stockTransfer): bool
    {
        return in_array($stockTransfer->status, [TransferStatus::DRAFT, TransferStatus::APPROVED], true)
            && $user->hasPermissionToInBranch('stock_transfer.cancel', $stockTransfer->from_branch_id);
    }
}
```

- [ ] **Step 4: Register the Policy**

In `app/Providers/AuthServiceProvider.php`, add to the `$policies` array:

```php
        \App\Models\StockTransfer::class => \App\Policies\StockTransferPolicy::class,
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=StockTransferAuthorizationTest`
Expected: 13 passed.

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: 479 passed (466 baseline + 13 new).

- [ ] **Step 7: Commit**

```bash
git add app/Policies/StockTransferPolicy.php app/Providers/AuthServiceProvider.php tests/Feature/StockTransferAuthorizationTest.php
git commit -m "feat: add branch-scoped, status-aware StockTransferPolicy"
```

---

### Task 3: CRUD controller, FormRequests, and views (no lifecycle actions yet)

**Files:**
- Create: `app/Http/Requests/StoreStockTransferRequest.php`
- Create: `app/Http/Requests/UpdateStockTransferRequest.php`
- Create: `app/Http/Controllers/StockTransferController.php` (index/create/store/show/edit/update/sparepartsByBranch only — `approve`/`dispatch`/`receive`/`cancel` are added in Task 4)
- Create: `resources/views/stock-transfers/index.blade.php`
- Create: `resources/views/stock-transfers/no-access.blade.php`
- Create: `resources/views/stock-transfers/create.blade.php`
- Create: `resources/views/stock-transfers/edit.blade.php`
- Create: `resources/views/stock-transfers/show.blade.php`
- Create: `resources/views/stock-transfers/_line_item_scripts.blade.php`
- Create: `resources/views/stock-transfers/_status_badge.blade.php`
- Modify: `routes/web.php` (add a new `stock-transfers` route group — index/create/store/show/edit/update/lookup only)
- Test: `tests/Feature/StockTransferManagementTest.php`

**Interfaces:**
- Consumes: `StockTransferPolicy` (Task 2); `StockTransfer`/`StockTransferLine`/`TransferStatus` (Task 1); `Sparepart`/`SparepartBranch` (already exist); `partials.list-filter-bar`, `partials.empty-state` (already exist); `DocumentNumberGenerator::next(Branch $branch, string $documentType): string` (already exists).
- Produces: routes `stock-transfers.index/create/store/show/edit/update`, `stock-transfers.lookup.spareparts`. Task 4 adds `approve`/`dispatch`/`receive`/`cancel` to the same controller and route group. The `_status_badge.blade.php` partial (taking a `$status` variable) is consumed by both `index.blade.php` and `show.blade.php` in this task.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/StockTransferManagementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\TransferStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferManagementTest extends TestCase
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

    protected function makeSparepartAtBranches(Branch $from, Branch $to, string $codeSuffix = ''): Sparepart
    {
        $sparepart = Sparepart::create(['code' => "OLI-01{$codeSuffix}", 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $from->id, 'selling_price' => 60000]);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $to->id, 'selling_price' => 60000]);

        return $sparepart;
    }

    protected function baseStorePayload(Branch $from, Branch $to, Sparepart $sparepart, float $qty = 5): array
    {
        return [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
            'lines' => [
                ['sparepart_id' => $sparepart->id, 'qty' => $qty],
            ],
        ];
    }

    public function test_store_creates_stock_transfer_with_lines(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));

        $stockTransfer = StockTransfer::first();
        $response->assertRedirect(route('stock-transfers.show', $stockTransfer));
        $this->assertSame(TransferStatus::DRAFT, $stockTransfer->status);
        $this->assertStringStartsWith('ST/JKT/', $stockTransfer->number);
        $this->assertCount(1, $stockTransfer->lines);
        $this->assertSame(5.0, (float) $stockTransfer->lines->first()->qty);
    }

    public function test_store_is_forbidden_without_stock_transfer_create_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));

        $response->assertForbidden();
    }

    public function test_store_rejects_a_transfer_with_no_lines(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'transfer_date' => now()->format('Y-m-d'), 'lines' => [],
        ]);

        $response->assertSessionHasErrors(['lines']);
    }

    public function test_store_rejects_duplicate_sparepart_in_same_document(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', [
            'from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'transfer_date' => now()->format('Y-m-d'),
            'lines' => [
                ['sparepart_id' => $sparepart->id, 'qty' => 3],
                ['sparepart_id' => $sparepart->id, 'qty' => 2],
            ],
        ]);

        $response->assertSessionHasErrors(['lines.0.sparepart_id', 'lines.1.sparepart_id']);
    }

    public function test_store_rejects_same_from_and_to_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', [
            'from_branch_id' => $branch->id, 'to_branch_id' => $branch->id, 'transfer_date' => now()->format('Y-m-d'),
            'lines' => [['sparepart_id' => $sparepart->id, 'qty' => 3]],
        ]);

        $response->assertSessionHasErrors(['to_branch_id']);
    }

    public function test_store_rejects_sparepart_not_configured_at_origin_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $to->id, 'selling_price' => 60000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));

        $response->assertSessionHasErrors(['lines.0.sparepart_id']);
    }

    public function test_store_rejects_sparepart_not_configured_at_destination_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $from->id, 'selling_price' => 60000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));

        $response->assertSessionHasErrors(['lines.0.sparepart_id']);
    }

    public function test_index_lists_transfers_visible_from_either_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $other = Branch::create(['code' => 'SBY', 'name' => 'Cabang Surabaya']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.view');
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));
        $visibleTransfer = StockTransfer::first();

        $response = $this->actingAs(User::find($user->id))->get('/stock-transfers');

        $response->assertOk();
        $response->assertSee($visibleTransfer->number);
    }

    public function test_index_shows_no_access_page_without_any_stock_transfer_view_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/stock-transfers');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_index_shows_empty_state_when_no_transfers_match(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_transfer.view');

        $response = $this->actingAs(User::find($user->id))->get('/stock-transfers');

        $response->assertOk();
        $response->assertSee('Belum ada transfer stock');
    }

    public function test_index_renders_all_five_status_badges_correctly(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $statuses = [
            TransferStatus::DRAFT => 'Draft',
            TransferStatus::APPROVED => 'Disetujui',
            TransferStatus::DISPATCHED => 'Dikirim',
            TransferStatus::RECEIVED => 'Diterima',
            TransferStatus::CANCELLED => 'Dibatalkan',
        ];
        foreach (array_keys($statuses) as $index => $status) {
            StockTransfer::create([
                'number' => "ST/JKT/202608/0000{$index}9",
                'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
                'transfer_date' => now()->format('Y-m-d'), 'status' => $status,
            ]);
        }

        $response = $this->actingAs(User::find($user->id))->get('/stock-transfers');

        $response->assertOk();
        foreach ($statuses as $label) {
            $response->assertSee($label);
        }
    }

    public function test_create_form_renders_for_a_user_with_stock_transfer_create_in_some_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_transfer.create');

        $response = $this->actingAs(User::find($user->id))->get('/stock-transfers/create');

        $response->assertOk();
    }

    public function test_create_form_replays_old_lines_after_a_validation_error(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $payload = $this->baseStorePayload($from, $to, $sparepart, 7);
        unset($payload['transfer_date']);

        $this->from(route('stock-transfers.create'))->actingAs(User::find($user->id))->post('/stock-transfers', $payload);
        $response = $this->actingAs(User::find($user->id))->get(route('stock-transfers.create'));

        $response->assertOk();
        $response->assertSee('oldLines', false);
        $response->assertSee('"qty":7', false);
    }

    public function test_edit_form_renders_for_a_user_with_stock_transfer_create_on_a_draft_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));
        $stockTransfer = StockTransfer::first();

        $response = $this->actingAs(User::find($user->id))->get("/stock-transfers/{$stockTransfer->id}/edit");

        $response->assertOk();
    }

    public function test_update_successfully_replaces_lines(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $newSparepart = $this->makeSparepartAtBranches($from, $to, '-updated');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart, 5));
        $stockTransfer = StockTransfer::first();

        $response = $this->actingAs(User::find($user->id))->put("/stock-transfers/{$stockTransfer->id}", [
            'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
            'lines' => [['sparepart_id' => $newSparepart->id, 'qty' => 9]],
        ]);

        $response->assertRedirect(route('stock-transfers.show', $stockTransfer));
        $stockTransfer->refresh();
        $this->assertCount(1, $stockTransfer->lines);
        $line = $stockTransfer->lines->first();
        $this->assertSame($newSparepart->id, $line->sparepart_id);
        $this->assertSame(9.0, (float) $line->qty);
    }

    public function test_update_can_change_destination_branch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $newTo = Branch::create(['code' => 'SBY', 'name' => 'Cabang Surabaya']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $newTo->id, 'selling_price' => 60000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $this->actingAs(User::find($user->id))->post('/stock-transfers', $this->baseStorePayload($from, $to, $sparepart));
        $stockTransfer = StockTransfer::first();

        $this->actingAs(User::find($user->id))->put("/stock-transfers/{$stockTransfer->id}", [
            'to_branch_id' => $newTo->id,
            'transfer_date' => now()->format('Y-m-d'),
            'lines' => [['sparepart_id' => $sparepart->id, 'qty' => 5]],
        ]);

        $stockTransfer->refresh();
        $this->assertSame($newTo->id, $stockTransfer->to_branch_id);
    }

    public function test_update_is_forbidden_for_a_non_draft_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->put("/stock-transfers/{$stockTransfer->id}", [
            'to_branch_id' => $to->id, 'transfer_date' => now()->format('Y-m-d'),
            'lines' => [['sparepart_id' => $sparepart->id, 'qty' => 1]],
        ]);

        $response->assertForbidden();
    }

    public function test_show_renders_both_branches_and_status_badge(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-transfers/{$stockTransfer->id}");

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
        $response->assertSee('Cabang Bandung');
        $response->assertSee('<span class="status-dot status-active">Draft</span>', false);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockTransferManagementTest`
Expected: FAIL — controller/routes/views don't exist yet.

- [ ] **Step 3: Create the FormRequests**

Create `app/Http/Requests/StoreStockTransferRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class StoreStockTransferRequest extends FormRequest
{
    public function authorize()
    {
        $fromBranchId = (int) $this->input('from_branch_id');

        return $fromBranchId && $this->user()->hasPermissionToInBranch('stock_transfer.create', $fromBranchId);
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'lines' => array_values(array_filter($this->input('lines', []), function ($line) {
                return ! empty($line['sparepart_id']);
            })),
        ]);
    }

    public function rules()
    {
        return [
            'from_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'to_branch_id' => ['required', 'integer', 'exists:branches,id', 'different:from_branch_id'],
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.sparepart_id' => ['required', 'integer', 'exists:spareparts,id', 'distinct'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $fromBranchId = (int) $this->input('from_branch_id');
            $toBranchId = (int) $this->input('to_branch_id');

            foreach ($this->input('lines', []) as $index => $line) {
                $sparepartId = $line['sparepart_id'] ?? null;
                if (! $sparepartId) {
                    continue;
                }

                $existsAtOrigin = SparepartBranch::where('sparepart_id', $sparepartId)
                    ->where('branch_id', $fromBranchId)->where('is_active', true)->exists();
                if (! $existsAtOrigin) {
                    $validator->errors()->add("lines.{$index}.sparepart_id", 'Sparepart belum dikonfigurasi atau tidak aktif di cabang asal.');

                    continue;
                }

                $existsAtDestination = SparepartBranch::where('sparepart_id', $sparepartId)
                    ->where('branch_id', $toBranchId)->where('is_active', true)->exists();
                if (! $existsAtDestination) {
                    $validator->errors()->add("lines.{$index}.sparepart_id", 'Sparepart belum dikonfigurasi atau tidak aktif di cabang tujuan.');
                }
            }
        });
    }
}
```

Create `app/Http/Requests/UpdateStockTransferRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStockTransferRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('update', $this->route('stockTransfer'));
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'lines' => array_values(array_filter($this->input('lines', []), function ($line) {
                return ! empty($line['sparepart_id']);
            })),
        ]);
    }

    public function rules()
    {
        return [
            'to_branch_id' => ['required', 'integer', 'exists:branches,id', 'different:from_branch_id_placeholder'],
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.sparepart_id' => ['required', 'integer', 'exists:spareparts,id', 'distinct'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $fromBranchId = (int) $this->route('stockTransfer')->from_branch_id;
            $toBranchId = (int) $this->input('to_branch_id');

            if ($toBranchId === $fromBranchId) {
                $validator->errors()->add('to_branch_id', 'Cabang tujuan tidak boleh sama dengan cabang asal.');
            }

            foreach ($this->input('lines', []) as $index => $line) {
                $sparepartId = $line['sparepart_id'] ?? null;
                if (! $sparepartId) {
                    continue;
                }

                $existsAtOrigin = SparepartBranch::where('sparepart_id', $sparepartId)
                    ->where('branch_id', $fromBranchId)->where('is_active', true)->exists();
                if (! $existsAtOrigin) {
                    $validator->errors()->add("lines.{$index}.sparepart_id", 'Sparepart belum dikonfigurasi atau tidak aktif di cabang asal.');

                    continue;
                }

                $existsAtDestination = SparepartBranch::where('sparepart_id', $sparepartId)
                    ->where('branch_id', $toBranchId)->where('is_active', true)->exists();
                if (! $existsAtDestination) {
                    $validator->errors()->add("lines.{$index}.sparepart_id", 'Sparepart belum dikonfigurasi atau tidak aktif di cabang tujuan.');
                }
            }
        });
    }
}
```

Note: `UpdateStockTransferRequest`'s `rules()` uses a harmless placeholder field name (`different:from_branch_id_placeholder`, a field that never exists in the request) instead of Laravel's `different:from_branch_id` rule — because `from_branch_id` is not part of this request's input at all (it's immutable, never submitted on update), so `different:from_branch_id` would compare against a always-absent field and never actually fire. The REAL same-branch check for update is done explicitly in `withValidator()` instead, comparing the route-bound `$stockTransfer->from_branch_id` against the submitted `to_branch_id`. `from_branch_id` is entirely absent from `rules()` — the origin branch can never be changed after creation.

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/StockTransferController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockTransferRequest;
use App\Http\Requests\UpdateStockTransferRequest;
use App\Models\Branch;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Services\DocumentNumberGenerator;
use App\Support\TransferStatus;
use Illuminate\Support\Facades\DB;

class StockTransferController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('stock_transfer.view');

        if ($permittedBranches->isEmpty()) {
            return view('stock-transfers.no-access');
        }

        $permittedBranchIds = $permittedBranches->pluck('id');

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranchIds)
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $stockTransfers = StockTransfer::with(['fromBranch', 'toBranch'])
            ->where(function ($query) use ($permittedBranchIds) {
                $query->whereIn('from_branch_id', $permittedBranchIds)
                    ->orWhereIn('to_branch_id', $permittedBranchIds);
            })
            ->when($branchIds, function ($query) use ($branchIds) {
                $query->where(function ($query) use ($branchIds) {
                    $query->whereIn('from_branch_id', $branchIds)
                        ->orWhereIn('to_branch_id', $branchIds);
                });
            })
            ->when($search, function ($query, $q) {
                $query->where('number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('stock-transfers.index', compact('stockTransfers'))
            ->with('branches', $permittedBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function create()
    {
        $fromBranches = auth()->user()->branchesWithPermission('stock_transfer.create');

        if ($fromBranches->isEmpty()) {
            return view('stock-transfers.no-access');
        }

        $allBranches = Branch::where('is_active', true)->orderBy('name')->get();

        return view('stock-transfers.create', compact('fromBranches', 'allBranches'));
    }

    public function store(StoreStockTransferRequest $request)
    {
        $data = $request->validated();
        $fromBranch = Branch::findOrFail($data['from_branch_id']);

        $stockTransfer = DB::transaction(function () use ($data, $fromBranch) {
            $stockTransfer = StockTransfer::create([
                'number' => (new DocumentNumberGenerator())->next($fromBranch, 'ST'),
                'from_branch_id' => $fromBranch->id,
                'to_branch_id' => $data['to_branch_id'],
                'transfer_date' => $data['transfer_date'],
                'status' => TransferStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($stockTransfer, $data['lines']);

            return $stockTransfer;
        });

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock berhasil dibuat.');
    }

    public function show(StockTransfer $stockTransfer)
    {
        $this->authorize('view', $stockTransfer);

        $stockTransfer->load(['fromBranch', 'toBranch', 'approvedBy', 'dispatchedBy', 'receivedBy', 'lines.sparepart']);

        return view('stock-transfers.show', compact('stockTransfer'));
    }

    public function edit(StockTransfer $stockTransfer)
    {
        $this->authorize('update', $stockTransfer);

        $stockTransfer->load('lines');
        $allBranches = Branch::where('is_active', true)->orderBy('name')->get();

        $spareparts = Sparepart::whereHas('sparepartBranches', function ($query) use ($stockTransfer) {
            $query->where('branch_id', $stockTransfer->from_branch_id)->where('is_active', true);
        })->get();
        $missingIds = $stockTransfer->lines->pluck('sparepart_id')->unique()->diff($spareparts->pluck('id'));
        if ($missingIds->isNotEmpty()) {
            $spareparts = $spareparts->concat(Sparepart::whereIn('id', $missingIds)->get());
        }

        $sparepartOptions = $spareparts->map(function (Sparepart $sparepart) {
            return ['id' => $sparepart->id, 'code' => $sparepart->code, 'name' => $sparepart->name];
        })->values();

        $existingLines = $stockTransfer->lines->map(function ($line) {
            return ['sparepart_id' => $line->sparepart_id, 'qty' => (float) $line->qty];
        })->values();

        return view('stock-transfers.edit', compact('stockTransfer', 'allBranches', 'sparepartOptions', 'existingLines'));
    }

    public function update(UpdateStockTransferRequest $request, StockTransfer $stockTransfer)
    {
        $data = $request->validated();

        $noLongerDraft = false;

        DB::transaction(function () use ($data, $stockTransfer, &$noLongerDraft) {
            $fresh = StockTransfer::whereKey($stockTransfer->id)->lockForUpdate()->first();
            if ($fresh->status !== TransferStatus::DRAFT) {
                $noLongerDraft = true;

                return;
            }

            $fresh->update([
                'to_branch_id' => $data['to_branch_id'],
                'transfer_date' => $data['transfer_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($fresh, $data['lines']);
        });

        if ($noLongerDraft) {
            return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock ini sudah tidak dalam status draft.');
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock berhasil diperbarui.');
    }

    public function sparepartsByBranch(Branch $branch)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('stock_transfer.create', $branch->id), 403);

        return response()->json(
            SparepartBranch::with('sparepart')
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->get()
                ->map(function (SparepartBranch $sb) {
                    return [
                        'id' => $sb->sparepart->id,
                        'code' => $sb->sparepart->code,
                        'name' => $sb->sparepart->name,
                        'on_hand_qty' => (float) $sb->stock->on_hand_qty,
                    ];
                })
                ->values()
        );
    }

    protected function syncLines(StockTransfer $stockTransfer, array $lines): void
    {
        $stockTransfer->lines()->delete();

        foreach (array_values(array_filter($lines)) as $index => $line) {
            StockTransferLine::create([
                'stock_transfer_id' => $stockTransfer->id,
                'sparepart_id' => $line['sparepart_id'],
                'qty' => (float) $line['qty'],
                'sort_order' => $index,
            ]);
        }
    }
}
```

Note: `sparepartsByBranch()` returns `id` as the SPAREPART's id (not the `SparepartBranch` row's id) — this is the one place in this module's controller where that distinction matters, since the line-item `<select>` needs to submit `sparepart_id`, unlike every other module's lookup endpoint which returns the `SparepartBranch` id.

- [ ] **Step 5: Add routes**

In `routes/web.php`, add a new group (placed near the `stock-adjustments` group, inside the same authenticated middleware block), and add the matching `use App\Http\Controllers\StockTransferController;` import at the top:

```php
    Route::prefix('stock-transfers')->name('stock-transfers.')->group(function () {
        Route::get('/lookup/spareparts/{branch}', [StockTransferController::class, 'sparepartsByBranch'])->name('lookup.spareparts');

        Route::get('/', [StockTransferController::class, 'index'])->name('index');
        Route::get('/create', [StockTransferController::class, 'create'])->name('create');
        Route::post('/', [StockTransferController::class, 'store'])->name('store');
        Route::get('/{stockTransfer}', [StockTransferController::class, 'show'])->name('show');
        Route::get('/{stockTransfer}/edit', [StockTransferController::class, 'edit'])->name('edit');
        Route::put('/{stockTransfer}', [StockTransferController::class, 'update'])->name('update');
    });
```

**The `/lookup/spareparts/{branch}` route MUST be registered before the `/{stockTransfer}` wildcard route.**

- [ ] **Step 6: Create the status badge partial**

Create `resources/views/stock-transfers/_status_badge.blade.php`:

```blade
@if ($status === \App\Support\TransferStatus::DRAFT)
    <span class="status-dot status-active">Draft</span>
@elseif ($status === \App\Support\TransferStatus::APPROVED)
    <span class="status-dot status-active">Disetujui</span>
@elseif ($status === \App\Support\TransferStatus::DISPATCHED)
    <span class="status-dot status-active">Dikirim</span>
@elseif ($status === \App\Support\TransferStatus::RECEIVED)
    <span class="status-dot status-active">Diterima</span>
@elseif ($status === \App\Support\TransferStatus::CANCELLED)
    <span class="status-dot status-inactive">Dibatalkan</span>
@else
    <span class="status-dot status-inactive">Status tidak dikenal</span>
@endif
```

Note this partial already applies the 008b-cleanup lesson from the start: an explicit `@elseif` for `CANCELLED` plus a genuinely distinct "unknown status" `@else`, not a silent catch-all.

- [ ] **Step 7: Create the line-item scripts partial**

Create `resources/views/stock-transfers/_line_item_scripts.blade.php`:

```blade
<template id="stockTransferLineTemplate">
    <div class="row g-2 align-items-start mb-2 stock-transfer-line">
        <div class="col-md-6">
            <select class="form-select stock-transfer-sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" class="form-control stock-transfer-on-hand-qty" readonly tabindex="-1">
        </div>
        <div class="col-md-3">
            <input type="number" step="0.001" min="0.001" class="form-control stock-transfer-qty" value="1">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-stock-transfer-line">&times;</button>
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
        const template = document.getElementById('stockTransferLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.stock-transfer-line');
        const index = lineCount++;
        const select = wrapper.querySelector('.stock-transfer-sparepart-select');
        select.name = `lines[${index}][sparepart_id]`;
        wrapper.querySelector('.stock-transfer-qty').name = `lines[${index}][qty]`;
        fillSelect(select, sparepartOptionsCache, '-- Pilih Sparepart --');

        select.addEventListener('change', function () {
            const selectedOption = select.options[select.selectedIndex];
            wrapper.querySelector('.stock-transfer-on-hand-qty').value = selectedOption ? (selectedOption.dataset.onHandQty || '0') : '';
        });

        wrapper.querySelector('.remove-stock-transfer-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('stockTransferLines').appendChild(wrapper);
    }

    document.getElementById('addStockTransferLine').addEventListener('click', addLine);

    window.StockTransferLineItems = {
        setSparepartOptions: function (items) {
            sparepartOptionsCache = items;
            document.querySelectorAll('.stock-transfer-sparepart-select').forEach(function (select) {
                const currentValue = select.value;
                fillSelect(select, items, '-- Pilih Sparepart --');
                select.value = currentValue;
                const selectedOption = select.options[select.selectedIndex];
                const row = select.closest('.stock-transfer-line');
                if (row && selectedOption && selectedOption.value) {
                    row.querySelector('.stock-transfer-on-hand-qty').value = selectedOption.dataset.onHandQty || '0';
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

The "Stok Tersedia" (`on_hand_qty`) field is readonly/display-only (no `name` attribute, never submitted) — purely a reference for the user showing current stock at the ORIGIN branch, same informational-only role as `system_qty` in migration 008b.

- [ ] **Step 8: Create the index/no-access/create/edit/show views**

Create `resources/views/stock-transfers/no-access.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Transfer Stock')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-arrow-left-right me-2"></i>Transfer Stock</h1>
    </div>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            Anda belum memiliki akses transfer stock di cabang manapun. Hubungi admin untuk meminta akses.
        </div>
    </div>
@endsection
```

Create `resources/views/stock-transfers/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Transfer Stock')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-arrow-left-right me-2"></i>Transfer Stock</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor transfer...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => auth()->user()->branchesWithPermission('stock_transfer.create')->isNotEmpty()
            ? '<a href="' . route('stock-transfers.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Transfer Baru</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nomor</th>
                        <th>Cabang Asal</th>
                        <th>Cabang Tujuan</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stockTransfers as $stockTransfer)
                        <tr>
                            <td><code>{{ $stockTransfer->number }}</code></td>
                            <td>{{ $stockTransfer->fromBranch->name }}</td>
                            <td>{{ $stockTransfer->toBranch->name }}</td>
                            <td>{{ $stockTransfer->transfer_date->format('d/m/Y') }}</td>
                            <td>@include('stock-transfers._status_badge', ['status' => $stockTransfer->status])</td>
                            <td class="text-end">
                                <a href="{{ route('stock-transfers.show', $stockTransfer) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-arrow-left-right',
                                    'title' => 'Belum ada transfer stock',
                                    'description' => 'Mulai dengan membuat transfer stock pertama.',
                                    'ctaRoute' => 'stock-transfers.create',
                                    'ctaLabel' => '+ Buat Transfer Pertama',
                                    'ctaVisible' => auth()->user()->branchesWithPermission('stock_transfer.create')->isNotEmpty(),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $stockTransfers->links() }}
    </div>
@endsection
```

Create `resources/views/stock-transfers/create.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Transfer Stock Baru')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-arrow-left-right me-2"></i>Transfer Stock Baru</h1>
    </div>
    <form method="POST" action="{{ route('stock-transfers.store') }}" id="stockTransferForm">
        @csrf
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Cabang Asal</label>
                        <select name="from_branch_id" id="fromBranchSelect" class="form-select @error('from_branch_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Cabang Asal --</option>
                            @foreach ($fromBranches as $branch)
                                <option value="{{ $branch->id }}" {{ (int) old('from_branch_id') === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('from_branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Cabang Tujuan</label>
                        <select name="to_branch_id" id="toBranchSelect" class="form-select @error('to_branch_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Cabang Tujuan --</option>
                            @foreach ($allBranches as $branch)
                                <option value="{{ $branch->id }}" {{ (int) old('to_branch_id') === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('to_branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tanggal Transfer</label>
                        <input type="date" name="transfer_date" value="{{ old('transfer_date', now()->format('Y-m-d')) }}" class="form-control @error('transfer_date') is-invalid @enderror" required>
                        @error('transfer_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addStockTransferLine" disabled>+ Tambah Sparepart</button>
                </div>
                <div class="row g-2 mb-1 text-muted small">
                    <div class="col-md-6">Sparepart</div>
                    <div class="col-md-2">Stok Tersedia</div>
                    <div class="col-md-3">Qty</div>
                </div>
                <div id="stockTransferLines"></div>
                @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('stock-transfers.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('stock-transfers._line_item_scripts')

    @php($oldLines = old('lines', []))
    @push('scripts')
    <script>
    (function () {
        const fromBranchSelect = document.getElementById('fromBranchSelect');
        const addButton = document.getElementById('addStockTransferLine');

        async function handleFromBranchChange(branchId) {
            addButton.disabled = true;
            if (!branchId) {
                return;
            }
            const spareparts = await StockTransferLineItems.fetchJson(`/stock-transfers/lookup/spareparts/${branchId}`);
            StockTransferLineItems.setSparepartOptions(spareparts);
            addButton.disabled = false;
        }

        fromBranchSelect.addEventListener('change', function () {
            handleFromBranchChange(this.value);
        });

        // Validation-error round-trip: replay the line rows submitted before the
        // failed validation. Built in from the start — this exact gap was an
        // Important finding in the sibling Goods Receipt module's final review.
        function replayOldLines() {
            const oldLines = @json($oldLines);
            oldLines.forEach(function (line) {
                StockTransferLineItems.addLine();
                const rows = document.querySelectorAll('#stockTransferLines .stock-transfer-line');
                const row = rows[rows.length - 1];
                if (line.sparepart_id) {
                    const select = row.querySelector('.stock-transfer-sparepart-select');
                    select.value = line.sparepart_id;
                    select.dispatchEvent(new Event('change'));
                }
                row.querySelector('.stock-transfer-qty').value = line.qty || '';
            });
        }

        // Validation-error round-trip: old('from_branch_id') re-selects the branch
        // option but does not fire a native `change` event, so the sparepart
        // cascade and add-line button would otherwise stay empty/disabled.
        if (fromBranchSelect.value) {
            handleFromBranchChange(fromBranchSelect.value).then(replayOldLines);
        } else {
            replayOldLines();
        }
    })();
    </script>
    @endpush
@endsection
```

Create `resources/views/stock-transfers/edit.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Ubah Transfer Stock')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-arrow-left-right me-2"></i>Ubah {{ $stockTransfer->number }}</h1>
    </div>
    <form method="POST" action="{{ route('stock-transfers.update', $stockTransfer) }}" id="stockTransferForm">
        @csrf
        @method('PUT')
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Cabang Asal</label>
                        <input type="text" class="form-control" value="{{ $stockTransfer->fromBranch->name }}" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Cabang Tujuan</label>
                        <select name="to_branch_id" class="form-select @error('to_branch_id') is-invalid @enderror" required>
                            @foreach ($allBranches as $branch)
                                <option value="{{ $branch->id }}" {{ (int) old('to_branch_id', $stockTransfer->to_branch_id) === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('to_branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tanggal Transfer</label>
                        <input type="date" name="transfer_date" value="{{ old('transfer_date', $stockTransfer->transfer_date->format('Y-m-d')) }}" class="form-control @error('transfer_date') is-invalid @enderror" required>
                        @error('transfer_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes', $stockTransfer->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Sparepart</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addStockTransferLine">+ Tambah Sparepart</button>
                </div>
                <div class="row g-2 mb-1 text-muted small">
                    <div class="col-md-6">Sparepart</div>
                    <div class="col-md-2">Stok Tersedia</div>
                    <div class="col-md-3">Qty</div>
                </div>
                <div id="stockTransferLines"></div>
                @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('stock-transfers.show', $stockTransfer) }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('stock-transfers._line_item_scripts')

    @push('scripts')
    <script>
    (function () {
        const existingSparepartOptions = @json($sparepartOptions);
        StockTransferLineItems.setSparepartOptions(existingSparepartOptions);

        const existingLines = @json($existingLines);
        existingLines.forEach(function (line) {
            StockTransferLineItems.addLine();
            const rows = document.querySelectorAll('#stockTransferLines .stock-transfer-line');
            const row = rows[rows.length - 1];
            const select = row.querySelector('.stock-transfer-sparepart-select');
            select.value = line.sparepart_id;
            select.dispatchEvent(new Event('change'));
            row.querySelector('.stock-transfer-qty').value = line.qty;
        });
    })();
    </script>
    @endpush
@endsection
```

Note: `edit.blade.php`'s sparepart lookup options come from the `$sparepartOptions` computed by the controller's `edit()` method (scoped to spareparts configured at the ORIGIN branch, since `from_branch_id` never changes) — NOT from a fresh AJAX call keyed to a branch selector, since the origin branch is fixed and shown as a disabled text field here, not a `<select>`. Both `@json(...)` calls are bare, comma-free variable references.

Create `resources/views/stock-transfers/show.blade.php` (Task 4 will add lifecycle action buttons alongside a future "Ubah" button — do not add them yet):

```blade
@extends('layouts.app')
@section('title', 'Detail Transfer Stock')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-arrow-left-right me-2"></i>{{ $stockTransfer->number }}</h1>
        <div class="d-flex gap-2">
            @can('update', $stockTransfer)
                <a href="{{ route('stock-transfers.edit', $stockTransfer) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><strong>Cabang Asal</strong><div>{{ $stockTransfer->fromBranch->name }}</div></div>
                <div class="col-md-3"><strong>Cabang Tujuan</strong><div>{{ $stockTransfer->toBranch->name }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $stockTransfer->transfer_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>@include('stock-transfers._status_badge', ['status' => $stockTransfer->status])</div>
                </div>
                <div class="col-md-4">
                    <strong>Disetujui</strong>
                    <div>
                        @if ($stockTransfer->approved_at)
                            {{ $stockTransfer->approvedBy->name ?? '-' }} pada {{ $stockTransfer->approved_at->format('d/m/Y H:i') }}
                        @else
                            -
                        @endif
                    </div>
                </div>
                <div class="col-md-4">
                    <strong>Dikirim</strong>
                    <div>
                        @if ($stockTransfer->dispatched_at)
                            {{ $stockTransfer->dispatchedBy->name ?? '-' }} pada {{ $stockTransfer->dispatched_at->format('d/m/Y H:i') }}
                        @else
                            -
                        @endif
                    </div>
                </div>
                <div class="col-md-4">
                    <strong>Diterima</strong>
                    <div>
                        @if ($stockTransfer->received_at)
                            {{ $stockTransfer->receivedBy->name ?? '-' }} pada {{ $stockTransfer->received_at->format('d/m/Y H:i') }}
                        @else
                            -
                        @endif
                    </div>
                </div>
                <div class="col-md-12"><strong>Catatan</strong><div>{{ $stockTransfer->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Baris Sparepart</h2>
            <table class="table table-sm">
                <thead><tr><th>Kode</th><th>Nama</th><th>Qty</th></tr></thead>
                <tbody>
                    @forelse ($stockTransfer->lines as $line)
                        <tr>
                            <td><code>{{ $line->sparepart->code }}</code></td>
                            <td>{{ $line->sparepart->name }}</td>
                            <td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-muted">Tidak ada baris sparepart.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <a href="{{ route('stock-transfers.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test --filter=StockTransferManagementTest`
Expected: 18 passed.

- [ ] **Step 10: Run the full suite**

Run: `php artisan test`
Expected: 497 passed (479 baseline + 18 new).

- [ ] **Step 11: Commit**

```bash
git add app/Http/Requests/StoreStockTransferRequest.php app/Http/Requests/UpdateStockTransferRequest.php app/Http/Controllers/StockTransferController.php resources/views/stock-transfers routes/web.php tests/Feature/StockTransferManagementTest.php
git commit -m "feat: implement stock transfer CRUD (create/edit/list/detail)"
```

---

### Task 4: Lifecycle actions — approve, dispatch, receive, cancel

**Files:**
- Modify: `app/Http/Controllers/StockTransferController.php` (add `approve`/`dispatch`/`receive`/`cancel`)
- Modify: `routes/web.php` (add 4 routes to the existing `stock-transfers` group)
- Modify: `resources/views/stock-transfers/show.blade.php` (add the 4 conditional action buttons)
- Test: `tests/Feature/StockTransferManagementTest.php` (extend with lifecycle tests)

**Interfaces:**
- Consumes: `StockTransferPolicy::approve/dispatch/receive/cancel` (Task 2); `SparepartBranch`/`SparepartBranchStock`, `InventoryMovement`, `InventoryMovementType::TRANSFER_OUT`/`TRANSFER_IN` (Task 1/existing).
- Produces: routes `stock-transfers.approve/dispatch/receive/cancel`. Nothing later in this plan consumes these beyond Task 5's sidebar link, which only needs `stock-transfers.index`.

- [ ] **Step 1: Write the failing lifecycle tests**

Append these test methods to `tests/Feature/StockTransferManagementTest.php` (inside the existing class, before the final closing `}`):

```php
    public function test_approve_moves_draft_to_approved(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $approver = User::factory()->create();
        $this->grantBranchPermission($approver, $from, 'stock_transfer.approve');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs(User::find($approver->id))->patch("/stock-transfers/{$stockTransfer->id}/approve");

        $response->assertRedirect(route('stock-transfers.show', $stockTransfer));
        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::APPROVED, $stockTransfer->status);
        $this->assertSame($approver->id, $stockTransfer->approved_by);
        $this->assertNotNull($stockTransfer->approved_at);
    }

    public function test_approve_is_forbidden_without_stock_transfer_approve_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/approve");

        $response->assertForbidden();
    }

    public function test_approve_is_forbidden_for_a_non_draft_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/approve");

        $response->assertForbidden();
    }

    public function test_dispatch_decreases_origin_stock_and_writes_transfer_out_movement(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $fromSparepartBranch = SparepartBranch::where('sparepart_id', $sparepart->id)->where('branch_id', $from->id)->first();
        $fromSparepartBranch->stock()->update(['on_hand_qty' => 20]);
        $dispatcher = User::factory()->create();
        $this->grantBranchPermission($dispatcher, $from, 'stock_transfer.dispatch');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);
        \App\Models\StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 8]);

        $response = $this->actingAs(User::find($dispatcher->id))->patch("/stock-transfers/{$stockTransfer->id}/dispatch");

        $response->assertRedirect(route('stock-transfers.show', $stockTransfer));
        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::DISPATCHED, $stockTransfer->status);
        $this->assertNotNull($stockTransfer->dispatched_by);
        $fromStock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $fromSparepartBranch->id)->first();
        $this->assertSame(12.0, (float) $fromStock->on_hand_qty);
        $movement = \DB::table('inventory_movements')->where('sparepart_branch_id', $fromSparepartBranch->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame('transfer_out', $movement->movement_type);
        $this->assertSame(8.0, (float) $movement->qty_out);
        $this->assertSame(12.0, (float) $movement->balance_after);
    }

    public function test_dispatch_rejects_the_whole_batch_when_any_line_violates_reserved_qty_at_origin(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $okSparepart = $this->makeSparepartAtBranches($from, $to, '-ok');
        $badSparepart = $this->makeSparepartAtBranches($from, $to, '-bad');
        $okFromStock = SparepartBranch::where('sparepart_id', $okSparepart->id)->where('branch_id', $from->id)->first();
        $okFromStock->stock()->update(['on_hand_qty' => 20]);
        $badFromStock = SparepartBranch::where('sparepart_id', $badSparepart->id)->where('branch_id', $from->id)->first();
        $badFromStock->stock()->update(['on_hand_qty' => 10, 'reserved_qty' => 8]);
        $dispatcher = User::factory()->create();
        $this->grantBranchPermission($dispatcher, $from, 'stock_transfer.dispatch');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);
        \App\Models\StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $okSparepart->id, 'qty' => 5, 'sort_order' => 0]);
        \App\Models\StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $badSparepart->id, 'qty' => 5, 'sort_order' => 1]);

        $response = $this->actingAs(User::find($dispatcher->id))->patch("/stock-transfers/{$stockTransfer->id}/dispatch");

        $response->assertSessionHas('error', function ($message) {
            return str_contains($message, 'OLI-01-bad');
        });
        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::APPROVED, $stockTransfer->status, 'A rejected dispatch must leave the document APPROVED.');
        $okStock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $okFromStock->id)->first();
        $this->assertSame(20.0, (float) $okStock->on_hand_qty, 'The valid line must not be dispatched either — all-or-nothing.');
        $this->assertSame(0, \DB::table('inventory_movements')->count());
    }

    public function test_dispatch_is_forbidden_without_stock_transfer_dispatch_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/dispatch");

        $response->assertForbidden();
    }

    public function test_dispatch_is_forbidden_for_a_draft_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.dispatch');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/dispatch");

        $response->assertForbidden();
    }

    public function test_receive_increases_destination_stock_and_writes_transfer_in_movement(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $toSparepartBranch = SparepartBranch::where('sparepart_id', $sparepart->id)->where('branch_id', $to->id)->first();
        $toSparepartBranch->stock()->update(['on_hand_qty' => 3]);
        $receiver = User::factory()->create();
        $this->grantBranchPermission($receiver, $to, 'stock_transfer.receive');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::DISPATCHED,
        ]);
        \App\Models\StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 8]);

        $response = $this->actingAs(User::find($receiver->id))->patch("/stock-transfers/{$stockTransfer->id}/receive");

        $response->assertRedirect(route('stock-transfers.show', $stockTransfer));
        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::RECEIVED, $stockTransfer->status);
        $this->assertNotNull($stockTransfer->received_by);
        $toStock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $toSparepartBranch->id)->first();
        $this->assertSame(11.0, (float) $toStock->on_hand_qty);
        $movement = \DB::table('inventory_movements')->where('sparepart_branch_id', $toSparepartBranch->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame('transfer_in', $movement->movement_type);
        $this->assertSame(8.0, (float) $movement->qty_in);
        $this->assertSame(11.0, (float) $movement->balance_after);
    }

    public function test_receive_rejects_when_destination_sparepart_branch_was_deactivated_after_dispatch(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = $this->makeSparepartAtBranches($from, $to);
        $toSparepartBranch = SparepartBranch::where('sparepart_id', $sparepart->id)->where('branch_id', $to->id)->first();
        $toSparepartBranch->update(['is_active' => false]);
        $receiver = User::factory()->create();
        $this->grantBranchPermission($receiver, $to, 'stock_transfer.receive');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::DISPATCHED,
        ]);
        \App\Models\StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 8]);

        $response = $this->actingAs(User::find($receiver->id))->patch("/stock-transfers/{$stockTransfer->id}/receive");

        $response->assertSessionHas('error');
        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::DISPATCHED, $stockTransfer->status, 'A rejected receive must leave the document DISPATCHED.');
        $this->assertSame(0, \DB::table('inventory_movements')->count());
    }

    public function test_receive_is_forbidden_without_stock_transfer_receive_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.view');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::DISPATCHED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/receive");

        $response->assertForbidden();
    }

    public function test_receive_is_forbidden_for_an_approved_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.receive');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/receive");

        $response->assertForbidden();
    }

    public function test_cancel_from_draft_sets_cancelled_with_no_stock_impact(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.cancel');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/cancel");

        $response->assertRedirect(route('stock-transfers.show', $stockTransfer));
        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::CANCELLED, $stockTransfer->status);
    }

    public function test_cancel_from_approved_sets_cancelled_with_no_stock_impact(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.cancel');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/cancel");

        $stockTransfer->refresh();
        $this->assertSame(TransferStatus::CANCELLED, $stockTransfer->status);
    }

    public function test_cancel_is_forbidden_for_a_dispatched_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.cancel');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::DISPATCHED,
        ]);

        $response = $this->actingAs(User::find($user->id))->patch("/stock-transfers/{$stockTransfer->id}/cancel");

        $response->assertForbidden();
    }

    public function test_show_renders_approve_button_for_a_draft_transfer_with_approve_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-transfers/{$stockTransfer->id}");

        $response->assertOk();
        $response->assertSee(route('stock-transfers.approve', $stockTransfer), false);
    }

    public function test_show_renders_dispatch_button_for_an_approved_transfer_with_dispatch_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $this->grantBranchPermission($user, $from, 'stock_transfer.dispatch');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::APPROVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-transfers/{$stockTransfer->id}");

        $response->assertOk();
        $response->assertSee(route('stock-transfers.dispatch', $stockTransfer), false);
    }

    public function test_show_renders_receive_button_for_a_dispatched_transfer_with_receive_permission(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $to, 'stock_transfer.view');
        $this->grantBranchPermission($user, $to, 'stock_transfer.receive');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::DISPATCHED,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-transfers/{$stockTransfer->id}");

        $response->assertOk();
        $response->assertSee(route('stock-transfers.receive', $stockTransfer), false);
    }

    public function test_show_hides_all_action_buttons_for_a_received_transfer(): void
    {
        $from = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $to = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $from, 'stock_transfer.view');
        $this->grantBranchPermission($user, $from, 'stock_transfer.create');
        $this->grantBranchPermission($user, $from, 'stock_transfer.approve');
        $this->grantBranchPermission($user, $from, 'stock_transfer.dispatch');
        $this->grantBranchPermission($user, $from, 'stock_transfer.cancel');
        $this->grantBranchPermission($user, $to, 'stock_transfer.receive');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/JKT/202608/00001', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::RECEIVED,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-transfers/{$stockTransfer->id}");

        $response->assertOk();
        $response->assertDontSee(route('stock-transfers.approve', $stockTransfer), false);
        $response->assertDontSee(route('stock-transfers.dispatch', $stockTransfer), false);
        $response->assertDontSee(route('stock-transfers.receive', $stockTransfer), false);
        $response->assertDontSee(route('stock-transfers.cancel', $stockTransfer), false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockTransferManagementTest`
Expected: the 18 pre-existing tests still PASS, the 18 new ones FAIL (routes/methods don't exist yet).

- [ ] **Step 3: Add the lifecycle routes**

In `routes/web.php`, extend the existing `stock-transfers` group from Task 3 by adding these 4 lines inside it (after the `update` route):

```php
        Route::patch('/{stockTransfer}/approve', [StockTransferController::class, 'approve'])->name('approve');
        Route::patch('/{stockTransfer}/dispatch', [StockTransferController::class, 'dispatch'])->name('dispatch');
        Route::patch('/{stockTransfer}/receive', [StockTransferController::class, 'receive'])->name('receive');
        Route::patch('/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->name('cancel');
```

- [ ] **Step 4: Add the lifecycle controller methods**

In `app/Http/Controllers/StockTransferController.php`, add these imports at the top (alongside the existing ones):

```php
use App\Models\InventoryMovement;
use App\Models\SparepartBranchStock;
use App\Support\InventoryMovementType;
```

Add these 4 methods to the class (place them after `update()` and before `sparepartsByBranch()`):

```php
    public function approve(StockTransfer $stockTransfer)
    {
        $this->authorize('approve', $stockTransfer);

        $noLongerDraft = false;

        DB::transaction(function () use ($stockTransfer, &$noLongerDraft) {
            $fresh = StockTransfer::whereKey($stockTransfer->id)->lockForUpdate()->first();
            if ($fresh->status !== TransferStatus::DRAFT) {
                $noLongerDraft = true;

                return;
            }

            $fresh->status = TransferStatus::APPROVED;
            $fresh->approved_by = auth()->id();
            $fresh->approved_at = now();
            $fresh->save();
        });

        if ($noLongerDraft) {
            return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock ini sudah tidak dalam status draft.');
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock berhasil disetujui.');
    }

    public function dispatch(StockTransfer $stockTransfer)
    {
        $this->authorize('dispatch', $stockTransfer);

        $noLongerApproved = false;
        $reservationViolations = [];

        DB::transaction(function () use ($stockTransfer, &$noLongerApproved, &$reservationViolations) {
            $fresh = StockTransfer::whereKey($stockTransfer->id)->lockForUpdate()->first();
            if ($fresh->status !== TransferStatus::APPROVED) {
                $noLongerApproved = true;

                return;
            }

            $lines = $fresh->lines()->reorder()->orderBy('sparepart_id')->with('sparepart')->get();

            // Pass 1: resolve and lock every line's ORIGIN stock row, validate qty against the
            // CURRENT reserved_qty before mutating anything — same two-pass all-or-nothing
            // pattern already proven in migration 008b's StockAdjustmentController::post().
            $lockedStocks = [];
            foreach ($lines as $line) {
                $sparepartBranch = SparepartBranch::where('sparepart_id', $line->sparepart_id)
                    ->where('branch_id', $fresh->from_branch_id)
                    ->where('is_active', true)
                    ->first();

                if (! $sparepartBranch) {
                    $reservationViolations[] = sprintf('%s sudah tidak dikonfigurasi atau tidak aktif di cabang asal', $line->sparepart->code);

                    continue;
                }

                $stock = SparepartBranchStock::where('sparepart_branch_id', $sparepartBranch->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedStocks[$line->id] = $stock;

                $qty = (float) $line->qty;
                $onHandQty = (float) $stock->on_hand_qty;
                $reservedQty = (float) $stock->reserved_qty;

                if (($onHandQty - $qty) < $reservedQty) {
                    $reservationViolations[] = sprintf(
                        '%s: stok %s dikurangi %s akan turun di bawah reservasi %s',
                        $line->sparepart->code,
                        $this->formatQtyForMessage($onHandQty),
                        $this->formatQtyForMessage($qty),
                        $this->formatQtyForMessage($reservedQty)
                    );
                }
            }

            if (! empty($reservationViolations)) {
                return;
            }

            // Pass 2: mutate. Safe now that pass 1 confirmed every line's origin stock exists
            // and won't drop below its reserved_qty.
            foreach ($lines as $line) {
                $stock = $lockedStocks[$line->id];
                $qty = (float) $line->qty;

                $stock->on_hand_qty = (float) $stock->on_hand_qty - $qty;
                $stock->save();

                InventoryMovement::create([
                    'movement_at' => now(),
                    'branch_id' => $fresh->from_branch_id,
                    'sparepart_branch_id' => $stock->sparepart_branch_id,
                    'movement_type' => InventoryMovementType::TRANSFER_OUT,
                    'qty_in' => 0,
                    'qty_out' => $qty,
                    'balance_after' => $stock->on_hand_qty,
                    'reference_type' => 'stock_transfer_line',
                    'reference_id' => $line->id,
                    'created_by' => auth()->id(),
                ]);
            }

            $fresh->status = TransferStatus::DISPATCHED;
            $fresh->dispatched_by = auth()->id();
            $fresh->dispatched_at = now();
            $fresh->save();
        });

        if ($noLongerApproved) {
            return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock ini sudah tidak dalam status disetujui.');
        }

        if (! empty($reservationViolations)) {
            $message = 'Tidak bisa mengirim: ' . implode('; ', $reservationViolations) . '.';

            return redirect()->route('stock-transfers.show', $stockTransfer)->with('error', $message);
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock berhasil dikirim.');
    }

    public function receive(StockTransfer $stockTransfer)
    {
        $this->authorize('receive', $stockTransfer);

        $noLongerDispatched = false;
        $configViolations = [];

        DB::transaction(function () use ($stockTransfer, &$noLongerDispatched, &$configViolations) {
            $fresh = StockTransfer::whereKey($stockTransfer->id)->lockForUpdate()->first();
            if ($fresh->status !== TransferStatus::DISPATCHED) {
                $noLongerDispatched = true;

                return;
            }

            $lines = $fresh->lines()->reorder()->orderBy('sparepart_id')->with('sparepart')->get();

            // Pass 1: resolve and lock every line's DESTINATION stock row. A sparepart's
            // SparepartBranch config at the destination could have been deactivated between
            // dispatch and receive — validated here, all-or-nothing, before mutating anything.
            $lockedStocks = [];
            foreach ($lines as $line) {
                $sparepartBranch = SparepartBranch::where('sparepart_id', $line->sparepart_id)
                    ->where('branch_id', $fresh->to_branch_id)
                    ->where('is_active', true)
                    ->first();

                if (! $sparepartBranch) {
                    $configViolations[] = sprintf('%s sudah tidak dikonfigurasi atau tidak aktif di cabang tujuan', $line->sparepart->code);

                    continue;
                }

                $lockedStocks[$line->id] = SparepartBranchStock::where('sparepart_branch_id', $sparepartBranch->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            if (! empty($configViolations)) {
                return;
            }

            // Pass 2: mutate.
            foreach ($lines as $line) {
                $stock = $lockedStocks[$line->id];
                $qty = (float) $line->qty;

                $stock->on_hand_qty = (float) $stock->on_hand_qty + $qty;
                $stock->save();

                InventoryMovement::create([
                    'movement_at' => now(),
                    'branch_id' => $fresh->to_branch_id,
                    'sparepart_branch_id' => $stock->sparepart_branch_id,
                    'movement_type' => InventoryMovementType::TRANSFER_IN,
                    'qty_in' => $qty,
                    'qty_out' => 0,
                    'balance_after' => $stock->on_hand_qty,
                    'reference_type' => 'stock_transfer_line',
                    'reference_id' => $line->id,
                    'created_by' => auth()->id(),
                ]);
            }

            $fresh->status = TransferStatus::RECEIVED;
            $fresh->received_by = auth()->id();
            $fresh->received_at = now();
            $fresh->save();
        });

        if ($noLongerDispatched) {
            return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock ini sudah tidak dalam status dikirim.');
        }

        if (! empty($configViolations)) {
            $message = 'Tidak bisa menerima: ' . implode('; ', $configViolations) . '.';

            return redirect()->route('stock-transfers.show', $stockTransfer)->with('error', $message);
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock berhasil diterima.');
    }

    public function cancel(StockTransfer $stockTransfer)
    {
        $this->authorize('cancel', $stockTransfer);

        $noLongerCancellable = false;

        DB::transaction(function () use ($stockTransfer, &$noLongerCancellable) {
            $fresh = StockTransfer::whereKey($stockTransfer->id)->lockForUpdate()->first();
            $cancellableStatuses = [TransferStatus::DRAFT, TransferStatus::APPROVED];
            if (! in_array($fresh->status, $cancellableStatuses, true)) {
                $noLongerCancellable = true;

                return;
            }

            $fresh->status = TransferStatus::CANCELLED;
            $fresh->save();
        });

        if ($noLongerCancellable) {
            return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock ini sudah tidak bisa dibatalkan.');
        }

        return redirect()->route('stock-transfers.show', $stockTransfer)->with('status', 'Transfer stock berhasil dibatalkan.');
    }

    protected function formatQtyForMessage(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }
```

Note: `formatQtyForMessage()` mirrors the exact helper already used in `StockAdjustmentController` — if this project later extracts shared controller helpers into a trait, that refactor is out of scope for this plan.

- [ ] **Step 5: Add the action buttons to `show.blade.php`**

In `resources/views/stock-transfers/show.blade.php`, extend the `<div class="d-flex gap-2">` block (which currently only contains the "Ubah" button from Task 3) to also include:

```blade
            @can('approve', $stockTransfer)
                <form method="POST" action="{{ route('stock-transfers.approve', $stockTransfer) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Setujui</button>
                </form>
            @endcan
            @can('dispatch', $stockTransfer)
                <form method="POST" action="{{ route('stock-transfers.dispatch', $stockTransfer) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Kirim</button>
                </form>
            @endcan
            @can('receive', $stockTransfer)
                <form method="POST" action="{{ route('stock-transfers.receive', $stockTransfer) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Terima</button>
                </form>
            @endcan
            @can('cancel', $stockTransfer)
                <form method="POST" action="{{ route('stock-transfers.cancel', $stockTransfer) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-outline-danger btn-sm">Batalkan</button>
                </form>
            @endcan
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=StockTransferManagementTest`
Expected: 36 passed.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: 515 passed (497 baseline + 18 new).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/StockTransferController.php routes/web.php resources/views/stock-transfers/show.blade.php tests/Feature/StockTransferManagementTest.php
git commit -m "feat: implement stock transfer approve/dispatch/receive/cancel lifecycle"
```

---

### Task 5: Sidebar wiring and full-suite verification

**Files:**
- Modify: `resources/views/partials/sidebar.blade.php`
- Test: `tests/Feature/AppShellTest.php` (extend or add, depending on what already exists — see Step 1)

**Interfaces:**
- Consumes: `route('stock-transfers.index')` (Task 3).
- Produces: nothing consumed by later tasks — this is the final task in the plan, and the final task of the entire migration 008 decomposition.

- [ ] **Step 1: Check for an existing positive placeholder test, write one if missing**

Search `tests/Feature/AppShellTest.php` for a test asserting the "Transfer Stock" sidebar item is VISIBLE when a user has `stock_transfer.view` granted. **Do not assume it exists** — this exact assumption was wrong for both the Goods Receipt (008a) and Stock Adjustment (008b) sidebar tasks; only a negative "hides all placeholders without any permission" test existed each time. If the same is true here, write the missing positive test yourself, modeled on the sibling tests already in this file: grant `stock_transfer.view` in some branch, hit a page that renders the sidebar (e.g. `/dashboard`), and assert both:
```php
$response->assertSee('Transfer Stock', false);
$response->assertSee(route('stock-transfers.index'), false);
```
The second assertion is the one that actually pins this to a real link rather than matching stale placeholder text — do not skip it.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AppShellTest`
Expected: the relevant test FAILS (the route URL doesn't appear yet — the placeholder is still a disabled `<span>`).

- [ ] **Step 3: Swap the sidebar placeholder**

In `resources/views/partials/sidebar.blade.php`, find the block:

```blade
        @if ($user->branchesWithPermission('stock_transfer.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-arrow-left-right me-2"></i> Transfer Stock
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
```

Replace with:

```blade
        @if ($user->branchesWithPermission('stock_transfer.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('stock-transfers.index') }}" class="nav-link {{ request()->routeIs('stock-transfers.*') ? 'active' : '' }}">
                <i class="bi bi-arrow-left-right me-2"></i> Transfer Stock
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
Expected: all tests PASS — 515 or 516 depending on whether Step 1 added a new test method or extended an existing one.

Run: `grep -rn "Belum ada transfer stock\|Buat Transfer Pertama\|Transfer Baru\|Cari nomor transfer" tests/Feature/AppShellTest.php tests/Feature/DashboardTest.php`
Expected: no unexpected matches beyond the "Transfer Stock" occurrence already reviewed in Step 1.

- [ ] **Step 6: Commit**

```bash
git add resources/views/partials/sidebar.blade.php tests/Feature/AppShellTest.php
git commit -m "feat: wire up Transfer Stock sidebar link"
```

---

## Self-Review Notes

- **Spec coverage:** every in-scope item from the design spec is covered — data model (Task 1), Policy (Task 2), CRUD controller/FormRequests/views (Task 3), lifecycle actions/dispatch-receive math (Task 4), sidebar wiring (Task 5). This closes out the entire migration 008 decomposition (008a Goods Receipt → 008b Stock Adjustment → 008c Transfer Stock).
- **Placeholder scan:** none found — every code block is complete and copy-ready.
- **Type consistency:** `StockTransfer`/`StockTransferLine` field and relation names (`fromBranch()`, `toBranch()`, `approvedBy()`, `dispatchedBy()`, `receivedBy()`, `lines()`, `stockTransfer()`, `sparepart()`) introduced in Task 1 are used identically in every later task. `TransferStatus`/`InventoryMovementType` constants referenced the same way everywhere.
- **Scope check:** 5 tasks, matching migration 008b's shape — this module's lifecycle (4 status-changing actions after create) is comparable in complexity to 008b's.
- **Concurrency discipline:** every status-changing action locks the header row and re-verifies status inside its transaction from the first draft. `dispatch()`'s reserved_qty guard is a genuine two-pass all-or-nothing implementation, applying the exact lesson migration 008b had to learn as a Critical bug during review — built in here from Task 4's first draft instead. `dispatch()` and `receive()` are deliberately two separate transactions (never one spanning both branches), which means this module never needs cross-branch lock ordering reasoning the way a hypothetical single-transaction design would.
- **Cross-module interaction:** `dispatch()`'s reserved_qty check directly parallels migration 008b's `post()` fix — both guard against a sparepart's `on_hand_qty` dropping below its `reserved_qty` (written by the PKB reservation module). `receive()` never needs an equivalent guard since incrementing `on_hand_qty` can never violate `reserved_qty <= on_hand_qty`.
- **`@json()` safety:** all `@json(` call sites in this plan (`create.blade.php`'s `$oldLines`, `edit.blade.php`'s `$sparepartOptions`/`$existingLines`) are bare, comma-free variable references.
- **008b-cleanup lessons applied proactively**: the status badge partial already has an explicit `CANCELLED` branch (not a silent `@else` catch-all) and a genuinely distinct "unknown status" fallback from its first draft; `dispatch()`'s and `receive()`'s rejection messages use the `session('error')`/`alert-danger` convention from the start, not `session('status')`.
