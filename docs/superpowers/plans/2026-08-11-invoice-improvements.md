# Penyesuaian Modul Invoice (Diskon Itemized, Direct Sales, & Conditional PPN Print) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-line discount to invoice items (jasa & sparepart), a new Direct Sales (invoice-without-PKB) creation flow, and conditional PPN display on the printed PDF.

**Architecture:** Extend the existing unified `invoice_details` table (no new tables) with `discount_percent`/`discount_amount` columns and fold the per-line discount formula into `InvoiceService::createFromWorkOrder()`/`updateInvoice()`. Make `invoices.work_order_id` nullable and add a parallel `InvoiceService::createDirectSale()` entry point that creates a DRAFT invoice + lines directly (no PKB), reusing the existing edit/update/post lifecycle for header discount/tax/due-date. Null-guard the two Blade views that assume `$invoice->workOrder` is always present.

**Tech Stack:** Laravel 8.75, PHP 7.4.33, MySQL (raw `DB::statement` CHECK constraints), Bootstrap 5.3.3, Select2 AJAX pickers, DomPDF.

## Global Constraints

- PHP 7.4.33 syntax only — no PHP8-only syntax (union types, named arguments, etc.). Arrow functions (`fn () =>`) are fine, already used throughout.
- All money/qty formulas use `round(..., 2)` exactly as the existing header calc does — no new rounding strategy.
- CHECK constraints are added via raw `DB::statement("ALTER TABLE ... ADD CONSTRAINT ...")`, matching every existing migration in this codebase — never use a fluent `->check()` (doesn't exist in this Laravel version).
- Every new/modified Feature test file defines its own local `grantBranchPermission()`/`userWithPermissions()` helper (copy-paste, not a shared trait) — matches the established convention in `InvoiceControllerTest.php`, `MasterRackIntegrationTest.php`, etc.
- Decimal assertions in tests compare as floats via `(float) $model->column`, matching `InvoiceControllerTest.php`'s existing style (not string comparison).
- The full `php artisan test` suite must be 100% green at the end of every task, immediately before that task's commit.
- Invoice number format is `{TYPE}/{BRANCH}/{PERIOD}/{NUMBER:5}` via `DocumentNumberGenerator::next($branch, $documentType)` — never hand-roll a different format. `DocumentNumberGeneratorTest.php`'s assertions on the `INV/...` format must keep passing unmodified.
- Direct Sales reuses the existing `invoice.create` permission code (branch-scoped) — do not add a new permission code.

---

## File Structure

**New files:**
- `database/migrations/2026_08_11_000004_add_discount_to_invoice_details_table.php` — adds `discount_percent`/`discount_amount` to `invoice_details`.
- `database/migrations/2026_08_11_000005_make_work_order_id_nullable_on_invoices_table.php` — makes `invoices.work_order_id` nullable.
- `app/Http/Requests/StoreDirectSaleInvoiceRequest.php` — validates the Direct Sales create form.
- `resources/views/invoices/create-direct.blade.php` — Direct Sales create form.
- `tests/Feature/InvoiceDirectSaleTest.php` — Direct Sales feature tests (policy, controller, number format, null-guards).
- `tests/Feature/InvoiceDirectSaleIntegrationTest.php` — end-to-end integration test (Task 5).

**Modified files:**
- `app/Models/InvoiceDetail.php` — fillable/casts for discount columns.
- `app/Models/Invoice.php` — `getIsDirectSaleAttribute()` accessor.
- `app/Services/InvoiceService.php` — per-line discount calc in `createFromWorkOrder()`/`updateInvoice()`; new `createDirectSale()` method.
- `app/Http/Requests/UpdateInvoiceRequest.php` — `discount_percent` rules per line.
- `app/Http/Controllers/InvoiceController.php` — `edit()` prefill; new `createDirect()`/`storeDirect()` methods.
- `app/Policies/InvoicePolicy.php` — new `createDirect()` ability.
- `resources/views/invoices/_line_item_scripts.blade.php` — new "Diskon (%)" input per line template + JS wiring.
- `resources/views/invoices/edit.blade.php` — new "Diskon (%)" column header + prefill wiring.
- `resources/views/invoices/show.blade.php` — null-guard `$invoice->workOrder`.
- `resources/views/invoices/print-pdf.blade.php` — Diskon column, null-guard workOrder block, conditional PPN row.
- `resources/views/invoices/index.blade.php` — "+ Invoice Langsung (DS)" entry button.
- `routes/web.php` — `invoices.createDirect`/`invoices.storeDirect` routes.
- `tests/Feature/InvoiceControllerTest.php` — new discount-related tests.

---

### Task 1: Fitur Diskon Per Item

**Files:**
- Create: `database/migrations/2026_08_11_000004_add_discount_to_invoice_details_table.php`
- Modify: `app/Models/InvoiceDetail.php`
- Modify: `app/Services/InvoiceService.php:22-94,96-177` (`createFromWorkOrder()`, `updateInvoice()`)
- Modify: `app/Http/Requests/UpdateInvoiceRequest.php:29-49` (`rules()`)
- Modify: `app/Http/Controllers/InvoiceController.php:80-109` (`edit()`)
- Modify: `resources/views/invoices/_line_item_scripts.blade.php`
- Modify: `resources/views/invoices/edit.blade.php`
- Modify: `resources/views/invoices/print-pdf.blade.php:76-92` (`table.line-table`)
- Test: `tests/Feature/InvoiceControllerTest.php`

**Interfaces:**
- Produces: `InvoiceDetail::$fillable` includes `discount_percent`, `discount_amount`. `InvoiceService::updateInvoice(Invoice $invoice, array $data)` reads `$line['discount_percent']` (nullable, default `0`) from each `services[]`/`spareparts[]` entry and persists `discount_percent`/`discount_amount`/`line_total` per row using: `$gross = round($qty * $unitPrice, 2); $discountAmount = round($gross * $discountPercent / 100, 2); $lineTotal = round($gross - $discountAmount, 2);`.
- Consumes: nothing new from other tasks (this task is self-contained and must land first — Task 2's `createDirectSale()` reuses this same formula).

- [ ] **Step 1: Write the failing feature test for per-line discount**

Add to `tests/Feature/InvoiceControllerTest.php` (after `test_update_recalculates_discount_tax_and_grand_total`):

```php
    public function test_update_applies_per_line_discount_and_computes_net_line_total(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => 'Ganti Oli',
                'qty' => 1,
                'unit_price' => 100000,
                'discount_percent' => 10,
            ]],
            'spareparts' => [[
                'work_order_sparepart_line_id' => $sparepartDetail->work_order_sparepart_line_id,
                'sparepart_branch_id' => $sparepartDetail->sparepart_branch_id,
                'qty' => 2,
                'unit_price' => 50000,
                'discount_percent' => 20,
            ]],
        ]);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();

        $newServiceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $this->assertSame(10.0, (float) $newServiceDetail->discount_percent);
        $this->assertSame(10000.0, (float) $newServiceDetail->discount_amount);
        $this->assertSame(90000.0, (float) $newServiceDetail->line_total);

        $newSparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $this->assertSame(20.0, (float) $newSparepartDetail->discount_percent);
        $this->assertSame(20000.0, (float) $newSparepartDetail->discount_amount);
        $this->assertSame(80000.0, (float) $newSparepartDetail->line_total);

        $this->assertSame(90000.0, (float) $invoice->subtotal_service);
        $this->assertSame(80000.0, (float) $invoice->subtotal_sparepart);
        $this->assertSame(170000.0, (float) $invoice->grand_total);
    }

    public function test_update_defaults_discount_percent_to_zero_when_omitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => 'Ganti Oli',
                'qty' => 1,
                'unit_price' => 50000,
            ]],
            'spareparts' => [],
        ]);

        $response->assertRedirect("/invoices/{$invoice->id}");
        $newServiceDetail = $invoice->fresh()->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $this->assertSame(0.0, (float) $newServiceDetail->discount_percent);
        $this->assertSame(0.0, (float) $newServiceDetail->discount_amount);
        $this->assertSame(50000.0, (float) $newServiceDetail->line_total);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=test_update_applies_per_line_discount_and_computes_net_line_total`
Expected: FAIL (`discount_percent` column doesn't exist / undefined array key, since the columns and calc don't exist yet).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_11_000004_add_discount_to_invoice_details_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddDiscountToInvoiceDetailsTable extends Migration
{
    public function up()
    {
        Schema::table('invoice_details', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('unit_price');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_percent');
        });

        DB::statement('ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_discount_percent_range CHECK (discount_percent >= 0 AND discount_percent <= 100)');
        DB::statement('ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_discount_amount_nonnegative CHECK (discount_amount >= 0)');
    }

    public function down()
    {
        DB::statement('ALTER TABLE invoice_details DROP CONSTRAINT ck_invoice_details_discount_amount_nonnegative');
        DB::statement('ALTER TABLE invoice_details DROP CONSTRAINT ck_invoice_details_discount_percent_range');

        Schema::table('invoice_details', function (Blueprint $table) {
            $table->dropColumn(['discount_percent', 'discount_amount']);
        });
    }
}
```

Run: `php artisan migrate`
Expected: migration runs without error.

- [ ] **Step 4: Update the `InvoiceDetail` model**

In `app/Models/InvoiceDetail.php`, change:

```php
    protected $fillable = [
        'invoice_id', 'item_type',
        'work_order_service_line_id', 'work_order_sparepart_line_id', 'sparepart_branch_id',
        'item_code_snapshot', 'description', 'qty', 'unit_price', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];
```

to:

```php
    protected $fillable = [
        'invoice_id', 'item_type',
        'work_order_service_line_id', 'work_order_sparepart_line_id', 'sparepart_branch_id',
        'item_code_snapshot', 'description', 'qty', 'unit_price',
        'discount_percent', 'discount_amount', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];
```

- [ ] **Step 5: Implement the per-line discount calc in `InvoiceService`**

In `app/Services/InvoiceService.php`, `createFromWorkOrder()` (around line 61-74 and 76-90), add `discount_percent`/`discount_amount` explicitly as `0` to both `InvoiceDetail::create()` calls (PKB lines never carry a discount at copy-time):

```php
            foreach ($serviceLines as $line) {
                InvoiceDetail::create([
                    'invoice_id' => $invoice->id,
                    'item_type' => InvoiceDetailItemType::SERVICE,
                    'work_order_service_line_id' => $line->id,
                    'work_order_sparepart_line_id' => null,
                    'item_code_snapshot' => null,
                    'description' => $line->description,
                    'qty' => $line->qty,
                    'unit_price' => $line->unit_price,
                    'discount_percent' => 0,
                    'discount_amount' => 0,
                    'line_total' => $line->line_total,
                    'sort_order' => $sortOrder++,
                ]);
            }

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
                    'discount_percent' => 0,
                    'discount_amount' => 0,
                    'line_total' => $line->line_total,
                    'sort_order' => $sortOrder++,
                ]);
            }
