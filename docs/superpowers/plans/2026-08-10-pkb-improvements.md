# Penyesuaian Spesifikasi Menu PKB & Master Kendaraan — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Tahun Kendaraan field to Master Kendaraan (surfaced through PKB creation, detail, and print), rename the PKB "Catatan" label to "Keluhan", and lock both jasa and sparepart unit prices in PKB lines to their master records so users can no longer freely edit them.

**Architecture:** Four independently-shippable changes layered onto the existing PKB (`WorkOrder`) and Master Kendaraan (`Vehicle`) modules — a new nullable `year` column threaded through the existing vehicle-lookup/label pipeline, two Blade label swaps, and two backend-enforced price overrides in `WorkOrderController::syncServiceLines()`/`syncSparepartLines()` backed by `readonly` UI inputs. No new tables, no new routes, no new policies.

**Tech Stack:** Laravel 8.75, PHP 7.4.33, MySQL, Blade, vanilla JS + Select2 (existing `_line_item_scripts.blade.php` picker infrastructure), PHPUnit, `Tests\Concerns\ExtractsPdfText` for PDF content assertions.

## Global Constraints

- PHP 7.4.33 — do not use PHP8-only syntax (no named arguments, no match expressions, no nullsafe on static calls, etc.).
- The database column and form field name `notes` does **not** change — only the label text shown to users changes ("Catatan" → "Keluhan").
- No data migration for legacy "Manual" (catalog-less) PKB service lines. They remain untouched in the database; a user only has to pick a real catalog entry if they actively re-open and re-save that draft in the Edit form.
- Every price change on a PKB line must be enforced server-side in `WorkOrderController`, never trust `readonly` as the only defense — it can be bypassed via devtools or a raw HTTP request.
- Follow this codebase's existing test convention: per-file helper duplication (no shared traits beyond what's already used, e.g. `RefreshDatabase`, `ExtractsPdfText`), TDD (failing test → implementation → passing test), one commit per task after its tests are green.
- Run the full test suite (`php artisan test`) at the end of every task, not just the files touched by that task — this catches ripple effects in unrelated files exactly like the two pre-existing tests this plan already found and fixes in Task 3.

---

## Correction found during plan-writing (read before starting Task 3)

