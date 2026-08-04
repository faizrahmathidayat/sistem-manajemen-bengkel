# Migrasi 007 — Reservasi Stok PKB Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add real stock reservation to PKB confirmation — `WorkOrderController::confirm()` locks and reserves sparepart stock (partial reservation when short), `overrideShortage()` records an acknowledged-shortage reason, and `cancel()` releases any active reservations. Extends migration 006's already-shipped `WorkOrder`/`WorkOrderPolicy`/`WorkOrderStatus` rather than building a new module.

**Architecture:** New `inventory_reservations` table, one row per `work_order_sparepart_line` (via a lightweight polymorphic-style `reference_type`/`reference_id` pair, not a DB foreign key to a specific table — mirrors the existing `attachments` pattern). `WorkOrderStatus` gains `OPEN`/`SHORTAGE`. `WorkOrderPolicy` gains `confirm()`/`overrideShortage()` and `cancel()` is loosened from `DRAFT`-only to `DRAFT`/`OPEN`/`SHORTAGE`. All stock math happens inside `DB::transaction()` blocks with `lockForUpdate()` on the relevant `sparepart_branch_stocks` row(s), consistent ordering (`orderBy('sparepart_branch_id')`) to avoid deadlocks between concurrent PKB confirmations.

**Tech Stack:** Laravel 8.75, PHP 7.4.33 (no PHP 8 syntax), MySQL 8.0, PHPUnit feature tests (`RefreshDatabase`).

## Global Constraints

- PHP runtime is 7.4.33 — never use PHP 8-only syntax (nullsafe `?->`, named arguments, match expressions, enums, constructor property promotion, union types), including inside Blade `@php()` blocks.
- `bigint` PKs, never UUID. `->simplePaginate()` only where lists exist (no new list screens in this plan).
- `WorkOrderPolicy::update()` is **unchanged** — stays `DRAFT`-only. A PKB is locked from editing the moment it leaves `DRAFT` (confirmed design decision — no "un-confirm back to DRAFT" flow exists or is planned).
- Override-shortage requires only the `pkb.override_stock_shortage` permission code — no separate approval workflow, no new `PENDING_APPROVAL`-style state.
- `sparepart_branch_stocks.on_hand_qty` is never written by this plan — it stays read-only until migration 008 (goods receipt). Every test that needs non-zero stock seeds `on_hand_qty` directly via `DB::table('sparepart_branch_stocks')->update([...])`, mirroring the existing Sparepart test suite's pattern.
- No `InventoryReservation` row is ever created with `qty <= 0` — `confirm()` only calls `InventoryReservation::create()` when `$reserveQty > 0`.
- All reservation/stock-mutation code paths run inside `DB::transaction()` with `lockForUpdate()` on the specific `sparepart_branch_stocks` row(s) touched, locked in a consistent order (`orderBy('sparepart_branch_id')`) across every code path that might lock more than one row in the same transaction.

---

### Task 1: Data model — migrations, `InventoryReservation` model, `WorkOrderStatus`/`WorkOrder` extensions

**Files:**
- Create: `database/migrations/2026_08_04_000001_create_inventory_reservations_table.php`
- Create: `database/migrations/2026_08_04_000002_add_shortage_columns_to_work_orders_table.php`
- Create: `app/Models/InventoryReservation.php`
- Modify: `app/Support/WorkOrderStatus.php`
- Modify: `app/Models/WorkOrder.php`
- Modify: `app/Models/WorkOrderSparepartLine.php`
- Test: `tests/Feature/InventoryReservationModelTest.php`

**Interfaces:**
- Produces: `InventoryReservation` (fields: `branch_id`, `sparepart_branch_id`, `reservation_type`, `reference_type`, `reference_id`, `qty`, `status`, `created_by`; relations `branch()`, `sparepartBranch()`); `WorkOrderStatus::OPEN` (`'open'`), `WorkOrderStatus::SHORTAGE` (`'shortage'`) alongside the existing `DRAFT`/`CANCELLED`; `WorkOrder`'s 3 new nullable columns (`shortage_override_reason`, `shortage_overridden_by`, `shortage_overridden_at`) plus a `shortageOverriddenBy()` `belongsTo(User::class, 'shortage_overridden_by')` relation; `WorkOrderSparepartLine::reservations()` — a `hasMany(InventoryReservation::class, 'reference_id')->where('reference_type', 'work_order_sparepart_line')` relation. Every later task that queries a line's reservations must go through this relation, not a raw query.

