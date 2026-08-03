# Migrasi 006 — PKB / Work Orders (CRUD-only) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the PKB (Perintah Kerja Bengkel / work order) module — header + jasa/sparepart line items — as a full CRUD document type (create/list/detail/edit/cancel), the first operational (non-master-data) module in this project. Scope is deliberately limited to `DRAFT`/`CANCELLED` status only; stock reservation and the `OPEN`/`SHORTAGE`/`COMPLETED`/`INVOICED` lifecycle are migration 007's job.

**Architecture:** Three new tables (`work_orders` header, `work_order_service_lines`, `work_order_sparepart_lines`) linked by branch/customer/vehicle/mechanic to existing master data. A new `WorkOrderPolicy` (second Policy in the codebase, copies `SparepartBranchPolicy`'s shape) enforces branch-scoped, status-aware authorization. The create/edit form extends the AJAX cascading-dropdown pattern already used by Kendaraan (category→brand→type) to four levels (branch→customer→vehicle, branch→mechanic) plus dynamically add/removable jasa/sparepart line rows, submitted as one POST with nested arrays. No stock reservation happens anywhere in this plan — sparepart lines are informational snapshots only.

**Tech Stack:** Laravel 8.75, PHP 7.4.33 (no PHP 8 syntax), MySQL 8.0, Blade + Bootstrap 5 (CDN), vanilla JS (`fetch`, no build step), PHPUnit feature tests (`RefreshDatabase`).

## Global Constraints

- PHP runtime is 7.4.33 — never use PHP 8-only syntax (nullsafe `?->`, named arguments, match expressions, enums, constructor property promotion, union types), including inside Blade `@php()` blocks.
- Laravel 8.75 pinned — never use `Request::integer()` or other Laravel 9+ `Request` helper methods; cast manually (`(int) request('x')`).
- `bigint` auto-increment PKs (`$table->id()` / `$table->foreignId()`), never UUID.
- Every list/index endpoint uses `->simplePaginate()`, never `->paginate()`.
- No hard deletes of transactional documents — status field only (`draft`/`cancelled` in this plan's scope). Line-item child tables (`work_order_service_lines`/`work_order_sparepart_lines`) DO cascade-delete when their parent `work_order` is deleted, but the application itself never deletes a `work_order` row — this cascade is a DB-level safety net only.
- `pkb.view`, `pkb.create`, `pkb.edit`, `pkb.cancel` are already seeded in `MenuPermissionSeeder` (menu code `operasional.pkb`, `is_branch_scoped = true`) — do not add new permission codes. `pkb.confirm`/`pkb.override_stock_shortage`/`pkb.print` exist in the same seed but are NOT used anywhere in this plan (migration 007+/print feature).
- Branch-scoped permission codes must never be checked via a bare `$this->authorize('pkb.view')` / `Gate`-only call with no model argument — `Gate::before` only short-circuits for the GLOBAL `user_permissions` table, and branch-scoped codes are deliberately never granted there (see project memory on the `menus.is_branch_scoped` incident). Every `pkb.*` check must go through either `hasPermissionToInBranch($code, $branchId)` directly, or a Policy method receiving a model instance.
- `line_total` on every line item is always computed server-side (`round($qty * $unit_price, 2)`) and never trusted from client input, even though the client submits a `qty`/`unit_price` pair.
- Search input sanitized once in the controller (`is_string(request('q')) ? trim(request('q')) : null`), never re-read raw in the Blade view (the `?q[]=x` array-crash bug class, hit 4 times already in this project).
- Reuse the existing design system and shared partials: `partials.list-filter-bar`, `partials.empty-state`, `partials.branch-multiselect-filter` — do not hand-roll new list/filter/empty-state markup.

---

### Task 1: Data model — migrations and Eloquent models

**Files:**
- Create: `database/migrations/2026_08_03_000001_create_work_orders_table.php`
- Create: `database/migrations/2026_08_03_000002_create_work_order_service_lines_table.php`
- Create: `database/migrations/2026_08_03_000003_create_work_order_sparepart_lines_table.php`
- Create: `app/Support/WorkOrderStatus.php`
- Create: `app/Models/WorkOrder.php`
- Create: `app/Models/WorkOrderServiceLine.php`
- Create: `app/Models/WorkOrderSparepartLine.php`
- Test: `tests/Feature/WorkOrderModelTest.php`

**Interfaces:**
- Produces: `WorkOrder` (fields: `number`, `branch_id`, `customer_id`, `vehicle_id`, `mechanic_id`, `work_order_date`, `odometer_km`, `status`, `notes`; relations `branch()`, `customer()`, `vehicle()`, `mechanic()`, `serviceLines()`, `sparepartLines()`); `WorkOrderServiceLine` (fields: `work_order_id`, `service_catalog_id`, `description`, `qty`, `unit_price`, `line_total`, `sort_order`); `WorkOrderSparepartLine` (fields: `work_order_id`, `sparepart_branch_id`, `item_code_snapshot`, `item_name_snapshot`, `qty`, `default_unit_price`, `unit_price`, `line_total`, `sort_order`); `WorkOrderStatus::DRAFT` (`'draft'`) and `WorkOrderStatus::CANCELLED` (`'cancelled'`) string constants. Every later task that reads/writes a `WorkOrder`'s status must use these constants, never a bare string literal.

- [ ] **Step 1: Write the failing model tests**

Create `tests/Feature/WorkOrderModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Models\WorkOrderServiceLine;
use App\Models\WorkOrderSparepartLine;
use App\Support\WorkOrderStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderModelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeVehicle(): Vehicle
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);

        return Vehicle::create([
            'customer_id' => $customer->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'type_id' => $type->id,
            'plate_number' => 'B 1234 XYZ',
        ]);
    }

    public function test_work_order_can_be_created_with_fillable_fields_and_defaults_to_draft(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $vehicle = $this->makeVehicle();
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);

        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/202608/00001',
            'branch_id' => $branch->id,
            'customer_id' => $vehicle->customer_id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->format('Y-m-d'),
        ]);

        $this->assertSame(WorkOrderStatus::DRAFT, $workOrder->status);
        $this->assertSame($user->id, $workOrder->created_by);
    }

    public function test_work_order_number_is_unique(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $vehicle = $this->makeVehicle();
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $attrs = [
            'number' => 'PKB/JKT/202608/00001',
            'branch_id' => $branch->id,
            'customer_id' => $vehicle->customer_id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->format('Y-m-d'),
        ];
        WorkOrder::create($attrs);

        $this->expectException(QueryException::class);
        WorkOrder::create($attrs);
    }

    public function test_service_line_belongs_to_work_order_and_optional_catalog(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $vehicle = $this->makeVehicle();
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/202608/00001', 'branch_id' => $branch->id,
            'customer_id' => $vehicle->customer_id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
        ]);
        $catalog = ServiceCatalog::create(['code' => 'SVC-01', 'name' => 'Ganti Oli', 'default_price' => 50000]);

        $line = WorkOrderServiceLine::create([
            'work_order_id' => $workOrder->id,
            'service_catalog_id' => $catalog->id,
            'description' => 'Ganti Oli',
            'qty' => 1,
            'unit_price' => 50000,
            'line_total' => 50000,
        ]);

        $this->assertSame($workOrder->id, $line->workOrder->id);
        $this->assertSame($catalog->id, $line->serviceCatalog->id);
        $this->assertCount(1, $workOrder->serviceLines);
    }

    public function test_service_line_allows_null_catalog_for_free_text_jasa(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $vehicle = $this->makeVehicle();
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/202608/00001', 'branch_id' => $branch->id,
            'customer_id' => $vehicle->customer_id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
        ]);

        $line = WorkOrderServiceLine::create([
            'work_order_id' => $workOrder->id,
            'service_catalog_id' => null,
            'description' => 'Servis manual custom',
            'qty' => 1,
            'unit_price' => 75000,
            'line_total' => 75000,
        ]);

        $this->assertNull($line->service_catalog_id);
        $this->assertNull($line->serviceCatalog);
    }

    public function test_sparepart_line_belongs_to_work_order_and_sparepart_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $vehicle = $this->makeVehicle();
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/202608/00001', 'branch_id' => $branch->id,
            'customer_id' => $vehicle->customer_id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
        ]);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        $line = WorkOrderSparepartLine::create([
            'work_order_id' => $workOrder->id,
            'sparepart_branch_id' => $sparepartBranch->id,
            'item_code_snapshot' => 'OLI-01',
            'item_name_snapshot' => 'Oli Mesin',
            'qty' => 2,
            'default_unit_price' => 60000,
            'unit_price' => 60000,
            'line_total' => 120000,
        ]);

        $this->assertSame($workOrder->id, $line->workOrder->id);
        $this->assertSame($sparepartBranch->id, $line->sparepartBranch->id);
        $this->assertCount(1, $workOrder->sparepartLines);
    }

    public function test_deleting_work_order_cascades_to_its_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $vehicle = $this->makeVehicle();
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/202608/00001', 'branch_id' => $branch->id,
            'customer_id' => $vehicle->customer_id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
        ]);
        $line = WorkOrderServiceLine::create([
            'work_order_id' => $workOrder->id, 'description' => 'Ganti Oli',
            'qty' => 1, 'unit_price' => 50000, 'line_total' => 50000,
        ]);

        $workOrder->delete();

        $this->assertDatabaseMissing('work_order_service_lines', ['id' => $line->id]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=WorkOrderModelTest`
Expected: FAIL — `work_orders` table / `WorkOrder` class don't exist yet.

- [ ] **Step 3: Create the `WorkOrderStatus` support class**

Create `app/Support/WorkOrderStatus.php`:

```php
<?php

namespace App\Support;

class WorkOrderStatus
{
    const DRAFT = 'draft';
    const CANCELLED = 'cancelled';
}
```

- [ ] **Step 4: Create the migrations**

Create `database/migrations/2026_08_03_000001_create_work_orders_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWorkOrdersTable extends Migration
{
    public function up()
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->foreignId('mechanic_id')->constrained('mechanics');
            $table->date('work_order_date');
            $table->decimal('odometer_km', 12, 1)->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'work_order_date', 'status']);
        });

        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT ck_work_orders_odometer_nonnegative CHECK (odometer_km IS NULL OR odometer_km >= 0)");
    }

    public function down()
    {
        Schema::dropIfExists('work_orders');
    }
}
```

Note: add `use Illuminate\Support\Facades\DB;` to the `use` block at the top of this file (needed for the `DB::statement` check constraint call, mirroring `2026_08_02_000015_create_sparepart_branch_stocks_table.php`).

Create `database/migrations/2026_08_03_000002_create_work_order_service_lines_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateWorkOrderServiceLinesTable extends Migration
{
    public function up()
    {
        Schema::create('work_order_service_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('service_catalog_id')->nullable()->constrained('service_catalogs');
            $table->string('description', 255);
            $table->decimal('qty', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE work_order_service_lines ADD CONSTRAINT ck_wo_service_lines_qty_positive CHECK (qty > 0)");
        DB::statement("ALTER TABLE work_order_service_lines ADD CONSTRAINT ck_wo_service_lines_price_nonnegative CHECK (unit_price >= 0 AND line_total >= 0)");
    }

    public function down()
    {
        Schema::dropIfExists('work_order_service_lines');
    }
}
```

Create `database/migrations/2026_08_03_000003_create_work_order_sparepart_lines_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateWorkOrderSparepartLinesTable extends Migration
{
    public function up()
    {
        Schema::create('work_order_sparepart_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('sparepart_branch_id')->constrained('sparepart_branches');
            $table->string('item_code_snapshot', 30);
            $table->string('item_name_snapshot', 150);
            $table->decimal('qty', 18, 3);
            $table->decimal('default_unit_price', 18, 2);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE work_order_sparepart_lines ADD CONSTRAINT ck_wo_sparepart_lines_qty_positive CHECK (qty > 0)");
        DB::statement("ALTER TABLE work_order_sparepart_lines ADD CONSTRAINT ck_wo_sparepart_lines_price_nonnegative CHECK (default_unit_price >= 0 AND unit_price >= 0 AND line_total >= 0)");
    }

    public function down()
    {
        Schema::dropIfExists('work_order_sparepart_lines');
    }
}
```

- [ ] **Step 5: Create the models**

Create `app/Models/WorkOrder.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\WorkOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'branch_id', 'customer_id', 'vehicle_id', 'mechanic_id',
        'work_order_date', 'odometer_km', 'status', 'notes',
    ];

    protected $casts = [
        'work_order_date' => 'date',
        'odometer_km' => 'decimal:1',
    ];

    protected $attributes = [
        'status' => WorkOrderStatus::DRAFT,
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function mechanic()
    {
        return $this->belongsTo(Mechanic::class);
    }

    public function serviceLines()
    {
        return $this->hasMany(WorkOrderServiceLine::class)->orderBy('sort_order');
    }

    public function sparepartLines()
    {
        return $this->hasMany(WorkOrderSparepartLine::class)->orderBy('sort_order');
    }
}
```

Create `app/Models/WorkOrderServiceLine.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrderServiceLine extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'work_order_id', 'service_catalog_id', 'description', 'qty', 'unit_price', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function serviceCatalog()
    {
        return $this->belongsTo(ServiceCatalog::class);
    }
}
```

Create `app/Models/WorkOrderSparepartLine.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrderSparepartLine extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'work_order_id', 'sparepart_branch_id', 'item_code_snapshot', 'item_name_snapshot',
        'qty', 'default_unit_price', 'unit_price', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'default_unit_price' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function sparepartBranch()
    {
        return $this->belongsTo(SparepartBranch::class);
    }
}
```

- [ ] **Step 6: Run migrations and tests to verify they pass**

Run: `php artisan migrate` then `php artisan test --filter=WorkOrderModelTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Support/WorkOrderStatus.php app/Models/WorkOrder.php app/Models/WorkOrderServiceLine.php app/Models/WorkOrderSparepartLine.php database/migrations/2026_08_03_000001_create_work_orders_table.php database/migrations/2026_08_03_000002_create_work_order_service_lines_table.php database/migrations/2026_08_03_000003_create_work_order_sparepart_lines_table.php tests/Feature/WorkOrderModelTest.php
git commit -m "feat: add PKB/work order data model (header + jasa/sparepart lines)"
```

---

### Task 2: Authorization — `WorkOrderPolicy`

**Files:**
- Create: `app/Policies/WorkOrderPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php:15-17` (add to `$policies` array)
- Test: `tests/Feature/WorkOrderAuthorizationTest.php`

**Interfaces:**
- Consumes: `WorkOrder` (Task 1), `WorkOrderStatus` (Task 1), `User::hasPermissionToInBranch(string $code, int $branchId): bool` (already exists).
- Produces: `WorkOrderPolicy::view/update/cancel(User, WorkOrder): bool`, consumed by Task 4's controller via `$this->authorize('view'|'update'|'cancel', $workOrder)`.

- [ ] **Step 1: Write the failing Policy tests**

Create `tests/Feature/WorkOrderAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\UserBranchService;
use App\Support\WorkOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderAuthorizationTest extends TestCase
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

    protected function makeWorkOrder(Branch $branch, array $overrides = []): WorkOrder
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);

        return WorkOrder::create(array_merge([
            'number' => 'PKB/JKT/202608/00001',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->format('Y-m-d'),
        ], $overrides));
    }

    public function test_policy_grants_view_and_update_for_the_correct_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $workOrder = $this->makeWorkOrder($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $workOrder));
        $this->assertTrue($reloaded->can('update', $workOrder));
    }

    public function test_policy_denies_access_for_a_user_with_permission_in_a_different_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'pkb.view');
        $workOrder = $this->makeWorkOrder($branchB);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('view', $workOrder));
    }

    public function test_policy_update_requires_edit_code_not_just_view(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $workOrder = $this->makeWorkOrder($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $workOrder));
        $this->assertFalse($reloaded->can('update', $workOrder));
    }

    public function test_policy_denies_update_and_cancel_for_a_cancelled_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $workOrder = $this->makeWorkOrder($branch, ['status' => WorkOrderStatus::CANCELLED]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('update', $workOrder));
        $this->assertFalse($reloaded->can('cancel', $workOrder));
    }

    public function test_policy_grants_cancel_for_a_draft_work_order_with_cancel_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $workOrder = $this->makeWorkOrder($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('cancel', $workOrder));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=WorkOrderAuthorizationTest`
Expected: FAIL — `WorkOrderPolicy` class doesn't exist / isn't registered.

- [ ] **Step 3: Create the Policy**

Create `app/Policies/WorkOrderPolicy.php`:

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
        return $workOrder->status === WorkOrderStatus::DRAFT
            && $user->hasPermissionToInBranch('pkb.cancel', $workOrder->branch_id);
    }
}
```

- [ ] **Step 4: Register the Policy**

In `app/Providers/AuthServiceProvider.php:15-17`, add the mapping:

```php
    protected $policies = [
        \App\Models\SparepartBranch::class => \App\Policies\SparepartBranchPolicy::class,
        \App\Models\WorkOrder::class => \App\Policies\WorkOrderPolicy::class,
    ];
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=WorkOrderAuthorizationTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Policies/WorkOrderPolicy.php app/Providers/AuthServiceProvider.php tests/Feature/WorkOrderAuthorizationTest.php
git commit -m "feat: add branch-scoped, status-aware WorkOrderPolicy"
```

---

### Task 3: Lookup endpoints for the cascading create/edit form

**Files:**
- Create: `app/Http/Controllers/WorkOrderLookupController.php`
- Modify: `routes/web.php` (add `work-orders` lookup routes)
- Test: `tests/Feature/WorkOrderLookupTest.php`

**Interfaces:**
- Consumes: `Customer::hasAccessToBranch()`, `Customer::branches()` (already exist), `Mechanic::hasAccessToBranch()` (already exists), `SparepartBranch` + `Sparepart`/`SparepartBranchStock` relations (already exist), `Vehicle::customer()` (already exists).
- Produces: 4 JSON endpoints consumed by Task 4's create/edit views' JS: `GET /work-orders/lookup/customers/{branch}`, `GET /work-orders/lookup/vehicles/{customer}`, `GET /work-orders/lookup/mechanics/{branch}`, `GET /work-orders/lookup/spareparts/{branch}`. Response shapes (JSON arrays of objects) are fixed by this task and consumed as-is by Task 4 — do not change field names later without updating the JS.
  - `customersByBranch`: `[{id, name}]`
  - `vehiclesByCustomer`: `[{id, plate_number, frame_number}]`
  - `mechanicsByBranch`: `[{id, name}]`
  - `sparepartsByBranch`: `[{id, code, name, selling_price, available_qty}]` (`id` is the `sparepart_branch_id`)

- [ ] **Step 1: Write the failing lookup tests**

Create `tests/Feature/WorkOrderLookupTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderLookupTest extends TestCase
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

    public function test_customers_by_branch_returns_only_customers_servable_in_that_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customerA->id, 'branch_id' => $branchA->id]);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah']);
        CustomerBranch::create(['customer_id' => $customerB->id, 'branch_id' => $branchB->id]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->getJson("/work-orders/lookup/customers/{$branchA->id}");

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Budi Santoso']);
        $response->assertJsonMissing(['name' => 'Siti Aminah']);
    }

    public function test_customers_by_branch_is_forbidden_without_pkb_create_in_that_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson("/work-orders/lookup/customers/{$branch->id}");

        $response->assertForbidden();
    }

    public function test_vehicles_by_customer_returns_only_that_customers_active_vehicles(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $otherCustomer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah']);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        Vehicle::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ']);
        Vehicle::create(['customer_id' => $otherCustomer->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 9999 ZZZ']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->getJson("/work-orders/lookup/vehicles/{$customer->id}");

        $response->assertOk();
        $response->assertJsonFragment(['plate_number' => 'B 1234 XYZ']);
        $response->assertJsonMissing(['plate_number' => 'B 9999 ZZZ']);
    }

    public function test_vehicles_by_customer_is_forbidden_when_user_has_no_pkb_create_in_any_shared_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson("/work-orders/lookup/vehicles/{$customer->id}");

        $response->assertForbidden();
    }

    public function test_mechanics_by_branch_returns_only_active_mechanics_assigned_to_that_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $mechanicA = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanicA->id, 'branch_id' => $branchA->id]);
        $mechanicB = Mechanic::create(['name' => 'Budi Hartono']);
        MechanicBranch::create(['mechanic_id' => $mechanicB->id, 'branch_id' => $branchB->id]);
        $inactiveMechanic = Mechanic::create(['name' => 'Non Aktif', 'is_active' => false]);
        MechanicBranch::create(['mechanic_id' => $inactiveMechanic->id, 'branch_id' => $branchA->id]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->getJson("/work-orders/lookup/mechanics/{$branchA->id}");

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Agus Setiawan']);
        $response->assertJsonMissing(['name' => 'Budi Hartono']);
        $response->assertJsonMissing(['name' => 'Non Aktif']);
    }

    public function test_spareparts_by_branch_returns_only_active_configs_for_that_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $configA = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchA->id, 'selling_price' => 60000]);
        $inactiveSparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        SparepartBranch::create(['sparepart_id' => $inactiveSparepart->id, 'branch_id' => $branchA->id, 'selling_price' => 100000, 'is_active' => false]);
        $sparepartInB = Sparepart::create(['code' => 'FIL-01', 'name' => 'Filter Udara']);
        SparepartBranch::create(['sparepart_id' => $sparepartInB->id, 'branch_id' => $branchB->id, 'selling_price' => 40000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->getJson("/work-orders/lookup/spareparts/{$branchA->id}");

        $response->assertOk();
        $response->assertJsonFragment(['code' => 'OLI-01', 'id' => $configA->id]);
        $response->assertJsonMissing(['code' => 'BAN-01']);
        $response->assertJsonMissing(['code' => 'FIL-01']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=WorkOrderLookupTest`
Expected: FAIL — routes/controller don't exist (404).

- [ ] **Step 3: Create the lookup controller**

Create `app/Http/Controllers/WorkOrderLookupController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\SparepartBranch;

class WorkOrderLookupController extends Controller
{
    public function customersByBranch(Branch $branch)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('pkb.create', $branch->id), 403);

        return response()->json(
            Customer::whereHas('customerBranches', function ($query) use ($branch) {
                $query->where('branch_id', $branch->id)->where('is_active', true);
            })
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
        );
    }

    public function vehiclesByCustomer(Customer $customer)
    {
        $userBranchIds = auth()->user()->branchesWithPermission('pkb.create')->pluck('id');
        $customerBranchIds = $customer->branches->pluck('id');
        abort_unless($userBranchIds->intersect($customerBranchIds)->isNotEmpty(), 403);

        return response()->json(
            $customer->vehicles()->where('is_active', true)->orderBy('plate_number')->get(['id', 'plate_number', 'frame_number'])
        );
    }

    public function mechanicsByBranch(Branch $branch)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('pkb.create', $branch->id), 403);

        return response()->json(
            Mechanic::whereHas('mechanicBranches', function ($query) use ($branch) {
                $query->where('branch_id', $branch->id)->where('is_active', true);
            })
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
        );
    }

    public function sparepartsByBranch(Branch $branch)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('pkb.create', $branch->id), 403);

        return response()->json(
            SparepartBranch::with(['sparepart', 'stock'])
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->get()
                ->map(function (SparepartBranch $sparepartBranch) {
                    return [
                        'id' => $sparepartBranch->id,
                        'code' => $sparepartBranch->sparepart->code,
                        'name' => $sparepartBranch->sparepart->name,
                        'selling_price' => (float) $sparepartBranch->selling_price,
                        'available_qty' => (float) $sparepartBranch->stock->available_qty,
                    ];
                })
                ->values()
        );
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, add `use App\Http\Controllers\WorkOrderLookupController;` to the `use` block at the top, and add this group inside the `Route::middleware(['auth'])->group(function () { ... })` block, after the `sparepart-branches` group (before `users`):