The design spec's test-suite audit (§2 of `docs/superpowers/specs/2026-08-10-pkb-improvements-design.md`) concluded the price-lock change was low-risk because "hampir semua payload `services[]` sudah menyertakan `service_catalog_id`". Re-reading `tests/Feature/WorkOrderManagementTest.php` line-by-line during plan-writing found **two real exceptions** the audit's grep missed (it only sampled, didn't read every hit in context):

- `test_update_does_not_silently_reassign_a_now_inactive_customer` (around line 285) — submits a PUT with a services line `['service_catalog_id' => null, 'description' => 'Servis tambahan', 'qty' => 2, 'unit_price' => 25000]` and asserts a 302 redirect (success).
- `test_update_replaces_lines_and_recomputes_totals` (around line 588) — submits the same shape of catalog-less line and asserts `line_total === 50000.0`.

Both currently pass because `service_catalog_id` is nullable today. Once Task 3 makes it `required_with:services.*.qty`, both would start failing with a 422/session error instead of the redirect they assert. Task 3 below includes fixing these two tests as an explicit step — this is not a design change, just a correction to the spec's own audit. (The other four `service_catalog_id => null` hits in that file — lines ~221, ~242, ~633, ~1085 — are genuinely harmless: the first two have an empty `description` so `prepareForValidation()` strips them before validation runs at all, and the last two are inside tests that expect a 403 from `FormRequest::authorize()`, which runs before `rules()` ever sees the payload.)

---

### Task 1: Tahun Kendaraan — migration, model, form, and every PKB display point

**Files:**
- Create: `database/migrations/2026_08_10_000001_add_year_to_vehicles_table.php`
- Modify: `app/Models/Vehicle.php`
- Modify: `app/Http/Requests/StoreVehicleRequest.php`
- Modify: `app/Http/Requests/UpdateVehicleRequest.php`
- Modify: `resources/views/vehicles/_form.blade.php`
- Modify: `app/Http/Controllers/WorkOrderLookupController.php`
- Modify: `resources/views/work-orders/_line_item_scripts.blade.php`
- Modify: `resources/views/work-orders/edit.blade.php`
- Modify: `resources/views/work-orders/show.blade.php`
- Modify: `resources/views/work-orders/print-pdf.blade.php`
- Test: `tests/Feature/VehicleManagementTest.php`
- Test: `tests/Feature/WorkOrderLookupTest.php`

**Interfaces:**
- Produces: `Vehicle::$fillable` includes `'year'` (nullable int, 4-digit, `1900..currentYear+1`), consumed by every task below and by Task 5's `VehicleYearPkbTest`.
- Produces: `WorkOrderLookupController::vehiclesByCustomer()` JSON now includes a `year` key per vehicle (alongside the existing `brand_name`/`type_name` added in an earlier milestone).
- Produces: `window.WorkOrderLineItems.vehicleLabel(item)` (in `_line_item_scripts.blade.php`) now renders `"{Brand} {Type} {Year} - {Plate}"` when `item.year` is present, falling back to the existing `"{Brand} {Type} - {Plate}"` format when it isn't.

- [ ] **Step 1: Write failing test — Vehicle model persists `year`**

Add to `tests/Feature/VehicleManagementTest.php`, near `test_store_creates_vehicle`:

```php
public function test_store_creates_vehicle_with_year(): void
{
    $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
    ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
    $user = $this->userWithPermissions(['vehicle.create']);

    $response = $this->actingAs($user)->post('/vehicles', [
        'customer_id' => $customer->id,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'type_id' => $type->id,
        'plate_number' => 'B 1234 XYZ',
        'year' => 2020,
        'is_active' => '1',
    ]);

    $response->assertRedirect('/vehicles');
    $this->assertDatabaseHas('vehicles', ['plate_number' => 'B 1234 XYZ', 'year' => 2020]);
}

public function test_store_rejects_year_outside_valid_range(): void
{
    $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
    ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
    $user = $this->userWithPermissions(['vehicle.create']);

    $response = $this->actingAs($user)->post('/vehicles', [
        'customer_id' => $customer->id,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'type_id' => $type->id,
        'plate_number' => 'B 1234 XYZ',
        'year' => 1899,
        'is_active' => '1',
    ]);

    $response->assertSessionHasErrors(['year']);
}
```

- [ ] **Step 2: Run and verify both tests fail**

Run: `php artisan test --filter VehicleManagementTest::test_store_creates_vehicle_with_year`
Expected: FAIL (`year` column does not exist / `SQLSTATE` unknown column, or 500).

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddYearToVehiclesTable extends Migration
{
    public function up()
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable()->after('engine_number');
        });
    }

    public function down()
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('year');
        });
    }
}
```

Run: `php artisan migrate`

- [ ] **Step 4: Add `year` to `Vehicle::$fillable`**

In `app/Models/Vehicle.php`:

```php
protected $fillable = [
    'customer_id', 'plate_number', 'frame_number', 'engine_number',
    'category_id', 'brand_id', 'type_id', 'year', 'is_active',
];
```

- [ ] **Step 5: Add validation rules**

In `app/Http/Requests/StoreVehicleRequest.php` and `app/Http/Requests/UpdateVehicleRequest.php`, add to `rules()`:

```php
'year' => ['nullable', 'integer', 'digits:4', 'between:1900,' . (now()->year + 1)],
```

- [ ] **Step 6: Run tests, verify they pass**

Run: `php artisan test --filter VehicleManagementTest`
Expected: PASS (all VehicleManagementTest tests, including the two new ones).

- [ ] **Step 7: Write failing test — year input renders and pre-fills on edit**

Add to `tests/Feature/VehicleManagementTest.php`:

```php
public function test_edit_page_renders_year_input_prefilled(): void
{
    $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
    ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
    $vehicle = Vehicle::create([
        'customer_id' => $customer->id, 'category_id' => $category->id,
        'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ', 'year' => 2019,
    ]);
    $user = $this->userWithPermissions(['vehicle.edit']);

    $response = $this->actingAs($user)->get("/vehicles/{$vehicle->id}/edit");

    $response->assertOk();
    $response->assertSee('name="year"', false);
    $response->assertSee('value="2019"', false);
}
```

- [ ] **Step 8: Run, verify it fails**

Run: `php artisan test --filter VehicleManagementTest::test_edit_page_renders_year_input_prefilled`
Expected: FAIL (no `name="year"` input in the response).

- [ ] **Step 9: Add the Tahun input to the shared vehicle form partial**

In `resources/views/vehicles/_form.blade.php`, replace the plate/frame/engine row (currently 3× `col-md-4`) with 4× `col-md-3` including a new Tahun field:

```blade
<div class="row">
    <div class="col-md-3 mb-3">
        <label for="plate_number" class="form-label">No. Polisi</label>
        <input type="text" name="plate_number" id="plate_number" value="{{ old('plate_number', $vehicle->plate_number) }}" class="form-control @error('plate_number') is-invalid @enderror" maxlength="30">
        @error('plate_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3 mb-3">
        <label for="frame_number" class="form-label">No. Rangka</label>
        <input type="text" name="frame_number" id="frame_number" value="{{ old('frame_number', $vehicle->frame_number) }}" class="form-control @error('frame_number') is-invalid @enderror" maxlength="100">
        @error('frame_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3 mb-3">
        <label for="engine_number" class="form-label">No. Mesin</label>
        <input type="text" name="engine_number" id="engine_number" value="{{ old('engine_number', $vehicle->engine_number) }}" class="form-control @error('engine_number') is-invalid @enderror" maxlength="100">
        @error('engine_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3 mb-3">
        <label for="year" class="form-label">Tahun Kendaraan</label>
        <input type="number" name="year" id="year" value="{{ old('year', $vehicle->year) }}" class="form-control @error('year') is-invalid @enderror" min="1900" max="{{ now()->year + 1 }}">
        @error('year')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
```

- [ ] **Step 10: Run, verify it passes**

Run: `php artisan test --filter VehicleManagementTest`
Expected: PASS.

- [ ] **Step 11: Write failing test — lookup JSON includes `year`**

Add to `tests/Feature/WorkOrderLookupTest.php`:

```php
public function test_vehicles_by_customer_includes_year(): void
{
    $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
    $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
    CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
    $category = VehicleCategory::create(['name' => 'Motor']);
    $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
    $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);
    Vehicle::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 001 CCC', 'year' => 2020]);
    $user = User::factory()->create();
    $this->grantBranchPermission($user, $branch, 'pkb.create');

    $response = $this->actingAs($user)->getJson("/work-orders/lookup/vehicles/{$customer->id}");

    $response->assertOk();
    $response->assertJsonFragment(['plate_number' => 'B 001 CCC', 'year' => 2020]);
}
```

- [ ] **Step 12: Run, verify it fails**

Run: `php artisan test --filter WorkOrderLookupTest::test_vehicles_by_customer_includes_year`
Expected: FAIL (`year` key missing from the JSON fragment).

- [ ] **Step 13: Add `year` to the lookup query and response map**

In `app/Http/Controllers/WorkOrderLookupController.php`:

```php
$vehicles = $customer->vehicles()->where('is_active', true)
    ->with(['brand:id,name', 'type:id,name'])
    ->orderBy('plate_number')
    ->get(['id', 'plate_number', 'frame_number', 'brand_id', 'type_id', 'year'])
    ->map(fn (Vehicle $vehicle) => [
        'id' => $vehicle->id,
        'plate_number' => $vehicle->plate_number,
        'frame_number' => $vehicle->frame_number,
        'brand_name' => optional($vehicle->brand)->name,
        'type_name' => optional($vehicle->type)->name,
        'year' => $vehicle->year,
    ]);
```

- [ ] **Step 14: Run, verify it passes**

Run: `php artisan test --filter WorkOrderLookupTest`
Expected: PASS.

- [ ] **Step 15: Write failing test — the Edit PKB page's vehicle option label includes the year**

Add to `tests/Feature/WorkOrderManagementTest.php` (near the other edit-page tests):

```php
public function test_edit_page_vehicle_option_label_includes_year(): void
{
    $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
    $scenario = $this->makeScenario($branch);
    $scenario['vehicle']->update(['year' => 2020]);
    $user = User::factory()->create();
    $this->grantBranchPermission($user, $branch, 'pkb.create');
    $this->grantBranchPermission($user, $branch, 'pkb.edit');
    $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
    $workOrder = WorkOrder::first();

    $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}/edit");

    $response->assertOk();
    $response->assertSee('Toyota Avanza 2020 - B 1234 ' . $branch->code);
}
```

- [ ] **Step 16: Run, verify it fails**

Run: `php artisan test --filter WorkOrderManagementTest::test_edit_page_vehicle_option_label_includes_year`
Expected: FAIL (label renders without the year, e.g. `"Toyota Avanza - B 1234 JKT"`).

- [ ] **Step 17: Extend the year into both label builders**

In `resources/views/work-orders/_line_item_scripts.blade.php`, update the JS `vehicleLabel()` function used by the AJAX-driven Create page:

```js
function vehicleLabel(item) {
    const plate = item.plate_number || item.frame_number || '-';
    const brandType = [item.brand_name, item.type_name, item.year].filter(Boolean).join(' ');
    return brandType ? (brandType + ' - ' + plate) : plate;
}
```

In `resources/views/work-orders/edit.blade.php`, update the server-rendered `$vehicleLabel` computation to match:

```blade
@php
    $vehicleBrandType = trim(($vehicle->brand->name ?? '') . ' ' . ($vehicle->type->name ?? '') . ' ' . ($vehicle->year ?? ''));
    $vehicleLabel = $vehicleBrandType !== '' ? $vehicleBrandType . ' - ' . ($vehicle->plate_number ?? $vehicle->frame_number) : ($vehicle->plate_number ?? $vehicle->frame_number);
@endphp
```

(`WorkOrderController::edit()` already eager-loads `vehicle.brand`/`vehicle.type` for the `$vehicles` collection; `year` comes along for free as a plain column, no eager-load change needed.)

- [ ] **Step 18: Run, verify it passes**

Run: `php artisan test --filter WorkOrderManagementTest::test_edit_page_vehicle_option_label_includes_year`
Expected: PASS.

- [ ] **Step 19: Write failing test — PKB detail page shows Tahun Kendaraan**

Add to `tests/Feature/WorkOrderManagementTest.php`:

```php
public function test_show_page_displays_vehicle_year(): void
{
    $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
    $scenario = $this->makeScenario($branch);
    $scenario['vehicle']->update(['year' => 2021]);
    $user = User::factory()->create();
    $this->grantBranchPermission($user, $branch, 'pkb.create');
    $this->grantBranchPermission($user, $branch, 'pkb.view');
    $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
    $workOrder = WorkOrder::first();

    $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}");

    $response->assertOk();
    $response->assertSee('Tahun Kendaraan');
    $response->assertSee('2021');
}
```

- [ ] **Step 20: Run, verify it fails**

Run: `php artisan test --filter WorkOrderManagementTest::test_show_page_displays_vehicle_year`
Expected: FAIL ("Tahun Kendaraan" not present in the response).

- [ ] **Step 21: Add the Tahun Kendaraan field to `show.blade.php`**

In `resources/views/work-orders/show.blade.php`, add a new field next to the existing Kendaraan/Mekanik fields (leave the existing `plate_number`-only Kendaraan field untouched, per the design doc's note that `show.blade.php` and `print-pdf.blade.php` are already inconsistent and unifying them is out of scope):

```blade
<div class="col-md-3"><strong>Tahun Kendaraan</strong><div>{{ $workOrder->vehicle->year ?? '-' }}</div></div>
```

Insert it right after the existing `Kendaraan` field (line ~46) so it reads Cabang / Customer / Kendaraan / Tahun Kendaraan / Mekanik / Tanggal / ... in the `row g-3`. `WorkOrderController::show()` already eager-loads `vehicle`; `year` is a plain column on it, no query change needed.

- [ ] **Step 22: Run, verify it passes**

Run: `php artisan test --filter WorkOrderManagementTest::test_show_page_displays_vehicle_year`
Expected: PASS.

- [ ] **Step 23: Write failing test — printed PDF includes the year**

Add to `tests/Feature/WorkOrderPrintTest.php`. Reuse the existing `makeWorkOrder()` helper but set a year on the vehicle first — since `makeWorkOrder()` creates the vehicle inline, add a small parameter-free variant that updates it after creation:

```php
public function test_print_content_includes_vehicle_year(): void
{
    $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
    $workOrder = $this->makeWorkOrder($branch);
    $workOrder->vehicle->update(['year' => 2022]);
    $user = User::factory()->create();
    $this->grantBranchPermission($user, $branch, 'pkb.print');

    $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/print");

    $content = $this->extractPdfText($response->getContent());
    $this->assertStringContainsString('2022', $content);
}
```

- [ ] **Step 24: Run, verify it fails**

Run: `php artisan test --filter WorkOrderPrintTest::test_print_content_includes_vehicle_year`
Expected: FAIL ("2022" absent from the extracted PDF text).

- [ ] **Step 25: Add the year to the PDF's Kendaraan line**

In `resources/views/work-orders/print-pdf.blade.php`, extend the existing line:

```blade
<div><span class="label">Kendaraan:</span> {{ optional($workOrder->vehicle->brand)->name }} {{ optional($workOrder->vehicle->type)->name }}{{ $workOrder->vehicle->year ? " ({$workOrder->vehicle->year})" : '' }}</div>
```

`WorkOrderController::printPdf()` already eager-loads `vehicle.brand`/`vehicle.type`; `year` comes along for free.

- [ ] **Step 26: Run, verify it passes**

Run: `php artisan test --filter WorkOrderPrintTest`
Expected: PASS.

- [ ] **Step 27: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 28: Commit**

```bash
git add database/migrations/2026_08_10_000001_add_year_to_vehicles_table.php app/Models/Vehicle.php app/Http/Requests/StoreVehicleRequest.php app/Http/Requests/UpdateVehicleRequest.php resources/views/vehicles/_form.blade.php app/Http/Controllers/WorkOrderLookupController.php resources/views/work-orders/_line_item_scripts.blade.php resources/views/work-orders/edit.blade.php resources/views/work-orders/show.blade.php resources/views/work-orders/print-pdf.blade.php tests/Feature/VehicleManagementTest.php tests/Feature/WorkOrderLookupTest.php tests/Feature/WorkOrderManagementTest.php tests/Feature/WorkOrderPrintTest.php
git commit -m "feat: add Tahun Kendaraan field, shown in PKB create/detail/print"
```

---

### Task 2: Label "Catatan" → "Keluhan"

**Files:**
- Modify: `resources/views/work-orders/create.blade.php`
- Modify: `resources/views/work-orders/edit.blade.php`
- Modify: `resources/views/work-orders/show.blade.php`
- Modify: `resources/views/work-orders/print-pdf.blade.php`
- Test: `tests/Feature/WorkOrderManagementTest.php`
- Test: `tests/Feature/WorkOrderPrintTest.php`

**Interfaces:**
- Consumes: nothing new — pure label text swap.
- Produces: nothing consumed by later tasks. The `name="notes"` form field and the `notes` DB column are unchanged, so no other file needs updating.

- [ ] **Step 1: Write failing tests — label text on create/edit/show**

Add to `tests/Feature/WorkOrderManagementTest.php`:

```php
public function test_create_page_shows_keluhan_label_not_catatan(): void
{
    $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
    $user = User::factory()->create();
    $this->grantBranchPermission($user, $branch, 'pkb.create');

    $response = $this->actingAs($user)->get('/work-orders/create');

    $response->assertOk();
    $response->assertSee('Keluhan');
    $response->assertDontSee('>Catatan<', false);
}