- [ ] **Step 1: Write the failing model tests**

Create `tests/Feature/InventoryReservationModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\InventoryReservation;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Models\WorkOrderSparepartLine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReservationModelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSparepartLine(): WorkOrderSparepartLine
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/202608/00001', 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
        ]);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        return WorkOrderSparepartLine::create([
            'work_order_id' => $workOrder->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'item_code_snapshot' => 'OLI-01', 'item_name_snapshot' => 'Oli Mesin',
            'qty' => 5, 'default_unit_price' => 60000, 'unit_price' => 60000, 'line_total' => 300000,
        ]);
    }

    public function test_reservation_can_be_created_with_fillable_fields_and_defaults_to_active(): void
    {
        $line = $this->makeSparepartLine();

        $reservation = InventoryReservation::create([
            'branch_id' => $line->workOrder->branch_id,
            'sparepart_branch_id' => $line->sparepart_branch_id,
            'reservation_type' => 'pkb',
            'reference_type' => 'work_order_sparepart_line',
            'reference_id' => $line->id,
            'qty' => 5,
        ]);

        $this->assertSame('active', $reservation->status);
        $this->assertSame(5.0, (float) $reservation->qty);
    }

    public function test_reservation_qty_must_be_positive(): void
    {
        $line = $this->makeSparepartLine();

        $this->expectException(QueryException::class);
        InventoryReservation::create([
            'branch_id' => $line->workOrder->branch_id,
            'sparepart_branch_id' => $line->sparepart_branch_id,
            'reservation_type' => 'pkb',
            'reference_type' => 'work_order_sparepart_line',
            'reference_id' => $line->id,
            'qty' => 0,
        ]);
    }

    public function test_sparepart_line_reservations_relation_scopes_to_its_own_reference_type(): void
    {
        $line = $this->makeSparepartLine();
        InventoryReservation::create([
            'branch_id' => $line->workOrder->branch_id, 'sparepart_branch_id' => $line->sparepart_branch_id,
            'reservation_type' => 'pkb', 'reference_type' => 'work_order_sparepart_line', 'reference_id' => $line->id, 'qty' => 3,
        ]);
        // A reservation with the same reference_id but a different reference_type must not leak in.
        InventoryReservation::create([
            'branch_id' => $line->workOrder->branch_id, 'sparepart_branch_id' => $line->sparepart_branch_id,
            'reservation_type' => 'transfer', 'reference_type' => 'stock_transfer_line', 'reference_id' => $line->id, 'qty' => 2,
        ]);

        $this->assertCount(1, $line->reservations);
        $this->assertSame(3.0, (float) $line->reservations->first()->qty);
    }

    public function test_work_order_has_null_shortage_columns_by_default(): void
    {
        $line = $this->makeSparepartLine();

        $this->assertNull($line->workOrder->shortage_override_reason);
        $this->assertNull($line->workOrder->shortage_overridden_by);
        $this->assertNull($line->workOrder->shortage_overridden_at);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=InventoryReservationModelTest`
Expected: FAIL — `inventory_reservations` table / `InventoryReservation` class / `reservations()` relation don't exist yet.

- [ ] **Step 3: Create the migrations**

Create `database/migrations/2026_08_04_000001_create_inventory_reservations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateInventoryReservationsTable extends Migration
{
    public function up()
    {
        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('sparepart_branch_id')->constrained('sparepart_branches');
            $table->string('reservation_type', 20);
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');
            $table->decimal('qty', 18, 3);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['sparepart_branch_id', 'status']);
        });

        DB::statement('ALTER TABLE inventory_reservations ADD CONSTRAINT ck_inventory_reservations_qty_positive CHECK (qty > 0)');
    }

    public function down()
    {
        Schema::dropIfExists('inventory_reservations');
    }
}
```

Create `database/migrations/2026_08_04_000002_add_shortage_columns_to_work_orders_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShortageColumnsToWorkOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->text('shortage_override_reason')->nullable()->after('notes');
            $table->unsignedBigInteger('shortage_overridden_by')->nullable()->after('shortage_override_reason');
            $table->timestamp('shortage_overridden_at')->nullable()->after('shortage_overridden_by');

            $table->foreign('shortage_overridden_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['shortage_overridden_by']);
            $table->dropColumn(['shortage_override_reason', 'shortage_overridden_by', 'shortage_overridden_at']);
        });
    }
}
```