```php
    Route::prefix('work-orders')->name('work-orders.')->group(function () {
        Route::get('/lookup/customers/{branch}', [WorkOrderLookupController::class, 'customersByBranch'])->name('lookup.customers');
        Route::get('/lookup/vehicles/{customer}', [WorkOrderLookupController::class, 'vehiclesByCustomer'])->name('lookup.vehicles');
        Route::get('/lookup/mechanics/{branch}', [WorkOrderLookupController::class, 'mechanicsByBranch'])->name('lookup.mechanics');
        Route::get('/lookup/spareparts/{branch}', [WorkOrderLookupController::class, 'sparepartsByBranch'])->name('lookup.spareparts');
    });
```

(The rest of the `work-orders` group — `index`/`create`/`store`/etc. — is added by Task 4 inside this same group. Task 4 must insert its routes into this same `Route::prefix('work-orders')` block, not create a second one.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=WorkOrderLookupTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/WorkOrderLookupController.php routes/web.php tests/Feature/WorkOrderLookupTest.php
git commit -m "feat: add branch/customer-scoped lookup endpoints for the PKB form"
```

---

### Task 4: CRUD controller, FormRequests, and views

**Files:**
- Create: `app/Http/Requests/StoreWorkOrderRequest.php`
- Create: `app/Http/Requests/UpdateWorkOrderRequest.php`
- Create: `app/Http/Controllers/WorkOrderController.php`
- Create: `resources/views/work-orders/index.blade.php`
- Create: `resources/views/work-orders/no-access.blade.php`
- Create: `resources/views/work-orders/create.blade.php`
- Create: `resources/views/work-orders/edit.blade.php`
- Create: `resources/views/work-orders/show.blade.php`
- Modify: `routes/web.php` (add CRUD routes into the existing `work-orders` group from Task 3)
- Test: `tests/Feature/WorkOrderManagementTest.php`

**Interfaces:**
- Consumes: `WorkOrderLookupController`'s 4 JSON endpoints and their exact response shapes (Task 3); `WorkOrderPolicy` (Task 2); `WorkOrder`/`WorkOrderServiceLine`/`WorkOrderSparepartLine`/`WorkOrderStatus` (Task 1); `partials.list-filter-bar`, `partials.empty-state`, `partials.branch-multiselect-filter` (already exist); `DocumentNumberGenerator::next(Branch $branch, string $documentType): string` (already exists, already tested with `'PKB'` as the type in its own test suite).
- Produces: routes `work-orders.index/create/store/show/edit/update/cancel`. Nothing later in this plan consumes these beyond Task 5's sidebar/dashboard links, which only need the route names, not any return shape.

- [ ] **Step 1: Write the failing management tests**

Create `tests/Feature/WorkOrderManagementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\UserBranchService;
use App\Support\WorkOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderManagementTest extends TestCase
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

    protected function makeScenario(Branch $branch): array
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
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
        $catalog = ServiceCatalog::create(['code' => 'SVC-01', 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        return compact('customer', 'vehicle', 'mechanic', 'catalog', 'sparepartBranch');
    }

    protected function baseStorePayload(Branch $branch, array $scenario): array
    {
        return [
            'branch_id' => $branch->id,
            'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->format('Y-m-d'),
            'odometer_km' => 15000,
            'notes' => 'Servis rutin',
            'services' => [
                ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 50000],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $scenario['sparepartBranch']->id, 'qty' => 2, 'unit_price' => 60000],
            ],
        ];
    }

    public function test_store_creates_work_order_with_header_and_both_line_types(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));

        $workOrder = WorkOrder::first();
        $response->assertRedirect(route('work-orders.show', $workOrder));
        $this->assertNotNull($workOrder);
        $this->assertSame(WorkOrderStatus::DRAFT, $workOrder->status);
        $this->assertStringStartsWith('PKB/JKT/', $workOrder->number);
        $this->assertCount(1, $workOrder->serviceLines);
        $this->assertCount(1, $workOrder->sparepartLines);
        $this->assertSame(50000.0, (float) $workOrder->serviceLines->first()->line_total);
        $this->assertSame(120000.0, (float) $workOrder->sparepartLines->first()->line_total);
    }

    public function test_store_recomputes_line_total_server_side_ignoring_client_value(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['services'][0]['line_total'] = 999999;

        $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $workOrder = WorkOrder::first();
        $this->assertSame(50000.0, (float) $workOrder->serviceLines->first()->line_total);
    }

    public function test_store_is_forbidden_without_pkb_create_in_the_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/work-orders', $this->baseStorePayload($branch, $scenario));

        $response->assertForbidden();
    }

    public function test_store_rejects_customer_not_servable_in_the_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $otherCustomer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['customer_id'] = $otherCustomer->id;

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors(['customer_id']);
    }

    public function test_store_rejects_vehicle_not_belonging_to_the_customer(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $otherCustomer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah']);
        CustomerBranch::create(['customer_id' => $otherCustomer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => 'Motor']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);
        $otherVehicle = Vehicle::create(['customer_id' => $otherCustomer->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 5555 AAA']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['vehicle_id'] = $otherVehicle->id;

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors(['vehicle_id']);
    }

    public function test_store_rejects_mechanic_not_assigned_to_the_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $otherMechanic = Mechanic::create(['name' => 'Mekanik Lain']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['mechanic_id'] = $otherMechanic->id;

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors(['mechanic_id']);
    }

    public function test_store_rejects_sparepart_from_a_different_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $otherBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $otherSparepart = Sparepart::create(['code' => 'FIL-01', 'name' => 'Filter Udara']);
        $otherSparepartBranch = SparepartBranch::create(['sparepart_id' => $otherSparepart->id, 'branch_id' => $otherBranch->id, 'selling_price' => 40000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['spareparts'][0]['sparepart_branch_id'] = $otherSparepartBranch->id;

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors();
    }

    public function test_store_rejects_a_work_order_with_no_lines_at_all(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $payload = $this->baseStorePayload($branch, $scenario);
        $payload['services'] = [];
        $payload['spareparts'] = [];

        $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

        $response->assertSessionHasErrors(['services']);
    }

    public function test_index_lists_work_orders_for_authorized_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $scenarioA = $this->makeScenario($branchA);
        $scenarioB = $this->makeScenario($branchB);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'pkb.view');
        $this->grantBranchPermission($user, $branchA, 'pkb.create');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branchA, $scenarioA));
        $this->grantBranchPermission($user, $branchB, 'pkb.create');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branchB, $scenarioB));

        $response = $this->actingAs(User::find($user->id))->get('/work-orders');

        $response->assertOk();
        $workOrderA = WorkOrder::where('branch_id', $branchA->id)->first();
        $workOrderB = WorkOrder::where('branch_id', $branchB->id)->first();
        $response->assertSee($workOrderA->number);
        $response->assertDontSee($workOrderB->number);
    }

    public function test_index_shows_no_access_page_without_any_pkb_view_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/work-orders');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_show_is_forbidden_for_a_work_order_in_an_unauthorized_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $creator = User::factory()->create();
        $this->grantBranchPermission($creator, $branch, 'pkb.create');
        $this->actingAs(User::find($creator->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->get("/work-orders/{$workOrder->id}");

        $response->assertForbidden();
    }

    public function test_update_replaces_lines_and_recomputes_totals(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();
        $updatePayload = [
            'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => null, 'description' => 'Servis tambahan', 'qty' => 2, 'unit_price' => 25000],
            ],
            'spareparts' => [],
        ];

        $response = $this->actingAs(User::find($user->id))->put("/work-orders/{$workOrder->id}", $updatePayload);

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $workOrder->refresh();
        $this->assertCount(1, $workOrder->serviceLines);
        $this->assertCount(0, $workOrder->sparepartLines);
        $this->assertSame(50000.0, (float) $workOrder->serviceLines->first()->line_total);
    }

    public function test_update_is_forbidden_for_a_cancelled_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();
        $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");
        $workOrder->refresh();

        $response = $this->actingAs(User::find($user->id))->put("/work-orders/{$workOrder->id}", [
            'customer_id' => $scenario['customer']->id, 'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => [['service_catalog_id' => null, 'description' => 'X', 'qty' => 1, 'unit_price' => 1000]],
        ]);

        $response->assertForbidden();
    }

    public function test_cancel_marks_work_order_cancelled(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $workOrder->refresh();
        $this->assertSame(WorkOrderStatus::CANCELLED, $workOrder->status);
    }

    public function test_cancel_is_forbidden_without_pkb_cancel_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
        $workOrder = WorkOrder::first();

        $response = $this->actingAs(User::find($user->id))->patch("/work-orders/{$workOrder->id}/cancel");

        $response->assertForbidden();
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders?q[]=PKB');

        $response->assertOk();
    }

    public function test_index_shows_empty_state_when_no_work_orders_match(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders');

        $response->assertOk();
        $response->assertSee('Belum ada PKB');
    }

    public function test_empty_state_cta_shown_with_create_permission_in_any_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders');

        $response->assertOk();
        $response->assertSee('Buat PKB Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs(User::find($user->id))->get('/work-orders');

        $response->assertOk();
        $response->assertDontSee('Buat PKB Pertama');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=WorkOrderManagementTest`
Expected: FAIL — controller/routes/views don't exist yet.

- [ ] **Step 3: Create the FormRequests**

Create `app/Http/Requests/StoreWorkOrderRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\SparepartBranch;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('pkb.create', $branchId);
    }

    public function rules()
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'mechanic_id' => ['required', 'integer', 'exists:mechanics,id'],
            'work_order_date' => ['required', 'date'],
            'odometer_km' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'services' => ['nullable', 'array'],
            'services.*.service_catalog_id' => ['nullable', 'integer', 'exists:service_catalogs,id'],
            'services.*.description' => ['required_with:services.*.qty', 'string', 'max:255'],
            'services.*.qty' => ['required_with:services.*.description', 'numeric', 'min:0.001'],
            'services.*.unit_price' => ['required_with:services.*.description', 'numeric', 'min:0'],
            'spareparts' => ['nullable', 'array'],
            'spareparts.*.sparepart_branch_id' => ['required_with:spareparts.*.qty', 'integer', 'exists:sparepart_branches,id'],
            'spareparts.*.qty' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0.001'],
            'spareparts.*.unit_price' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $this->validateCascadingBusinessRules($validator, (int) $this->input('branch_id'));
        });
    }

    protected function validateCascadingBusinessRules($validator, int $branchId): void
    {
        $customerId = $this->input('customer_id');
        $vehicleId = $this->input('vehicle_id');
        $mechanicId = $this->input('mechanic_id');

        if ($branchId && $customerId) {
            $customer = Customer::find($customerId);
            if ($customer && ! $customer->hasAccessToBranch($branchId)) {
                $validator->errors()->add('customer_id', 'Customer tidak dapat dilayani di cabang ini.');
            }
        }

        if ($customerId && $vehicleId) {
            $vehicle = Vehicle::find($vehicleId);
            if ($vehicle && (int) $vehicle->customer_id !== (int) $customerId) {
                $validator->errors()->add('vehicle_id', 'Kendaraan tidak sesuai dengan customer yang dipilih.');
            }
        }

        if ($branchId && $mechanicId) {
            $mechanic = Mechanic::find($mechanicId);
            if (! $mechanic || ! $mechanic->is_active || ! $mechanic->hasAccessToBranch($branchId)) {
                $validator->errors()->add('mechanic_id', 'Mekanik tidak aktif atau tidak ditugaskan di cabang ini.');
            }
        }

        $services = array_filter($this->input('services', []));
        $spareparts = array_filter($this->input('spareparts', []));
        if (empty($services) && empty($spareparts)) {
            $validator->errors()->add('services', 'PKB harus memiliki minimal satu baris jasa atau sparepart.');
        }

        if ($branchId) {
            foreach ($this->input('spareparts', []) as $index => $line) {
                $sparepartBranchId = $line['sparepart_branch_id'] ?? null;
                if (! $sparepartBranchId) {
                    continue;
                }
                $sparepartBranch = SparepartBranch::find($sparepartBranchId);
                if (! $sparepartBranch || ! $sparepartBranch->is_active || (int) $sparepartBranch->branch_id !== $branchId) {
                    $validator->errors()->add("spareparts.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang PKB ini.');
                }
            }
        }
    }
}
```

Create `app/Http/Requests/UpdateWorkOrderRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\SparepartBranch;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkOrderRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('update', $this->route('workOrder'));
    }

    public function rules()
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'mechanic_id' => ['required', 'integer', 'exists:mechanics,id'],
            'work_order_date' => ['required', 'date'],
            'odometer_km' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'services' => ['nullable', 'array'],
            'services.*.service_catalog_id' => ['nullable', 'integer', 'exists:service_catalogs,id'],
            'services.*.description' => ['required_with:services.*.qty', 'string', 'max:255'],
            'services.*.qty' => ['required_with:services.*.description', 'numeric', 'min:0.001'],
            'services.*.unit_price' => ['required_with:services.*.description', 'numeric', 'min:0'],
            'spareparts' => ['nullable', 'array'],
            'spareparts.*.sparepart_branch_id' => ['required_with:spareparts.*.qty', 'integer', 'exists:sparepart_branches,id'],
            'spareparts.*.qty' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0.001'],
            'spareparts.*.unit_price' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $branchId = (int) $this->route('workOrder')->branch_id;
            $customerId = $this->input('customer_id');
            $vehicleId = $this->input('vehicle_id');
            $mechanicId = $this->input('mechanic_id');

            if ($customerId) {
                $customer = Customer::find($customerId);
                if ($customer && ! $customer->hasAccessToBranch($branchId)) {
                    $validator->errors()->add('customer_id', 'Customer tidak dapat dilayani di cabang ini.');
                }
            }

            if ($customerId && $vehicleId) {
                $vehicle = Vehicle::find($vehicleId);
                if ($vehicle && (int) $vehicle->customer_id !== (int) $customerId) {
                    $validator->errors()->add('vehicle_id', 'Kendaraan tidak sesuai dengan customer yang dipilih.');
                }
            }

            if ($mechanicId) {
                $mechanic = Mechanic::find($mechanicId);
                if (! $mechanic || ! $mechanic->is_active || ! $mechanic->hasAccessToBranch($branchId)) {
                    $validator->errors()->add('mechanic_id', 'Mekanik tidak aktif atau tidak ditugaskan di cabang ini.');
                }
            }

            $services = array_filter($this->input('services', []));
            $spareparts = array_filter($this->input('spareparts', []));
            if (empty($services) && empty($spareparts)) {
                $validator->errors()->add('services', 'PKB harus memiliki minimal satu baris jasa atau sparepart.');
            }

            foreach ($this->input('spareparts', []) as $index => $line) {
                $sparepartBranchId = $line['sparepart_branch_id'] ?? null;
                if (! $sparepartBranchId) {
                    continue;
                }
                $sparepartBranch = SparepartBranch::find($sparepartBranchId);
                if (! $sparepartBranch || ! $sparepartBranch->is_active || (int) $sparepartBranch->branch_id !== $branchId) {
                    $validator->errors()->add("spareparts.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang PKB ini.');
                }
            }
        });
    }
}
```

Note: the duplication between `StoreWorkOrderRequest` and `UpdateWorkOrderRequest` mirrors this codebase's existing convention (every Store/Update FormRequest pair in this project is two independent classes — see `StoreVehicleRequest`/no update-equivalent exists there, but `StoreUserRequest`/`UpdateUserRequest`, `StoreBranchRequest`/`UpdateBranchRequest` — never a shared abstract base). Do not introduce a shared trait/base class for this.

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/WorkOrderController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\ServiceCatalog;
use App\Models\SparepartBranch;
use App\Models\WorkOrder;
use App\Models\WorkOrderServiceLine;
use App\Models\WorkOrderSparepartLine;
use App\Services\DocumentNumberGenerator;
use App\Support\WorkOrderStatus;
use Illuminate\Support\Facades\DB;

class WorkOrderController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('pkb.view');

        if ($permittedBranches->isEmpty()) {
            return view('work-orders.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $workOrders = WorkOrder::with(['branch', 'customer', 'vehicle', 'mechanic'])
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($search, function ($query, $q) {
                $query->where('number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->orderByDesc('work_order_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('work-orders.index', compact('workOrders'))
            ->with('branches', $permittedBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function create()
    {
        $branches = auth()->user()->branchesWithPermission('pkb.create');

        if ($branches->isEmpty()) {
            return view('work-orders.no-access');
        }

        return view('work-orders.create', compact('branches'));
    }

    public function store(StoreWorkOrderRequest $request)
    {
        $data = $request->validated();
        $branch = Branch::findOrFail($data['branch_id']);

        $workOrder = DB::transaction(function () use ($data, $branch) {
            $workOrder = WorkOrder::create([
                'number' => (new DocumentNumberGenerator())->next($branch, 'PKB'),
                'branch_id' => $branch->id,
                'customer_id' => $data['customer_id'],
                'vehicle_id' => $data['vehicle_id'],
                'mechanic_id' => $data['mechanic_id'],
                'work_order_date' => $data['work_order_date'],
                'odometer_km' => $data['odometer_km'] ?? null,
                'status' => WorkOrderStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncServiceLines($workOrder, $data['services'] ?? []);
            $this->syncSparepartLines($workOrder, $data['spareparts'] ?? []);

            return $workOrder;
        });

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB berhasil dibuat.');
    }

    public function show(WorkOrder $workOrder)
    {
        $this->authorize('view', $workOrder);

        $workOrder->load(['branch', 'customer', 'vehicle', 'mechanic', 'serviceLines', 'sparepartLines']);

        return view('work-orders.show', compact('workOrder'));
    }

    public function edit(WorkOrder $workOrder)
    {
        $this->authorize('update', $workOrder);

        $workOrder->load(['serviceLines', 'sparepartLines']);
        $customers = Customer::whereHas('customerBranches', function ($query) use ($workOrder) {
            $query->where('branch_id', $workOrder->branch_id)->where('is_active', true);
        })->where('is_active', true)->orderBy('name')->get();
        $mechanics = Mechanic::whereHas('mechanicBranches', function ($query) use ($workOrder) {
            $query->where('branch_id', $workOrder->branch_id)->where('is_active', true);
        })->where('is_active', true)->orderBy('name')->get();
        $sparepartBranches = SparepartBranch::with(['sparepart', 'stock'])
            ->where('branch_id', $workOrder->branch_id)
            ->where('is_active', true)
            ->get();
        $serviceCatalogs = ServiceCatalog::where('is_active', true)->orderBy('name')->get();
        $vehicles = $workOrder->customer->vehicles()->where('is_active', true)->orderBy('plate_number')->get();

        return view('work-orders.edit', compact('workOrder', 'customers', 'mechanics', 'sparepartBranches', 'serviceCatalogs', 'vehicles'));
    }

    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $workOrder) {
            $workOrder->update([
                'customer_id' => $data['customer_id'],
                'vehicle_id' => $data['vehicle_id'],
                'mechanic_id' => $data['mechanic_id'],
                'work_order_date' => $data['work_order_date'],
                'odometer_km' => $data['odometer_km'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncServiceLines($workOrder, $data['services'] ?? []);
            $this->syncSparepartLines($workOrder, $data['spareparts'] ?? []);
        });

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB berhasil diperbarui.');
    }

    public function cancel(WorkOrder $workOrder)
    {
        $this->authorize('cancel', $workOrder);

        $workOrder->update(['status' => WorkOrderStatus::CANCELLED]);

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB berhasil dibatalkan.');
    }

    protected function syncServiceLines(WorkOrder $workOrder, array $lines): void
    {
        $workOrder->serviceLines()->delete();

        foreach (array_values(array_filter($lines)) as $index => $line) {
            $qty = (float) $line['qty'];
            $unitPrice = (float) $line['unit_price'];
            WorkOrderServiceLine::create([
                'work_order_id' => $workOrder->id,
                'service_catalog_id' => $line['service_catalog_id'] ?? null,
                'description' => $line['description'],
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => round($qty * $unitPrice, 2),
                'sort_order' => $index,
            ]);
        }
    }

    protected function syncSparepartLines(WorkOrder $workOrder, array $lines): void
    {
        $workOrder->sparepartLines()->delete();

        foreach (array_values(array_filter($lines)) as $index => $line) {
            $sparepartBranch = SparepartBranch::with('sparepart')->findOrFail($line['sparepart_branch_id']);
            $qty = (float) $line['qty'];
            $unitPrice = (float) $line['unit_price'];
            WorkOrderSparepartLine::create([
                'work_order_id' => $workOrder->id,
                'sparepart_branch_id' => $sparepartBranch->id,
                'item_code_snapshot' => $sparepartBranch->sparepart->code,
                'item_name_snapshot' => $sparepartBranch->sparepart->name,
                'qty' => $qty,
                'default_unit_price' => $sparepartBranch->selling_price,
                'unit_price' => $unitPrice,
                'line_total' => round($qty * $unitPrice, 2),
                'sort_order' => $index,
            ]);
        }
    }
}
```