public function test_edit_page_shows_keluhan_label_not_catatan(): void
{
    $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
    $scenario = $this->makeScenario($branch);
    $user = User::factory()->create();
    $this->grantBranchPermission($user, $branch, 'pkb.create');
    $this->grantBranchPermission($user, $branch, 'pkb.edit');
    $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
    $workOrder = WorkOrder::first();

    $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}/edit");

    $response->assertOk();
    $response->assertSee('Keluhan');
    $response->assertDontSee('>Catatan<', false);
}

public function test_show_page_shows_keluhan_label_not_catatan(): void
{
    $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
    $scenario = $this->makeScenario($branch);
    $user = User::factory()->create();
    $this->grantBranchPermission($user, $branch, 'pkb.create');
    $this->grantBranchPermission($user, $branch, 'pkb.view');
    $this->actingAs(User::find($user->id))->post('/work-orders', $this->baseStorePayload($branch, $scenario));
    $workOrder = WorkOrder::first();

    $response = $this->actingAs(User::find($user->id))->get("/work-orders/{$workOrder->id}");

    $response->assertOk();
    $response->assertSee('Keluhan');
    $response->assertDontSee('>Catatan<', false);
}
```

Add to `tests/Feature/WorkOrderPrintTest.php`:

```php
public function test_print_content_shows_keluhan_label_not_catatan(): void
{
    $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
    $workOrder = $this->makeWorkOrder($branch);
    $user = User::factory()->create();
    $this->grantBranchPermission($user, $branch, 'pkb.print');

    $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/print");

    $content = $this->extractPdfText($response->getContent());
    $this->assertStringContainsString('Keluhan:', $content);
    $this->assertStringNotContainsString('Catatan:', $content);
}
```

- [ ] **Step 2: Run, verify all four fail**

Run: `php artisan test --filter "WorkOrderManagementTest::test_create_page_shows_keluhan_label_not_catatan|WorkOrderManagementTest::test_edit_page_shows_keluhan_label_not_catatan|WorkOrderManagementTest::test_show_page_shows_keluhan_label_not_catatan|WorkOrderPrintTest::test_print_content_shows_keluhan_label_not_catatan"`
Expected: FAIL on all four (current label is "Catatan").

- [ ] **Step 3: Swap the label text (four files, `name="notes"` untouched)**

`resources/views/work-orders/create.blade.php` (line ~54): `<label class="form-label">Catatan</label>` → `<label class="form-label">Keluhan</label>`.

`resources/views/work-orders/edit.blade.php` (line ~47): same swap.

`resources/views/work-orders/show.blade.php` (line ~66): `<div class="col-md-6"><strong>Catatan</strong><div>{{ $workOrder->notes ?? '-' }}</div></div>` → `<div class="col-md-6"><strong>Keluhan</strong><div>{{ $workOrder->notes ?? '-' }}</div></div>`.

`resources/views/work-orders/print-pdf.blade.php` (line ~67): `<div><span class="label">Catatan:</span> {{ $workOrder->notes ?? '-' }}</div>` → `<div><span class="label">Keluhan:</span> {{ $workOrder->notes ?? '-' }}</div>`.

Note: `resources/views/work-orders/index.blade.php` needs **no change** — it has no Catatan column at all (confirmed during design-doc exploration).

- [ ] **Step 4: Run, verify all four pass**

Run: `php artisan test --filter "WorkOrderManagementTest::test_create_page_shows_keluhan_label_not_catatan|WorkOrderManagementTest::test_edit_page_shows_keluhan_label_not_catatan|WorkOrderManagementTest::test_show_page_shows_keluhan_label_not_catatan|WorkOrderPrintTest::test_print_content_shows_keluhan_label_not_catatan"`
Expected: PASS.

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions. (Existing tests that submit/assert on `notes` by field name are unaffected since the field name didn't change.)

- [ ] **Step 6: Commit**

```bash
git add resources/views/work-orders/create.blade.php resources/views/work-orders/edit.blade.php resources/views/work-orders/show.blade.php resources/views/work-orders/print-pdf.blade.php tests/Feature/WorkOrderManagementTest.php tests/Feature/WorkOrderPrintTest.php
git commit -m "chore: relabel PKB Catatan field as Keluhan"
```

---

### Task 3: Lock service (jasa) price to the catalog

**Files:**
- Modify: `resources/views/work-orders/_line_item_scripts.blade.php`
- Modify: `app/Http/Requests/StoreWorkOrderRequest.php`
- Modify: `app/Http/Requests/UpdateWorkOrderRequest.php`
- Modify: `app/Http/Controllers/WorkOrderController.php`
- Modify: `tests/Feature/WorkOrderManagementTest.php` (fix the two tests identified in the "Correction" section above, plus new tests)

**Interfaces:**
- Consumes: `ServiceCatalog::$default_price` (existing column, unchanged).
- Produces: `WorkOrderController::syncServiceLines()` now always sets `unit_price` from `ServiceCatalog::findOrFail($line['service_catalog_id'])->default_price`, ignoring any client-submitted `unit_price`. `services.*.service_catalog_id` is now a required field whenever a line has a `qty` (previously nullable).

- [ ] **Step 1: Fix the two pre-existing tests that submit a catalog-less service line and expect success**

In `tests/Feature/WorkOrderManagementTest.php`:

`test_update_does_not_silently_reassign_a_now_inactive_customer` (around line 297-307) — change the services line to use the scenario's real catalog:

```php
'services' => [
    ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Servis tambahan', 'qty' => 2, 'unit_price' => 25000],
],
```

`test_update_replaces_lines_and_recomputes_totals` (around line 597-606) — same fix, but use `qty => 1` so the existing `line_total === 50000.0` assertion (line ~614) still holds once the price is force-set to the catalog's `default_price` of 50000 (set in `makeScenario()`):

```php
'services' => [
    ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Servis tambahan', 'qty' => 1, 'unit_price' => 25000],
],
```

- [ ] **Step 2: Run, verify these two still pass with the fix (before any price-lock code exists yet — this just re-validates the fixed payloads against current behavior)**

Run: `php artisan test --filter "WorkOrderManagementTest::test_update_does_not_silently_reassign_a_now_inactive_customer|WorkOrderManagementTest::test_update_replaces_lines_and_recomputes_totals"`
Expected: PASS (current code still accepts a submitted `unit_price` verbatim, so `line_total` for the second test is `1 * 25000 = 25000`... this will change once Step 6 below forces the catalog price. Re-run again after Step 6 to confirm the final assertion.)

Update the second test's assertion now, ahead of the implementation, since TDD requires the test to encode the *target* behavior:

```php
$this->assertSame(50000.0, (float) $workOrder->serviceLines->first()->line_total);
```

(`qty=1 × catalog default_price=50000 = 50000` once price-lock lands — this is intentionally a currently-failing assertion until Step 6.)

- [ ] **Step 3: Write failing test — price lock overrides a divergent client-submitted price**

Add to `tests/Feature/WorkOrderManagementTest.php`:

```php
public function test_store_forces_service_unit_price_from_catalog_ignoring_client_value(): void
{
    $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
    $scenario = $this->makeScenario($branch);
    $user = User::factory()->create();
    $this->grantBranchPermission($user, $branch, 'pkb.create');
    $payload = $this->baseStorePayload($branch, $scenario);
    // catalog default_price is 50000 (set in makeScenario); submit a tampered price.
    $payload['services'][0]['unit_price'] = 1;

    $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

    $workOrder = WorkOrder::first();
    $this->assertSame(50000.0, (float) $workOrder->serviceLines->first()->unit_price);
}

