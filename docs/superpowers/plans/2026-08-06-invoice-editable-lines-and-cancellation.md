# Invoice: Editable Lines & Cancellation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a draft Invoice's line items (not just its header discount/PPN/notes) be edited before posting — including adding sparepart lines that don't trace back to any PKB line — and let a draft Invoice be cancelled, releasing any PKB stock reservations still tied to it.

**Architecture:** Extends the already-shipped Sales Invoice module (`docs/superpowers/plans/2026-08-05-sales-invoice-module.md`, design doc `docs/superpowers/specs/2026-08-06-invoice-editable-lines-and-cancellation-design.md`). Adds one schema change (`invoice_details.sparepart_branch_id` + relaxed CHECK constraints, `invoices` cancellation columns), two new `InvoiceService` methods (`updateInvoice()` replacing the current header-only recalculation, `cancelInvoice()`), and the controller/request/view wiring to reach both from the browser.

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL 8 (tests run against real MySQL, not sqlite — `phpunit.xml` sets `DB_CONNECTION=mysql`, `DB_DATABASE=bengkel_testing`, so DB `CHECK` constraints are enforced during tests too), Blade + Bootstrap 5 (CDN) + jQuery + Select2, no SPA/build step.

## Global Constraints

- PHP 7.4.33 — no PHP 8+ syntax (nullsafe `?->`, named args, `match`, enums, constructor promotion).
- No roles table — authorization is direct-to-user via `Gate::before` + `$user->hasPermissionToInBranch('code', $branchId)`, exactly as used by every existing Policy/Controller in this codebase.
- Policies are registered manually in `app/Providers/AuthServiceProvider.php`'s `$policies` array. `Invoice::class => InvoicePolicy::class` is already registered — this plan only adds a method to the existing `InvoicePolicy`, no new registration needed.
- The `invoice.void` permission code is already seeded (`database/seeders/MenuPermissionSeeder.php:66`) and unused until this plan. Do not re-seed it.
- **Already implemented — do not redo:** `App\Models\Invoice`, `App\Models\InvoiceDetail`, `App\Support\InvoiceStatus` (`DRAFT`/`POSTED`/`CANCELLED` — `CANCELLED` is currently unused), `App\Support\InvoiceDetailItemType` (`SERVICE`/`SPAREPART`), `App\Services\InvoiceService::createFromWorkOrder()`, the full `InvoiceController` (index/store/show/edit/update/post), `InvoicePolicy` (view/create/update/post), routes `invoices.index/store/show/edit/update/post`, and the `invoices/index.blade.php`, `show.blade.php`, `edit.blade.php`, `no-access.blade.php` views. This plan modifies several of these files — read them before editing, don't assume the sales-invoice-module plan's original text still matches the file on disk (it has since gained discount/PPN editing and posting).
- Any code that locks `sparepart_branch_stocks` rows must lock them in ascending `sparepart_branch_id` order, and must lock the "owning" row (the `Invoice` via `lockForUpdate()`) *before* locking any stock rows — this is the same deadlock-avoidance convention `WorkOrderController::confirm()/cancel()`, `GoodsReceiptController::post()`, `StockAdjustmentController::post()`, and `InvoiceService::postInvoice()` already use. Since a PKB can only reach `COMPLETED` (a prerequisite for having an Invoice) from `OPEN`/`SHORTAGE`+override, and `WorkOrderPolicy::cancel()` only allows cancelling a PKB that's still `DRAFT`/`OPEN`/`SHORTAGE`, no `WorkOrderController` code path can touch a `WorkOrderSparepartLine`'s reservations once its PKB is `COMPLETED` — so once an Invoice exists, only `InvoiceService` (locking the Invoice row first) ever touches that PKB's reservations, and locking the Invoice row is sufficient mutual exclusion.
- Follow existing file conventions exactly: controllers redirect with `->with('status', ...)` on success and `->with('error', ...)` on a recoverable failure; service methods throw `\DomainException` on invalid-state calls, caught by the controller; FormRequests put `authorize()` as `$this->user()->can('ability', $this->route('paramName'))`; Blade status/info cards use the same `@if ($model->some_timestamp_column) ... @elseif ($model->status === ...) ... @endif` pattern `work-orders/show.blade.php` uses for `shortage_overridden_at` (chosen there, and here, specifically so the info card keeps showing after the status moves on — see commit `b012c6d`).

---

## Task 1: Schema — `invoice_details.sparepart_branch_id`, `invoices` cancellation columns, free-form-safe posting

**Files:**
- Create: `database/migrations/2026_08_06_000001_add_sparepart_branch_id_to_invoice_details_table.php`
- Create: `database/migrations/2026_08_06_000002_add_cancellation_columns_to_invoices_table.php`
- Modify: `app/Models/Invoice.php`
- Modify: `app/Models/InvoiceDetail.php`
- Modify: `app/Services/InvoiceService.php`
- Test: `tests/Feature/InvoiceModelTest.php` (new file)