Note: `branch_id` is deliberately absent from `update()`'s data — the PKB's branch never changes after creation (matches the design spec; `UpdateWorkOrderRequest` has no `branch_id` field at all, and `WorkOrderController::edit()` computes `$customers`/`$mechanics`/`$sparepartBranches` synchronously from `$workOrder->branch_id` since that branch is fixed — only the customer→vehicle cascade still needs the AJAX lookup on the edit page, via the same `lookup.vehicles` endpoint from Task 3).

- [ ] **Step 5: Add the CRUD routes**

In `routes/web.php`, add `use App\Http\Controllers\WorkOrderController;` to the `use` block, and add these routes **inside the existing `work-orders` group created in Task 3** (before the closing `});` of that group, after the 4 `lookup.*` routes):

```php
        Route::get('/', [WorkOrderController::class, 'index'])->name('index');
        Route::get('/create', [WorkOrderController::class, 'create'])->name('create');
        Route::post('/', [WorkOrderController::class, 'store'])->name('store');
        Route::get('/{workOrder}', [WorkOrderController::class, 'show'])->name('show');
        Route::get('/{workOrder}/edit', [WorkOrderController::class, 'edit'])->name('edit');
        Route::put('/{workOrder}', [WorkOrderController::class, 'update'])->name('update');
        Route::patch('/{workOrder}/cancel', [WorkOrderController::class, 'cancel'])->name('cancel');
```