public function test_store_rejects_a_service_line_without_a_catalog(): void
{
    $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
    $scenario = $this->makeScenario($branch);
    $user = User::factory()->create();
    $this->grantBranchPermission($user, $branch, 'pkb.create');
    $payload = $this->baseStorePayload($branch, $scenario);
    $payload['services'] = [
        ['service_catalog_id' => null, 'description' => 'Servis manual', 'qty' => 1, 'unit_price' => 50000],
    ];

    $response = $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

    $response->assertSessionHasErrors(['services.0.service_catalog_id']);
    $this->assertSame(0, WorkOrder::count());
}
```

- [ ] **Step 4: Run, verify both fail**

Run: `php artisan test --filter "WorkOrderManagementTest::test_store_forces_service_unit_price_from_catalog_ignoring_client_value|WorkOrderManagementTest::test_store_rejects_a_service_line_without_a_catalog"`
Expected: FAIL — the first because the current controller trusts the submitted `unit_price` (1, not 50000); the second because `service_catalog_id` is currently nullable so no validation error is raised.

- [ ] **Step 5: Require `service_catalog_id` in both FormRequests**

In `app/Http/Requests/StoreWorkOrderRequest.php` and `app/Http/Requests/UpdateWorkOrderRequest.php`, change:

```php
'services.*.service_catalog_id' => ['nullable', 'integer', 'exists:service_catalogs,id'],
```

to:

```php
'services.*.service_catalog_id' => ['required_with:services.*.qty', 'integer', 'exists:service_catalogs,id'],
```

- [ ] **Step 6: Force the price in `WorkOrderController::syncServiceLines()`**

```php
protected function syncServiceLines(WorkOrder $workOrder, array $lines): void
{
    $workOrder->serviceLines()->delete();

    foreach (array_values(array_filter($lines)) as $index => $line) {
        $catalog = ServiceCatalog::findOrFail($line['service_catalog_id']);
        $qty = (float) $line['qty'];
        $unitPrice = (float) $catalog->default_price;
        WorkOrderServiceLine::create([
            'work_order_id' => $workOrder->id,
            'service_catalog_id' => $catalog->id,
            'description' => $line['description'],
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => round($qty * $unitPrice, 2),
            'sort_order' => $index,
        ]);
    }
}
```

- [ ] **Step 7: Run the four tests from Steps 2-4, verify they all pass now**

Run: `php artisan test --filter "WorkOrderManagementTest::test_update_does_not_silently_reassign_a_now_inactive_customer|WorkOrderManagementTest::test_update_replaces_lines_and_recomputes_totals|WorkOrderManagementTest::test_store_forces_service_unit_price_from_catalog_ignoring_client_value|WorkOrderManagementTest::test_store_rejects_a_service_line_without_a_catalog"`
Expected: PASS.

- [ ] **Step 8: Remove the "-- Manual --" option and lock the UI price field**

In `resources/views/work-orders/_line_item_scripts.blade.php`, update `serviceLineTemplate`:

```blade
<template id="serviceLineTemplate">
    <div class="row g-2 align-items-start mb-2 service-line">
        <div class="col-md-3">
            <select class="form-select service-catalog-select" data-name-prefix="services" required>
                <option value="">-- Pilih Jasa --</option>
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
            <input type="number" step="0.01" min="0" class="form-control service-unit-price" readonly>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-sm remove-line">&times;</button>
        </div>
    </div>