**Interfaces:**
- Produces: `invoice_details.sparepart_branch_id` (nullable FK to `sparepart_branches`, always set when `item_type = 'sparepart'`, regardless of whether the row traces to a PKB line); CHECK constraints `ck_invoice_details_not_both_sources` and `ck_invoice_details_sparepart_requires_branch` (replacing `ck_invoice_details_single_source`, which required *exactly* one of `work_order_service_line_id`/`work_order_sparepart_line_id` — free-form lines with neither set are now valid); `invoices.cancel_reason`/`cancelled_by`/`cancelled_at` columns; `Invoice::cancelledBy(): BelongsTo`. `InvoiceService::postInvoice()` is fixed to deduct stock for *every* sparepart-type `InvoiceDetail` (grouped by the new `sparepart_branch_id` column), not only ones with `work_order_sparepart_line_id` set — this was a latent bug the old code never hit because every sparepart line was PKB-traced until this plan. Tasks 2 and 3 rely on `sparepart_branch_id` being present on every sparepart `InvoiceDetail` and on the cancellation columns existing.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/InvoiceModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
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
use App\Services\InvoiceService;
use App\Services\UserBranchService;
use App\Support\InvoiceDetailItemType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceModelTest extends TestCase
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

    // Mirrors InvoiceControllerTest::makeWorkOrder()/makeInvoice() — duplicated rather than
    // shared, matching this codebase's existing convention of each test file keeping its own
    // local scenario builder (see the comment atop InvoiceControllerTest::makeWorkOrder()).
    protected function makeInvoice(Branch $branch): Invoice
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => "B 1234 {$branch->code}",
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => "SVC-01-{$branch->code}", 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => "OLI-01-{$branch->code}", 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 50000],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000],
            ],
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        return (new InvoiceService())->createFromWorkOrder($workOrder->fresh());
    }

    public function test_invoice_detail_rejects_sparepart_row_without_sparepart_branch_id(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $sparepartDetail = $invoice->details->firstWhere('item_type', InvoiceDetailItemType::SPAREPART);

        $this->expectException(QueryException::class);
        InvoiceDetail::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceDetailItemType::SPAREPART,
            'work_order_service_line_id' => null,
            'work_order_sparepart_line_id' => $sparepartDetail->work_order_sparepart_line_id,
            'sparepart_branch_id' => null,
            'description' => 'Sparepart tanpa cabang',
            'qty' => 1,
            'unit_price' => 10000,
            'line_total' => 10000,
            'sort_order' => 99,
        ]);
    }

    public function test_invoice_detail_allows_free_form_line_traced_to_neither_service_nor_sparepart_line(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $sparepartBranchId = $invoice->details->firstWhere('item_type', InvoiceDetailItemType::SPAREPART)->sparepart_branch_id;

        $detail = InvoiceDetail::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceDetailItemType::SPAREPART,
            'work_order_service_line_id' => null,
            'work_order_sparepart_line_id' => null,
            'sparepart_branch_id' => $sparepartBranchId,
            'description' => 'Sparepart tambahan',
            'qty' => 1,
            'unit_price' => 10000,
            'line_total' => 10000,
            'sort_order' => 99,
        ]);

        $this->assertNotNull($detail->id);
    }

    public function test_invoice_detail_rejects_row_tracing_to_both_service_and_sparepart_line(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', InvoiceDetailItemType::SPAREPART);

        $this->expectException(QueryException::class);
        InvoiceDetail::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceDetailItemType::SERVICE,
            'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
            'work_order_sparepart_line_id' => $sparepartDetail->work_order_sparepart_line_id,
            'sparepart_branch_id' => null,
            'description' => 'Baris tidak valid',
            'qty' => 1,
            'unit_price' => 10000,
            'line_total' => 10000,
            'sort_order' => 99,
        ]);
    }

    public function test_post_invoice_deducts_stock_for_free_form_sparepart_line_not_traced_to_pkb(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);

        $extraSparepart = Sparepart::create(['code' => 'FLT-01', 'name' => 'Filter Udara']);
        $extraSparepartBranch = SparepartBranch::create(['sparepart_id' => $extraSparepart->id, 'branch_id' => $branch->id, 'selling_price' => 45000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $extraSparepartBranch->id)->update(['on_hand_qty' => 5]);

        InvoiceDetail::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceDetailItemType::SPAREPART,
            'work_order_service_line_id' => null,
            'work_order_sparepart_line_id' => null,
            'sparepart_branch_id' => $extraSparepartBranch->id,
            'description' => 'Filter Udara',
            'qty' => 1,
            'unit_price' => 45000,
            'line_total' => 45000,
            'sort_order' => 99,
        ]);
        $invoice->update([
            'subtotal_sparepart' => (float) $invoice->subtotal_sparepart + 45000,
            'grand_total' => (float) $invoice->grand_total + 45000,
        ]);

        (new InvoiceService())->postInvoice($invoice->fresh());

        $stockAfter = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $extraSparepartBranch->id)->first();
        $this->assertSame(4.0, (float) $stockAfter->on_hand_qty);
        $this->assertDatabaseHas('inventory_movements', [
            'sparepart_branch_id' => $extraSparepartBranch->id,
            'movement_type' => 'usage_out',
        ]);
    }
}
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test tests/Feature/InvoiceModelTest.php`
Expected: FAIL for `test_invoice_detail_rejects_sparepart_row_without_sparepart_branch_id` (no exception is thrown yet — `sparepart_branch_id` isn't fillable/doesn't exist, so the insert succeeds under the *old* constraint since `work_order_sparepart_line_id` alone satisfies it), `test_invoice_detail_allows_free_form_line_traced_to_neither_service_nor_sparepart_line` (throws under the *old* `ck_invoice_details_single_source` constraint, which the test doesn't expect), and `test_post_invoice_deducts_stock_for_free_form_sparepart_line_not_traced_to_pkb` (errors on the same old-constraint violation during setup). `test_invoice_detail_rejects_row_tracing_to_both_service_and_sparepart_line` may already pass — the old constraint already forbids that combination too; that's a regression guard, not new behavior.

- [ ] **Step 3: Create the `invoice_details` migration**

Create `database/migrations/2026_08_06_000001_add_sparepart_branch_id_to_invoice_details_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddSparepartBranchIdToInvoiceDetailsTable extends Migration
{
    public function up()
    {
        Schema::table('invoice_details', function (Blueprint $table) {
            $table->foreignId('sparepart_branch_id')->nullable()->after('work_order_sparepart_line_id')->constrained('sparepart_branches');
        });

        // Backfill existing PKB-traced sparepart rows so the new
        // ck_invoice_details_sparepart_requires_branch CHECK (added below) doesn't reject them.
        DB::statement('
            UPDATE invoice_details
            JOIN work_order_sparepart_lines ON work_order_sparepart_lines.id = invoice_details.work_order_sparepart_line_id
            SET invoice_details.sparepart_branch_id = work_order_sparepart_lines.sparepart_branch_id
            WHERE invoice_details.item_type = "sparepart"
        ');

        DB::statement('ALTER TABLE invoice_details DROP CHECK ck_invoice_details_single_source');
        DB::statement('ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_not_both_sources CHECK (NOT (work_order_service_line_id IS NOT NULL AND work_order_sparepart_line_id IS NOT NULL))');
        DB::statement("ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_sparepart_requires_branch CHECK (item_type <> 'sparepart' OR sparepart_branch_id IS NOT NULL)");
    }

    public function down()
    {
        DB::statement('ALTER TABLE invoice_details DROP CHECK ck_invoice_details_sparepart_requires_branch');
        DB::statement('ALTER TABLE invoice_details DROP CHECK ck_invoice_details_not_both_sources');
        DB::statement('ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_single_source CHECK ((work_order_service_line_id IS NOT NULL AND work_order_sparepart_line_id IS NULL) OR (work_order_service_line_id IS NULL AND work_order_sparepart_line_id IS NOT NULL))');

        Schema::table('invoice_details', function (Blueprint $table) {
            $table->dropForeign(['sparepart_branch_id']);
            $table->dropColumn('sparepart_branch_id');
        });
    }
}
```

- [ ] **Step 4: Create the `invoices` cancellation-columns migration**

Create `database/migrations/2026_08_06_000002_add_cancellation_columns_to_invoices_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCancellationColumnsToInvoicesTable extends Migration
{
    public function up()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->text('cancel_reason')->nullable()->after('notes');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancel_reason');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');

            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancel_reason', 'cancelled_by', 'cancelled_at']);
        });
    }
}
```

- [ ] **Step 5: Update the `Invoice` model**

In `app/Models/Invoice.php`, update `$fillable`/`$casts` and add the `cancelledBy()` relation:

```php
    protected $fillable = [
        'number', 'work_order_id', 'branch_id', 'customer_id', 'invoice_date', 'status',
        'subtotal_service', 'subtotal_sparepart',
        'discount_percent', 'discount_amount',
        'tax_percent', 'tax_amount',
        'grand_total', 'notes',
        'cancel_reason', 'cancelled_by', 'cancelled_at',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'subtotal_service' => 'decimal:2',
        'subtotal_sparepart' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];