- [ ] **Step 6: Create the index view**

Create `resources/views/work-orders/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Perintah Kerja Bengkel')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-clipboard-check me-2"></i>Perintah Kerja Bengkel</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor PKB...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => auth()->user()->branchesWithPermission('pkb.create')->isNotEmpty()
            ? '<a href="' . route('work-orders.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> PKB Baru</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nomor PKB</th>
                        <th>Cabang</th>
                        <th>Customer</th>
                        <th>Kendaraan</th>
                        <th>Mekanik</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($workOrders as $workOrder)
                        <tr>
                            <td><code>{{ $workOrder->number }}</code></td>
                            <td>{{ $workOrder->branch->name }}</td>
                            <td>{{ $workOrder->customer->name }}</td>
                            <td>{{ $workOrder->vehicle->plate_number ?? '-' }}</td>
                            <td>{{ $workOrder->mechanic->name }}</td>
                            <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                            <td>
                                @if ($workOrder->status === \App\Support\WorkOrderStatus::DRAFT)
                                    <span class="status-dot status-active">Draft</span>
                                @else
                                    <span class="status-dot status-inactive">Dibatalkan</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('work-orders.show', $workOrder) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-clipboard-check',
                                    'title' => 'Belum ada PKB',
                                    'description' => 'Mulai dengan membuat PKB pertama.',
                                    'ctaRoute' => 'work-orders.create',
                                    'ctaLabel' => '+ Buat PKB Pertama',
                                    'ctaVisible' => auth()->user()->branchesWithPermission('pkb.create')->isNotEmpty(),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $workOrders->links() }}
    </div>
@endsection
```