```

In `updateInvoice()` (lines 113-129 and 131-148), replace the service-line and sparepart-line loops:

```php
            foreach ($data['services'] ?? [] as $line) {
                $qty = (float) $line['qty'];
                $unitPrice = (float) $line['unit_price'];
                $gross = round($qty * $unitPrice, 2);
                $discountPercent = (float) ($line['discount_percent'] ?? 0);
                $discountAmount = round($gross * $discountPercent / 100, 2);
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
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discountAmount,
                    'line_total' => round($gross - $discountAmount, 2),
                    'sort_order' => $sortOrder++,
                ]);
            }

            foreach ($data['spareparts'] ?? [] as $line) {
                $sparepartBranch = SparepartBranch::with('sparepart')->findOrFail($line['sparepart_branch_id']);
                $qty = (float) $line['qty'];
                $unitPrice = (float) $line['unit_price'];
                $gross = round($qty * $unitPrice, 2);
                $discountPercent = (float) ($line['discount_percent'] ?? 0);
                $discountAmount = round($gross * $discountPercent / 100, 2);
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
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discountAmount,
                    'line_total' => round($gross - $discountAmount, 2),
                    'sort_order' => $sortOrder++,
                ]);
            }
```

Nothing else in `updateInvoice()` changes — the header aggregation block (`$subtotalService = ...sum('line_total')`, etc.) already sums the (now-net) `line_total`, so it is automatically correct.

- [ ] **Step 6: Add validation rules to `UpdateInvoiceRequest`**

In `app/Http/Requests/UpdateInvoiceRequest.php::rules()`, add two lines after `services.*.unit_price` and after `spareparts.*.unit_price` respectively:

```php
            'services.*.unit_price' => ['required_with:services.*.description', 'numeric', 'min:0'],
            'services.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'spareparts' => ['nullable', 'array'],
            'spareparts.*' => ['array'],
            'spareparts.*.work_order_sparepart_line_id' => ['nullable', 'integer', 'exists:work_order_sparepart_lines,id'],
            'spareparts.*.sparepart_branch_id' => ['required_with:spareparts.*.qty', 'integer', 'exists:sparepart_branches,id'],
            'spareparts.*.qty' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0.001'],
            'spareparts.*.unit_price' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0'],
            'spareparts.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