```

Add this method after `customer()`:

```php
    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
```

- [ ] **Step 6: Update the `InvoiceDetail` model**

In `app/Models/InvoiceDetail.php`, add `sparepart_branch_id` to `$fillable`:

```php
    protected $fillable = [
        'invoice_id', 'item_type',
        'work_order_service_line_id', 'work_order_sparepart_line_id', 'sparepart_branch_id',
        'item_code_snapshot', 'description', 'qty', 'unit_price', 'line_total', 'sort_order',
    ];
```

- [ ] **Step 7: Set `sparepart_branch_id` in `InvoiceService::createFromWorkOrder()`**

In `app/Services/InvoiceService.php`, inside the `foreach ($sparepartLines as $line)` loop, add the new field:

```php
            foreach ($sparepartLines as $line) {
                InvoiceDetail::create([
                    'invoice_id' => $invoice->id,
                    'item_type' => InvoiceDetailItemType::SPAREPART,
                    'work_order_service_line_id' => null,
                    'work_order_sparepart_line_id' => $line->id,
                    'sparepart_branch_id' => $line->sparepart_branch_id,
                    'item_code_snapshot' => $line->item_code_snapshot,
                    'description' => $line->item_name_snapshot,
                    'qty' => $line->qty,
                    'unit_price' => $line->unit_price,
                    'line_total' => $line->line_total,
                    'sort_order' => $sortOrder++,
                ]);
            }
```

- [ ] **Step 8: Fix `InvoiceService::postInvoice()` to deduct stock for every sparepart line, not just PKB-traced ones**

In `app/Services/InvoiceService.php`, replace the `$sparepartDetails`/`$bySparepart` query and the reservation-sum line:

```php
            $sparepartDetails = $fresh->details()
                ->where('item_type', InvoiceDetailItemType::SPAREPART)
                ->with(['sparepartLine' => function ($query) {
                    $query->with(['reservations' => fn ($rq) => $rq->where('status', 'active')]);
                }])
                ->get();

            // Group by sparepart_branch_id (the column, not the PKB line — a free-form line has
            // no PKB line to derive it from) and lock/validate in ascending id order, same
            // convention as every other reservation-touching path in this codebase.
            $bySparepart = $sparepartDetails
                ->groupBy(fn (InvoiceDetail $detail) => $detail->sparepart_branch_id)
                ->sortKeys();
```

```php
                $totalQtyOut = (float) $detailsForSparepart->sum('qty');
                // Free-form lines (no work_order_sparepart_line_id) have no PKB reservation to
                // release — sparepartLine is null for those, hence the null-safe check.
                $totalReservedToRelease = (float) $detailsForSparepart->sum(
                    fn (InvoiceDetail $d) => $d->sparepartLine ? $d->sparepartLine->reservations->sum('qty') : 0
                );
```

And in the mutation pass:

```php
                foreach ($detailsForSparepart as $detail) {
                    if ($detail->sparepartLine) {
                        foreach ($detail->sparepartLine->reservations as $reservation) {
                            $stock->reserved_qty -= $reservation->qty;
                            $reservation->status = 'released';
                            $reservation->save();
                        }
                    }

                    $stock->on_hand_qty -= $detail->qty;
                    $stock->save();
```

(the `InventoryMovement::create([...])` call right after stays exactly as-is.)

- [ ] **Step 9: Run the migrations**

Run: `php artisan migrate`
Expected: the two new migrations run successfully against `bengkel_testing`/your local DB.

- [ ] **Step 10: Run the tests and confirm they pass**

Run: `php artisan test tests/Feature/InvoiceModelTest.php tests/Feature/InvoiceControllerTest.php`
Expected: PASS — `InvoiceModelTest`'s four tests pass, and the pre-existing `InvoiceControllerTest` suite still passes unchanged (Step 7/8 don't change any externally-visible behavior for the PKB-traced-only scenarios those tests cover).

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_08_06_000001_add_sparepart_branch_id_to_invoice_details_table.php database/migrations/2026_08_06_000002_add_cancellation_columns_to_invoices_table.php app/Models/Invoice.php app/Models/InvoiceDetail.php app/Services/InvoiceService.php tests/Feature/InvoiceModelTest.php
git commit -m "feat: add sparepart_branch_id and invoice cancellation columns"
```

---

## Task 2: Editable invoice lines before posting

**Files:**
- Modify: `app/Services/InvoiceService.php`
- Modify: `app/Http/Requests/UpdateInvoiceRequest.php`
- Modify: `app/Http/Controllers/InvoiceController.php`
- Modify: `resources/views/invoices/edit.blade.php`
- Create: `resources/views/invoices/_line_item_scripts.blade.php`
- Modify: `tests/Feature/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `invoice_details.sparepart_branch_id` (Task 1); `WorkOrderSparepartLine::reservations()` (existing).
- Produces: `InvoiceService::updateInvoice(Invoice $invoice, array $data): Invoice` (throws `\DomainException` if the invoice is no longer `draft`) — replaces the inline recalculation currently in `InvoiceController::update()`; `InvoiceService::releaseReservationsForLines(iterable $workOrderSparepartLineIds): void` (protected helper) — Task 3's `cancelInvoice()` reuses this exact method.

- [ ] **Step 1: Write the failing tests**

Modify `tests/Feature/InvoiceControllerTest.php`: change the `use App\Models\Invoice;` line to also import `InvoiceDetail`, and replace `test_update_recalculates_discount_tax_and_grand_total` with a version that resubmits the invoice's existing (unchanged) lines — the endpoint now does a full line sync, so a submission with no `services`/`spareparts` would delete every line:

```php
use App\Models\Invoice;
use App\Models\InvoiceDetail;
```

```php
    public function test_update_recalculates_discount_tax_and_grand_total(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        // subtotal_service=50000 (1 x 50000), subtotal_sparepart=120000 (2 x 60000) -> subtotal=170000
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 10,
            'tax_percent' => 11,
            'notes' => 'Diskon member',
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => $serviceDetail->description,
                'qty' => (float) $serviceDetail->qty,
                'unit_price' => (float) $serviceDetail->unit_price,
            ]],
            'spareparts' => [[
                'work_order_sparepart_line_id' => $sparepartDetail->work_order_sparepart_line_id,
                'sparepart_branch_id' => $sparepartDetail->sparepart_branch_id,
                'qty' => (float) $sparepartDetail->qty,
                'unit_price' => (float) $sparepartDetail->unit_price,
            ]],
        ]);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();
        $this->assertSame(10.0, (float) $invoice->discount_percent);
        $this->assertSame(17000.0, (float) $invoice->discount_amount);
        $this->assertSame(11.0, (float) $invoice->tax_percent);
        $this->assertSame(16830.0, (float) $invoice->tax_amount);
        $this->assertSame(169830.0, (float) $invoice->grand_total);
        $this->assertSame('Diskon member', $invoice->notes);
    }