Create `resources/views/work-orders/no-access.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Perintah Kerja Bengkel')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-clipboard-check me-2"></i>Perintah Kerja Bengkel</h1>
    </div>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            Anda belum memiliki akses PKB di cabang manapun. Hubungi admin untuk meminta akses.
        </div>
    </div>
@endsection
```

- [ ] **Step 7: Create the create/edit line-item template partial**

Since create and edit share the same jasa/sparepart line-row markup and add/remove JS, extract that shared piece into `resources/views/work-orders/_line_item_scripts.blade.php` (included by both `create.blade.php` and `edit.blade.php`):

```blade
<template id="serviceLineTemplate">
    <div class="row g-2 align-items-start mb-2 service-line">
        <div class="col-md-3">
            <select class="form-select service-catalog-select" data-name-prefix="services">
                <option value="">-- Manual --</option>
                @foreach ($serviceCatalogs as $catalog)
                    <option value="{{ $catalog->id }}" data-price="{{ $catalog->default_price }}" data-name="{{ $catalog->name }}">{{ $catalog->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <input type="text" class="form-control service-description" placeholder="Deskripsi jasa">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" min="0.001" class="form-control service-qty" value="1">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" min="0" class="form-control service-unit-price">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-line">&times;</button>
        </div>
    </div>
</template>

<template id="sparepartLineTemplate">
    <div class="row g-2 align-items-start mb-2 sparepart-line">
        <div class="col-md-5">
            <select class="form-select sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
            <div class="form-text sparepart-availability"></div>
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" min="0.001" class="form-control sparepart-qty" value="1">
        </div>
        <div class="col-md-2">
            <input type="number" step="0.01" min="0" class="form-control sparepart-unit-price">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-line">&times;</button>
        </div>
    </div>
</template>

@push('scripts')
<script>
(function () {
    let serviceLineCount = 0;
    let sparepartLineCount = 0;
    let sparepartOptionsCache = [];

    function fillSelect(select, items, placeholder, valueKey, labelFn) {
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        items.forEach(function (item) {
            const option = document.createElement('option');
            option.value = item[valueKey];
            option.textContent = labelFn(item);
            option.dataset.item = JSON.stringify(item);
            select.appendChild(option);
        });
    }

    async function fetchJson(url) {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        return response.json();
    }

    function addServiceLine() {
        const template = document.getElementById('serviceLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.service-line');
        const index = serviceLineCount++;
        wrapper.querySelector('.service-catalog-select').name = `services[${index}][service_catalog_id]`;
        wrapper.querySelector('.service-description').name = `services[${index}][description]`;
        wrapper.querySelector('.service-qty').name = `services[${index}][qty]`;
        wrapper.querySelector('.service-unit-price').name = `services[${index}][unit_price]`;
        wrapper.querySelector('.service-catalog-select').addEventListener('change', function () {
            const selected = this.selectedOptions[0];
            const description = wrapper.querySelector('.service-description');
            const unitPrice = wrapper.querySelector('.service-unit-price');
            if (this.value) {
                description.value = selected.dataset.name || '';
                unitPrice.value = selected.dataset.price || 0;
            }
        });
        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('serviceLines').appendChild(wrapper);
    }

    function addSparepartLine() {
        const template = document.getElementById('sparepartLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.sparepart-line');
        const index = sparepartLineCount++;
        const select = wrapper.querySelector('.sparepart-select');
        select.name = `spareparts[${index}][sparepart_branch_id]`;
        wrapper.querySelector('.sparepart-qty').name = `spareparts[${index}][qty]`;
        wrapper.querySelector('.sparepart-unit-price').name = `spareparts[${index}][unit_price]`;
        fillSelect(select, sparepartOptionsCache, '-- Pilih Sparepart --', 'id', function (item) {
            return item.code + ' — ' + item.name;
        });
        select.addEventListener('change', function () {
            const selected = this.selectedOptions[0];
            const availability = wrapper.querySelector('.sparepart-availability');
            const unitPrice = wrapper.querySelector('.sparepart-unit-price');
            if (this.value && selected.dataset.item) {
                const item = JSON.parse(selected.dataset.item);
                unitPrice.value = item.selling_price;
                availability.textContent = 'Stok tersedia: ' + item.available_qty;
            } else {
                availability.textContent = '';
            }
        });
        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('sparepartLines').appendChild(wrapper);
    }

    document.getElementById('addServiceLine').addEventListener('click', addServiceLine);
    document.getElementById('addSparepartLine').addEventListener('click', addSparepartLine);

    window.WorkOrderLineItems = {
        setSparepartOptions: function (items) {
            sparepartOptionsCache = items;
            document.querySelectorAll('.sparepart-select').forEach(function (select) {
                const currentValue = select.value;
                fillSelect(select, items, '-- Pilih Sparepart --', 'id', function (item) {
                    return item.code + ' — ' + item.name;
                });
                select.value = currentValue;
            });
        },
        addServiceLine: addServiceLine,
        addSparepartLine: addSparepartLine,
        fetchJson: fetchJson,
        fillSelect: fillSelect,
    };
})();
</script>
@endpush
```