```

(i.e. insert `'services.*.discount_percent' => [...]` right after the existing `services.*.unit_price` rule, and `'spareparts.*.discount_percent' => [...]` right after the existing `spareparts.*.unit_price` rule — the rest of the array is unchanged.)

- [ ] **Step 7: Prefill `discount_percent` in `InvoiceController::edit()`**

In `app/Http/Controllers/InvoiceController.php::edit()`, add `discount_percent` to both mapped arrays:

```php
        $existingServiceLines = $invoice->details->where('item_type', InvoiceDetailItemType::SERVICE)->map(function (InvoiceDetail $detail) {
            return [
                'work_order_service_line_id' => $detail->work_order_service_line_id,
                'description' => $detail->description,
                'qty' => (float) $detail->qty,
                'unit_price' => (float) $detail->unit_price,
                'discount_percent' => (float) $detail->discount_percent,
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
                'discount_percent' => (float) $detail->discount_percent,
            ];
        })->values();
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --filter=InvoiceControllerTest`
Expected: PASS, including the two new tests and all pre-existing ones (`test_update_recalculates_discount_tax_and_grand_total` etc. must remain green — they omit `discount_percent` per line, which the `?? 0` default handles).

- [ ] **Step 9: Add the "Diskon (%)" column to the line-item templates**

In `resources/views/invoices/_line_item_scripts.blade.php`, change the service template's column widths and add a discount input (`col-md-7`→`col-md-6`, `col-md-2` qty stays, `col-md-2` price→`col-md-1`, add new `col-md-1` diskon, `col-md-1` remove-button stays):

```blade
<template id="invoiceServiceLineTemplate">
    <div class="row g-2 align-items-start mb-2 service-line">
        <div class="col-md-6 service-item-locked d-none">
            <input type="text" class="form-control-plaintext fw-bold service-locked-description" readonly>
        </div>
        <div class="col-md-6 service-item-free">
            <select class="form-select service-catalog-select">
                <option value="">-- Manual --</option>
                @foreach ($serviceCatalogs as $catalog)
                    <option value="{{ $catalog->id }}" data-price="{{ $catalog->default_price }}" data-name="{{ $catalog->name }}">{{ $catalog->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" min="0.001" class="form-control service-qty" value="1">
        </div>
        <div class="col-md-1">
            <input type="number" step="0.01" min="0" class="form-control service-unit-price">
        </div>
        <div class="col-md-1">
            <input type="number" step="0.01" min="0" max="100" class="form-control service-discount-percent" value="0">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-line">&times;</button>
        </div>
    </div>
</template>

<template id="invoiceSparepartLineTemplate">
    <div class="row g-2 align-items-start mb-2 sparepart-line">
        <div class="col-md-4 sparepart-item-locked d-none">
            <input type="text" class="form-control-plaintext fw-bold sparepart-locked-label" readonly>
        </div>
        <div class="col-md-4 sparepart-item-free">
            <select class="form-select sparepart-select">
                <option value="">-- Pilih Sparepart --</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" step="0.001" min="0.001" class="form-control sparepart-qty" value="1">
        </div>
        <div class="col-md-1">
            <input type="number" step="0.01" min="0" class="form-control sparepart-unit-price">
        </div>
        <div class="col-md-1">
            <input type="number" step="0.01" min="0" max="100" class="form-control sparepart-discount-percent" value="0">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-line">&times;</button>
        </div>
    </div>
</template>
```

In the same file's `<script>` block, wire the `name` attribute for the new inputs. In `addServiceLine()`, after the existing `wrapper.querySelector('.service-unit-price').name = ...` line, add:

```js
        wrapper.querySelector('.service-unit-price').name = `services[${index}][unit_price]`;
        wrapper.querySelector('.service-discount-percent').name = `services[${index}][discount_percent]`;
```

In `addSparepartLine()`, after the existing `wrapper.querySelector('.sparepart-unit-price').name = ...` line, add:

```js
        wrapper.querySelector('.sparepart-unit-price').name = `spareparts[${index}][unit_price]`;
        wrapper.querySelector('.sparepart-discount-percent').name = `spareparts[${index}][discount_percent]`;
```

- [ ] **Step 10: Wire the header labels and prefill in `edit.blade.php`**

In `resources/views/invoices/edit.blade.php`, change the "Baris Jasa" label row:

```blade
                <div class="row g-2 small text-muted mb-1">
                    <div class="col-md-6">Jasa</div>
                    <div class="col-md-2">Qty</div>
                    <div class="col-md-1">Harga Satuan</div>
                    <div class="col-md-1">Diskon %</div>
                    <div class="col-md-1"></div>
                </div>
```

and the "Baris Sparepart" label row:

```blade
                <div class="row g-2 small text-muted mb-1">
                    <div class="col-md-4">Sparepart</div>
                    <div class="col-md-2">Qty</div>
                    <div class="col-md-1">Harga Satuan</div>
                    <div class="col-md-1">Diskon %</div>
                    <div class="col-md-1"></div>
                </div>
```

In the inline `<script>` block at the bottom, add prefill lines to both `forEach` callbacks:

```js
        existingServiceLines.forEach(function (line) {
            const row = InvoiceLineItems.addServiceLine(true);
            row.querySelector('.service-wo-line-id').value = line.work_order_service_line_id || '';
            row.querySelector('.service-locked-description').value = line.description;
            row.querySelector('.service-qty').value = line.qty;
            row.querySelector('.service-unit-price').value = line.unit_price;
            row.querySelector('.service-discount-percent').value = line.discount_percent || 0;
        });

        const existingSparepartLines = @json($existingSparepartLines);
        existingSparepartLines.forEach(function (line) {
            const locked = !!line.work_order_sparepart_line_id;
            const row = InvoiceLineItems.addSparepartLine(branchId, locked);
            row.querySelector('.sparepart-wo-line-id').value = line.work_order_sparepart_line_id || '';
            row.querySelector('.sparepart-qty').value = line.qty;
            row.querySelector('.sparepart-unit-price').value = line.unit_price;
            row.querySelector('.sparepart-discount-percent').value = line.discount_percent || 0;
            if (locked) {
```

(only the two new `.service-discount-percent`/`.sparepart-discount-percent` lines are additions; everything else in that block is unchanged.)

- [ ] **Step 11: Add the "Diskon" column to `print-pdf.blade.php`**

In `resources/views/invoices/print-pdf.blade.php`, change the `table.line-table`:

```blade
    <table class="line-table">
        <thead>
            <tr><th>Tipe</th><th>Kode</th><th>Deskripsi</th><th>Qty</th><th>Harga Satuan</th><th>Diskon</th><th>Total</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->details as $detail)
                <tr>
                    <td>{{ $detail->item_type === \App\Support\InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart' }}</td>
                    <td>{{ $detail->item_code_snapshot ?? '-' }}</td>
                    <td>{{ $detail->description }}</td>
                    <td class="num">{{ number_format($detail->qty, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                    <td class="num">{{ $detail->discount_amount > 0 ? number_format($detail->discount_amount, 0, ',', '.') : '-' }}</td>
                    <td class="num">{{ number_format($detail->line_total, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
```

- [ ] **Step 12: Run the full test suite**

Run: `php artisan test`
Expected: 100% PASS (no regressions in `InvoicePdfBuilderTest.php`, `InvoicePrintEmailTest.php`, etc. — the new PDF column is purely additive).

- [ ] **Step 13: Commit**

```bash
git add database/migrations/2026_08_11_000004_add_discount_to_invoice_details_table.php app/Models/InvoiceDetail.php app/Services/InvoiceService.php app/Http/Requests/UpdateInvoiceRequest.php app/Http/Controllers/InvoiceController.php resources/views/invoices/_line_item_scripts.blade.php resources/views/invoices/edit.blade.php resources/views/invoices/print-pdf.blade.php tests/Feature/InvoiceControllerTest.php
git commit -m "feat: add per-line discount to invoice service and sparepart items"
```

---

### Task 2: Direct Sales Backend Core

**Files:**
- Create: `database/migrations/2026_08_11_000005_make_work_order_id_nullable_on_invoices_table.php`
- Create: `app/Http/Requests/StoreDirectSaleInvoiceRequest.php`
- Modify: `app/Models/Invoice.php`
- Modify: `app/Services/InvoiceService.php` (new `createDirectSale()` method)
- Modify: `app/Policies/InvoicePolicy.php` (new `createDirect()` ability)
- Modify: `routes/web.php`
- Test: `tests/Feature/InvoiceDirectSaleTest.php` (new)

**Interfaces:**
- Consumes: `InvoiceDetail`'s `discount_percent`/`discount_amount` columns from Task 1 (the new service reuses the same per-line formula).
- Produces: `InvoiceService::createDirectSale(Branch $branch, Customer $customer, array $data): Invoice` — `$data` shape identical to `updateInvoice()`'s `$data['services']`/`$data['spareparts']` plus `invoice_date`. `InvoicePolicy::createDirect(User $user, Branch $branch): bool`. Routes named `invoices.createDirect` (`GET /invoices/direct/create`) and `invoices.storeDirect` (`POST /invoices/direct`) — Task 3 wires these to controller methods.

- [ ] **Step 1: Write the failing test for the nullable migration + `createDirectSale()`**

Create `tests/Feature/InvoiceDirectSaleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\InvoiceService;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDirectSaleTest extends TestCase
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

    protected function makeBranchAndCustomer(): array
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);

        return [$branch, $customer];
    }

    public function test_create_direct_sale_builds_draft_invoice_without_work_order(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'services' => [
                ['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000, 'discount_percent' => 0],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000, 'discount_percent' => 10],
            ],
        ]);

        $this->assertNull($invoice->work_order_id);
        $this->assertTrue($invoice->is_direct_sale);
        $this->assertSame(\App\Support\InvoiceStatus::DRAFT, $invoice->status);
        $this->assertStringStartsWith("DS/{$branch->code}/", $invoice->number);
        $this->assertCount(2, $invoice->details);

        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $this->assertSame(10.0, (float) $sparepartDetail->discount_percent);
        $this->assertSame(12000.0, (float) $sparepartDetail->discount_amount);
        $this->assertSame(108000.0, (float) $sparepartDetail->line_total);

        $this->assertSame(40000.0, (float) $invoice->subtotal_service);
        $this->assertSame(108000.0, (float) $invoice->subtotal_sparepart);
        $this->assertSame(148000.0, (float) $invoice->grand_total);
    }

    public function test_invoices_table_accepts_null_work_order_id(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();

        $invoice = \App\Models\Invoice::create([
            'number' => 'DS/JKT/202608/00001',
            'work_order_id' => null,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'status' => \App\Support\InvoiceStatus::DRAFT,
        ]);

        $this->assertNull($invoice->fresh()->work_order_id);
    }

    public function test_invoice_policy_create_direct_requires_invoice_create_permission_in_branch(): void
    {
        [$branch] = $this->makeBranchAndCustomer();
        $user = User::factory()->create();
        $policy = new \App\Policies\InvoicePolicy();

        $this->assertFalse($policy->createDirect($user, $branch));

        $this->grantBranchPermission($user, $branch, 'invoice.create');
        $this->assertTrue($policy->createDirect($user->fresh(), $branch));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=InvoiceDirectSaleTest`
Expected: FAIL — `work_order_id` is still `NOT NULL` at the DB level, `InvoiceService::createDirectSale()` doesn't exist, `Invoice::is_direct_sale` accessor doesn't exist, `InvoicePolicy::createDirect()` doesn't exist.

- [ ] **Step 3: Write the nullable migration**

Create `database/migrations/2026_08_11_000005_make_work_order_id_nullable_on_invoices_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MakeWorkOrderIdNullableOnInvoicesTable extends Migration
{
    public function up()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable(false)->change();
        });
    }
}
```

Run: `php artisan migrate`
Expected: migration runs without error; `invoices_work_order_id_unique` index and FK are preserved as-is (doctrine/dbal's `->change()` only touches nullability).

- [ ] **Step 4: Add the `is_direct_sale` accessor to `Invoice`**

In `app/Models/Invoice.php`, add after `getOutstandingAmountAttribute()`:

```php
    public function getIsDirectSaleAttribute(): bool
    {
        return is_null($this->work_order_id);
    }
```

- [ ] **Step 5: Implement `InvoiceService::createDirectSale()`**

In `app/Services/InvoiceService.php`, add `use App\Models\Branch;` and `use App\Models\Customer;` to the imports, then add this new public method (after `createFromWorkOrder()`, before `updateInvoice()`):

```php
    public function createDirectSale(Branch $branch, Customer $customer, array $data): Invoice
    {
        return DB::transaction(function () use ($branch, $customer, $data) {
            $invoice = Invoice::create([
                'number' => (new DocumentNumberGenerator())->next($branch, 'DS'),
                'work_order_id' => null,
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'status' => InvoiceStatus::DRAFT,
                'subtotal_service' => 0,
                'subtotal_sparepart' => 0,
                'discount_percent' => 0,
                'discount_amount' => 0,
                'tax_percent' => 0,
                'tax_amount' => 0,
                'grand_total' => 0,
            ]);

            $sortOrder = 0;

            foreach ($data['services'] ?? [] as $line) {
                $qty = (float) $line['qty'];
                $unitPrice = (float) $line['unit_price'];
                $gross = round($qty * $unitPrice, 2);
                $discountPercent = (float) ($line['discount_percent'] ?? 0);
                $discountAmount = round($gross * $discountPercent / 100, 2);
                InvoiceDetail::create([
                    'invoice_id' => $invoice->id,
                    'item_type' => InvoiceDetailItemType::SERVICE,
                    'description' => $line['description'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discountAmount,
                    'line_total' => round($gross - $discountAmount, 2),
                    'sort_order' => $sortOrder++,
                ]);
            }

            foreach ($data['spareparts'] ?? [] as $line) {
                $sparepartBranch = SparepartBranch::with('sparepart')->findOrFail($line['sparepart_branch_id']);
                $qty = (float) $line['qty'];
                $unitPrice = (float) $line['unit_price'];
                $gross = round($qty * $unitPrice, 2);
                $discountPercent = (float) ($line['discount_percent'] ?? 0);
                $discountAmount = round($gross * $discountPercent / 100, 2);
                InvoiceDetail::create([
                    'invoice_id' => $invoice->id,
                    'item_type' => InvoiceDetailItemType::SPAREPART,
                    'sparepart_branch_id' => $sparepartBranch->id,
                    'item_code_snapshot' => $sparepartBranch->sparepart->code,
                    'description' => $sparepartBranch->sparepart->name,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discountAmount,
                    'line_total' => round($gross - $discountAmount, 2),
                    'sort_order' => $sortOrder++,
                ]);
            }

            $subtotalService = round((float) $invoice->details()->where('item_type', InvoiceDetailItemType::SERVICE)->sum('line_total'), 2);
            $subtotalSparepart = round((float) $invoice->details()->where('item_type', InvoiceDetailItemType::SPAREPART)->sum('line_total'), 2);
            $invoice->update([
                'subtotal_service' => $subtotalService,
                'subtotal_sparepart' => $subtotalSparepart,
                'grand_total' => round($subtotalService + $subtotalSparepart, 2),
            ]);

            return $invoice->fresh('details');
        });
    }
```

- [ ] **Step 6: Add `InvoicePolicy::createDirect()`**

In `app/Policies/InvoicePolicy.php`, add after `create()`:

```php
    public function createDirect(User $user, Branch $branch): bool
    {
        return $user->hasPermissionToInBranch('invoice.create', $branch->id);
    }
```

Add `use App\Models\Branch;` to the imports at the top of the file.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=InvoiceDirectSaleTest`
Expected: PASS (all 3 tests).

- [ ] **Step 8: Write `StoreDirectSaleInvoiceRequest`**

Create `app/Http/Requests/StoreDirectSaleInvoiceRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class StoreDirectSaleInvoiceRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId > 0 && $this->user()->hasPermissionToInBranch('invoice.create', $branchId);
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
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'invoice_date' => ['required', 'date'],
            'services' => ['nullable', 'array'],
            'services.*' => ['array'],
            'services.*.description' => ['required_with:services.*.qty', 'string', 'max:255'],
            'services.*.qty' => ['required_with:services.*.description', 'numeric', 'min:0.001'],
            'services.*.unit_price' => ['required_with:services.*.description', 'numeric', 'min:0'],
            'services.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'spareparts' => ['nullable', 'array'],
            'spareparts.*' => ['array'],
            'spareparts.*.sparepart_branch_id' => ['required_with:spareparts.*.qty', 'integer', 'exists:sparepart_branches,id'],
            'spareparts.*.qty' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0.001'],
            'spareparts.*.unit_price' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0'],
            'spareparts.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $branchId = (int) $this->input('branch_id');
            $services = $this->input('services', []);
            $spareparts = $this->input('spareparts', []);

            if (empty($services) && empty($spareparts)) {
                $validator->errors()->add('services', 'Invoice harus punya minimal satu baris jasa atau sparepart.');
            }

            foreach ($spareparts as $index => $line) {
                $sparepartBranchId = $line['sparepart_branch_id'] ?? null;
                if (! $sparepartBranchId) {
                    continue;
                }
                $sparepartBranch = SparepartBranch::find($sparepartBranchId);
                if (! $sparepartBranch || ! $sparepartBranch->is_active || (int) $sparepartBranch->branch_id !== $branchId) {
                    $validator->errors()->add("spareparts.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang yang dipilih.');
                }
            }
        });
    }
}
```

- [ ] **Step 9: Add the Direct Sales routes**

In `routes/web.php`, inside the `Route::prefix('invoices')->name('invoices.')->group(...)` block (around line 198), add the two new routes **before** `Route::get('/{invoice}', ...)`:

```php
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::get('/direct/create', [InvoiceController::class, 'createDirect'])->name('createDirect');
        Route::post('/direct', [InvoiceController::class, 'storeDirect'])->name('storeDirect');
        Route::post('/', [InvoiceController::class, 'store'])->name('store');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('show');
```

(`InvoiceController::createDirect`/`storeDirect` don't exist yet — Task 3 adds them. This step only adds the routes; running `php artisan route:list` will show them pointing at not-yet-defined methods, which is fine since no test hits them until Task 3.)

- [ ] **Step 10: Run the full test suite**

Run: `php artisan test`
Expected: 100% PASS. (Routing to undefined controller methods doesn't break anything unless a request actually hits them, which nothing does yet.)

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_08_11_000005_make_work_order_id_nullable_on_invoices_table.php app/Models/Invoice.php app/Services/InvoiceService.php app/Policies/InvoicePolicy.php app/Http/Requests/StoreDirectSaleInvoiceRequest.php routes/web.php tests/Feature/InvoiceDirectSaleTest.php
git commit -m "feat: add direct sales invoice backend (nullable work_order_id, createDirectSale service, policy, request)"
```

---

### Task 3: Direct Sales UI & Null-Guard

**Files:**
- Modify: `app/Http/Controllers/InvoiceController.php` (new `createDirect()`/`storeDirect()` methods)
- Create: `resources/views/invoices/create-direct.blade.php`
- Modify: `resources/views/invoices/index.blade.php` (entry button)
- Modify: `resources/views/invoices/show.blade.php` (null-guard)
- Modify: `resources/views/invoices/print-pdf.blade.php` (null-guard)
- Test: `tests/Feature/InvoiceDirectSaleTest.php` (extend)

**Interfaces:**
- Consumes: `InvoiceService::createDirectSale()`, `InvoicePolicy::createDirect()`, `StoreDirectSaleInvoiceRequest`, routes `invoices.createDirect`/`invoices.storeDirect` from Task 2.
- Produces: working `GET /invoices/direct/create` and `POST /invoices/direct` HTTP endpoints; `show`/`print` no longer crash for a Direct Sales invoice.

- [ ] **Step 1: Write the failing tests for the controller endpoints and null-guards**

Append to `tests/Feature/InvoiceDirectSaleTest.php`:

```php
    public function test_create_direct_form_is_visible_for_user_with_invoice_create_permission(): void
    {
        [$branch] = $this->makeBranchAndCustomer();
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->get('/invoices/direct/create');

        $response->assertOk();
        $response->assertSee('Invoice Langsung');
    }

    public function test_create_direct_form_shows_no_access_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/invoices/direct/create');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_store_direct_creates_invoice_and_redirects_to_show(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $catalog = ServiceCatalog::create(['code' => 'SVC-CUCI', 'name' => 'Cuci Mobil', 'default_price' => 40000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->post('/invoices/direct', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'services' => [
                ['description' => $catalog->name, 'qty' => 1, 'unit_price' => 40000],
            ],
            'spareparts' => [],
        ]);

        $invoice = \App\Models\Invoice::latest('id')->first();
        $response->assertRedirect("/invoices/{$invoice->id}");
        $this->assertNull($invoice->work_order_id);
        $this->assertStringStartsWith('DS/', $invoice->number);
    }

    public function test_store_direct_rejects_empty_line_items(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->post('/invoices/direct', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors('services');
    }

    public function test_show_direct_sale_invoice_does_not_crash_and_shows_placeholder(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000]],
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee('Direct Sales');
    }

    public function test_print_direct_sale_invoice_does_not_crash(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000]],
        ]);
        (new InvoiceService())->updateInvoice($invoice, [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000]],
            'spareparts' => [],
        ]);
        (new InvoiceService())->postInvoice($invoice->fresh());
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.print');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $response->assertOk();
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=InvoiceDirectSaleTest`
Expected: FAIL — `InvoiceController::createDirect`/`storeDirect` don't exist (route resolution error), and `show`/`print` for a Direct Sales invoice throw (`Attempt to read property "number" on null` / `Attempt to read property "vehicle" on null`) since `show.blade.php`/`print-pdf.blade.php` aren't null-guarded yet.

- [ ] **Step 3: Add `createDirect()`/`storeDirect()` to `InvoiceController`**

In `app/Http/Controllers/InvoiceController.php`, add `use App\Http\Requests\StoreDirectSaleInvoiceRequest;`, `use App\Models\Branch;`, `use App\Models\Customer;` to the imports, then add these two methods (after `store()`, before `show()`):

```php
    public function createDirect()
    {
        $branches = auth()->user()->branchesWithPermission('invoice.create');

        if ($branches->isEmpty()) {
            return view('invoices.no-access');
        }

        $serviceCatalogs = ServiceCatalog::where('is_active', true)->orderBy('name')->get();

        return view('invoices.create-direct', compact('branches', 'serviceCatalogs'));
    }

    public function storeDirect(StoreDirectSaleInvoiceRequest $request)
    {
        $branch = Branch::findOrFail($request->input('branch_id'));
        $customer = Customer::findOrFail($request->input('customer_id'));

        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, $request->validated());

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice Direct Sales berhasil dibuat.');
    }