</template>
```

(`.service-description` stays editable — the spec only locks the price, not the description — and the existing `change` listener on `.service-catalog-select` already writes `data-price` into `.service-unit-price`, so no JS logic change is needed beyond the template's new attributes.)

- [ ] **Step 9: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions across all 18 files identified as touching `POST/PUT /work-orders`.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/StoreWorkOrderRequest.php app/Http/Requests/UpdateWorkOrderRequest.php app/Http/Controllers/WorkOrderController.php resources/views/work-orders/_line_item_scripts.blade.php tests/Feature/WorkOrderManagementTest.php
git commit -m "feat: lock PKB service line price to the service catalog"
```

---

### Task 4: Lock sparepart price to the branch selling price

**Files:**
- Modify: `resources/views/work-orders/_line_item_scripts.blade.php`
- Modify: `app/Http/Controllers/WorkOrderController.php`
- Test: `tests/Feature/WorkOrderManagementTest.php`

**Interfaces:**
- Consumes: `SparepartBranch::$selling_price` (existing column, unchanged).
- Produces: `WorkOrderController::syncSparepartLines()` now always sets `unit_price` from `$sparepartBranch->selling_price`, ignoring any client-submitted `unit_price`. No FormRequest rule change needed — `spareparts.*.sparepart_branch_id` was already required.