- [ ] **Step 4: Create the `InventoryReservation` model**

Create `app/Models/InventoryReservation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryReservation extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'branch_id', 'sparepart_branch_id', 'reservation_type', 'reference_type', 'reference_id', 'qty', 'status', 'created_by',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
    ];

    protected $attributes = [
        'status' => 'active',
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

Note: no `HasAudit` trait here — that trait sets both `created_by` AND `updated_by` on every `creating`/`updating` event, but this table has no `updated_by` column (deliberately — reservations are either `active` or `released`, there's no need to track "who last touched it" beyond `created_by`). Using `HasAudit` would attempt to write a non-existent `updated_by` column and fail. `created_by` is set explicitly wherever a reservation is created (Task 3).

- [ ] **Step 5: Extend `WorkOrderStatus`**

In `app/Support/WorkOrderStatus.php`, replace the full file:

```php
<?php

namespace App\Support;

class WorkOrderStatus
{
    const DRAFT = 'draft';
    const OPEN = 'open';
    const SHORTAGE = 'shortage';
    const CANCELLED = 'cancelled';
}
```

- [ ] **Step 6: Extend the `WorkOrder` model**

In `app/Models/WorkOrder.php`, replace the `$fillable`/`$casts` arrays and add one relation:

```php
    protected $fillable = [
        'number', 'branch_id', 'customer_id', 'vehicle_id', 'mechanic_id',
        'work_order_date', 'odometer_km', 'status', 'notes',
        'shortage_override_reason', 'shortage_overridden_by', 'shortage_overridden_at',
    ];

    protected $casts = [
        'work_order_date' => 'date',
        'odometer_km' => 'decimal:1',
        'shortage_overridden_at' => 'datetime',
    ];
```

Add this relation alongside the existing `branch()`/`customer()`/`vehicle()`/`mechanic()` methods:

```php
    public function shortageOverriddenBy()
    {
        return $this->belongsTo(User::class, 'shortage_overridden_by');
    }
```

- [ ] **Step 7: Extend `WorkOrderSparepartLine`**

In `app/Models/WorkOrderSparepartLine.php`, add this relation alongside the existing `workOrder()`/`sparepartBranch()` methods:

```php
    public function reservations()
    {
        return $this->hasMany(InventoryReservation::class, 'reference_id')
            ->where('reference_type', 'work_order_sparepart_line');
    }