Note: `addSparepartLine`'s "add" button starts `disabled` in both `create.blade.php` and `edit.blade.php` until sparepart options are loaded for the chosen branch (create: after the branch AJAX call resolves; edit: immediately, since the branch is fixed and known at page load) — each view's own inline script (Steps 8/9) is responsible for enabling it and calling `WorkOrderLineItems.setSparepartOptions(...)` once data is available.

- [ ] **Step 8: Create the create view**

Create `resources/views/work-orders/create.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'PKB Baru')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-clipboard-check me-2"></i>PKB Baru</h1>
    </div>
    <form method="POST" action="{{ route('work-orders.store') }}" id="workOrderForm">
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
                        <label class="form-label">Customer</label>
                        <select name="customer_id" id="customerSelect" class="form-select @error('customer_id') is-invalid @enderror" required disabled>
                            <option value="">-- Pilih Cabang Dulu --</option>
                        </select>
                        @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kendaraan</label>
                        <select name="vehicle_id" id="vehicleSelect" class="form-select @error('vehicle_id') is-invalid @enderror" required disabled>
                            <option value="">-- Pilih Customer Dulu --</option>
                        </select>
                        @error('vehicle_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mekanik</label>
                        <select name="mechanic_id" id="mechanicSelect" class="form-select @error('mechanic_id') is-invalid @enderror" required disabled>
                            <option value="">-- Pilih Cabang Dulu --</option>
                        </select>
                        @error('mechanic_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tanggal PKB</label>
                        <input type="date" name="work_order_date" value="{{ old('work_order_date', now()->format('Y-m-d')) }}" class="form-control @error('work_order_date') is-invalid @enderror" required>
                        @error('work_order_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kilometer</label>
                        <input type="number" step="0.1" min="0" name="odometer_km" value="{{ old('odometer_km') }}" class="form-control @error('odometer_km') is-invalid @enderror">
                        @error('odometer_km')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
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
                    <h2 class="h6 mb-0">Baris Jasa</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addServiceLine">+ Tambah Jasa</button>
                </div>
                <div id="serviceLines"></div>
                @error('services')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Sparepart</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addSparepartLine" disabled>+ Tambah Sparepart</button>
                </div>
                <div id="sparepartLines"></div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('work-orders.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('work-orders._line_item_scripts', ['serviceCatalogs' => \App\Models\ServiceCatalog::where('is_active', true)->orderBy('name')->get()])

    @push('scripts')
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const customerSelect = document.getElementById('customerSelect');
        const vehicleSelect = document.getElementById('vehicleSelect');
        const mechanicSelect = document.getElementById('mechanicSelect');
        const addSparepartButton = document.getElementById('addSparepartLine');

        branchSelect.addEventListener('change', async function () {
            customerSelect.disabled = true;
            mechanicSelect.disabled = true;
            addSparepartButton.disabled = true;
            WorkOrderLineItems.fillSelect(vehicleSelect, [], '-- Pilih Customer Dulu --', 'id', function (i) { return i.plate_number; });
            vehicleSelect.disabled = true;
            if (!this.value) {
                WorkOrderLineItems.fillSelect(customerSelect, [], '-- Pilih Cabang Dulu --', 'id', function (i) { return i.name; });
                WorkOrderLineItems.fillSelect(mechanicSelect, [], '-- Pilih Cabang Dulu --', 'id', function (i) { return i.name; });
                return;
            }
            const [customers, mechanics, spareparts] = await Promise.all([
                WorkOrderLineItems.fetchJson(`/work-orders/lookup/customers/${this.value}`),
                WorkOrderLineItems.fetchJson(`/work-orders/lookup/mechanics/${this.value}`),
                WorkOrderLineItems.fetchJson(`/work-orders/lookup/spareparts/${this.value}`),
            ]);
            WorkOrderLineItems.fillSelect(customerSelect, customers, '-- Pilih Customer --', 'id', function (i) { return i.name; });
            customerSelect.disabled = false;
            WorkOrderLineItems.fillSelect(mechanicSelect, mechanics, '-- Pilih Mekanik --', 'id', function (i) { return i.name; });
            mechanicSelect.disabled = false;
            WorkOrderLineItems.setSparepartOptions(spareparts);
            addSparepartButton.disabled = false;
        });

        customerSelect.addEventListener('change', async function () {
            if (!this.value) {
                WorkOrderLineItems.fillSelect(vehicleSelect, [], '-- Pilih Customer Dulu --', 'id', function (i) { return i.plate_number; });
                vehicleSelect.disabled = true;
                return;
            }
            const vehicles = await WorkOrderLineItems.fetchJson(`/work-orders/lookup/vehicles/${this.value}`);
            WorkOrderLineItems.fillSelect(vehicleSelect, vehicles, '-- Pilih Kendaraan --', 'id', function (i) { return i.plate_number || i.frame_number; });
            vehicleSelect.disabled = false;
        });
    })();
    </script>
    @endpush
@endsection
```