```

Then append these three new tests right before the class's final closing `}`:

```php
    public function test_update_removing_sparepart_line_releases_pkb_reservation(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $sparepartBranchId = $sparepartDetail->sparepart_branch_id;
        $stockBefore = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchId)->first();
        $this->assertSame(2.0, (float) $stockBefore->reserved_qty);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => $serviceDetail->description,
                'qty' => (float) $serviceDetail->qty,
                'unit_price' => (float) $serviceDetail->unit_price,
            ]],
            'spareparts' => [],
        ]);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();
        $this->assertCount(1, $invoice->details);
        $this->assertSame(0.0, (float) $invoice->subtotal_sparepart);
        $stockAfter = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchId)->first();
        $this->assertSame(0.0, (float) $stockAfter->reserved_qty);
        $this->assertDatabaseHas('inventory_reservations', [
            'sparepart_branch_id' => $sparepartBranchId,
            'reference_type' => 'work_order_sparepart_line',
            'status' => 'released',
        ]);
    }

    public function test_update_adds_free_form_sparepart_line_not_traced_to_pkb(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);

        $extraSparepart = Sparepart::create(['code' => 'FLT-01', 'name' => 'Filter Udara']);
        $extraSparepartBranch = SparepartBranch::create(['sparepart_id' => $extraSparepart->id, 'branch_id' => $branch->id, 'selling_price' => 45000]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => $serviceDetail->description,
                'qty' => (float) $serviceDetail->qty,
                'unit_price' => (float) $serviceDetail->unit_price,
            ]],
            'spareparts' => [
                [
                    'work_order_sparepart_line_id' => $sparepartDetail->work_order_sparepart_line_id,
                    'sparepart_branch_id' => $sparepartDetail->sparepart_branch_id,
                    'qty' => (float) $sparepartDetail->qty,
                    'unit_price' => (float) $sparepartDetail->unit_price,
                ],
                [
                    'work_order_sparepart_line_id' => null,
                    'sparepart_branch_id' => $extraSparepartBranch->id,
                    'qty' => 1,
                    'unit_price' => 45000,
                ],
            ],
        ]);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();
        $this->assertCount(3, $invoice->details);
        $this->assertSame(165000.0, (float) $invoice->subtotal_sparepart);
        $freeFormDetail = $invoice->details->firstWhere('sparepart_branch_id', $extraSparepartBranch->id);
        $this->assertNull($freeFormDetail->work_order_sparepart_line_id);
    }

    public function test_update_rejects_sparepart_from_a_different_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $invoice = $this->makeInvoice($branchA);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);

        $otherSparepart = Sparepart::create(['code' => 'FLT-02', 'name' => 'Filter Oli']);
        $otherBranchSparepart = SparepartBranch::create(['sparepart_id' => $otherSparepart->id, 'branch_id' => $branchB->id, 'selling_price' => 30000]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => $serviceDetail->description,
                'qty' => (float) $serviceDetail->qty,
                'unit_price' => (float) $serviceDetail->unit_price,
            ]],
            'spareparts' => [[
                'work_order_sparepart_line_id' => null,
                'sparepart_branch_id' => $otherBranchSparepart->id,
                'qty' => 1,
                'unit_price' => 30000,
            ]],
        ]);

        $response->assertSessionHasErrors('spareparts.0.sparepart_branch_id');
    }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test --filter=test_update_ tests/Feature/InvoiceControllerTest.php`
Expected: FAIL — `test_update_recalculates_discount_tax_and_grand_total` fails because `UpdateInvoiceRequest` doesn't yet accept `services`/`spareparts` keys the way the new test expects them to be validated (currently harmless-extra input, but the controller doesn't use them yet, so nothing changes and the *new* tests asserting line-sync behavior all fail outright since no sync logic exists).

- [ ] **Step 3: Add `InvoiceService::updateInvoice()` and `releaseReservationsForLines()`**

In `app/Services/InvoiceService.php`, add these two imports:

```php
use App\Models\SparepartBranch;
use App\Models\WorkOrderSparepartLine;
```

Add this method (replacing nothing — it's new — place it right before `postInvoice()`):

```php
    public function updateInvoice(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($fresh->status !== InvoiceStatus::DRAFT) {
                throw new DomainException('Invoice sudah tidak berstatus draft, tidak bisa diubah lagi.');
            }

            $beforeLineIds = $fresh->details()
                ->whereNotNull('work_order_sparepart_line_id')
                ->pluck('work_order_sparepart_line_id');

            $fresh->details()->delete();

            $sortOrder = 0;

            foreach ($data['services'] ?? [] as $line) {
                $qty = (float) $line['qty'];
                $unitPrice = (float) $line['unit_price'];
                InvoiceDetail::create([
                    'invoice_id' => $fresh->id,
                    'item_type' => InvoiceDetailItemType::SERVICE,
                    'work_order_service_line_id' => $line['work_order_service_line_id'] ?? null,
                    'work_order_sparepart_line_id' => null,
                    'sparepart_branch_id' => null,
                    'item_code_snapshot' => null,
                    'description' => $line['description'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => round($qty * $unitPrice, 2),
                    'sort_order' => $sortOrder++,
                ]);
            }

            foreach ($data['spareparts'] ?? [] as $line) {
                $sparepartBranch = SparepartBranch::with('sparepart')->findOrFail($line['sparepart_branch_id']);
                $qty = (float) $line['qty'];
                $unitPrice = (float) $line['unit_price'];
                InvoiceDetail::create([
                    'invoice_id' => $fresh->id,
                    'item_type' => InvoiceDetailItemType::SPAREPART,
                    'work_order_service_line_id' => null,
                    'work_order_sparepart_line_id' => $line['work_order_sparepart_line_id'] ?? null,
                    'sparepart_branch_id' => $sparepartBranch->id,
                    'item_code_snapshot' => $sparepartBranch->sparepart->code,
                    'description' => $sparepartBranch->sparepart->name,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => round($qty * $unitPrice, 2),
                    'sort_order' => $sortOrder++,
                ]);
            }

            $afterLineIds = collect($data['spareparts'] ?? [])->pluck('work_order_sparepart_line_id')->filter()->values();
            $droppedLineIds = $beforeLineIds->diff($afterLineIds)->values();
            $this->releaseReservationsForLines($droppedLineIds);

            $subtotalService = round((float) $fresh->details()->where('item_type', InvoiceDetailItemType::SERVICE)->sum('line_total'), 2);
            $subtotalSparepart = round((float) $fresh->details()->where('item_type', InvoiceDetailItemType::SPAREPART)->sum('line_total'), 2);
            $subtotal = $subtotalService + $subtotalSparepart;
            $discountPercent = (float) $data['discount_percent'];
            $taxPercent = (float) $data['tax_percent'];
            $discountAmount = round($subtotal * $discountPercent / 100, 2);
            $taxableBase = $subtotal - $discountAmount;
            $taxAmount = round($taxableBase * $taxPercent / 100, 2);

            $fresh->update([
                'subtotal_service' => $subtotalService,
                'subtotal_sparepart' => $subtotalSparepart,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
                'tax_percent' => $taxPercent,
                'tax_amount' => $taxAmount,
                'grand_total' => round($taxableBase + $taxAmount, 2),
                'notes' => $data['notes'] ?? null,
            ]);

            return $fresh->fresh('details');
        });
    }

    // Shared by updateInvoice() (dropped lines) and cancelInvoice() (Task 3 — all remaining
    // lines). Locks sparepart_branch_stocks rows in ascending id order, matching every other
    // reservation-touching path in this codebase, to avoid AB-BA deadlocks. Safe to call with
    // an empty collection.
    protected function releaseReservationsForLines($workOrderSparepartLineIds): void
    {
        $ids = collect($workOrderSparepartLineIds)->values();

        if ($ids->isEmpty()) {
            return;
        }

        $lines = WorkOrderSparepartLine::whereIn('id', $ids)
            ->with(['reservations' => fn ($q) => $q->where('status', 'active')])
            ->get();

        $bySparepart = $lines->groupBy('sparepart_branch_id')->sortKeys();

        foreach ($bySparepart as $sparepartBranchId => $linesForSparepart) {
            $stock = SparepartBranchStock::where('sparepart_branch_id', $sparepartBranchId)
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($linesForSparepart as $line) {
                foreach ($line->reservations as $reservation) {
                    $stock->reserved_qty -= $reservation->qty;
                    $reservation->status = 'released';
                    $reservation->save();
                }
            }

            $stock->save();
        }
    }
