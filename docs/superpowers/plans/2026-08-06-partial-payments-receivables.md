# Partial Payments & Receivables (Migration 010) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user record a customer payment (`PaymentReceipt`) that allocates across one or more of that customer's outstanding invoices (`PaymentAllocation`), automatically moving each allocated invoice's status through `posted → partially_paid → paid` as its `paid_amount` accumulates, with a whole-document void to reverse a mistaken receipt.

**Architecture:** New standalone module (no existing controller/service to extend), built the same way Goods Receipt / Stock Adjustment / Stock Transfer were: a data model + a service class doing all the locking/business logic + a Policy + a customer-first create flow + list/detail views. Wires into the already-shipped Sales Invoice module by adding `paid_amount` to `invoices` and 2 new `InvoiceStatus` constants, and into the already-seeded permission catalog (`payment.view/create/void/print` — no new permission codes).

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL 8 (tests run against real MySQL — `phpunit.xml` sets `DB_CONNECTION=mysql`, `DB_DATABASE=bengkel_testing`, so DB `CHECK` constraints are enforced during tests), Blade + Bootstrap 5 (CDN) + jQuery + Select2, no SPA/build step.

## Global Constraints

- PHP 7.4.33 — no PHP 8+ syntax (nullsafe `?->`, named args, `match`, enums, constructor promotion) anywhere, Blade `@php()` included.
- No roles table — authorization is direct-to-user via `Gate::before` + `$user->hasPermissionToInBranch('code', $branchId)`.
- Policies are registered manually in `app/Providers/AuthServiceProvider.php`'s `$policies` array — this plan adds `PaymentReceipt::class => PaymentReceiptPolicy::class` there (Task 2).
- **Permission codes already seeded, do not re-seed:** `payment.view`, `payment.create`, `payment.void`, `payment.print` (`database/seeders/MenuPermissionSeeder.php:71-81`). There is deliberately no `payment.post` — a `PaymentReceipt` is posted the instant it's created (see spec Decision 1).
- Every list endpoint uses `->simplePaginate()`, never `->paginate()`.
- All money columns are `decimal(18,2)`. Every float comparison against a money value uses an epsilon of `0.0005` (`abs($a - $b) < 0.0005` or `$a > $b + 0.0005`), never `===`/`==`, matching `StockAdjustmentController`/`StockTransferController`.
- **Locking discipline (see spec Decision 6):** both `PaymentService::createPaymentReceipt()` and `voidPaymentReceipt()` must lock every `Invoice` row they touch in **ascending `id` order**, and every check that depends on current DB state (branch/customer match, invoice status, remaining outstanding balance) must be re-verified *after* the lock is acquired — never trust a value the request/form was built with.
- Reuse the existing `/lookup/customers?branch_id=X` endpoint (`app/Http/Controllers/LookupController::customers()`) for the Customer picker — it already filters to customers with an *active* `customer_branches` row for the given branch. Do not build a second customer lookup.
- New document number prefix: `PAY` (via the existing `App\Services\DocumentNumberGenerator::next($branch, 'PAY')`, same as `INV`/`PKB`/`PB`/`SA`/`ST`).
- Status badge colors follow the severity convention fixed in commit `ea76a33`: `status-inactive` (gray/neutral), `status-active` (green/good), `status-warning` (yellow/needs attention), `status-danger` (red/terminal-negative). Task 5 also fixes Invoice's `CANCELLED` badge to `status-danger` (it was still `status-inactive`, predating that convention) since it's in the exact block Task 5 already touches.

---

## Task 1: Schema, Models, Support constants, and `PaymentService::createPaymentReceipt()`

**Files:**
- Create: `database/migrations/2026_08_06_000004_create_payment_receipts_table.php`
- Create: `database/migrations/2026_08_06_000005_create_payment_allocations_table.php`
- Create: `database/migrations/2026_08_06_000006_add_paid_amount_to_invoices_table.php`
- Create: `app/Support/PaymentMethod.php`
- Create: `app/Support/PaymentReceiptStatus.php`
- Create: `app/Models/PaymentReceipt.php`
- Create: `app/Models/PaymentAllocation.php`
- Modify: `app/Support/InvoiceStatus.php`
- Modify: `app/Models/Invoice.php`
- Create: `app/Services/PaymentService.php`
- Test: `tests/Feature/PaymentServiceTest.php` (new)

**Interfaces:**
- Produces: `PaymentReceipt` (fillable: `number`, `branch_id`, `customer_id`, `payment_date`, `payment_method`, `reference_number`, `amount`, `status`, `notes`, `voided_at`, `voided_by`, `void_reason`; relations `branch()`, `customer()`, `voidedBy()`, `allocations()`), `PaymentAllocation` (fillable: `payment_receipt_id`, `invoice_id`, `allocated_amount`; relations `paymentReceipt()`, `invoice()`), `App\Support\PaymentMethod::{CASH,TRANSFER,QRIS,DEBIT_CARD,CREDIT_CARD,OTHER}` + `::LABELS`, `App\Support\PaymentReceiptStatus::{POSTED,VOID}`, `InvoiceStatus::{PARTIALLY_PAID,PAID}`, `Invoice::getOutstandingAmountAttribute()`, `Invoice::allocations(): HasMany`, and `PaymentService::createPaymentReceipt(array $data): PaymentReceipt`.
- Consumes (from already-shipped code): `App\Services\DocumentNumberGenerator::next(Branch $branch, string $type): string`, `App\Support\InvoiceStatus::{POSTED,PARTIALLY_PAID}`, `Invoice::grand_total`/`paid_amount`/`branch_id`/`customer_id`/`status`.

- [ ] **Step 1: Write the migrations**

`database/migrations/2026_08_06_000004_create_payment_receipts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatePaymentReceiptsTable extends Migration
{
    public function up()
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained('customers');
            $table->date('payment_date');
            $table->string('payment_method', 20);
            $table->string('reference_number', 100)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('posted');
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('voided_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'payment_date', 'status']);
        });

        DB::statement('ALTER TABLE payment_receipts ADD CONSTRAINT ck_payment_receipts_amount_positive CHECK (amount > 0)');
    }

    public function down()
    {
        Schema::dropIfExists('payment_receipts');
    }
}
```

`database/migrations/2026_08_06_000005_create_payment_allocations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatePaymentAllocationsTable extends Migration
{
    public function up()
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_receipt_id')->constrained('payment_receipts')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->decimal('allocated_amount', 18, 2);
            $table->timestamps();

            $table->unique(['payment_receipt_id', 'invoice_id']);
            $table->index('invoice_id');
        });

        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT ck_payment_allocations_amount_positive CHECK (allocated_amount > 0)');
    }

    public function down()
    {
        Schema::dropIfExists('payment_allocations');
    }
}
```