- [ ] **Step 9: Create the edit view**

Create `resources/views/work-orders/edit.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Ubah PKB')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-clipboard-check me-2"></i>Ubah PKB {{ $workOrder->number }} — {{ $workOrder->branch->name }}</h1>
    </div>
    <form method="POST" action="{{ route('work-orders.update', $workOrder) }}" id="workOrderForm">
        @csrf
        @method('PUT')
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" id="customerSelect" class="form-select @error('customer_id') is-invalid @enderror" required>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}" {{ (int) old('customer_id', $workOrder->customer_id) === $customer->id ? 'selected' : '' }}>{{ $customer->name }}</option>
                            @endforeach
                        </select>
                        @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kendaraan</label>
                        <select name="vehicle_id" id="vehicleSelect" class="form-select @error('vehicle_id') is-invalid @enderror" required>
                            @foreach ($vehicles as $vehicle)
                                <option value="{{ $vehicle->id }}" {{ (int) old('vehicle_id', $workOrder->vehicle_id) === $vehicle->id ? 'selected' : '' }}>{{ $vehicle->plate_number ?? $vehicle->frame_number }}</option>
                            @endforeach
                        </select>
                        @error('vehicle_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mekanik</label>
                        <select name="mechanic_id" id="mechanicSelect" class="form-select @error('mechanic_id') is-invalid @enderror" required>
                            @foreach ($mechanics as $mechanic)
                                <option value="{{ $mechanic->id }}" {{ (int) old('mechanic_id', $workOrder->mechanic_id) === $mechanic->id ? 'selected' : '' }}>{{ $mechanic->name }}</option>
                            @endforeach
                        </select>
                        @error('mechanic_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tanggal PKB</label>
                        <input type="date" name="work_order_date" value="{{ old('work_order_date', $workOrder->work_order_date->format('Y-m-d')) }}" class="form-control @error('work_order_date') is-invalid @enderror" required>
                        @error('work_order_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kilometer</label>
                        <input type="number" step="0.1" min="0" name="odometer_km" value="{{ old('odometer_km', $workOrder->odometer_km) }}" class="form-control @error('odometer_km') is-invalid @enderror">
                        @error('odometer_km')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control @error('notes') is-invalid @enderror" rows="1">{{ old('notes', $workOrder->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Jasa</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addServiceLine">+ Tambah Jasa</button>
                </div>
                <div id="serviceLines"></div>
                @error('services')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Sparepart</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addSparepartLine">+ Tambah Sparepart</button>
                </div>
                <div id="sparepartLines"></div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('work-orders.show', $workOrder) }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @include('work-orders._line_item_scripts', ['serviceCatalogs' => $serviceCatalogs])

    @push('scripts')
    <script>
    (function () {
        const customerSelect = document.getElementById('customerSelect');
        const vehicleSelect = document.getElementById('vehicleSelect');

        const existingSparepartOptions = @json($sparepartBranches->map(function ($sb) {
            return [
                'id' => $sb->id,
                'code' => $sb->sparepart->code,
                'name' => $sb->sparepart->name,
                'selling_price' => (float) $sb->selling_price,
                'available_qty' => (float) $sb->stock->available_qty,
            ];
        }));
        WorkOrderLineItems.setSparepartOptions(existingSparepartOptions);

        const existingServiceLines = @json($workOrder->serviceLines->map(function ($line) {
            return [
                'service_catalog_id' => $line->service_catalog_id,
                'description' => $line->description,
                'qty' => (float) $line->qty,
                'unit_price' => (float) $line->unit_price,
            ];
        }));
        existingServiceLines.forEach(function (line) {
            WorkOrderLineItems.addServiceLine();
            const rows = document.querySelectorAll('#serviceLines .service-line');
            const row = rows[rows.length - 1];
            if (line.service_catalog_id) row.querySelector('.service-catalog-select').value = line.service_catalog_id;
            row.querySelector('.service-description').value = line.description;
            row.querySelector('.service-qty').value = line.qty;
            row.querySelector('.service-unit-price').value = line.unit_price;
        });

        const existingSparepartLines = @json($workOrder->sparepartLines->map(function ($line) {
            return [
                'sparepart_branch_id' => $line->sparepart_branch_id,
                'qty' => (float) $line->qty,
                'unit_price' => (float) $line->unit_price,
            ];
        }));
        existingSparepartLines.forEach(function (line) {
            WorkOrderLineItems.addSparepartLine();
            const rows = document.querySelectorAll('#sparepartLines .sparepart-line');
            const row = rows[rows.length - 1];
            row.querySelector('.sparepart-select').value = line.sparepart_branch_id;
            row.querySelector('.sparepart-qty').value = line.qty;
            row.querySelector('.sparepart-unit-price').value = line.unit_price;
        });

        customerSelect.addEventListener('change', async function () {
            if (!this.value) {
                WorkOrderLineItems.fillSelect(vehicleSelect, [], '-- Pilih Kendaraan --', 'id', function (i) { return i.plate_number; });
                return;
            }
            const vehicles = await WorkOrderLineItems.fetchJson(`/work-orders/lookup/vehicles/${this.value}`);
            WorkOrderLineItems.fillSelect(vehicleSelect, vehicles, '-- Pilih Kendaraan --', 'id', function (i) { return i.plate_number || i.frame_number; });
        });
    })();
    </script>
    @endpush
@endsection
```

- [ ] **Step 10: Create the show (detail) view**