```

- [ ] **Step 4: Rewrite `UpdateInvoiceRequest`**

Replace the full contents of `app/Http/Requests/UpdateInvoiceRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use App\Models\WorkOrderServiceLine;
use App\Models\WorkOrderSparepartLine;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('update', $this->route('invoice'));
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'services' => array_values(array_filter($this->input('services', []), function ($line) {
                return isset($line['description']) && trim($line['description']) !== '';
            })),
            'spareparts' => array_values(array_filter($this->input('spareparts', []), function ($line) {
                return ! empty($line['sparepart_branch_id']);
            })),
        ]);
    }

    public function rules()
    {
        return [
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_percent' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'services' => ['nullable', 'array'],
            'services.*' => ['array'],
            'services.*.work_order_service_line_id' => ['nullable', 'integer', 'exists:work_order_service_lines,id'],
            'services.*.description' => ['required_with:services.*.qty', 'string', 'max:255'],
            'services.*.qty' => ['required_with:services.*.description', 'numeric', 'min:0.001'],
            'services.*.unit_price' => ['required_with:services.*.description', 'numeric', 'min:0'],
            'spareparts' => ['nullable', 'array'],
            'spareparts.*' => ['array'],
            'spareparts.*.work_order_sparepart_line_id' => ['nullable', 'integer', 'exists:work_order_sparepart_lines,id'],
            'spareparts.*.sparepart_branch_id' => ['required_with:spareparts.*.qty', 'integer', 'exists:sparepart_branches,id'],
            'spareparts.*.qty' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0.001'],
            'spareparts.*.unit_price' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $invoice = $this->route('invoice');
            $branchId = (int) $invoice->branch_id;
            $workOrderId = (int) $invoice->work_order_id;

            foreach ($this->input('services', []) as $index => $line) {
                $lineId = $line['work_order_service_line_id'] ?? null;
                if (! $lineId) {
                    continue;
                }
                $exists = WorkOrderServiceLine::where('id', $lineId)->where('work_order_id', $workOrderId)->exists();
                if (! $exists) {
                    $validator->errors()->add("services.{$index}.work_order_service_line_id", 'Baris jasa PKB tidak valid untuk invoice ini.');
                }
            }

            foreach ($this->input('spareparts', []) as $index => $line) {
                $sparepartBranchId = $line['sparepart_branch_id'] ?? null;
                if ($sparepartBranchId) {
                    $sparepartBranch = SparepartBranch::find($sparepartBranchId);
                    if (! $sparepartBranch || ! $sparepartBranch->is_active || (int) $sparepartBranch->branch_id !== $branchId) {
                        $validator->errors()->add("spareparts.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang invoice ini.');
                    }
                }

                $lineId = $line['work_order_sparepart_line_id'] ?? null;
                if (! $lineId) {
                    continue;
                }
                $exists = WorkOrderSparepartLine::where('id', $lineId)->where('work_order_id', $workOrderId)->exists();
                if (! $exists) {
                    $validator->errors()->add("spareparts.{$index}.work_order_sparepart_line_id", 'Baris sparepart PKB tidak valid untuk invoice ini.');
                }
            }
        });
    }
}
```

- [ ] **Step 5: Wire `InvoiceController::update()` to the service, and update `edit()`**

In `app/Http/Controllers/InvoiceController.php`, update the `use` imports:

```php
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\WorkOrder;
use App\Services\InvoiceService;
use App\Support\InvoiceDetailItemType;
use DomainException;
use Illuminate\Http\Request;
```

(`App\Support\InvoiceStatus` and `Illuminate\Support\Facades\DB` are no longer used by this controller — drop both imports.)

Replace `edit()` and `update()`:

```php
    public function edit(Invoice $invoice)
    {
        $this->authorize('update', $invoice);

        $invoice->load('details');

        $existingServiceLines = $invoice->details->where('item_type', InvoiceDetailItemType::SERVICE)->map(function (InvoiceDetail $detail) {
            return [
                'work_order_service_line_id' => $detail->work_order_service_line_id,
                'description' => $detail->description,
                'qty' => (float) $detail->qty,
                'unit_price' => (float) $detail->unit_price,
            ];
        })->values();

        $existingSparepartLines = $invoice->details->where('item_type', InvoiceDetailItemType::SPAREPART)->map(function (InvoiceDetail $detail) {
            return [
                'work_order_sparepart_line_id' => $detail->work_order_sparepart_line_id,
                'sparepart_branch_id' => $detail->sparepart_branch_id,
                'item_code_snapshot' => $detail->item_code_snapshot,
                'description' => $detail->description,
                'qty' => (float) $detail->qty,
                'unit_price' => (float) $detail->unit_price,
            ];
        })->values();

        return view('invoices.edit', compact('invoice', 'existingServiceLines', 'existingSparepartLines'));
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        try {
            (new InvoiceService())->updateInvoice($invoice, $request->validated());
        } catch (DomainException $e) {
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice berhasil diperbarui.');
    }
```

- [ ] **Step 6: Create the invoice line-item editor script partial**

Create `resources/views/invoices/_line_item_scripts.blade.php`:

```blade
<template id="invoiceServiceLineTemplate">
    <div class="row g-2 align-items-start mb-2 service-line">
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

<template id="invoiceSparepartLineTemplate">
    <div class="row g-2 align-items-start mb-2 sparepart-line">
        <div class="col-md-5 sparepart-item-locked d-none">
            <input type="text" class="form-control-plaintext fw-bold sparepart-locked-label" readonly>
        </div>
        <div class="col-md-5 sparepart-item-free">
            <select class="form-select sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
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

    function addServiceLine(locked) {
        const template = document.getElementById('invoiceServiceLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.service-line');
        const index = serviceLineCount++;

        const hiddenId = document.createElement('input');
        hiddenId.type = 'hidden';
        hiddenId.className = 'service-wo-line-id';
        hiddenId.name = `services[${index}][work_order_service_line_id]`;
        wrapper.appendChild(hiddenId);

        const description = wrapper.querySelector('.service-description');
        description.name = `services[${index}][description]`;
        wrapper.querySelector('.service-qty').name = `services[${index}][qty]`;
        wrapper.querySelector('.service-unit-price').name = `services[${index}][unit_price]`;

        if (locked) {
            description.readOnly = true;
            description.classList.add('bg-light');
        }

        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            wrapper.remove();
        });
        document.getElementById('invoiceServiceLines').appendChild(wrapper);
        return wrapper;
    }

    function addSparepartLine(branchId, locked) {
        const template = document.getElementById('invoiceSparepartLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.sparepart-line');
        const index = sparepartLineCount++;

        const hiddenWoLineId = document.createElement('input');
        hiddenWoLineId.type = 'hidden';
        hiddenWoLineId.className = 'sparepart-wo-line-id';
        hiddenWoLineId.name = `spareparts[${index}][work_order_sparepart_line_id]`;
        wrapper.appendChild(hiddenWoLineId);

        wrapper.querySelector('.sparepart-qty').name = `spareparts[${index}][qty]`;
        wrapper.querySelector('.sparepart-unit-price').name = `spareparts[${index}][unit_price]`;

        const select = wrapper.querySelector('.sparepart-select');

        if (locked) {
            wrapper.querySelector('.sparepart-item-locked').classList.remove('d-none');
            wrapper.querySelector('.sparepart-item-free').classList.add('d-none');

            const hiddenBranchId = document.createElement('input');
            hiddenBranchId.type = 'hidden';
            hiddenBranchId.className = 'sparepart-locked-branch-id';
            hiddenBranchId.name = `spareparts[${index}][sparepart_branch_id]`;
            wrapper.appendChild(hiddenBranchId);
        } else {
            select.name = `spareparts[${index}][sparepart_branch_id]`;
            initAjaxSelect(select, {
                endpoint: '{{ route('lookup.spareparts') }}',
                extraParams: function () { return { branch_id: branchId }; },
                placeholder: '-- Pilih Sparepart --',
                onSelect: function (item) {
                    wrapper.querySelector('.sparepart-unit-price').value = item.selling_price;
                },
            });
        }

        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            if ($(select).data('select2')) $(select).select2('destroy');
            wrapper.remove();
        });
        document.getElementById('invoiceSparepartLines').appendChild(wrapper);
        return wrapper;
    }

    async function preselectFreeFormSparepartLine(row, sparepartBranchId, branchId) {
        const select = row.querySelector('.sparepart-select');
        const item = await preselectAjaxOption(select, {
            endpoint: '{{ route('lookup.spareparts') }}',
            id: sparepartBranchId,
            extraParams: function () { return { branch_id: branchId }; },
        });
        if (item && !row.querySelector('.sparepart-unit-price').value) {
            row.querySelector('.sparepart-unit-price').value = item.selling_price;
        }
        $(select).trigger('change');
    }

    document.getElementById('addInvoiceServiceLine').addEventListener('click', function () {
        addServiceLine(false);
    });
    document.getElementById('addInvoiceSparepartLine').addEventListener('click', function () {
        addSparepartLine(window.currentInvoiceBranchId || null, false);
    });

    window.InvoiceLineItems = {
        addServiceLine: addServiceLine,
        addSparepartLine: addSparepartLine,
        preselectFreeFormSparepartLine: preselectFreeFormSparepartLine,
    };
})();
</script>
@endpush
```

- [ ] **Step 7: Rewrite the invoice edit view to include the line editor**

Replace the full contents of `resources/views/invoices/edit.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Ubah Invoice')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-receipt me-2"></i>Ubah {{ $invoice->number }}</h1>
    </div>

    <form method="POST" action="{{ route('invoices.update', $invoice) }}" id="invoiceForm">
        @csrf
        @method('PUT')

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="discount_percent" class="form-label">Diskon (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="discount_percent" id="discount_percent"
                            class="form-control @error('discount_percent') is-invalid @enderror"
                            value="{{ old('discount_percent', $invoice->discount_percent) }}" required>
                        @error('discount_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="tax_percent" class="form-label">PPN (%)</label>
                        <input type="number" step="0.01" min="0" name="tax_percent" id="tax_percent"
                            class="form-control @error('tax_percent') is-invalid @enderror"
                            value="{{ old('tax_percent', $invoice->tax_percent) }}" required>
                        @error('tax_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-12">
                        <label for="notes" class="form-label">Catatan</label>
                        <textarea name="notes" id="notes" class="form-control @error('notes') is-invalid @enderror" rows="2">{{ old('notes', $invoice->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Jasa</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addInvoiceServiceLine">+ Tambah Jasa</button>
                </div>
                <div id="invoiceServiceLines"></div>
                @error('services')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Baris Sparepart</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addInvoiceSparepartLine">+ Tambah Sparepart</button>
                </div>
                <div id="invoiceSparepartLines"></div>
                @error('spareparts')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Simpan</button>
            <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-outline-secondary btn-sm">Batal</a>
        </div>
    </form>

    @include('invoices._line_item_scripts')

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    @endpush

    @push('scripts')
    <script>
    (function () {
        const branchId = {{ $invoice->branch_id }};
        window.currentInvoiceBranchId = branchId;

        const existingServiceLines = @json($existingServiceLines);
        existingServiceLines.forEach(function (line) {
            const locked = !!line.work_order_service_line_id;
            const row = InvoiceLineItems.addServiceLine(locked);
            row.querySelector('.service-wo-line-id').value = line.work_order_service_line_id || '';
            row.querySelector('.service-description').value = line.description;
            row.querySelector('.service-qty').value = line.qty;
            row.querySelector('.service-unit-price').value = line.unit_price;
        });

        const existingSparepartLines = @json($existingSparepartLines);
        existingSparepartLines.forEach(function (line) {
            const locked = !!line.work_order_sparepart_line_id;
            const row = InvoiceLineItems.addSparepartLine(branchId, locked);
            row.querySelector('.sparepart-wo-line-id').value = line.work_order_sparepart_line_id || '';
            row.querySelector('.sparepart-qty').value = line.qty;
            row.querySelector('.sparepart-unit-price').value = line.unit_price;
            if (locked) {
                row.querySelector('.sparepart-locked-branch-id').value = line.sparepart_branch_id;
                row.querySelector('.sparepart-locked-label').value = (line.item_code_snapshot ? line.item_code_snapshot + ' — ' : '') + line.description;
            } else {
                InvoiceLineItems.preselectFreeFormSparepartLine(row, line.sparepart_branch_id, branchId);
            }
        });
    })();
    </script>
    @endpush
@endsection
```

- [ ] **Step 8: Add edit-page rendering tests**

Append to `tests/Feature/InvoiceControllerTest.php`, right before the class's final closing `}`:

```php
    public function test_edit_page_renders_locked_pkb_lines_with_line_editor_markup(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/edit");

        $response->assertOk();
        $response->assertSee('select2', false);
        $response->assertSee('select2-ajax-picker.js', false);
        $response->assertSee('sparepart-item-locked', false);
        $response->assertSee('sparepart-item-free', false);
        $response->assertSee('"work_order_service_line_id":' . $serviceDetail->work_order_service_line_id, false);
        $response->assertSee('"work_order_sparepart_line_id":' . $sparepartDetail->work_order_sparepart_line_id, false);
    }

    public function test_edit_page_includes_free_form_line_data_without_a_pkb_trace(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $extraSparepart = Sparepart::create(['code' => 'FLT-01', 'name' => 'Filter Udara']);
        $extraSparepartBranch = SparepartBranch::create(['sparepart_id' => $extraSparepart->id, 'branch_id' => $branch->id, 'selling_price' => 45000]);
        InvoiceDetail::create([
            'invoice_id' => $invoice->id,
            'item_type' => \App\Support\InvoiceDetailItemType::SPAREPART,
            'work_order_service_line_id' => null,
            'work_order_sparepart_line_id' => null,
            'sparepart_branch_id' => $extraSparepartBranch->id,
            'description' => 'Filter Udara',
            'qty' => 1,
            'unit_price' => 45000,
            'line_total' => 45000,
            'sort_order' => 99,
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/edit");

        $response->assertOk();
        $response->assertSee('"work_order_sparepart_line_id":null,"sparepart_branch_id":' . $extraSparepartBranch->id, false);
    }
```

- [ ] **Step 9: Run the tests and confirm they pass**

Run: `php artisan test tests/Feature/InvoiceControllerTest.php`
Expected: PASS (all tests, including the new and modified ones).

- [ ] **Step 10: Run the full suite**

Run: `php artisan test`
Expected: all pass — no other suite touches `InvoiceService`/`UpdateInvoiceRequest`/the invoice edit view.

- [ ] **Step 11: Commit**

```bash
git add app/Services/InvoiceService.php app/Http/Requests/UpdateInvoiceRequest.php app/Http/Controllers/InvoiceController.php resources/views/invoices/edit.blade.php resources/views/invoices/_line_item_scripts.blade.php tests/Feature/InvoiceControllerTest.php
git commit -m "feat: allow editing Invoice line items before posting"
```

---

## Task 3: Cancel a draft Invoice

**Files:**
- Modify: `app/Services/InvoiceService.php`
- Modify: `app/Policies/InvoicePolicy.php`
- Create: `app/Http/Requests/CancelInvoiceRequest.php`
- Modify: `app/Http/Controllers/InvoiceController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/invoices/show.blade.php`
- Modify: `tests/Feature/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `InvoiceService::releaseReservationsForLines()` (Task 2).
- Produces: `InvoiceService::cancelInvoice(Invoice $invoice, string $reason): Invoice` (throws `\DomainException` if not `draft`); `InvoicePolicy::cancel(User $user, Invoice $invoice): bool`; route `invoices.cancel` (`PATCH /invoices/{invoice}/cancel`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/InvoiceControllerTest.php`, right before the class's final closing `}`:

```php
    public function test_cancel_marks_draft_invoice_as_cancelled_and_releases_reservations(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $sparepartBranchId = $sparepartDetail->sparepart_branch_id;
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.void');

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/cancel", ['reason' => 'Customer batal servis.']);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();
        $this->assertSame(\App\Support\InvoiceStatus::CANCELLED, $invoice->status);
        $this->assertSame('Customer batal servis.', $invoice->cancel_reason);
        $this->assertSame($user->id, $invoice->cancelled_by);
        $this->assertNotNull($invoice->cancelled_at);
        $stockAfter = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchId)->first();
        $this->assertSame(0.0, (float) $stockAfter->reserved_qty);
    }

    public function test_cancel_is_forbidden_once_invoice_is_posted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        (new InvoiceService())->postInvoice($invoice);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.void');

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/cancel", ['reason' => 'Coba batalkan setelah posting.']);

        $response->assertForbidden();
    }

    public function test_cancel_requires_invoice_void_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/cancel", ['reason' => 'Tanpa izin.']);

        $response->assertForbidden();
    }

    public function test_cancel_requires_a_reason(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.void');

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/cancel", []);

        $response->assertSessionHasErrors('reason');
    }

    public function test_show_offers_cancel_form_for_draft_invoice_with_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'invoice.void');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee(route('invoices.cancel', $invoice), false);
        $response->assertSee('Batalkan Invoice');
    }

    public function test_show_displays_cancellation_info_after_invoice_is_cancelled(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'invoice.void');
        $this->actingAs($user)->patch("/invoices/{$invoice->id}/cancel", ['reason' => 'Customer batal servis.']);

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee('Invoice dibatalkan');
        $response->assertSee('Customer batal servis.');
        $response->assertDontSee('Batalkan Invoice');
    }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test --filter=test_cancel_ tests/Feature/InvoiceControllerTest.php`
Expected: FAIL — route `invoices.cancel` doesn't exist yet.

- [ ] **Step 3: Add `InvoiceService::cancelInvoice()`**

In `app/Services/InvoiceService.php`, add this method right after `updateInvoice()`:

```php
    public function cancelInvoice(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($fresh->status !== InvoiceStatus::DRAFT) {
                throw new DomainException('Invoice sudah tidak berstatus draft, tidak bisa dibatalkan.');
            }

            $lineIds = $fresh->details()->whereNotNull('work_order_sparepart_line_id')->pluck('work_order_sparepart_line_id');
            $this->releaseReservationsForLines($lineIds);

            $fresh->update([
                'status' => InvoiceStatus::CANCELLED,
                'cancel_reason' => $reason,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
            ]);

            return $fresh;
        });
    }