- [ ] **Step 1: Write failing test — price lock overrides a divergent client-submitted sparepart price**

Add to `tests/Feature/WorkOrderManagementTest.php`:

```php
public function test_store_forces_sparepart_unit_price_from_branch_selling_price_ignoring_client_value(): void
{
    $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
    $scenario = $this->makeScenario($branch);
    $user = User::factory()->create();
    $this->grantBranchPermission($user, $branch, 'pkb.create');
    $payload = $this->baseStorePayload($branch, $scenario);
    // sparepartBranch selling_price is 60000 (set in makeScenario); submit a tampered price.
    $payload['spareparts'][0]['unit_price'] = 1;

    $this->actingAs(User::find($user->id))->post('/work-orders', $payload);

    $workOrder = WorkOrder::first();
    $this->assertSame(60000.0, (float) $workOrder->sparepartLines->first()->unit_price);
}
```

- [ ] **Step 2: Run, verify it fails**

Run: `php artisan test --filter WorkOrderManagementTest::test_store_forces_sparepart_unit_price_from_branch_selling_price_ignoring_client_value`
Expected: FAIL (`unit_price` saved as 1, not 60000).

- [ ] **Step 3: Force the price in `WorkOrderController::syncSparepartLines()`**

```php
protected function syncSparepartLines(WorkOrder $workOrder, array $lines): void
{
    $workOrder->sparepartLines()->delete();

    foreach (array_values(array_filter($lines)) as $index => $line) {
        $sparepartBranch = SparepartBranch::with('sparepart')->findOrFail($line['sparepart_branch_id']);
        $qty = (float) $line['qty'];
        $unitPrice = (float) $sparepartBranch->selling_price;
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
```