`database/migrations/2026_08_06_000006_add_paid_amount_to_invoices_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPaidAmountToInvoicesTable extends Migration
{
    public function up()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('paid_amount', 18, 2)->default(0)->after('grand_total');
        });

        DB::statement('ALTER TABLE invoices ADD CONSTRAINT ck_invoices_paid_amount_nonnegative CHECK (paid_amount >= 0)');
    }

    public function down()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
}
```

Run: `php artisan migrate`
Expected: 3 new migrations run cleanly against the dev DB (or the test DB re-migrates automatically via `RefreshDatabase` once tests run).

- [ ] **Step 2: Add the Support constants classes**

`app/Support/PaymentMethod.php`:

```php
<?php

namespace App\Support;

class PaymentMethod
{
    const CASH = 'cash';
    const TRANSFER = 'transfer';
    const QRIS = 'qris';
    const DEBIT_CARD = 'debit_card';
    const CREDIT_CARD = 'credit_card';
    const OTHER = 'other';

    const ALL = [self::CASH, self::TRANSFER, self::QRIS, self::DEBIT_CARD, self::CREDIT_CARD, self::OTHER];

    const LABELS = [
        self::CASH => 'Tunai',
        self::TRANSFER => 'Transfer Bank',
        self::QRIS => 'QRIS',
        self::DEBIT_CARD => 'Kartu Debit',
        self::CREDIT_CARD => 'Kartu Kredit',
        self::OTHER => 'Lainnya',
    ];
}
```

`app/Support/PaymentReceiptStatus.php`:

```php
<?php

namespace App\Support;

class PaymentReceiptStatus
{
    const POSTED = 'posted';
    const VOID = 'void';
}
```

Modify `app/Support/InvoiceStatus.php` to:

```php
<?php

namespace App\Support;

class InvoiceStatus
{
    const DRAFT = 'draft';
    const POSTED = 'posted';
    const CANCELLED = 'cancelled';
    const PARTIALLY_PAID = 'partially_paid';
    const PAID = 'paid';
}
```

- [ ] **Step 3: Add the two new models**

`app/Models/PaymentReceipt.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\PaymentReceiptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentReceipt extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'branch_id', 'customer_id', 'payment_date', 'payment_method',
        'reference_number', 'amount', 'status', 'notes',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'voided_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => PaymentReceiptStatus::POSTED,
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
```

`app/Models/PaymentAllocation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = ['payment_receipt_id', 'invoice_id', 'allocated_amount'];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
    ];

    public function paymentReceipt()
    {
        return $this->belongsTo(PaymentReceipt::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
```

- [ ] **Step 4: Extend `Invoice`**

In `app/Models/Invoice.php`:
- Add `'paid_amount'` to `$fillable`.
- Add `'paid_amount' => 'decimal:2'` to `$casts`.
- Add a new relation and accessor:

```php
    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function getOutstandingAmountAttribute()
    {
        return round((float) $this->grand_total - (float) $this->paid_amount, 2);
    }
```