```

Add `use App\Models\InventoryReservation;` to the file's `use` block (it's in the same `App\Models` namespace, so a bare `InventoryReservation::class` reference works without an import — but add the explicit `use` statement anyway for consistency with this file's existing style, if the existing file already imports sibling models this way; if `WorkOrderSparepartLine.php` currently has no cross-model `use` imports because everything is same-namespace, skip the import and just reference `InventoryReservation::class` directly, matching whatever the file's existing convention already is — check the file before editing).

- [ ] **Step 8: Run migrations and tests to verify they pass**

Run: `php artisan migrate` then `php artisan test --filter=InventoryReservationModelTest`
Expected: PASS.

- [ ] **Step 9: Run the full suite**

Run: `php artisan test`
Expected: all pre-existing tests still PASS (no regression from the `WorkOrder`/`WorkOrderSparepartLine` model changes).

- [ ] **Step 10: Commit**

```bash
git add app/Models/InventoryReservation.php app/Models/WorkOrder.php app/Models/WorkOrderSparepartLine.php app/Support/WorkOrderStatus.php database/migrations/2026_08_04_000001_create_inventory_reservations_table.php database/migrations/2026_08_04_000002_add_shortage_columns_to_work_orders_table.php tests/Feature/InventoryReservationModelTest.php
git commit -m "feat: add inventory_reservations table and OPEN/SHORTAGE work order statuses"
```

---

### Task 2: Authorization — extend `WorkOrderPolicy`

**Files:**
- Modify: `app/Policies/WorkOrderPolicy.php`
- Test: `tests/Feature/WorkOrderAuthorizationTest.php` (append new tests)

**Interfaces:**
- Consumes: `WorkOrderStatus::OPEN`/`SHORTAGE` (Task 1), `WorkOrder::shortage_overridden_at` (Task 1).
- Produces: `WorkOrderPolicy::confirm(User, WorkOrder): bool`, `WorkOrderPolicy::overrideShortage(User, WorkOrder): bool`, `WorkOrderPolicy::cancel()` now also returns `true` for `OPEN`/`SHORTAGE` (not just `DRAFT`) — consumed by Task 3's controller via `$this->authorize(...)` and Task 4's Blade `@can(...)` directives.

- [ ] **Step 1: Write the failing Policy tests**

Append these methods to `tests/Feature/WorkOrderAuthorizationTest.php` (reuses the existing `grantBranchPermission()`/`makeWorkOrder()` helpers already in this file — `makeWorkOrder(Branch $branch, array $overrides = [])` already accepts a `$overrides` array merged into the `WorkOrder::create([...])` call, so `['status' => WorkOrderStatus::OPEN]` etc. works directly):

```php
    public function test_policy_grants_cancel_for_an_open_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $workOrder = $this->makeWorkOrder($branch, ['status' => WorkOrderStatus::OPEN]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('cancel', $workOrder));
    }

    public function test_policy_grants_cancel_for_a_shortage_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $workOrder = $this->makeWorkOrder($branch, ['status' => WorkOrderStatus::SHORTAGE]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('cancel', $workOrder));
    }

    public function test_policy_grants_confirm_for_a_draft_work_order_with_confirm_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $workOrder = $this->makeWorkOrder($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('confirm', $workOrder));
    }

    public function test_policy_denies_confirm_for_a_non_draft_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $workOrder = $this->makeWorkOrder($branch, ['status' => WorkOrderStatus::OPEN]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('confirm', $workOrder));
    }

    public function test_policy_grants_override_shortage_for_a_not_yet_overridden_shortage_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $workOrder = $this->makeWorkOrder($branch, ['status' => WorkOrderStatus::SHORTAGE]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('overrideShortage', $workOrder));
    }

    public function test_policy_denies_override_shortage_when_already_overridden(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $workOrder = $this->makeWorkOrder($branch, [
            'status' => WorkOrderStatus::SHORTAGE,
            'shortage_overridden_at' => now(),
        ]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('overrideShortage', $workOrder));
    }

    public function test_policy_denies_override_shortage_for_a_non_shortage_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $workOrder = $this->makeWorkOrder($branch, ['status' => WorkOrderStatus::OPEN]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('overrideShortage', $workOrder));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=WorkOrderAuthorizationTest`
Expected: the 7 new tests FAIL — `confirm`/`overrideShortage` abilities don't exist on the Policy yet, and `cancel` currently only allows `DRAFT`.

- [ ] **Step 3: Extend the Policy**

Replace the full contents of `app/Policies/WorkOrderPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\WorkOrderStatus;