```

- [ ] **Step 4: Add `InvoicePolicy::cancel()`**

In `app/Policies/InvoicePolicy.php`, add this method after `post()`:

```php
    public function cancel(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::DRAFT
            && $user->hasPermissionToInBranch('invoice.void', $invoice->branch_id);
    }
```

- [ ] **Step 5: Create `CancelInvoiceRequest`**

Create `app/Http/Requests/CancelInvoiceRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelInvoiceRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('cancel', $this->route('invoice'));
    }

    public function rules()
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 6: Add `InvoiceController::cancel()`**

In `app/Http/Controllers/InvoiceController.php`, add the import:

```php
use App\Http\Requests\CancelInvoiceRequest;
```

Add this method at the end of the class, right after `post()`:

```php
    public function cancel(CancelInvoiceRequest $request, Invoice $invoice)
    {
        try {
            (new InvoiceService())->cancelInvoice($invoice, $request->validated()['reason']);
        } catch (DomainException $e) {
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice berhasil dibatalkan.');
    }
```

- [ ] **Step 7: Add the route**

In `routes/web.php`, update the `invoices` group:

```php
    Route::prefix('invoices')->name('invoices.')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::post('/', [InvoiceController::class, 'store'])->name('store');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('show');
        Route::get('/{invoice}/edit', [InvoiceController::class, 'edit'])->name('edit');
        Route::put('/{invoice}', [InvoiceController::class, 'update'])->name('update');
        Route::patch('/{invoice}/post', [InvoiceController::class, 'post'])->name('post');
        Route::patch('/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('cancel');
    });
```