Create `resources/views/work-orders/show.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Detail PKB')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-clipboard-check me-2"></i>{{ $workOrder->number }}</h1>
        <div class="d-flex gap-2">
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
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><strong>Cabang</strong><div>{{ $workOrder->branch->name }}</div></div>
                <div class="col-md-3"><strong>Customer</strong><div>{{ $workOrder->customer->name }}</div></div>
                <div class="col-md-3"><strong>Kendaraan</strong><div>{{ $workOrder->vehicle->plate_number ?? '-' }}</div></div>
                <div class="col-md-3"><strong>Mekanik</strong><div>{{ $workOrder->mechanic->name }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $workOrder->work_order_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3"><strong>Kilometer</strong><div>{{ $workOrder->odometer_km ?? '-' }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>
                        @if ($workOrder->status === \App\Support\WorkOrderStatus::DRAFT)
                            <span class="status-dot status-active">Draft</span>
                        @else
                            <span class="status-dot status-inactive">Dibatalkan</span>
                        @endif
                    </div>
                </div>
                <div class="col-md-6"><strong>Catatan</strong><div>{{ $workOrder->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Baris Jasa</h2>
            <table class="table table-sm">
                <thead><tr><th>Deskripsi</th><th>Qty</th><th>Harga</th><th>Total</th></tr></thead>
                <tbody>
                    @forelse ($workOrder->serviceLines as $line)
                        <tr>
                            <td>{{ $line->description }}</td>
                            <td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->unit_price, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">Tidak ada baris jasa.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Baris Sparepart</h2>
            <table class="table table-sm">
                <thead><tr><th>Kode</th><th>Nama</th><th>Qty</th><th>Harga</th><th>Total</th></tr></thead>
                <tbody>
                    @forelse ($workOrder->sparepartLines as $line)
                        <tr>
                            <td><code>{{ $line->item_code_snapshot }}</code></td>
                            <td>{{ $line->item_name_snapshot }}</td>
                            <td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->unit_price, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">Tidak ada baris sparepart.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <a href="{{ route('work-orders.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
```

- [ ] **Step 11: Run tests to verify they pass**

Run: `php artisan test --filter=WorkOrderManagementTest`
Expected: PASS.

- [ ] **Step 12: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS.

- [ ] **Step 13: Commit**

```bash
git add app/Http/Requests/StoreWorkOrderRequest.php app/Http/Requests/UpdateWorkOrderRequest.php app/Http/Controllers/WorkOrderController.php resources/views/work-orders routes/web.php tests/Feature/WorkOrderManagementTest.php
git commit -m "feat: add PKB CRUD (create/list/detail/edit/cancel) with cascading form"
```

---

### Task 5: Sidebar link, Dashboard button, and full-suite verification

**Files:**
- Modify: `resources/views/partials/sidebar.blade.php:6-12` (swap PKB placeholder for a real link)
- Modify: `resources/views/dashboard/index.blade.php:18-21` (swap "Buat PKB Baru" placeholder for a real link)
- Modify: `tests/Feature/AppShellTest.php` (update stale comments describing the old disabled-placeholder state; add 2 new tests for the Dashboard button)

**Interfaces:**
- Consumes: `route('work-orders.index')`, `route('work-orders.create')` (Task 4).
- Produces: nothing consumed by later tasks — this is the final task in the plan.

- [ ] **Step 1: Write the failing sidebar/dashboard tests**

Append these methods to `tests/Feature/DashboardTest.php` (reuses the existing `protected function grantBranchPermission(User $user, Branch $branch, string $code): void` helper already in that file):

```php
    public function test_buat_pkb_baru_button_shown_when_user_has_pkb_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Buat PKB Baru');
    }

    public function test_buat_pkb_baru_button_hidden_when_user_only_has_view_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Buat PKB Baru');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DashboardTest`
Expected: the 2 new tests FAIL (button currently always renders the disabled span regardless of permission, so `assertDontSee('Buat PKB Baru')` fails — the text is always present).

- [ ] **Step 3: Swap the sidebar placeholder**

In `resources/views/partials/sidebar.blade.php:6-12`, replace:

```blade
        @if ($user->branchesWithPermission('pkb.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-clipboard-check me-2"></i> Perintah Kerja Bengkel
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
```

with:

```blade
        @if ($user->branchesWithPermission('pkb.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('work-orders.index') }}" class="nav-link {{ request()->routeIs('work-orders.*') ? 'active' : '' }}">
                <i class="bi bi-clipboard-check me-2"></i> Perintah Kerja Bengkel
            </a>
        </li>
        @endif
```

- [ ] **Step 4: Swap the Dashboard button**

In `resources/views/dashboard/index.blade.php:18-21`, replace:

```blade
            <span class="btn btn-outline-secondary btn-sm disabled" style="cursor: not-allowed;" aria-disabled="true">
                <i class="bi bi-clipboard-plus"></i> Buat PKB Baru
                <span class="badge-soon">Segera Hadir</span>
            </span>
```

with:

```blade
            @if (auth()->user()->branchesWithPermission('pkb.create')->isNotEmpty())
                <a href="{{ route('work-orders.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-clipboard-plus"></i> Buat PKB Baru
                </a>
            @endif
```

- [ ] **Step 5: Update the stale comments in `AppShellTest.php`**

In `tests/Feature/AppShellTest.php`, the comment block above `test_sidebar_shows_pkb_placeholder_when_user_has_pkb_view_permission_in_a_branch`'s final assertion (around line 215-222) currently reads:

```php
        // "Perintah Kerja Bengkel" (not a bare "Segera Hadir" — the Dashboard
        // header's own "Buat PKB Baru — Segera Hadir" button shares that text)
        // is unique to this sidebar placeholder and is the whole assertion:
        // "bi-clipboard-check" was tried as a companion assertion but is NOT
        // unique — the Dashboard's own "Status PKB Hari Ini" KPI card renders
        // the same icon class unconditionally (dashboard/index.blade.php),
        // which would make that assertion pass even if this sidebar placeholder
        // were broken.
        $response->assertSee('Perintah Kerja Bengkel', false);
```

Replace the comment (keep the assertion itself unchanged — the sidebar link's visible text is still "Perintah Kerja Bengkel", so this assertion still correctly proves the link renders):

```php
        // "Perintah Kerja Bengkel" is the sidebar link's own unique text.
        // "bi-clipboard-check" is NOT a safe companion assertion — the
        // Dashboard's own "Status PKB Hari Ini" KPI card renders the same
        // icon class unconditionally (dashboard/index.blade.php), which would
        // make that assertion pass even if this sidebar link were broken.
        // (The Dashboard's own "Buat PKB Baru" button, previously a disabled
        // placeholder mentioned here, is now a real conditional link — see
        // test_buat_pkb_baru_button_shown_when_user_has_pkb_create_permission
        // in DashboardTest.php.)
        $response->assertSee('Perintah Kerja Bengkel', false);
```

Do not rename `test_sidebar_shows_pkb_placeholder_when_user_has_pkb_view_permission_in_a_branch` or `test_sidebar_hides_pkb_placeholder_without_permission` — both remain behaviorally correct as-is (a real link now renders/hides under the exact same condition a placeholder used to), only their now-slightly-inaccurate names referencing "placeholder" are a cosmetic nit, not worth the churn of touching a passing test file further than the comment update above.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardTest` then `php artisan test --filter=AppShellTest`
Expected: all PASS.

- [ ] **Step 7: Run the full suite and the text-collision grep**

Run: `php artisan test`
Expected: all tests PASS.

Run: `grep -rn "Belum ada PKB\|Buat PKB Pertama\|PKB Baru\|Cari nomor PKB" tests/Feature/AppShellTest.php`
Expected: no unexpected matches beyond the existing "Perintah Kerja Bengkel" occurrences already reviewed in Step 5. If a match is found on a NEW string from this plan, verify (per standing project practice) whether it collides with an unrelated assertion in that file, and if so, narrow the colliding assertion to a unique icon class or route, the same way prior collisions in this project were resolved.

- [ ] **Step 8: Commit**

```bash
git add resources/views/partials/sidebar.blade.php resources/views/dashboard/index.blade.php tests/Feature/AppShellTest.php tests/Feature/DashboardTest.php
git commit -m "feat: wire up PKB sidebar link and Dashboard create button"
```

---

## Self-Review Notes

- **Spec coverage:** every in-scope item from the design spec is covered — data model (Task 1), Policy (Task 2), lookup endpoints (Task 3), CRUD controller/FormRequests/views (Task 4), sidebar + Dashboard button + AppShellTest comment cleanup (Task 5). Explicitly out-of-scope items (reservation, `OPEN`/`SHORTAGE` statuses, print, invoice) are untouched by every task.
- **Placeholder scan:** none found — every code block across all 5 tasks is complete and copy-ready; no "TODO"/"add validation"/"similar to Task N" shortcuts.
- **Type consistency:** `WorkOrder`/`WorkOrderServiceLine`/`WorkOrderSparepartLine` field names and relation method names (`serviceLines()`, `sparepartLines()`, `branch()`, `customer()`, `vehicle()`, `mechanic()`) introduced in Task 1 are used identically in every later task (Policy, controller, views). The 4 lookup endpoints' JSON response shapes fixed in Task 3 (`{id, name}` / `{id, plate_number, frame_number}` / `{id, code, name, selling_price, available_qty}`) are consumed with matching field names in Task 4's JS (`item.name`, `item.plate_number`, `item.code`, `item.selling_price`, `item.available_qty`).
- **Scope check:** 5 tasks, matching the size and shape of the prior largest migration in this project (Sparepart & Stock, also 5 tasks) — appropriate given this module introduces a new document type (2 line-item tables), a second Policy, 4 new endpoints, and the project's first multi-level cascading create form.