class WorkOrderPolicy
{
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasPermissionToInBranch('pkb.view', $workOrder->branch_id);
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->status === WorkOrderStatus::DRAFT
            && $user->hasPermissionToInBranch('pkb.edit', $workOrder->branch_id);
    }

    public function cancel(User $user, WorkOrder $workOrder): bool
    {
        return in_array($workOrder->status, [WorkOrderStatus::DRAFT, WorkOrderStatus::OPEN, WorkOrderStatus::SHORTAGE], true)
            && $user->hasPermissionToInBranch('pkb.cancel', $workOrder->branch_id);
    }

    public function confirm(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->status === WorkOrderStatus::DRAFT
            && $user->hasPermissionToInBranch('pkb.confirm', $workOrder->branch_id);
    }

    public function overrideShortage(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->status === WorkOrderStatus::SHORTAGE
            && is_null($workOrder->shortage_overridden_at)
            && $user->hasPermissionToInBranch('pkb.override_stock_shortage', $workOrder->branch_id);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=WorkOrderAuthorizationTest`
Expected: PASS (all 7 new tests plus every pre-existing test in the file, including the ones proving `update()` still denies non-`DRAFT` and `cancel()` still requires `pkb.cancel`).

- [ ] **Step 5: Commit**

```bash
git add app/Policies/WorkOrderPolicy.php tests/Feature/WorkOrderAuthorizationTest.php
git commit -m "feat: extend WorkOrderPolicy with confirm/overrideShortage, loosen cancel to OPEN/SHORTAGE"
```

---

### Task 3: Controller — `confirm()`, `overrideShortage()`, extended `cancel()`

**Files:**
- Create: `app/Http/Requests/OverrideShortageRequest.php`
- Modify: `app/Http/Controllers/WorkOrderController.php` (add `confirm()`/`overrideShortage()`, replace `cancel()`)
- Modify: `routes/web.php` (add 2 routes into the existing `work-orders` group)
- Test: `tests/Feature/WorkOrderManagementTest.php` (append new tests)

**Interfaces:**
- Consumes: `WorkOrderPolicy::confirm/overrideShortage/cancel` (Task 2), `InventoryReservation`, `WorkOrderSparepartLine::reservations()` (Task 1), `SparepartBranchStock` (already exists, `available_qty` accessor already exists).
- Produces: routes `work-orders.confirm`, `work-orders.overrideShortage`. Nothing later in this plan consumes these beyond Task 4's view, which only needs the route names.

- [ ] **Step 1: Write the failing tests**

Append these methods to `tests/Feature/WorkOrderManagementTest.php` (reuses the existing `grantBranchPermission()`/`makeScenario()`/`baseStorePayload()` helpers already in this file):

```php
    protected function confirmWorkOrder(Branch $branch, array $scenario, array $overrides = []): WorkOrder
    {
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $this->actingAs(User::find($user->id))->post('/work-orders', array_merge($this->baseStorePayload($branch, $scenario), $overrides));
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/confirm");

        return $workOrder->fresh();
    }

    public function test_confirm_reserves_full_qty_and_sets_open_when_stock_is_sufficient(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);

        $workOrder = $this->confirmWorkOrder($branch, $scenario);

        $this->assertSame(WorkOrderStatus::OPEN, $workOrder->status);
        $line = $workOrder->sparepartLines->first();
        $this->assertCount(1, $line->reservations);
        $this->assertSame(2.0, (float) $line->reservations->first()->qty);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $this->assertSame(2.0, (float) $stock->reserved_qty);
    }

    public function test_confirm_partially_reserves_and_sets_shortage_when_stock_is_insufficient(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);

        $workOrder = $this->confirmWorkOrder($branch, $scenario);

        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->status);
        $line = $workOrder->sparepartLines->first();
        $this->assertCount(1, $line->reservations);
        $this->assertSame(1.0, (float) $line->reservations->first()->qty);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $this->assertSame(1.0, (float) $stock->reserved_qty);
    }

    public function test_confirm_creates_no_reservation_when_stock_is_zero(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);

        $workOrder = $this->confirmWorkOrder($branch, $scenario);

        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->status);
        $this->assertCount(0, $workOrder->sparepartLines->first()->reservations);
    }

    public function test_confirm_sets_open_immediately_for_a_jasa_only_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['spareparts'] = [];

        $workOrder = $this->confirmWorkOrder($branch, $scenario, $payload);

        $this->assertSame(WorkOrderStatus::OPEN, $workOrder->status);
    }

    public function test_confirm_is_forbidden_without_pkb_confirm_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::latest('id')->first();

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/confirm");

        $response->assertForbidden();
    }

    public function test_confirm_is_forbidden_for_a_non_draft_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/confirm");

        $response->assertForbidden();
    }

    public function test_override_shortage_records_reason_without_changing_status_or_reservations(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/override-shortage", [
            'reason' => 'Sparepart dipesan dari cabang lain, tetap lanjutkan servis.',
        ]);

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $workOrder->refresh();
        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->status);
        $this->assertNotNull($workOrder->shortage_overridden_at);
        $this->assertSame($user->id, $workOrder->shortage_overridden_by);
        $stockAfter = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $this->assertSame(1.0, (float) $stockAfter->reserved_qty);
    }

    public function test_override_shortage_requires_a_reason(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => '']);

        $response->assertSessionHasErrors(['reason']);
    }

    public function test_override_shortage_is_forbidden_when_status_is_not_shortage(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => 'x']);

        $response->assertForbidden();
    }

    public function test_override_shortage_is_forbidden_when_already_overridden(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => 'first']);

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => 'second']);

        $response->assertForbidden();
    }

    public function test_cancel_from_open_releases_active_reservations(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $workOrder->refresh();
        $this->assertSame(WorkOrderStatus::CANCELLED, $workOrder->status);
        $line = $workOrder->sparepartLines->first();
        $this->assertSame('released', $line->reservations->first()->status);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $this->assertSame(0.0, (float) $stock->reserved_qty);
    }

    public function test_cancel_from_shortage_releases_partial_reservation(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');

        $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->first();
        $this->assertSame(0.0, (float) $stock->reserved_qty);
    }

    public function test_cancel_from_draft_still_works_without_touching_reservations(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::latest('id')->first();

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $this->assertSame(WorkOrderStatus::CANCELLED, $workOrder->fresh()->status);
    }

    public function test_update_is_still_forbidden_for_open_and_shortage_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.edit');

        $response = $this->actingAs(User::find($user->id))->put("/work-orders/{$workOrder->id}", [
            'customer_id' => $scenario['customer']->id, 'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => [['service_catalog_id' => null, 'description' => 'X', 'qty' => 1, 'unit_price' => 1000]],
        ]);

        $response->assertForbidden();
    }