(Add `use App\Models\PaymentAllocation;` — actually unnecessary since it's the same `App\Models` namespace as `Invoice`, no import needed.)

- [ ] **Step 5: Write the failing tests for `PaymentService::createPaymentReceipt()`**

Create `tests/Feature/PaymentServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
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
use App\Services\PaymentService;
use App\Services\UserBranchService;
use App\Support\InvoiceStatus;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
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
    // local scenario builder.
    protected function makePostedInvoice(Branch $branch, Customer $customer, float $grandTotal): Invoice
    {
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::firstOrCreate(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id,
            'plate_number' => 'B ' . random_int(1000, 9999) . ' ' . $branch->code,
        ]);
        $mechanic = Mechanic::firstOrCreate(['name' => "Mekanik {$branch->code}"]);
        MechanicBranch::firstOrCreate(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create([
            'code' => 'SVC-' . random_int(1000, 9999), 'name' => 'Jasa', 'default_price' => $grandTotal,
        ]);

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
                ['service_catalog_id' => $catalog->id, 'description' => 'Jasa', 'qty' => 1, 'unit_price' => $grandTotal],
            ],
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $invoice = (new InvoiceService())->createFromWorkOrder($workOrder->fresh());

        return (new InvoiceService())->postInvoice($invoice);
    }

    public function test_create_payment_receipt_allocates_across_two_invoices_and_updates_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $invoiceA = $this->makePostedInvoice($branch, $customer, 100000);
        $invoiceB = $this->makePostedInvoice($branch, $customer, 200000);

        $receipt = (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 250000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoiceA->id, 'allocated_amount' => 100000],
                ['invoice_id' => $invoiceB->id, 'allocated_amount' => 150000],
            ],
        ]);

        $this->assertNotNull($receipt->number);
        $this->assertCount(2, $receipt->allocations);

        $invoiceA->refresh();
        $invoiceB->refresh();
        $this->assertSame(100000.0, (float) $invoiceA->paid_amount);
        $this->assertSame(InvoiceStatus::PAID, $invoiceA->status);
        $this->assertSame(150000.0, (float) $invoiceB->paid_amount);
        $this->assertSame(InvoiceStatus::PARTIALLY_PAID, $invoiceB->status);
        $this->assertSame(50000.0, (float) $invoiceB->outstanding_amount);
    }

    public function test_create_rejects_allocation_exceeding_outstanding_balance(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);

        $this->expectException(DomainException::class);

        (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 150000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 150000],
            ],
        ]);
    }

    public function test_create_rejects_invoice_belonging_to_a_different_customer(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari']);
        $invoice = $this->makePostedInvoice($branch, $customerA, 100000);

        $this->expectException(DomainException::class);

        (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customerB->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 50000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 50000],
            ],
        ]);
    }

    public function test_create_rejects_invoice_that_is_not_posted_or_partially_paid(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $invoice->update(['status' => InvoiceStatus::PAID, 'paid_amount' => 100000]);

        $this->expectException(DomainException::class);

        (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 10000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 10000],
            ],
        ]);
    }
}
```

- [ ] **Step 6: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/PaymentServiceTest.php`
Expected: FAIL — `App\Services\PaymentService` does not exist (class not found), or migrations for `payment_receipts`/`payment_allocations`/`invoices.paid_amount` are missing if Step 1 wasn't actually run against the test DB (it re-migrates automatically via `RefreshDatabase`, so this should only fail on the missing service class).

- [ ] **Step 7: Implement `PaymentService`**

`app/Services/PaymentService.php`:

```php
<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentReceipt;
use App\Support\InvoiceStatus;
use App\Support\PaymentReceiptStatus;
use DomainException;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function createPaymentReceipt(array $data): PaymentReceipt
    {
        return DB::transaction(function () use ($data) {
            $allocations = collect($data['allocations'])->sortBy('invoice_id')->values();

            $lockedInvoices = [];

            foreach ($allocations as $allocation) {
                $invoice = Invoice::whereKey($allocation['invoice_id'])->lockForUpdate()->first();

                if (! $invoice) {
                    throw new DomainException("Invoice #{$allocation['invoice_id']} tidak ditemukan.");
                }

                if ((int) $invoice->branch_id !== (int) $data['branch_id'] || (int) $invoice->customer_id !== (int) $data['customer_id']) {
                    throw new DomainException("Invoice {$invoice->number} bukan milik cabang/customer pembayaran ini.");
                }

                if (! in_array($invoice->status, [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID], true)) {
                    throw new DomainException("Invoice {$invoice->number} tidak dapat menerima pembayaran (status saat ini: {$invoice->status}).");
                }

                $outstanding = round((float) $invoice->grand_total - (float) $invoice->paid_amount, 2);
                $allocatedAmount = (float) $allocation['allocated_amount'];

                if ($allocatedAmount > $outstanding + 0.0005) {
                    throw new DomainException(sprintf(
                        'Alokasi untuk invoice %s (%s) melebihi sisa piutang (%s).',
                        $invoice->number,
                        number_format($allocatedAmount, 0, ',', '.'),
                        number_format($outstanding, 0, ',', '.')
                    ));
                }

                $lockedInvoices[$allocation['invoice_id']] = $invoice;
            }

            $branch = Branch::findOrFail($data['branch_id']);

            $receipt = PaymentReceipt::create([
                'number' => (new DocumentNumberGenerator())->next($branch, 'PAY'),
                'branch_id' => $data['branch_id'],
                'customer_id' => $data['customer_id'],
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'amount' => $data['amount'],
                'status' => PaymentReceiptStatus::POSTED,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($allocations as $allocation) {
                $invoice = $lockedInvoices[$allocation['invoice_id']];
                $allocatedAmount = (float) $allocation['allocated_amount'];

                PaymentAllocation::create([
                    'payment_receipt_id' => $receipt->id,
                    'invoice_id' => $invoice->id,
                    'allocated_amount' => $allocatedAmount,
                ]);

                $invoice->paid_amount = round((float) $invoice->paid_amount + $allocatedAmount, 2);
                $invoice->status = $this->recomputeInvoiceStatus($invoice);
                $invoice->save();
            }

            return $receipt->fresh('allocations');
        });
    }

    protected function recomputeInvoiceStatus(Invoice $invoice): string
    {
        $paid = (float) $invoice->paid_amount;
        $grandTotal = (float) $invoice->grand_total;

        if ($paid >= $grandTotal - 0.0005) {
            return InvoiceStatus::PAID;
        }

        if ($paid > 0.0005) {
            return InvoiceStatus::PARTIALLY_PAID;
        }

        return InvoiceStatus::POSTED;
    }
}
```

- [ ] **Step 8: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/PaymentServiceTest.php`
Expected: PASS (4 tests).

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_06_000004_create_payment_receipts_table.php \
        database/migrations/2026_08_06_000005_create_payment_allocations_table.php \
        database/migrations/2026_08_06_000006_add_paid_amount_to_invoices_table.php \
        app/Support/PaymentMethod.php app/Support/PaymentReceiptStatus.php app/Support/InvoiceStatus.php \
        app/Models/PaymentReceipt.php app/Models/PaymentAllocation.php app/Models/Invoice.php \
        app/Services/PaymentService.php tests/Feature/PaymentServiceTest.php
git commit -m "feat: add PaymentReceipt/PaymentAllocation schema and PaymentService::createPaymentReceipt"
```

---

## Task 2: `PaymentService::voidPaymentReceipt()` and `PaymentReceiptPolicy`

**Files:**
- Create: `app/Policies/PaymentReceiptPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php` (register the policy)
- Modify: `app/Services/PaymentService.php`
- Modify: `tests/Feature/PaymentServiceTest.php`

**Interfaces:**
- Consumes: everything from Task 1.
- Produces: `PaymentService::voidPaymentReceipt(PaymentReceipt $receipt, string $reason): PaymentReceipt`, `PaymentReceiptPolicy::view(User, PaymentReceipt): bool` / `::void(User, PaymentReceipt): bool`. Task 3's Controller/FormRequest call both.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PaymentServiceTest.php` (inside the class, before the final closing `}`):

```php
    public function test_void_reverses_allocations_and_recomputes_invoice_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $invoiceA = $this->makePostedInvoice($branch, $customer, 100000);
        $invoiceB = $this->makePostedInvoice($branch, $customer, 200000);

        $service = new PaymentService();
        $receipt = $service->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 250000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoiceA->id, 'allocated_amount' => 100000],
                ['invoice_id' => $invoiceB->id, 'allocated_amount' => 150000],
            ],
        ]);

        $voided = $service->voidPaymentReceipt($receipt, 'Salah input nominal');

        $this->assertSame(\App\Support\PaymentReceiptStatus::VOID, $voided->status);
        $this->assertSame('Salah input nominal', $voided->void_reason);
        $this->assertNotNull($voided->voided_at);

        $invoiceA->refresh();
        $invoiceB->refresh();
        $this->assertSame(0.0, (float) $invoiceA->paid_amount);
        $this->assertSame(InvoiceStatus::POSTED, $invoiceA->status);
        $this->assertSame(0.0, (float) $invoiceB->paid_amount);
        $this->assertSame(InvoiceStatus::POSTED, $invoiceB->status);
    }

    public function test_void_is_rejected_when_receipt_is_already_void(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);

        $service = new PaymentService();
        $receipt = $service->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 100000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 100000],
            ],
        ]);
        $service->voidPaymentReceipt($receipt, 'Pertama');

        $this->expectException(DomainException::class);
        $service->voidPaymentReceipt($receipt->fresh(), 'Kedua');
    }
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/PaymentServiceTest.php`
Expected: FAIL — `Call to undefined method App\Services\PaymentService::voidPaymentReceipt()`.

- [ ] **Step 3: Implement `voidPaymentReceipt()`**

Add to `app/Services/PaymentService.php` (inside the class):

```php
    public function voidPaymentReceipt(PaymentReceipt $receipt, string $reason): PaymentReceipt
    {
        return DB::transaction(function () use ($receipt, $reason) {
            $fresh = PaymentReceipt::whereKey($receipt->id)->lockForUpdate()->first();

            if ($fresh->status !== PaymentReceiptStatus::POSTED) {
                throw new DomainException('Payment receipt ini sudah tidak berstatus posted, tidak bisa di-void.');
            }

            $allocations = $fresh->allocations()->orderBy('invoice_id')->get();

            foreach ($allocations as $allocation) {
                $invoice = Invoice::whereKey($allocation->invoice_id)->lockForUpdate()->first();

                $invoice->paid_amount = max(0, round((float) $invoice->paid_amount - (float) $allocation->allocated_amount, 2));
                $invoice->status = $this->recomputeInvoiceStatus($invoice);
                $invoice->save();
            }

            $fresh->update([
                'status' => PaymentReceiptStatus::VOID,
                'void_reason' => $reason,
                'voided_by' => auth()->id(),
                'voided_at' => now(),
            ]);

            return $fresh;
        });
    }
```

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/PaymentServiceTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Write `PaymentReceiptPolicy` and register it**

`app/Policies/PaymentReceiptPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\PaymentReceipt;
use App\Models\User;
use App\Support\PaymentReceiptStatus;

class PaymentReceiptPolicy
{
    public function view(User $user, PaymentReceipt $paymentReceipt): bool
    {
        return $user->hasPermissionToInBranch('payment.view', $paymentReceipt->branch_id);
    }

    public function void(User $user, PaymentReceipt $paymentReceipt): bool
    {
        return $paymentReceipt->status === PaymentReceiptStatus::POSTED
            && $user->hasPermissionToInBranch('payment.void', $paymentReceipt->branch_id);
    }
}
```

In `app/Providers/AuthServiceProvider.php`, add to the `$policies` array:

```php
        \App\Models\PaymentReceipt::class => \App\Policies\PaymentReceiptPolicy::class,
```

(Match the existing array's style — check the file for whether entries use imported class names or fully-qualified strings, and follow whichever the file already does.)

- [ ] **Step 6: Run the full test suite to confirm no regression**

Run: `php artisan test`
Expected: PASS, no failures outside this plan's own new tests.

- [ ] **Step 7: Commit**

```bash
git add app/Services/PaymentService.php app/Policies/PaymentReceiptPolicy.php \
        app/Providers/AuthServiceProvider.php tests/Feature/PaymentServiceTest.php
git commit -m "feat: add PaymentService::voidPaymentReceipt and PaymentReceiptPolicy"
```

---

## Task 3: FormRequests, `PaymentReceiptController`, outstanding-invoices lookup, routes

**Files:**
- Create: `app/Http/Requests/StorePaymentReceiptRequest.php`
- Create: `app/Http/Requests/VoidPaymentReceiptRequest.php`
- Create: `app/Http/Controllers/PaymentReceiptController.php`
- Create: `app/Http/Controllers/PaymentLookupController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PaymentReceiptControllerTest.php` (new)

**Interfaces:**
- Consumes: `PaymentService::createPaymentReceipt()`/`voidPaymentReceipt()`, `PaymentReceiptPolicy`, `LookupController::customers` (reused as-is, unmodified), `Customer::hasAccessToBranch(int): bool` (already exists on `Customer`).
- Produces: routes `payment-receipts.index/create/store/show/void`, `payment-receipts.lookup.outstanding-invoices`. Task 4's views POST/GET these routes.

- [ ] **Step 1: Write the FormRequests**

`app/Http/Requests/StorePaymentReceiptRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Invoice;
use App\Support\InvoiceStatus;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentReceiptRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('payment.create', $branchId);
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'allocations' => array_values(array_filter($this->input('allocations', []), function ($line) {
                return ! empty($line['invoice_id']) && isset($line['allocated_amount']) && (float) $line['allocated_amount'] > 0;
            })),
        ]);
    }

    public function rules()
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', 'string', 'in:' . implode(',', \App\Support\PaymentMethod::ALL)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*' => ['array'],
            'allocations.*.invoice_id' => ['required', 'integer', 'exists:invoices,id', 'distinct'],
            'allocations.*.allocated_amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $branchId = (int) $this->input('branch_id');
            $customerId = (int) $this->input('customer_id');

            $customer = Customer::find($customerId);
            if ($customer && ! $customer->hasAccessToBranch($branchId)) {
                $validator->errors()->add('customer_id', 'Customer ini tidak terdaftar di cabang tersebut.');
            }

            $sumAllocations = round(collect($this->input('allocations', []))->sum(function ($line) {
                return (float) ($line['allocated_amount'] ?? 0);
            }), 2);
            $amount = round((float) $this->input('amount', 0), 2);

            if (abs($sumAllocations - $amount) > 0.0005) {
                $validator->errors()->add('amount', 'Total nominal pembayaran harus sama dengan total seluruh alokasi invoice.');
            }

            foreach ($this->input('allocations', []) as $index => $line) {
                $invoiceId = $line['invoice_id'] ?? null;
                if (! $invoiceId) {
                    continue;
                }
                $invoice = Invoice::find($invoiceId);
                if (! $invoice) {
                    continue;
                }
                if ((int) $invoice->branch_id !== $branchId || (int) $invoice->customer_id !== $customerId) {
                    $validator->errors()->add("allocations.{$index}.invoice_id", 'Invoice bukan milik cabang/customer ini.');
                    continue;
                }
                if (! in_array($invoice->status, [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID], true)) {
                    $validator->errors()->add("allocations.{$index}.invoice_id", 'Invoice ini tidak memiliki sisa piutang yang bisa dibayar.');
                }
            }
        });
    }
}
```

`app/Http/Requests/VoidPaymentReceiptRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidPaymentReceiptRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('void', $this->route('paymentReceipt'));
    }

    public function rules()
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 2: Write `PaymentLookupController`**

`app/Http/Controllers/PaymentLookupController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\InvoiceStatus;

class PaymentLookupController extends Controller
{
    public function outstandingInvoicesByCustomer(Customer $customer)
    {
        $branchId = (int) request('branch_id');
        abort_if($branchId <= 0, 400, 'branch_id is required.');
        abort_unless(auth()->user()->hasPermissionToInBranch('payment.create', $branchId), 403);
        abort_unless($customer->hasAccessToBranch($branchId), 403);

        $invoices = $customer->invoices()
            ->where('branch_id', $branchId)
            ->whereIn('status', [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID])
            ->orderBy('invoice_date')
            ->get();

        return response()->json(
            $invoices->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'invoice_date' => $invoice->invoice_date->format('d/m/Y'),
                    'grand_total' => (float) $invoice->grand_total,
                    'paid_amount' => (float) $invoice->paid_amount,
                    'outstanding_amount' => (float) $invoice->outstanding_amount,
                ];
            })->values()
        );
    }
}
```

This requires a `Customer::invoices()` relation — check `app/Models/Customer.php` (read in Task 1 research: it currently has `customerBranches()`, `branches()`, `hasAccessToBranch()`, `vehicles()`, no `invoices()`). Add it:

```php
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
```

- [ ] **Step 3: Write `PaymentReceiptController`**

`app/Http/Controllers/PaymentReceiptController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentReceiptRequest;
use App\Http\Requests\VoidPaymentReceiptRequest;
use App\Models\PaymentReceipt;
use App\Services\PaymentService;
use App\Support\PaymentMethod;
use DomainException;