- [ ] **Step 4: Run, verify it passes**

Run: `php artisan test --filter WorkOrderManagementTest::test_store_forces_sparepart_unit_price_from_branch_selling_price_ignoring_client_value`
Expected: PASS.

- [ ] **Step 5: Lock the UI price field**

In `resources/views/work-orders/_line_item_scripts.blade.php`, update `sparepartLineTemplate`'s price input:

```blade
<input type="number" step="0.01" min="0" class="form-control sparepart-unit-price" readonly>
```

(The existing `onSelect` callback in `addSparepartLine()` already writes `item.selling_price` into `.sparepart-unit-price`; no JS logic change needed.)

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/WorkOrderController.php resources/views/work-orders/_line_item_scripts.blade.php tests/Feature/WorkOrderManagementTest.php
git commit -m "feat: lock PKB sparepart line price to branch selling price"
```

---

### Task 5: Integration test suite and manual verification

**Files:**
- Create: `tests/Feature/VehicleYearPkbTest.php`
- Create: `tests/Feature/PkbPriceLockTest.php`

**Interfaces:**
- Consumes: everything produced by Tasks 1-4 (`Vehicle::year`, `WorkOrderLookupController` year field, locked service/sparepart pricing, relabeled Keluhan field). Nothing new produced — this task is pure regression/integration coverage plus manual verification, no production code changes.

- [ ] **Step 1: Write `VehicleYearPkbTest` — end-to-end year visibility across the PKB lifecycle**

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class VehicleYearPkbTest extends TestCase
{
    use RefreshDatabase;
    use ExtractsPdfText;

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

    protected function makeWorkOrderWithYear(Branch $branch, ?int $year): WorkOrder
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => "Motor {$branch->code}"]);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => "B 001 {$branch->code}", 'year' => $year,
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => "SVC-{$branch->code}", 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => "OLI-{$branch->code}", 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.print');

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
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 1, 'unit_price' => 60000],
            ],
        ]);

        return WorkOrder::latest('id')->first();
    }

    public function test_vehicle_year_flows_through_lookup_show_and_print(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrderWithYear($branch, 2020);

        $lookupResponse = $this->actingAs(User::first())->getJson("/work-orders/lookup/vehicles/{$workOrder->customer_id}");
        $lookupResponse->assertOk();
        $lookupResponse->assertJsonFragment(['year' => 2020]);

        $showResponse = $this->actingAs(User::first())->get("/work-orders/{$workOrder->id}");
        $showResponse->assertOk();
        $showResponse->assertSee('Tahun Kendaraan');
        $showResponse->assertSee('2020');

        $printResponse = $this->actingAs(User::first())->get("/work-orders/{$workOrder->id}/print");
        $printContent = $this->extractPdfText($printResponse->getContent());
        $this->assertStringContainsString('2020', $printContent);
    }

    public function test_vehicle_without_year_degrades_gracefully_everywhere(): void
    {
        $branch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $workOrder = $this->makeWorkOrderWithYear($branch, null);

        $showResponse = $this->actingAs(User::first())->get("/work-orders/{$workOrder->id}");
        $showResponse->assertOk();
        $showResponse->assertSee('Tahun Kendaraan');

        $printResponse = $this->actingAs(User::first())->get("/work-orders/{$workOrder->id}/print");
        $printResponse->assertOk();
    }
}
```