- [ ] **Step 8: Add the cancellation form/info card to the show view**

In `resources/views/invoices/show.blade.php`, insert this block right after the "Ringkasan" card, before the final "Kembali" link:

```blade
    @if ($invoice->cancelled_at)
        <div class="card mb-3">
            <div class="card-body">
                <p class="mb-0">
                    <strong>Invoice dibatalkan</strong> oleh {{ optional($invoice->cancelledBy)->name ?? '-' }}
                    pada {{ $invoice->cancelled_at->format('d/m/Y H:i') }}: {{ $invoice->cancel_reason }}
                </p>
            </div>
        </div>
    @elseif ($invoice->status === \App\Support\InvoiceStatus::DRAFT)
        @can('cancel', $invoice)
            <div class="card mb-3">
                <div class="card-body">
                    <form method="POST" action="{{ route('invoices.cancel', $invoice) }}">
                        @csrf
                        @method('PATCH')
                        <label for="reason" class="form-label"><strong>Batalkan Invoice</strong></label>
                        <textarea name="reason" id="reason" class="form-control @error('reason') is-invalid @enderror" rows="2" required></textarea>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-outline-danger btn-sm mt-2">Kirim Pembatalan</button>
                    </form>
                </div>
            </div>
        @endcan
    @endif

```

- [ ] **Step 9: Run the tests and confirm they pass**

Run: `php artisan test tests/Feature/InvoiceControllerTest.php`
Expected: PASS.

- [ ] **Step 10: Run the full suite**

Run: `php artisan test`
Expected: all pass — this is the last task in this plan, so this is the final full-suite regression check for the whole feature (Tasks 1-3 combined).

- [ ] **Step 11: Commit**

```bash
git add app/Services/InvoiceService.php app/Policies/InvoicePolicy.php app/Http/Requests/CancelInvoiceRequest.php app/Http/Controllers/InvoiceController.php routes/web.php resources/views/invoices/show.blade.php tests/Feature/InvoiceControllerTest.php
git commit -m "feat: allow cancelling a draft Invoice and releasing its reservations"
```