class PaymentReceiptController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('payment.view');

        if ($permittedBranches->isEmpty()) {
            return view('payment-receipts.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $paymentReceipts = PaymentReceipt::with(['branch', 'customer'])
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($search, function ($query, $q) {
                $query->where('number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('payment-receipts.index', compact('paymentReceipts'))
            ->with('branches', $permittedBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function create()
    {
        $branches = auth()->user()->branchesWithPermission('payment.create');

        if ($branches->isEmpty()) {
            return view('payment-receipts.no-access');
        }

        return view('payment-receipts.create', [
            'branches' => $branches,
            'paymentMethods' => PaymentMethod::LABELS,
        ]);
    }

    public function store(StorePaymentReceiptRequest $request)
    {
        try {
            $receipt = (new PaymentService())->createPaymentReceipt($request->validated());
        } catch (DomainException $e) {
            return redirect()->route('payment-receipts.create')->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('payment-receipts.show', $receipt)->with('status', 'Pembayaran berhasil dicatat.');
    }

    public function show(PaymentReceipt $paymentReceipt)
    {
        $this->authorize('view', $paymentReceipt);

        $paymentReceipt->load(['branch', 'customer', 'voidedBy', 'allocations.invoice']);

        return view('payment-receipts.show', compact('paymentReceipt'));
    }

    public function void(VoidPaymentReceiptRequest $request, PaymentReceipt $paymentReceipt)
    {
        try {
            (new PaymentService())->voidPaymentReceipt($paymentReceipt, $request->validated()['reason']);
        } catch (DomainException $e) {
            return redirect()->route('payment-receipts.show', $paymentReceipt)->with('error', $e->getMessage());
        }

        return redirect()->route('payment-receipts.show', $paymentReceipt)->with('status', 'Pembayaran berhasil di-void.');
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add the import lines near the other controller `use` statements:

```php
use App\Http\Controllers\PaymentLookupController;
use App\Http\Controllers\PaymentReceiptController;
```

Add a new route group (placed after the `invoices` group, e.g. right after line 185's closing `});`):

```php
    Route::prefix('payment-receipts')->name('payment-receipts.')->group(function () {
        Route::get('/lookup/outstanding-invoices/{customer}', [PaymentLookupController::class, 'outstandingInvoicesByCustomer'])->name('lookup.outstanding-invoices');

        Route::get('/', [PaymentReceiptController::class, 'index'])->name('index');
        Route::get('/create', [PaymentReceiptController::class, 'create'])->name('create');
        Route::post('/', [PaymentReceiptController::class, 'store'])->name('store');
        Route::get('/{paymentReceipt}', [PaymentReceiptController::class, 'show'])->name('show');
        Route::patch('/{paymentReceipt}/void', [PaymentReceiptController::class, 'void'])->name('void');
    });
```

- [ ] **Step 5: Write the failing controller tests**

Create `tests/Feature/PaymentReceiptControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\PaymentReceipt;
use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentReceiptControllerTest extends TestCase
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

    protected function makePostedInvoice(Branch $branch, Customer $customer, float $grandTotal): Invoice
    {
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::firstOrCreate(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id,
            'plate_number' => 'B ' . random_int(1000, 9999) . ' ' . $branch->code,
        ]);
        $mechanic = Mechanic::firstOrCreate(['name' => "Mekanik {$branch->code}"]);
        MechanicBranch::firstOrCreate(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create([
            'code' => 'SVC-' . random_int(1000, 9999), 'name' => 'Jasa', 'default_price' => $grandTotal,
        ]);

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
                ['service_catalog_id' => $catalog->id, 'description' => 'Jasa', 'qty' => 1, 'unit_price' => $grandTotal],
            ],
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $invoice = (new InvoiceService())->createFromWorkOrder($workOrder->fresh());

        return (new InvoiceService())->postInvoice($invoice);
    }

    public function test_store_creates_payment_receipt_and_updates_invoice_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'payment.create');

        $response = $this->actingAs($user)->post('/payment-receipts', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->format('Y-m-d'),
            'payment_method' => 'cash',
            'amount' => 100000,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 100000],
            ],
        ]);

        $receipt = PaymentReceipt::latest('id')->first();
        $response->assertRedirect("/payment-receipts/{$receipt->id}");
        $invoice->refresh();
        $this->assertSame(\App\Support\InvoiceStatus::PAID, $invoice->status);
    }

    public function test_store_requires_payment_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/payment-receipts', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->format('Y-m-d'),
            'payment_method' => 'cash',
            'amount' => 100000,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 100000],
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_store_rejects_when_allocation_sum_does_not_match_amount(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'payment.create');

        $response = $this->actingAs($user)->post('/payment-receipts', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->format('Y-m-d'),
            'payment_method' => 'cash',
            'amount' => 50000,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 100000],
            ],
        ]);

        $response->assertSessionHasErrors('amount');
    }

    public function test_void_reverses_the_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $receipt = (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 100000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 100000],
            ],
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'payment.void');

        $response = $this->actingAs($user)->patch("/payment-receipts/{$receipt->id}/void", [
            'reason' => 'Salah nominal',
        ]);

        $response->assertRedirect("/payment-receipts/{$receipt->id}");
        $receipt->refresh();
        $this->assertSame(\App\Support\PaymentReceiptStatus::VOID, $receipt->status);
        $invoice->refresh();
        $this->assertSame(\App\Support\InvoiceStatus::POSTED, $invoice->status);
    }

    public function test_void_requires_payment_void_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $receipt = (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 100000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 100000],
            ],
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch("/payment-receipts/{$receipt->id}/void", [
            'reason' => 'Salah nominal',
        ]);

        $response->assertForbidden();
    }

    public function test_lookup_outstanding_invoices_returns_only_this_customer_and_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $invoice = $this->makePostedInvoice($branch, $customer, 100000);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'payment.create');

        $response = $this->actingAs($user)->getJson("/payment-receipts/lookup/outstanding-invoices/{$customer->id}?branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonFragment(['number' => $invoice->number]);
    }
}
```

- [ ] **Step 6: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/PaymentReceiptControllerTest.php`
Expected: FAIL — routes/controllers don't exist yet (404s / `RouteNotFoundException`).

- [ ] **Step 7: Confirm the implementation from Steps 1-4 makes them pass**

Run: `php artisan test tests/Feature/PaymentReceiptControllerTest.php`
Expected: PASS (6 tests). If any fail, fix the controller/FormRequest/route from the steps above (not new code — this step is verifying Steps 1-4, not writing more).

- [ ] **Step 8: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/StorePaymentReceiptRequest.php app/Http/Requests/VoidPaymentReceiptRequest.php \
        app/Http/Controllers/PaymentReceiptController.php app/Http/Controllers/PaymentLookupController.php \
        app/Models/Customer.php routes/web.php tests/Feature/PaymentReceiptControllerTest.php
git commit -m "feat: add payment receipt create/void endpoints and outstanding-invoices lookup"
```

---

## Task 4: UI — index, create (customer-first, multi-invoice allocation), show/void, sidebar

**Files:**
- Create: `resources/views/payment-receipts/index.blade.php`
- Create: `resources/views/payment-receipts/no-access.blade.php`
- Create: `resources/views/payment-receipts/create.blade.php`
- Create: `resources/views/payment-receipts/show.blade.php`
- Modify: `resources/views/partials/sidebar.blade.php`
- Test: `tests/Feature/PaymentReceiptControllerTest.php` (extend)

**Interfaces:**
- Consumes: routes from Task 3, `/lookup/customers?branch_id=X` (existing, unmodified), `select2-ajax-picker.js` (`initAjaxSelect`, existing, unmodified).

- [ ] **Step 1: Write `no-access.blade.php` and `index.blade.php`**

`resources/views/payment-receipts/no-access.blade.php`:

```php
@extends('layouts.app')
@section('title', 'Penerimaan Pembayaran')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-cash-coin me-2"></i>Penerimaan Pembayaran</h1>
    </div>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0">Anda belum memiliki akses penerimaan pembayaran di cabang manapun.</p>
        </div>
    </div>
@endsection
```

`resources/views/payment-receipts/index.blade.php`:

```php
@extends('layouts.app')
@section('title', 'Penerimaan Pembayaran')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-cash-coin me-2"></i>Penerimaan Pembayaran</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor pembayaran...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => auth()->user()->branchesWithPermission('payment.create')->isNotEmpty()
            ? '<a href="' . route('payment-receipts.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Catat Pembayaran</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nomor</th>
                        <th>Cabang</th>
                        <th>Customer</th>
                        <th>Tanggal</th>
                        <th>Nominal</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($paymentReceipts as $paymentReceipt)
                        <tr>
                            <td><code>{{ $paymentReceipt->number }}</code></td>
                            <td>{{ $paymentReceipt->branch->name }}</td>
                            <td>{{ $paymentReceipt->customer->name }}</td>
                            <td>{{ $paymentReceipt->payment_date->format('d/m/Y') }}</td>
                            <td>{{ number_format($paymentReceipt->amount, 0, ',', '.') }}</td>
                            <td>
                                @if ($paymentReceipt->status === \App\Support\PaymentReceiptStatus::POSTED)
                                    <span class="status-dot status-active">Posted</span>
                                @else
                                    <span class="status-dot status-danger">Void</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('payment-receipts.show', $paymentReceipt) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-cash-coin',
                                    'title' => 'Belum ada pembayaran',
                                    'description' => 'Mulai dengan mencatat pembayaran pertama.',
                                    'ctaRoute' => 'payment-receipts.create',
                                    'ctaLabel' => '+ Catat Pembayaran Pertama',
                                    'ctaVisible' => auth()->user()->branchesWithPermission('payment.create')->isNotEmpty(),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $paymentReceipts->links() }}
    </div>
@endsection
```

- [ ] **Step 2: Write `create.blade.php`**

`resources/views/payment-receipts/create.blade.php`:

```php
@extends('layouts.app')
@section('title', 'Catat Pembayaran')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-cash-coin me-2"></i>Catat Pembayaran</h1>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('payment-receipts.store') }}" id="paymentReceiptForm">
        @csrf
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Cabang</label>
                        <select name="branch_id" id="branchSelect" class="form-select" required>
                            <option value="">-- Pilih Cabang --</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" id="customerSelect" class="form-select" required disabled>
                            <option value="">-- Pilih Cabang Dulu --</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tanggal Bayar</label>
                        <input type="date" name="payment_date" value="{{ now()->format('Y-m-d') }}" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Metode</label>
                        <select name="payment_method" class="form-select" required>
                            @foreach ($paymentMethods as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">No. Referensi</label>
                        <input type="text" name="reference_number" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Total Nominal Dibayar</label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="amountInput" class="form-control" readonly required>
                        <div class="form-text">Terisi otomatis dari total alokasi di bawah.</div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6">Alokasi ke Invoice</h2>
                <p class="text-muted small" id="invoicesHint">Pilih customer terlebih dahulu untuk melihat invoice yang punya sisa piutang.</p>
                <div class="table-responsive">
                    <table class="table table-sm" id="outstandingInvoicesTable" style="display:none">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Nomor</th>
                                <th>Tanggal</th>
                                <th>Grand Total</th>
                                <th>Sudah Dibayar</th>
                                <th>Sisa Piutang</th>
                                <th style="width: 160px">Nominal Dibayar</th>
                            </tr>
                        </thead>
                        <tbody id="outstandingInvoicesBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        <a href="{{ route('payment-receipts.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>

    @push('scripts')
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const customerSelect = document.getElementById('customerSelect');
        const invoicesHint = document.getElementById('invoicesHint');
        const invoicesTable = document.getElementById('outstandingInvoicesTable');
        const invoicesBody = document.getElementById('outstandingInvoicesBody');
        const amountInput = document.getElementById('amountInput');

        function resetCustomer() {
            customerSelect.innerHTML = '<option value="">-- Pilih Cabang Dulu --</option>';
            customerSelect.disabled = true;
            resetInvoices();
        }

        function resetInvoices() {
            invoicesTable.style.display = 'none';
            invoicesBody.innerHTML = '';
            invoicesHint.style.display = 'block';
            recomputeAmount();
        }

        function recomputeAmount() {
            let total = 0;
            invoicesBody.querySelectorAll('.invoice-allocation-input').forEach(function (input) {
                if (input.closest('tr').querySelector('.invoice-check').checked) {
                    total += parseFloat(input.value || '0');
                }
            });
            amountInput.value = total.toFixed(2);
        }

        branchSelect.addEventListener('change', function () {
            resetCustomer();
            if (!branchSelect.value) return;

            customerSelect.disabled = false;
            customerSelect.innerHTML = '<option value="">-- Pilih Customer --</option>';

            // The shared `/lookup/customers` endpoint requires a 3-character search term
            // (Select2 AJAX-as-you-type convention), which doesn't fit this page's "just list
            // every customer of this branch" need — use the dedicated endpoint from Step 3 instead.
            fetch(`/payment-receipts/lookup/customers-by-branch/${branchSelect.value}`)
                .then(r => r.json())
                .then(function (customers) {
                    customers.forEach(function (customer) {
                        const opt = document.createElement('option');
                        opt.value = customer.id;
                        opt.textContent = customer.text;
                        customerSelect.appendChild(opt);
                    });
                });
        });

        customerSelect.addEventListener('change', function () {
            resetInvoices();
            if (!customerSelect.value || !branchSelect.value) return;

            fetch(`/payment-receipts/lookup/outstanding-invoices/${customerSelect.value}?branch_id=${branchSelect.value}`)
                .then(r => r.json())
                .then(function (invoices) {
                    invoicesHint.style.display = invoices.length ? 'none' : 'block';
                    invoicesTable.style.display = invoices.length ? '' : 'none';
                    invoices.forEach(function (invoice, index) {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td><input type="checkbox" class="invoice-check"></td>
                            <td><input type="hidden" class="invoice-id" name="allocations[${index}][invoice_id]" value="${invoice.id}">${invoice.number}</td>
                            <td>${invoice.invoice_date}</td>
                            <td>${invoice.grand_total.toLocaleString('id-ID')}</td>
                            <td>${invoice.paid_amount.toLocaleString('id-ID')}</td>
                            <td>${invoice.outstanding_amount.toLocaleString('id-ID')}</td>
                            <td><input type="number" step="0.01" min="0" max="${invoice.outstanding_amount}" class="form-control form-control-sm invoice-allocation-input" name="allocations[${index}][allocated_amount]" value="0" disabled></td>
                        `;
                        invoicesBody.appendChild(tr);
                    });

                    invoicesBody.querySelectorAll('.invoice-check').forEach(function (checkbox) {
                        checkbox.addEventListener('change', function () {
                            const input = checkbox.closest('tr').querySelector('.invoice-allocation-input');
                            input.disabled = !checkbox.checked;
                            if (checkbox.checked && parseFloat(input.value) === 0) {
                                input.value = input.max;
                            }
                            if (!checkbox.checked) {
                                input.value = 0;
                            }
                            recomputeAmount();
                        });
                    });
                    invoicesBody.querySelectorAll('.invoice-allocation-input').forEach(function (input) {
                        input.addEventListener('input', recomputeAmount);
                    });
                });
        });
    })();
    </script>
    @endpush
@endsection
```

- [ ] **Step 3: Add the small `customers-by-branch` lookup this create page needs**

The create page's branch→customer cascade needs a plain "every customer of this branch" list (not the search-as-you-type `/lookup/customers` endpoint, which requires a 3-character query). Add one method to `PaymentLookupController`:

```php
    public function customersByBranch(int $branchId)
    {
        abort_unless(auth()->user()->hasPermissionToInBranch('payment.create', $branchId), 403);

        return response()->json(
            Customer::whereHas('customerBranches', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)->where('is_active', true);
            })
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn ($customer) => ['id' => $customer->id, 'text' => $customer->name])
                ->values()
        );
    }