- [ ] **Step 2: Run, verify it passes (this is regression coverage over already-implemented behavior, so it should be green immediately)**

Run: `php artisan test --filter VehicleYearPkbTest`
Expected: PASS. If it fails, that's a real gap Tasks 1-4 missed — fix the production code (not the test) before continuing.

- [ ] **Step 3: Write `PkbPriceLockTest` — holistic price-lock coverage across create and edit**

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PkbPriceLockTest extends TestCase
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
        $catalog = ServiceCatalog::create(['code' => "SVC-{$branch->code}", 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => "OLI-{$branch->code}", 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        return compact('customer', 'vehicle', 'mechanic', 'catalog', 'sparepartBranch');
    }

    public function test_create_ignores_tampered_prices_for_both_line_types_at_once(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 1],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $scenario['sparepartBranch']->id, 'qty' => 1, 'unit_price' => 1],
            ],
        ]);

        $workOrder = WorkOrder::first();
        $this->assertSame(50000.0, (float) $workOrder->serviceLines->first()->unit_price);
        $this->assertSame(60000.0, (float) $workOrder->sparepartLines->first()->unit_price);
    }

    public function test_editing_a_draft_reapplies_current_master_prices_even_if_catalog_changed_since_creation(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 50000],
            ],
            'spareparts' => [],
        ]);
        $workOrder = WorkOrder::first();
        $scenario['catalog']->update(['default_price' => 75000]);

        $this->actingAs($user)->put("/work-orders/{$workOrder->id}", [
            'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 50000],
            ],
            'spareparts' => [],
        ]);

        $workOrder->refresh();
        $this->assertSame(75000.0, (float) $workOrder->serviceLines->first()->unit_price);
    }

    public function test_update_rejects_a_service_line_without_a_catalog(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => $scenario['catalog']->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 50000],
            ],
            'spareparts' => [],
        ]);
        $workOrder = WorkOrder::first();

        $response = $this->actingAs($user)->put("/work-orders/{$workOrder->id}", [
            'customer_id' => $scenario['customer']->id,
            'vehicle_id' => $scenario['vehicle']->id,
            'mechanic_id' => $scenario['mechanic']->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => null, 'description' => 'Servis manual lama', 'qty' => 1, 'unit_price' => 50000],
            ],
            'spareparts' => [],
        ]);

        $response->assertSessionHasErrors(['services.0.service_catalog_id']);
    }
}
```

- [ ] **Step 4: Run, verify all three pass**

Run: `php artisan test --filter PkbPriceLockTest`
Expected: PASS. If it fails, that's a real gap in Tasks 3-4's implementation — fix the production code before continuing.

- [ ] **Step 5: Run the entire project test suite**

Run: `php artisan test`
Expected: PASS across every test file in the project, with zero regressions from all four prior tasks combined.

- [ ] **Step 6: Manual browser verification**

Using the dev server preview:
1. Open Master Kendaraan → create a vehicle, confirm the Tahun Kendaraan field appears in the form row and saves correctly; edit it and confirm the year is pre-filled.
2. Open PKB → Buat PKB Baru, pick a customer with a vehicle that has a year set, confirm the vehicle dropdown label shows the year (e.g. "Honda Beat 2020 - B 001 CCC").
3. On the same Create PKB page, add a jasa line, pick a catalog entry, confirm the harga satuan field is greyed out/non-editable and auto-fills from the catalog. Confirm the "-- Manual --" option no longer exists (dropdown starts with "-- Pilih Jasa --").
4. Add a sparepart line, confirm its harga satuan field is likewise non-editable and auto-fills from the selected sparepart's selling price.
5. Submit the PKB, open its detail page, confirm "Keluhan" (not "Catatan") is the label, and confirm "Tahun Kendaraan" shows the vehicle's year.
6. Click Cetak PKB, confirm the printed PDF shows "Keluhan:" and the vehicle's year in parentheses next to Kendaraan.

- [ ] **Step 7: Commit**

```bash
git add tests/Feature/VehicleYearPkbTest.php tests/Feature/PkbPriceLockTest.php
git commit -m "test: add end-to-end coverage for PKB vehicle year and price locking"
```