```

Add `use App\Support\WorkOrderStatus;` to this test file's `use` block if not already present (check the file first — Task 4's plan review confirmed this file already references `WorkOrderStatus` in earlier tests, so it should already be imported).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=WorkOrderManagementTest`
Expected: the new tests FAIL — `confirm`/`overrideShortage` routes don't exist yet (404), and `cancel()` doesn't yet release reservations.

- [ ] **Step 3: Create the `OverrideShortageRequest`**

Create `app/Http/Requests/OverrideShortageRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OverrideShortageRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('overrideShortage', $this->route('workOrder'));
    }

    public function rules()
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 4: Add the controller methods**

In `app/Http/Controllers/WorkOrderController.php`, add `use App\Http\Requests\OverrideShortageRequest;`, `use App\Models\InventoryReservation;`, and `use App\Models\SparepartBranchStock;` to the `use` block at the top of the file.

Add these two new public methods (place them after `store()` and before `show()`, or wherever reads cleanly next to the other actions):

```php
    public function confirm(WorkOrder $workOrder)
    {
        $this->authorize('confirm', $workOrder);

        DB::transaction(function () use ($workOrder) {
            $lines = $workOrder->sparepartLines()->orderBy('sparepart_branch_id')->get();
            $hasShortage = false;

            foreach ($lines as $line) {
                $stock = SparepartBranchStock::where('sparepart_branch_id', $line->sparepart_branch_id)
                    ->lockForUpdate()
                    ->first();

                $available = $stock->on_hand_qty - $stock->reserved_qty;
                $reserveQty = min($available, $line->qty);

                if ($reserveQty > 0) {
                    InventoryReservation::create([
                        'branch_id' => $workOrder->branch_id,
                        'sparepart_branch_id' => $line->sparepart_branch_id,
                        'reservation_type' => 'pkb',
                        'reference_type' => 'work_order_sparepart_line',
                        'reference_id' => $line->id,
                        'qty' => $reserveQty,
                        'created_by' => auth()->id(),
                    ]);

                    $stock->reserved_qty += $reserveQty;
                    $stock->save();
                }

                if ((float) $reserveQty < (float) $line->qty) {
                    $hasShortage = true;
                }
            }

            $workOrder->status = $hasShortage ? WorkOrderStatus::SHORTAGE : WorkOrderStatus::OPEN;
            $workOrder->save();
        });

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB berhasil dikonfirmasi.');
    }

    public function overrideShortage(OverrideShortageRequest $request, WorkOrder $workOrder)
    {
        $workOrder->update([
            'shortage_override_reason' => $request->validated()['reason'],
            'shortage_overridden_by' => auth()->id(),
            'shortage_overridden_at' => now(),
        ]);

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'Kekurangan stok berhasil dicatat sebagai disetujui.');
    }
```

Replace the existing `cancel()` method:

```php
    public function cancel(WorkOrder $workOrder)
    {
        $this->authorize('cancel', $workOrder);

        DB::transaction(function () use ($workOrder) {
            if (in_array($workOrder->status, [WorkOrderStatus::OPEN, WorkOrderStatus::SHORTAGE], true)) {
                $lines = $workOrder->sparepartLines()->orderBy('sparepart_branch_id')->get();

                foreach ($lines as $line) {
                    $activeReservations = $line->reservations()->where('status', 'active')->get();

                    foreach ($activeReservations as $reservation) {
                        $stock = SparepartBranchStock::where('sparepart_branch_id', $reservation->sparepart_branch_id)
                            ->lockForUpdate()
                            ->first();
                        $stock->reserved_qty -= $reservation->qty;
                        $stock->save();

                        $reservation->status = 'released';
                        $reservation->save();
                    }
                }
            }

            $workOrder->status = WorkOrderStatus::CANCELLED;
            $workOrder->save();
        });

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB berhasil dibatalkan.');
    }
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, no new `use` import is needed (`OverrideShortageRequest` is type-hinted only inside the controller method, not referenced in the route file itself). Add these 2 routes inside the existing `work-orders` group, immediately after the existing `Route::patch('/{workOrder}/cancel', ...)` line:

```php
        Route::patch('/{workOrder}/confirm', [WorkOrderController::class, 'confirm'])->name('confirm');
        Route::patch('/{workOrder}/override-shortage', [WorkOrderController::class, 'overrideShortage'])->name('overrideShortage');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=WorkOrderManagementTest`
Expected: PASS (all new tests plus every pre-existing test in the file).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/OverrideShortageRequest.php app/Http/Controllers/WorkOrderController.php routes/web.php tests/Feature/WorkOrderManagementTest.php
git commit -m "feat: implement PKB confirm (partial stock reservation), override-shortage, and reservation-releasing cancel"
```

---

### Task 4: UI — confirm/override buttons, reserved-qty display, status badges; final verification

**Files:**
- Modify: `resources/views/work-orders/show.blade.php`
- Test: `tests/Feature/WorkOrderManagementTest.php` (append view-rendering tests)

**Interfaces:**
- Consumes: `route('work-orders.confirm')`, `route('work-orders.overrideShortage')` (Task 3), `@can('confirm'|'overrideShortage'|'cancel', $workOrder)` (Task 2), `$line->reservations` (Task 1, must be eager-loaded by `show()` — see Step 3).
- Produces: nothing consumed by later tasks — this is the final task in the plan.

- [ ] **Step 1: Write the failing tests**

Append these methods to `tests/Feature/WorkOrderManagementTest.php`:

```php
    public function test_show_renders_confirm_button_for_a_draft_work_order_with_confirm_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::latest('id')->first();

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee(route('work-orders.confirm', $workOrder), false);
    }

    public function test_show_renders_open_status_and_reserved_qty_after_confirm(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee('Dikonfirmasi');
        $response->assertDontSee(route('work-orders.confirm', $workOrder), false);
        $response->assertDontSee(route('work-orders.edit', $workOrder), false);
    }

    public function test_show_renders_shortage_status_and_override_form_when_not_yet_overridden(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee('Kurang Stok');
        $response->assertSee(route('work-orders.overrideShortage', $workOrder), false);
    }

    public function test_show_hides_override_form_after_shortage_is_overridden(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 1]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => 'Ditunda menunggu barang datang.']);

        $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertDontSee(route('work-orders.overrideShortage', $workOrder), false);
        $response->assertSee('Ditunda menunggu barang datang.');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=WorkOrderManagementTest`
Expected: the 4 new tests FAIL — `show.blade.php` doesn't yet render any confirm/override UI or the `OPEN`/`SHORTAGE` badges.

- [ ] **Step 3: Update `show()` to eager-load reservations**

In `app/Http/Controllers/WorkOrderController.php`, change the `show()` method's `load(...)` call from:

```php
        $workOrder->load(['branch', 'customer', 'vehicle', 'mechanic', 'serviceLines', 'sparepartLines']);
```

to:

```php
        $workOrder->load(['branch', 'customer', 'vehicle', 'mechanic', 'serviceLines', 'sparepartLines.reservations']);
```

- [ ] **Step 4: Update the view**

In `resources/views/work-orders/show.blade.php`, replace the header actions block (currently lines 6-20, the `<div class="d-flex gap-2">...</div>`):

```blade
        <div class="d-flex gap-2">
            @can('confirm', $workOrder)
                <form method="POST" action="{{ route('work-orders.confirm', $workOrder) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Konfirmasi</button>
                </form>
            @endcan
            @can('update', $workOrder)
                <a href="{{ route('work-orders.edit', $workOrder) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
            @can('cancel', $workOrder)
                <form method="POST" action="{{ route('work-orders.cancel', $workOrder) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-outline-danger btn-sm">Batalkan</button>
                </form>
            @endcan
        </div>
```

Replace the status badge block (currently the `<strong>Status</strong>` `<div>` containing the `@if ($workOrder->status === \App\Support\WorkOrderStatus::DRAFT) ... @endif`):

```blade
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>
                        @if ($workOrder->status === \App\Support\WorkOrderStatus::DRAFT)
                            <span class="status-dot status-active">Draft</span>
                        @elseif ($workOrder->status === \App\Support\WorkOrderStatus::OPEN)
                            <span class="status-dot status-active">Dikonfirmasi</span>
                        @elseif ($workOrder->status === \App\Support\WorkOrderStatus::SHORTAGE)
                            <span class="status-dot status-inactive">Kurang Stok</span>
                        @else
                            <span class="status-dot status-inactive">Dibatalkan</span>
                        @endif
                    </div>
                </div>