```

Add its route inside the `payment-receipts` group (before the outstanding-invoices lookup line):

```php
        Route::get('/lookup/customers-by-branch/{branchId}', [PaymentLookupController::class, 'customersByBranch'])->name('lookup.customers-by-branch');
```

- [ ] **Step 4: Write `show.blade.php`**

`resources/views/payment-receipts/show.blade.php`:

```php
@extends('layouts.app')
@section('title', 'Detail Pembayaran')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-cash-coin me-2"></i>{{ $paymentReceipt->number }}</h1>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><strong>Cabang</strong><div>{{ $paymentReceipt->branch->name }}</div></div>
                <div class="col-md-3"><strong>Customer</strong><div>{{ $paymentReceipt->customer->name }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $paymentReceipt->payment_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>
                        @if ($paymentReceipt->status === \App\Support\PaymentReceiptStatus::POSTED)
                            <span class="status-dot status-active">Posted</span>
                        @else
                            <span class="status-dot status-danger">Void</span>
                        @endif
                    </div>
                </div>
                <div class="col-md-3"><strong>Metode</strong><div>{{ \App\Support\PaymentMethod::LABELS[$paymentReceipt->payment_method] ?? $paymentReceipt->payment_method }}</div></div>
                <div class="col-md-3"><strong>No. Referensi</strong><div>{{ $paymentReceipt->reference_number ?? '-' }}</div></div>
                <div class="col-md-3"><strong>Total Nominal</strong><div>{{ number_format($paymentReceipt->amount, 0, ',', '.') }}</div></div>
                <div class="col-md-12"><strong>Catatan</strong><div>{{ $paymentReceipt->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Alokasi Invoice</h2>
            <table class="table table-sm">
                <thead><tr><th>No. Invoice</th><th>Nominal Dialokasikan</th></tr></thead>
                <tbody>
                    @foreach ($paymentReceipt->allocations as $allocation)
                        <tr>
                            <td><a href="{{ route('invoices.show', $allocation->invoice) }}">{{ $allocation->invoice->number }}</a></td>
                            <td>{{ number_format($allocation->allocated_amount, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($paymentReceipt->voided_at)
        <div class="card mb-3">
            <div class="card-body">
                <p class="mb-0">
                    <strong>Pembayaran di-void</strong> oleh {{ optional($paymentReceipt->voidedBy)->name ?? '-' }}
                    pada {{ $paymentReceipt->voided_at->format('d/m/Y H:i') }}: {{ $paymentReceipt->void_reason }}
                </p>
            </div>
        </div>
    @elseif ($paymentReceipt->status === \App\Support\PaymentReceiptStatus::POSTED)
        @can('void', $paymentReceipt)
            <div class="card mb-3">
                <div class="card-body">
                    <form method="POST" action="{{ route('payment-receipts.void', $paymentReceipt) }}">
                        @csrf
                        @method('PATCH')
                        <label for="reason" class="form-label"><strong>Void Pembayaran</strong></label>
                        <textarea name="reason" id="reason" class="form-control @error('reason') is-invalid @enderror" rows="2" required></textarea>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-outline-danger btn-sm mt-2">Kirim Void</button>
                    </form>
                </div>
            </div>
        @endcan
    @endif

    <a href="{{ route('payment-receipts.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
```

- [ ] **Step 5: Wire the sidebar placeholder to a real link**

In `resources/views/partials/sidebar.blade.php`, replace the disabled Payment placeholder block (lines 20-26):

```php
        @if ($user->branchesWithPermission('payment.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-cash-coin me-2"></i> Penerimaan Pembayaran
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
```

with:

```php
        @if ($user->branchesWithPermission('payment.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('payment-receipts.index') }}" class="nav-link {{ request()->routeIs('payment-receipts.*') ? 'active' : '' }}">
                <i class="bi bi-cash-coin me-2"></i> Penerimaan Pembayaran
            </a>
        </li>
        @endif
```

- [ ] **Step 6: Add a sidebar test and run it**

Check `tests/Feature/AppShellTest.php` for the existing pattern used for prior placeholder-to-real-link conversions (e.g. Invoice's own sidebar test) and add an analogous pair of tests: one asserting the real link renders (`assertSee(route('payment-receipts.index'), false)`) for a user with `payment.view` in some branch, one asserting the disabled placeholder no longer appears. Follow the exact existing test names/style in that file (e.g. mirror whatever `test_sidebar_shows_invoice_link_when_user_has_invoice_view_permission_in_a_branch`-shaped test already exists for Invoice).

Run: `php artisan test tests/Feature/AppShellTest.php`
Expected: PASS.

- [ ] **Step 7: Manual browser verification**

Start the dev server (`preview_start` with the existing `.claude/launch.json` config), log in as a demo user with `payment.create`/`payment.view` in a branch (seed one via tinker if needed, matching the pattern used for Task 2/Task 3 manual verification in the Invoice editable-lines session), create at least one posted invoice with an outstanding balance, then:
- Visit `/payment-receipts/create`, pick the branch, confirm the customer dropdown populates, confirm outstanding invoices load with correct `outstanding_amount`, check one, confirm the amount auto-fills, submit.
- Confirm redirect to the new receipt's show page, confirm the invoice's status flips to `paid`/`partially_paid` (check via tinker or the Invoice show page).
- Void the receipt, confirm the invoice's `paid_amount`/status revert.
- Clean up any demo data created for this via tinker afterward.

- [ ] **Step 8: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 9: Commit**

```bash
git add resources/views/payment-receipts app/Http/Controllers/PaymentLookupController.php \
        routes/web.php resources/views/partials/sidebar.blade.php tests/Feature/AppShellTest.php
git commit -m "feat: add payment receipt create/index/show UI and wire sidebar link"
```

---

## Task 5: Invoice-side integration — payment history card, status badges

**Files:**
- Modify: `resources/views/invoices/show.blade.php`
- Modify: `resources/views/invoices/index.blade.php`
- Test: `tests/Feature/InvoiceControllerTest.php` (extend)

**Interfaces:**
- Consumes: `Invoice::allocations()` (Task 1), `InvoiceStatus::{PARTIALLY_PAID,PAID}` (Task 1).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/InvoiceControllerTest.php`:

```php
    public function test_show_displays_payment_history_and_outstanding_balance(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        (new InvoiceService())->postInvoice($invoice);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'payment.create');

        (new \App\Services\PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id,
            'customer_id' => $invoice->customer_id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => null,
            'amount' => 50000,
            'notes' => null,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'allocated_amount' => 50000],
            ],
        ]);

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee('Riwayat Pembayaran');
        $invoice->refresh();
        $response->assertSee(number_format($invoice->outstanding_amount, 0, ',', '.'));
    }
```

(Check what `makeInvoice()`'s fixture actually adds up to — from the shared helper it's 1 service line `qty 1 @ 50000` + 1 sparepart line `qty 2 @ 60000` = grand total 170000, so a 50000 allocation leaves it `partially_paid` with `outstanding_amount = 120000`; adjust the asserted `number_format` value if the fixture differs.)

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php artisan test tests/Feature/InvoiceControllerTest.php`
Expected: FAIL — "Riwayat Pembayaran" not found on the page.

- [ ] **Step 3: Add the payment history card and extend the status badges**

In `resources/views/invoices/show.blade.php`, replace the existing status block:

```php
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>
                        @if ($invoice->status === \App\Support\InvoiceStatus::DRAFT)
                            <span class="status-dot status-inactive">Draft</span>
                        @elseif ($invoice->status === \App\Support\InvoiceStatus::POSTED)
                            <span class="status-dot status-active">Diposting</span>
                        @elseif ($invoice->status === \App\Support\InvoiceStatus::PARTIALLY_PAID)
                            <span class="status-dot status-warning">Dibayar Sebagian</span>
                        @elseif ($invoice->status === \App\Support\InvoiceStatus::PAID)
                            <span class="status-dot status-active">Lunas</span>
                        @else
                            <span class="status-dot status-danger">Dibatalkan</span>
                        @endif
                    </div>
                </div>
```

(Note this also fixes the pre-existing `CANCELLED` branch from `status-inactive` to `status-danger`, per the Global Constraints note.)

Add a new "Sisa Piutang" field next to the existing Ringkasan card's Grand Total (inside the same `<div class="row g-2">` in the Ringkasan card):

```php
                <div class="col-md-3"><strong>Sudah Dibayar</strong><div>{{ number_format($invoice->paid_amount, 0, ',', '.') }}</div></div>
                <div class="col-md-3"><strong>Sisa Piutang</strong><div>{{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</div></div>
```

Add a new "Riwayat Pembayaran" card right after the existing Ringkasan card (before the `@if ($invoice->cancelled_at)` block):

```php
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Riwayat Pembayaran</h2>
            <table class="table table-sm">
                <thead><tr><th>No. Pembayaran</th><th>Tanggal</th><th>Nominal Dialokasikan</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($invoice->allocations()->with('paymentReceipt')->get() as $allocation)
                        <tr>
                            <td><a href="{{ route('payment-receipts.show', $allocation->paymentReceipt) }}">{{ $allocation->paymentReceipt->number }}</a></td>
                            <td>{{ $allocation->paymentReceipt->payment_date->format('d/m/Y') }}</td>
                            <td>{{ number_format($allocation->allocated_amount, 0, ',', '.') }}</td>
                            <td>
                                @if ($allocation->paymentReceipt->status === \App\Support\PaymentReceiptStatus::VOID)
                                    <span class="status-dot status-danger">Void</span>
                                @else
                                    <span class="status-dot status-active">Posted</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">Belum ada pembayaran.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
```

- [ ] **Step 4: Update `InvoiceController::show()` to eager-load allocations**

In `app/Http/Controllers/InvoiceController.php`, change `show()`'s eager-load list:

```php
        $invoice->load(['branch', 'customer', 'workOrder', 'details', 'allocations.paymentReceipt']);
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `php artisan test tests/Feature/InvoiceControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Update the index page badge for consistency**

In `resources/views/invoices/index.blade.php`, apply the same badge block extension (DRAFT=inactive/POSTED=active/PARTIALLY_PAID=warning/PAID=active/else=danger) as Step 3, replacing lines 39-45.

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions (this is the plan's final full-suite run).

- [ ] **Step 8: Commit**

```bash
git add resources/views/invoices/show.blade.php resources/views/invoices/index.blade.php \
        app/Http/Controllers/InvoiceController.php tests/Feature/InvoiceControllerTest.php
git commit -m "feat: show payment history and outstanding balance on Invoice, extend status badges"
```

---

## After all tasks

Report final test count and a short end-to-end summary (create receipt → allocate to 2 invoices → statuses update → void → statuses revert), matching the sign-off format used for the Sales Invoice and Editable Lines/Cancellation plans. Do not start Migration 011 (Audit Log) or the Laporan track without explicit user instruction.