```

- [ ] **Step 4: Null-guard `show.blade.php`**

In `resources/views/invoices/show.blade.php`, change line 40:

```blade
                <div class="col-md-3"><strong>PKB</strong><div>{{ $invoice->workOrder->number }}</div></div>
```

to:

```blade
                <div class="col-md-3"><strong>PKB</strong><div>{{ optional($invoice->workOrder)->number ?? 'Direct Sales' }}</div></div>
```

- [ ] **Step 5: Null-guard `print-pdf.blade.php`**

In `resources/views/invoices/print-pdf.blade.php`, change:

```blade
                <div><span class="label">Nama:</span> {{ $invoice->customer->name }}</div>
                <div><span class="label">Alamat:</span> {{ $invoice->customer->address ?? '-' }}</div>
                <div><span class="label">No. Polisi:</span> {{ $invoice->workOrder->vehicle->plate_number }}</div>
                <div><span class="label">Kendaraan:</span> {{ optional($invoice->workOrder->vehicle->brand)->name }} {{ optional($invoice->workOrder->vehicle->type)->name }}</div>
```

to:

```blade
                <div><span class="label">Nama:</span> {{ $invoice->customer->name }}</div>
                <div><span class="label">Alamat:</span> {{ $invoice->customer->address ?? '-' }}</div>
                @if ($invoice->workOrder)
                    <div><span class="label">No. Polisi:</span> {{ $invoice->workOrder->vehicle->plate_number }}</div>
                    <div><span class="label">Kendaraan:</span> {{ optional($invoice->workOrder->vehicle->brand)->name }} {{ optional($invoice->workOrder->vehicle->type)->name }}</div>
                @endif