```

Replace the sparepart lines table (currently the `<table class="table table-sm">` inside the "Baris Sparepart" card, header row + `@forelse` body):

```blade
            <table class="table table-sm">
                <thead><tr><th>Kode</th><th>Nama</th><th>Qty</th><th>Direservasi</th><th>Harga</th><th>Total</th></tr></thead>
                <tbody>
                    @forelse ($workOrder->sparepartLines as $line)
                        <tr>
                            <td><code>{{ $line->item_code_snapshot }}</code></td>
                            <td>{{ $line->item_name_snapshot }}</td>
                            <td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->reservations->where('status', 'active')->sum('qty'), 0, ',', '.') }}</td>
                            <td>{{ number_format($line->unit_price, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">Tidak ada baris sparepart.</td></tr>
                    @endforelse
                </tbody>
            </table>
```

Add a shortage-override section right after the "Baris Sparepart" card's closing `</div>` (the outer `<div class="card mb-3">` for that card), before the final `<a href="{{ route('work-orders.index') }}" ...>Kembali</a>` line:

```blade
    @if ($workOrder->status === \App\Support\WorkOrderStatus::SHORTAGE)
        <div class="card mb-3">
            <div class="card-body">
                @if ($workOrder->shortage_overridden_at)
                    <p class="mb-0">
                        <strong>Kekurangan stok disetujui</strong> oleh {{ optional($workOrder->shortageOverriddenBy)->name ?? '-' }}
                        pada {{ $workOrder->shortage_overridden_at->format('d/m/Y H:i') }}:
                        {{ $workOrder->shortage_override_reason }}
                    </p>
                @else
                    @can('overrideShortage', $workOrder)
                        <form method="POST" action="{{ route('work-orders.overrideShortage', $workOrder) }}">
                            @csrf
                            @method('PATCH')
                            <label for="reason" class="form-label"><strong>Override Kekurangan Stok</strong></label>
                            <textarea name="reason" id="reason" class="form-control @error('reason') is-invalid @enderror" rows="2" required></textarea>
                            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <button type="submit" class="btn btn-outline-warning btn-sm mt-2">Kirim Override</button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>
    @endif
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=WorkOrderManagementTest`
Expected: PASS (all new tests plus every pre-existing test in the file).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS.

Grep the new user-facing strings against the text-collision-prone test files, per this project's established practice:

Run: `grep -rn "Dikonfirmasi\|Kurang Stok\|Konfirmasi\b" tests/Feature/AppShellTest.php tests/Feature/DashboardTest.php`
Expected: no matches. If a match is found, use the existing mitigation — narrow the colliding assertion to a unique route/icon-class check.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/WorkOrderController.php resources/views/work-orders/show.blade.php tests/Feature/WorkOrderManagementTest.php
git commit -m "feat: add confirm/override-shortage UI and reserved-qty display to PKB detail page"
```

---

## Self-Review Notes

- **Spec coverage:** every in-scope item from the design spec is covered — data model (Task 1), Policy extension (Task 2), confirm/override/cancel business logic with locking (Task 3), UI (Task 4). Explicitly out-of-scope items (`update()` unchanged, no approval workflow, no invoice/COMPLETED status, `expires_at` column not created, `on_hand_qty` never written) are untouched by every task.
- **Placeholder scan:** none found — every code block is complete and copy-ready.
- **Type consistency:** `InventoryReservation`'s fields and `WorkOrderSparepartLine::reservations()`'s relation shape (Task 1) are used identically in Task 3's controller logic (`$line->reservations()->where('status', 'active')`) and Task 4's view (`$line->reservations->where('status', 'active')->sum('qty')` — note the parentheses difference: Task 3 calls `reservations()` as a query builder to filter+update, Task 4 reads `reservations` as an already-eager-loaded collection to sum, both valid given `show()`'s eager-load in Task 4 Step 3). `WorkOrderPolicy`'s three new/changed ability names (`confirm`, `overrideShortage`, `cancel`) match exactly between Task 2's Policy methods, Task 3's `$this->authorize(...)`/`OverrideShortageRequest::authorize()` calls, and Task 4's `@can(...)` directives.
- **Scope check:** 4 tasks, smaller than migration 006's 5 tasks, consistent with the "007 extends an existing module rather than building a new one" sizing estimate given to the user during brainstorming.