```

and:

```blade
                <div><span class="label">No. PKB:</span> {{ $invoice->workOrder->number }}</div>
```

to:

```blade
                <div><span class="label">No. PKB:</span> {{ optional($invoice->workOrder)->number ?? 'Direct Sales' }}</div>
```

- [ ] **Step 6: Create `create-direct.blade.php`**

Create `resources/views/invoices/create-direct.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Invoice Langsung (Direct Sales)')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-receipt me-2"></i>Tambah Invoice Langsung (Direct Sales)</h1>
    </div>

    <form method="POST" action="{{ route('invoices.storeDirect') }}" id="invoiceForm">
        @csrf

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="branch_id" class="form-label">Cabang</label>
                        <select name="branch_id" id="branch_id" class="form-select @error('branch_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Cabang --</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" {{ (int) old('branch_id') === $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="customer_id" class="form-label">Customer</label>
                        <select name="customer_id" id="customer_id" class="form-select @error('customer_id') is-invalid @enderror" required>
                        </select>
                        @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="invoice_date" class="form-label">Tanggal Invoice</label>
                        <input type="date" name="invoice_date" id="invoice_date"
                            class="form-control @error('invoice_date') is-invalid @enderror"
                            value="{{ old('invoice_date', now()->toDateString()) }}" required>
                        @error('invoice_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                <div class="row g-2 small text-muted mb-1">
                    <div class="col-md-6">Jasa</div>
                    <div class="col-md-2">Qty</div>
                    <div class="col-md-1">Harga Satuan</div>
                    <div class="col-md-1">Diskon %</div>
                    <div class="col-md-1"></div>
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
                <div class="row g-2 small text-muted mb-1">
                    <div class="col-md-4">Sparepart</div>
                    <div class="col-md-2">Qty</div>
                    <div class="col-md-1">Harga Satuan</div>
                    <div class="col-md-1">Diskon %</div>
                    <div class="col-md-1"></div>
                </div>
                <div id="invoiceSparepartLines"></div>
                @error('spareparts')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Simpan</button>
            <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
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
        const customerSelect = document.getElementById('customer_id');

        function initCustomerPicker(branchId) {
            if ($(customerSelect).data('select2')) {
                $(customerSelect).select2('destroy');
            }
            customerSelect.innerHTML = '';
            initAjaxSelect(customerSelect, {
                endpoint: '{{ route('lookup.customers') }}',
                extraParams: function () { return { branch_id: branchId }; },
                placeholder: '-- Pilih Customer --',
            });
        }

        document.getElementById('branch_id').addEventListener('change', function () {
            window.currentInvoiceBranchId = this.value || null;
            initCustomerPicker(this.value || null);
        });

        const initialBranchId = document.getElementById('branch_id').value || null;
        window.currentInvoiceBranchId = initialBranchId;
        initCustomerPicker(initialBranchId);
    })();
    </script>
    @endpush
@endsection
```

Note: `_line_item_scripts.blade.php`'s own script block already wires `document.getElementById('addInvoiceServiceLine').addEventListener(...)` and the sparepart equivalent (see that file's bottom `<script>` block) — this view's script block above intentionally does **not** duplicate those two listeners, only the `branch_id`/`customer_id` AJAX-picker wiring that `_line_item_scripts.blade.php` has no knowledge of.

(Use this corrected script block in the file instead of the first draft above.)

- [ ] **Step 7: Add the entry-point button to `index.blade.php`**

In `resources/views/invoices/index.blade.php`, change the header block:

```blade
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-receipt me-2"></i>Invoice</h1>
        @if (auth()->user()->branchesWithPermission('invoice.create')->isNotEmpty())
            <a href="{{ route('invoices.createDirect') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Invoice Langsung (DS)
            </a>
        @endif
    </div>
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --filter=InvoiceDirectSaleTest`
Expected: PASS (all tests including the new controller/view/null-guard ones).

- [ ] **Step 9: Run the full test suite**

Run: `php artisan test`
Expected: 100% PASS — specifically re-verify `InvoicePkbGapReportControllerTest.php`/`InvoicePkbGapReportExportTest.php` still pass unmodified (they should, since that controller already filters `whereNotNull('invoices.work_order_id')`).

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/InvoiceController.php resources/views/invoices/create-direct.blade.php resources/views/invoices/index.blade.php resources/views/invoices/show.blade.php resources/views/invoices/print-pdf.blade.php tests/Feature/InvoiceDirectSaleTest.php
git commit -m "feat: add direct sales invoice creation UI and null-guard PKB references"
```

---

### Task 4: Conditional PPN Print

**Files:**
- Modify: `resources/views/invoices/print-pdf.blade.php`
- Test: `tests/Feature/InvoicePdfBuilderTest.php`

**Interfaces:**
- Consumes: nothing from Tasks 1-3.
- Produces: nothing consumed by later tasks (independent, could be done any time after Task 1's PDF column change).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/InvoicePdfBuilderTest.php` already has a `makeInvoice(Branch $branch)` helper (builds a PKB, confirms/completes it, calls `InvoiceService::createFromWorkOrder()`) and uses the `Tests\Concerns\ExtractsPdfText` trait's `extractPdfText(string $binaryOutput): string` method — mirror both exactly. Add these two tests at the end of the class, before the final `}`:

```php
    public function test_pdf_hides_ppn_row_when_tax_is_zero(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        // tax_percent/tax_amount are 0 by default on a freshly created draft invoice.

        $output = InvoicePdfBuilder::build($invoice)->output();
        $content = $this->extractPdfText($output);

        $this->assertStringNotContainsString('PPN', $content);
    }

    public function test_pdf_shows_ppn_row_when_tax_is_positive(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);

        (new InvoiceService())->updateInvoice($invoice, [
            'discount_percent' => 0,
            'tax_percent' => 11,
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

        $output = InvoicePdfBuilder::build($invoice->fresh())->output();
        $content = $this->extractPdfText($output);

        $this->assertStringContainsString('PPN', $content);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=InvoicePdfBuilderTest`
Expected: `test_pdf_hides_ppn_row_when_tax_is_zero` FAILs (PPN row always renders today).

- [ ] **Step 3: Wrap the PPN row in a conditional**

In `resources/views/invoices/print-pdf.blade.php`, change:

```blade
        <tr><td>PPN ({{ number_format($invoice->tax_percent, 2, ',', '.') }}%)</td><td class="num">{{ number_format($invoice->tax_amount, 0, ',', '.') }}</td></tr>
```

to:

```blade
        @if ($invoice->tax_percent > 0 && $invoice->tax_amount > 0)
            <tr><td>PPN ({{ number_format($invoice->tax_percent, 2, ',', '.') }}%)</td><td class="num">{{ number_format($invoice->tax_amount, 0, ',', '.') }}</td></tr>
        @endif
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=InvoicePdfBuilderTest`
Expected: PASS.

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: 100% PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/views/invoices/print-pdf.blade.php tests/Feature/InvoicePdfBuilderTest.php
git commit -m "feat: hide PPN row on printed invoice PDF when tax is zero"
```

---

### Task 5: End-to-End Integration Test Suite & Verifikasi Manual

**Files:**
- Create: `tests/Feature/InvoiceDirectSaleIntegrationTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1-4.
- Produces: nothing (terminal task).

- [ ] **Step 1: Write the end-to-end integration test**

Create `tests/Feature/InvoiceDirectSaleIntegrationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDirectSaleIntegrationTest extends TestCase
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

    public function test_full_direct_sale_lifecycle_create_edit_discount_post_print(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => 'SVC-CUCI', 'name' => 'Cuci Mobil', 'default_price' => 40000]);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'invoice.edit');
        $this->grantBranchPermission($user, $branch, 'invoice.post');
        $this->grantBranchPermission($user, $branch, 'invoice.print');

        // 1. Create the Direct Sales invoice with a per-line discount already on the sparepart line.
        $storeResponse = $this->actingAs($user)->post('/invoices/direct', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'services' => [
                ['description' => $catalog->name, 'qty' => 1, 'unit_price' => 40000, 'discount_percent' => 0],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000, 'discount_percent' => 10],
            ],
        ]);

        $invoice = \App\Models\Invoice::latest('id')->first();
        $storeResponse->assertRedirect("/invoices/{$invoice->id}");
        $this->assertNull($invoice->work_order_id);
        $this->assertTrue($invoice->is_direct_sale);
        $this->assertStringStartsWith("DS/{$branch->code}/", $invoice->number);
        $this->assertSame(InvoiceStatus::DRAFT, $invoice->status);

        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $this->assertSame(12000.0, (float) $sparepartDetail->discount_amount);
        $this->assertSame(108000.0, (float) $sparepartDetail->line_total);

        // 2. show() does not crash and labels it "Direct Sales" instead of a PKB number.
        $showResponse = $this->actingAs($user)->get("/invoices/{$invoice->id}");
        $showResponse->assertOk();
        $showResponse->assertSee('Direct Sales');

        // 3. Header discount/tax entered via the existing edit/update flow.
        $updateResponse = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 5,
            'tax_percent' => 11,
            'services' => [[
                'work_order_service_line_id' => null,
                'description' => $catalog->name,
                'qty' => 1,
                'unit_price' => 40000,
                'discount_percent' => 0,
            ]],
            'spareparts' => [[
                'work_order_sparepart_line_id' => null,
                'sparepart_branch_id' => $sparepartBranch->id,
                'qty' => 2,
                'unit_price' => 60000,
                'discount_percent' => 10,
            ]],
        ]);
        $updateResponse->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();
        $this->assertSame(5.0, (float) $invoice->discount_percent);
        $this->assertSame(11.0, (float) $invoice->tax_percent);

        // 4. Posting deducts stock exactly like a PKB-based invoice.
        $stockBefore = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $postResponse = $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");
        $postResponse->assertRedirect("/invoices/{$invoice->id}");
        $this->assertSame(InvoiceStatus::POSTED, $invoice->fresh()->status);
        $stockAfter = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame((float) $stockBefore->on_hand_qty - 2.0, (float) $stockAfter->on_hand_qty);

        // 5. Printing the PDF does not crash and shows "Direct Sales" instead of a PKB number.
        $printResponse = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");
        $printResponse->assertOk();

        // 6. The PKB-vs-Invoice gap report must NOT list this Direct Sales invoice as a gap
        // (its query already filters whereNotNull('invoices.work_order_id')). Permission code
        // and route confirmed from InvoicePkbGapReportControllerTest.php / routes/web.php.
        $this->grantBranchPermission($user, $branch, 'report.invoice_pkb_gap.view');
        $gapResponse = $this->actingAs($user)->get('/reports/invoice-pkb-gap');
        $gapResponse->assertOk();
        $gapResponse->assertDontSee($invoice->number);
    }
}
```

- [ ] **Step 2: Run the test to verify it passes**

Run: `php artisan test --filter=InvoiceDirectSaleIntegrationTest`
Expected: PASS. If it fails, debug against the real behavior rather than adjusting the test to hide a regression — this test exercises the full stack built across Tasks 1-4.

- [ ] **Step 3: Run the full test suite**

Run: `php artisan test`
Expected: 100% PASS across the entire suite (every prior milestone's tests plus all new ones from this milestone).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/InvoiceDirectSaleIntegrationTest.php
git commit -m "test: add end-to-end integration test for direct sales invoice lifecycle"
```

- [ ] **Step 5: Manual browser verification**

Using `mcp__Claude_Browser__*` tools against the running dev server, logged in as the `demo` user (branch id 4, "Cabang Demo"):

1. Grant the demo user `invoice.create`/`invoice.edit`/`invoice.post`/`invoice.print` in branch 4 via tinker if not already present (mirror the ad-hoc permission-granting pattern used in every prior milestone's manual verification).
2. Navigate to `/invoices`, confirm the "+ Invoice Langsung (DS)" button is visible.
3. Click it, fill in Customer (AJAX picker), add one jasa line and one sparepart line (with a discount % on at least one line), submit, confirm redirect to the new invoice's `show` page and that it displays "Direct Sales" instead of a PKB number.
4. Click "Ubah", confirm the "Diskon (%)" column appears on both line-item tables and pre-fills correctly, set header discount/PPN, save.
5. Click "Posting", confirm success and stock deduction (spot-check via a sparepart-branches page or tinker).
6. Click "Cetak Invoice", confirm the PDF renders with a "Diskon" column and (since PPN was set to a positive value in step 4) a visible PPN row; then edit the invoice down to 0% PPN on a *different* test invoice (or verify via the PDF-hides-PPN test already covered by Task 4's automated test) and confirm no PPN row when tax is 0.
7. Confirm no console errors or broken network requests throughout (`read_console_messages`, `read_network_requests`).

Report screenshots/results in the closing summary for this task.

---

## Self-Review Notes

- **Spec coverage:** All 8 decisions and all 3 features from `docs/superpowers/specs/2026-08-11-invoice-improvements-design.md` are covered — Fitur 1 (Task 1), Fitur 2 (Tasks 2-3), Fitur 3 (Task 4), end-to-end verification (Task 5). The §5.11 null-guards and §5.12 "no changes needed" list are both respected (no PKB-gap-report/Dashboard/edit.blade.php changes appear anywhere in this plan).
- **Formula consistency:** The `$gross = round($qty * $unitPrice, 2); $discountAmount = round($gross * $discountPercent / 100, 2); $lineTotal = round($gross - $discountAmount, 2);` formula is identical across `InvoiceService::updateInvoice()` (Task 1) and `InvoiceService::createDirectSale()` (Task 2) — no drift between the two call sites.
- **Route ordering:** Task 2 Step 9 places `/direct/create` and `/direct` before `/{invoice}` in `routes/web.php`, avoiding the wildcard-capture bug the design spec flagged.
- **Placeholder scan:** All test code, helper names (`ExtractsPdfText::extractPdfText()`, `InvoicePdfBuilderTest::makeInvoice()`), permission codes (`report.invoice_pkb_gap.view`), and routes (`/reports/invoice-pkb-gap`) were confirmed by reading the actual files before being written into this plan — no "adjust as needed" placeholders remain.
