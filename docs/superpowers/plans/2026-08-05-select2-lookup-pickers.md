# Server-Side Select2 Lookup Pickers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task — this plan touches auth-critical lookup permission gating (moving from module-specific `.create` checks to entity `.view` checks) across 6+ files and deletes 3 existing endpoints, so per the project's process preference it runs through the subagent review loop rather than inline execution. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the "fetch every record, dump into a plain `<select>`" pattern for Customer, Mechanic, and Sparepart pickers with Select2 AJAX search (minimum 3 characters), backed by one shared `LookupController` and one shared JS helper, across 7 touch points in 6 modules.

**Architecture:** One new `LookupController` (3 actions: `customers`, `mechanics`, `spareparts`, each supporting a `q` search mode and an `ids[]` exact-resolve mode) replaces 4 existing per-module lookup methods. One new JS file (`public/js/select2-ajax-picker.js`, two functions: `initAjaxSelect` for search behavior + auto-fill, `preselectAjaxOption` for showing an already-selected value without a full option list) replaces the "fetch everything, `fillSelect()`" half of each module's existing line-item JS — the dynamic-row-adding, form-submission, and other module-specific JS is untouched.

**Tech Stack:** Laravel 8 (`^8.75` — pinned, no post-8.x `Request` helpers), jQuery 3.7.x + Select2 4.1.x via CDN (new dependency, loaded only on the 11 views that need it, never in `layouts/app.blade.php`), Bootstrap 5.

Design spec: `docs/superpowers/specs/2026-08-05-select2-lookup-pickers-design.md`.

## Global Constraints

- jQuery + Select2 loaded via CDN **only** in views that use them (never in `layouts/app.blade.php`) — matches the existing Chart.js-on-Dashboard-only precedent.
- Permission gating on the new shared endpoints is the entity's own `.view` permission, **not** the calling module's `.create` permission: `customer.view` (global), `mechanic.view` (global), `sparepart.view` (branch-scoped via `hasPermissionToInBranch`). This is a deliberate read/write authorization split — the real write-authorization check for actually submitting a PKB/receipt/adjustment/transfer is completely unaffected, still happens at that document's own submission-time `authorize()` call.
- `q` search requires 3+ characters (checked server-side too, not just client-side) — under 3 characters returns `[]`, never an error.
- `ids[]` is a second, mutually-exclusive query mode on the same 3 endpoints (no separate routes) — resolves specific records by id regardless of length, for pre-selecting an already-known value (edit-page initial state, create-page validation-error replay). An id outside the caller's branch/permission scope is silently excluded from the result, not a 403.
- Sparepart response `id` is `sparepart_branch_id` (matches Work Order, Goods Receipt, Stock Adjustment line storage) but **also** includes a `sparepart_id` field, because `StockTransferLine` stores the bare `sparepart_id` — Stock Transfer's line-item JS reads `.sparepart_id` instead of `.id` from the same response shape.
- Response shape: `{id, text, ...extras}` for every entity — `text` is what Select2 displays (`code — name` for spareparts, matching the existing convention already used in this app's Kartu Stok tab).
- `LIKE` search terms are escaped via `addcslashes($term, '%_\\')` before use — matches the fix already applied to Customer list search in Foundation v3.
- Every list/index endpoint continues to use `->simplePaginate()` where applicable — not touched by this plan (no index/list pages are in scope here, only create/edit form pickers).
- Full TDD: write the failing test first, confirm the failure reason, implement, confirm green.

---

### Task 1: Shared `LookupController` — customers, mechanics, spareparts (`q` + `ids[]` modes)

**Files:**
- Create: `app/Http/Controllers/LookupController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/LookupControllerTest.php`

**Interfaces:**
- Produces: routes `GET /lookup/customers?q=&branch_id=&ids[]=`, `GET /lookup/mechanics?q=&branch_id=&ids[]=`, `GET /lookup/spareparts?q=&branch_id=&ids[]=` (branch_id required for spareparts). Response shape: customers/mechanics → `[{id, text}]`; spareparts → `[{id, sparepart_id, text, code, selling_price, available_qty}]`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/LookupControllerTest.php`:

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
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LookupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermission(string $code): User
    {
        $user = User::factory()->create();
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        return User::find($user->id);
    }

    protected function userWithBranchPermission(string $code, Branch $branch): User
    {
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        return User::find($user->id);
    }

    // --- customers() ---

    public function test_customers_returns_matches_for_a_three_character_query(): void
    {
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        $user = $this->userWithPermission('customer.view');

        $response = $this->actingAs($user)->getJson('/lookup/customers?q=Bud');

        $response->assertOk();
        $response->assertJsonFragment(['text' => 'Budi Santoso']);
        $response->assertJsonMissing(['text' => 'Siti Aminah']);
    }

    public function test_customers_returns_empty_for_less_than_three_characters(): void
    {
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $user = $this->userWithPermission('customer.view');

        $response = $this->actingAs($user)->getJson('/lookup/customers?q=Bu');

        $response->assertOk();
        $response->assertExactJson([]);
    }

    public function test_customers_is_forbidden_without_customer_view_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/lookup/customers?q=Bud')->assertForbidden();
    }

    public function test_customers_filters_by_branch_id_when_given(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $inBranchA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $inBranchB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Wijaya', 'stnk_name' => 'Budi Wijaya']);
        CustomerBranch::create(['customer_id' => $inBranchA->id, 'branch_id' => $branchA->id]);
        CustomerBranch::create(['customer_id' => $inBranchB->id, 'branch_id' => $branchB->id]);
        $user = $this->userWithPermission('customer.view');

        $response = $this->actingAs($user)->getJson("/lookup/customers?q=Bud&branch_id={$branchA->id}");

        $response->assertOk();
        $response->assertJsonFragment(['text' => 'Budi Santoso']);
        $response->assertJsonMissing(['text' => 'Budi Wijaya']);
    }

    public function test_customers_ignores_branch_id_when_absent(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $inBranchA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $unassigned = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Wijaya', 'stnk_name' => 'Budi Wijaya']);
        CustomerBranch::create(['customer_id' => $inBranchA->id, 'branch_id' => $branchA->id]);
        $user = $this->userWithPermission('customer.view');

        $response = $this->actingAs($user)->getJson('/lookup/customers?q=Bud');

        $response->assertOk();
        $response->assertJsonFragment(['text' => 'Budi Santoso']);
        $response->assertJsonFragment(['text' => 'Budi Wijaya']);
    }

    public function test_customers_ids_mode_resolves_specific_records_regardless_of_length(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Al', 'stnk_name' => 'Al']);
        $user = $this->userWithPermission('customer.view');

        $response = $this->actingAs($user)->getJson("/lookup/customers?ids[]={$customer->id}");

        $response->assertOk();
        $response->assertJsonFragment(['id' => $customer->id, 'text' => 'Al']);
    }

    // --- mechanics() ---

    public function test_mechanics_returns_matches_and_is_forbidden_without_permission(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermission('mechanic.view');

        $this->actingAs($user)->getJson('/lookup/mechanics?q=Agu')->assertOk()
            ->assertJsonFragment(['text' => 'Agus Setiawan']);

        $noPermUser = User::factory()->create();
        $this->actingAs($noPermUser)->getJson('/lookup/mechanics?q=Agu')->assertForbidden();
    }

    public function test_mechanics_filters_by_branch_id_when_given(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $inBranchA = Mechanic::create(['name' => 'Agus Setiawan']);
        $inBranchB = Mechanic::create(['name' => 'Agus Wibowo']);
        MechanicBranch::create(['mechanic_id' => $inBranchA->id, 'branch_id' => $branchA->id]);
        MechanicBranch::create(['mechanic_id' => $inBranchB->id, 'branch_id' => $branchB->id]);
        $user = $this->userWithPermission('mechanic.view');

        $response = $this->actingAs($user)->getJson("/lookup/mechanics?q=Agu&branch_id={$branchA->id}");

        $response->assertOk();
        $response->assertJsonFragment(['text' => 'Agus Setiawan']);
        $response->assertJsonMissing(['text' => 'Agus Wibowo']);
    }

    // --- spareparts() ---

    public function test_spareparts_requires_branch_id(): void
    {
        $user = $this->userWithPermission('mechanic.view');

        $this->actingAs($user)->getJson('/lookup/spareparts?q=Oli')->assertStatus(400);
    }

    public function test_spareparts_returns_matches_scoped_to_branch_with_full_shape(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'SP-OLI-001', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create([
            'sparepart_id' => $sparepart->id, 'branch_id' => $branch->id,
            'selling_price' => 45000, 'minimum_stock' => 10,
        ]);
        $user = $this->userWithBranchPermission('sparepart.view', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/spareparts?q=Oli&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonFragment([
            'id' => $sparepartBranch->id,
            'sparepart_id' => $sparepart->id,
            'text' => 'SP-OLI-001 — Oli Mesin',
            'code' => 'SP-OLI-001',
            'selling_price' => 45000.0,
            'available_qty' => 0.0,
        ]);
    }

    public function test_spareparts_is_forbidden_without_sparepart_view_in_that_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/lookup/spareparts?q=Oli&branch_id={$branch->id}")->assertForbidden();
    }

    public function test_spareparts_ids_mode_resolves_multiple_records(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartA = Sparepart::create(['code' => 'SP-A', 'name' => 'Sparepart A']);
        $sparepartB = Sparepart::create(['code' => 'SP-B', 'name' => 'Sparepart B']);
        $sbA = SparepartBranch::create(['sparepart_id' => $sparepartA->id, 'branch_id' => $branch->id, 'selling_price' => 10000]);
        $sbB = SparepartBranch::create(['sparepart_id' => $sparepartB->id, 'branch_id' => $branch->id, 'selling_price' => 20000]);
        $user = $this->userWithBranchPermission('sparepart.view', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/spareparts?ids[]={$sbA->id}&ids[]={$sbB->id}&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['id' => $sbA->id, 'code' => 'SP-A']);
        $response->assertJsonFragment(['id' => $sbB->id, 'code' => 'SP-B']);
    }

    public function test_spareparts_ids_mode_silently_excludes_ids_outside_branch_scope(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepart = Sparepart::create(['code' => 'SP-A', 'name' => 'Sparepart A']);
        $sbInBranchB = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchB->id, 'selling_price' => 10000]);
        $user = $this->userWithBranchPermission('sparepart.view', $branchA);

        $response = $this->actingAs($user)->getJson("/lookup/spareparts?ids[]={$sbInBranchB->id}&branch_id={$branchA->id}");

        $response->assertOk();
        $response->assertExactJson([]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=LookupControllerTest`
Expected: FAIL — route/controller don't exist.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/LookupController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\SparepartBranch;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    public function customers(Request $request)
    {
        $this->authorize('customer.view');

        $query = Customer::where('is_active', true);
        $ids = array_map('intval', (array) $request->query('ids', []));

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        } else {
            $term = $this->searchTerm($request);
            if ($term === null) {
                return response()->json([]);
            }
            $query->where('name', 'like', '%' . addcslashes($term, '%_\\') . '%');
        }

        if ($branchId = $request->query('branch_id')) {
            $query->whereHas('customerBranches', function ($inner) use ($branchId) {
                $inner->where('branch_id', $branchId)->where('is_active', true);
            });
        }

        return response()->json(
            $query->orderBy('name')->limit(20)->get()
                ->map(fn (Customer $customer) => ['id' => $customer->id, 'text' => $customer->name])
                ->values()
        );
    }

    public function mechanics(Request $request)
    {
        $this->authorize('mechanic.view');

        $query = Mechanic::where('is_active', true);
        $ids = array_map('intval', (array) $request->query('ids', []));

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        } else {
            $term = $this->searchTerm($request);
            if ($term === null) {
                return response()->json([]);
            }
            $query->where('name', 'like', '%' . addcslashes($term, '%_\\') . '%');
        }

        if ($branchId = $request->query('branch_id')) {
            $query->whereHas('mechanicBranches', function ($inner) use ($branchId) {
                $inner->where('branch_id', $branchId)->where('is_active', true);
            });
        }

        return response()->json(
            $query->orderBy('name')->limit(20)->get()
                ->map(fn (Mechanic $mechanic) => ['id' => $mechanic->id, 'text' => $mechanic->name])
                ->values()
        );
    }

    public function spareparts(Request $request)
    {
        $branchId = (int) $request->query('branch_id');
        abort_if($branchId <= 0, 400, 'branch_id is required.');
        abort_unless(auth()->user()->hasPermissionToInBranch('sparepart.view', $branchId), 403);

        $query = SparepartBranch::with(['sparepart', 'stock'])
            ->where('branch_id', $branchId)
            ->where('is_active', true);
        $ids = array_map('intval', (array) $request->query('ids', []));

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        } else {
            $term = $this->searchTerm($request);
            if ($term === null) {
                return response()->json([]);
            }
            $escaped = addcslashes($term, '%_\\');
            $query->whereHas('sparepart', function ($inner) use ($escaped) {
                $inner->where('name', 'like', "%{$escaped}%")->orWhere('code', 'like', "%{$escaped}%");
            });
        }

        return response()->json(
            $query->get()
                ->sortBy(fn (SparepartBranch $sb) => $sb->sparepart->name)
                ->take(20)
                ->map(function (SparepartBranch $sb) {
                    return [
                        'id' => $sb->id,
                        'sparepart_id' => $sb->sparepart_id,
                        'text' => $sb->sparepart->code . ' — ' . $sb->sparepart->name,
                        'code' => $sb->sparepart->code,
                        'selling_price' => (float) $sb->selling_price,
                        'available_qty' => (float) $sb->stock->available_qty,
                    ];
                })
                ->values()
        );
    }

    private function searchTerm(Request $request): ?string
    {
        $q = $request->query('q');
        if (! is_string($q)) {
            return null;
        }
        $q = trim($q);

        return mb_strlen($q) >= 3 ? $q : null;
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\LookupController;
```

Add this route group inside the `Route::middleware(['auth'])->group(...)` block, right after `Route::get('/stock-card', ...)` and before the `work-orders` group:

```php
    Route::prefix('lookup')->name('lookup.')->group(function () {
        Route::get('/customers', [LookupController::class, 'customers'])->name('customers');
        Route::get('/mechanics', [LookupController::class, 'mechanics'])->name('mechanics');
        Route::get('/spareparts', [LookupController::class, 'spareparts'])->name('spareparts');
    });
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=LookupControllerTest`
Expected: PASS, 14/14.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/LookupController.php routes/web.php tests/Feature/LookupControllerTest.php
git commit -m "feat: add shared LookupController for customer/mechanic/sparepart search"
```

---

### Task 2: Pilot — Work Order (PKB): shared JS helper + all 3 pickers, create & edit

**Files:**
- Create: `public/js/select2-ajax-picker.js`
- Modify: `resources/views/work-orders/create.blade.php`
- Modify: `resources/views/work-orders/edit.blade.php`
- Modify: `resources/views/work-orders/_line_item_scripts.blade.php`
- Modify: `app/Http/Controllers/WorkOrderController.php` (`edit()` method)
- Modify: `app/Http/Controllers/WorkOrderLookupController.php` (remove `customersByBranch`, `mechanicsByBranch`, `sparepartsByBranch`; keep `vehiclesByCustomer`)
- Modify: `routes/web.php` (remove the 3 old lookup routes, keep `lookup.vehicles`)
- Modify: `tests/Feature/WorkOrderLookupTest.php` (remove tests for the 3 deleted endpoints, keep vehicle tests)
- Test: `tests/Feature/WorkOrderManagementTest.php` (existing file — add coverage that create/edit pages load the new JS/CSS and render the picker containers; the actual search/select behavior has no PHP-testable surface since it's client-side JS, covered by manual verification in Task 8)

**Interfaces:**
- Consumes: `LookupController` routes (Task 1): `/lookup/customers`, `/lookup/mechanics`, `/lookup/spareparts`.
- Produces: `window.initAjaxSelect(el, opts)`, `window.preselectAjaxOption(el, opts)` (global functions, used by every later task in this plan).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/WorkOrderManagementTest.php` (new methods, inside the existing class):

```php
    public function test_create_page_loads_select2_and_lookup_js(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = $this->userWithBranchPermission('pkb.create', $branch);

        $response = $this->actingAs($user)->get('/work-orders/create');

        $response->assertOk();
        $response->assertSee('select2', false);
        $response->assertSee('select2-ajax-picker.js', false);
        $response->assertSee('id="customerSelect"', false);
        $response->assertSee('id="mechanicSelect"', false);
    }

    public function test_edit_page_loads_select2_and_lookup_js(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = $this->userWithBranchPermission('pkb.create', $branch);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        \App\Models\CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        \App\Models\MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $category = \App\Models\VehicleCategory::create(['name' => 'Motor']);
        $brand = \App\Models\VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $type = \App\Models\VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);
        $vehicle = \App\Models\Vehicle::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 ABC']);
        $workOrder = \App\Models\WorkOrder::create([
            'number' => 'PKB-TEST-0001', 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id, 'mechanic_id' => $mechanic->id, 'work_order_date' => now(),
        ]);

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}/edit");

        $response->assertOk();
        $response->assertSee('select2', false);
        $response->assertSee('select2-ajax-picker.js', false);
    }
```

(This test file's `use` imports and a `userWithBranchPermission` helper already exist per the project's established test pattern — confirm the exact helper name/signature already in `WorkOrderManagementTest.php` and reuse it verbatim; if the file already imports `Customer`/`Mechanic`/`Branch` at the top, don't re-add duplicate `use` statements — this step only adds two new test methods to the existing class.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=WorkOrderManagementTest`
Expected: the 2 new tests FAIL (no Select2/JS file referenced yet).

- [ ] **Step 3: Write the shared JS helper**

`public/js/select2-ajax-picker.js`:

```js
function initAjaxSelect(el, options) {
    const opts = Object.assign({ endpoint: '', extraParams: function () { return {}; }, placeholder: '-- Cari --', onSelect: null }, options);
    const $el = $(el);
    $el.select2({
        placeholder: opts.placeholder,
        allowClear: true,
        minimumInputLength: 3,
        width: '100%',
        language: {
            inputTooShort: function () { return 'Ketik minimal 3 huruf...'; },
            searching: function () { return 'Mencari...'; },
            noResults: function () { return 'Tidak ditemukan.'; },
        },
        ajax: {
            url: opts.endpoint,
            delay: 300,
            data: function (params) {
                return Object.assign({ q: params.term }, opts.extraParams());
            },
            processResults: function (data) {
                return { results: data };
            },
        },
    });
    if (opts.onSelect) {
        $el.on('select2:select', function (e) {
            opts.onSelect(e.params.data);
        });
    }
    return $el;
}

async function preselectAjaxOption(el, options) {
    const opts = Object.assign({ endpoint: '', id: null, extraParams: function () { return {}; } }, options);
    if (!opts.id) return null;
    const params = new URLSearchParams(Object.assign({ 'ids[]': opts.id }, opts.extraParams()));
    const response = await fetch(`${opts.endpoint}?${params}`, { headers: { Accept: 'application/json' } });
    const items = await response.json();
    const item = items[0];
    if (!item) return null;
    const option = new Option(item.text, item.id, true, true);
    $(el).append(option);
    return item;
}
```

- [ ] **Step 4: Load jQuery/Select2 and the new helper in the PKB views**

In `resources/views/work-orders/create.blade.php`, add right before the `@push('scripts')` block that already exists near the bottom of the file (i.e., as a new `@push('scripts')` block placed before the existing one, since `@push` appends in order and the helper must load before it's used):

```blade
    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    @endpush
```

Add the identical block to `resources/views/work-orders/edit.blade.php` in the same position (before its existing `@push('scripts')` block).

- [ ] **Step 5: Wire the Customer and Mechanic pickers in `create.blade.php`**

In `resources/views/work-orders/create.blade.php`, replace the entire IIFE script body (the `(function () { ... })();` block currently handling `branchSelect`/`customerSelect`/`mechanicSelect`/`vehicleSelect`) with:

```blade
    @push('scripts')
    <script>
    (function () {
        const branchSelect = document.getElementById('branchSelect');
        const customerSelect = document.getElementById('customerSelect');
        const vehicleSelect = document.getElementById('vehicleSelect');
        const mechanicSelect = document.getElementById('mechanicSelect');
        const addSparepartButton = document.getElementById('addSparepartLine');
        let currentBranchId = branchSelect.value || null;
        window.currentWorkOrderBranchId = currentBranchId;

        function initPickers() {
            initAjaxSelect(customerSelect, {
                endpoint: '{{ route('lookup.customers') }}',
                extraParams: function () { return { branch_id: currentBranchId }; },
                placeholder: '-- Pilih Customer --',
            });
            initAjaxSelect(mechanicSelect, {
                endpoint: '{{ route('lookup.mechanics') }}',
                extraParams: function () { return { branch_id: currentBranchId }; },
                placeholder: '-- Pilih Mekanik --',
            });
        }

        function destroyPickers() {
            if ($(customerSelect).data('select2')) $(customerSelect).select2('destroy');
            if ($(mechanicSelect).data('select2')) $(mechanicSelect).select2('destroy');
        }

        branchSelect.addEventListener('change', function () {
            currentBranchId = this.value || null;
            window.currentWorkOrderBranchId = currentBranchId;
            destroyPickers();
            customerSelect.innerHTML = '<option value=""></option>';
            mechanicSelect.innerHTML = '<option value=""></option>';
            WorkOrderLineItems.fillSelect(vehicleSelect, [], '-- Pilih Customer Dulu --', 'id', function (i) { return i.plate_number; });
            vehicleSelect.disabled = true;
            if (!currentBranchId) {
                customerSelect.disabled = true;
                mechanicSelect.disabled = true;
                addSparepartButton.disabled = true;
                initPickers();
                return;
            }
            customerSelect.disabled = false;
            mechanicSelect.disabled = false;
            addSparepartButton.disabled = false;
            initPickers();
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

        // Select2 replaces the native <select>'s change semantics with its own
        // jQuery events; customerSelect's cascade to vehicles must still fire on
        // a Select2-driven selection, so re-trigger the native listener via jQuery.
        $(customerSelect).on('select2:select select2:clear', function () {
            customerSelect.dispatchEvent(new Event('change'));
        });

        async function replayOldLines() {
            const oldServices = @json(old('services', []));
            oldServices.forEach(function (line) {
                WorkOrderLineItems.addServiceLine();
                const rows = document.querySelectorAll('#serviceLines .service-line');
                const row = rows[rows.length - 1];
                if (line.service_catalog_id) row.querySelector('.service-catalog-select').value = line.service_catalog_id;
                row.querySelector('.service-description').value = line.description || '';
                row.querySelector('.service-qty').value = line.qty || '';
                row.querySelector('.service-unit-price').value = line.unit_price || '';
            });

            const oldSpareparts = @json(old('spareparts', []));
            for (const line of oldSpareparts) {
                WorkOrderLineItems.addSparepartLine(currentBranchId);
                const rows = document.querySelectorAll('#sparepartLines .sparepart-line');
                const row = rows[rows.length - 1];
                row.querySelector('.sparepart-qty').value = line.qty || '';
                row.querySelector('.sparepart-unit-price').value = line.unit_price || '';
                if (line.sparepart_branch_id) {
                    await WorkOrderLineItems.preselectSparepartLine(row, line.sparepart_branch_id, currentBranchId);
                }
            }

            const oldCustomerId = @json(old('customer_id'));
            if (oldCustomerId) {
                await preselectAjaxOption(customerSelect, { endpoint: '{{ route('lookup.customers') }}', id: oldCustomerId, extraParams: function () { return { branch_id: currentBranchId }; } });
                $(customerSelect).trigger('change');
            }
            const oldMechanicId = @json(old('mechanic_id'));
            if (oldMechanicId) {
                await preselectAjaxOption(mechanicSelect, { endpoint: '{{ route('lookup.mechanics') }}', id: oldMechanicId, extraParams: function () { return { branch_id: currentBranchId }; } });
            }
        }

        if (branchSelect.value) {
            customerSelect.disabled = false;
            mechanicSelect.disabled = false;
            addSparepartButton.disabled = false;
            initPickers();
            replayOldLines().then(async function () {
                const oldCustomerId = @json(old('customer_id'));
                if (oldCustomerId) {
                    const vehicles = await WorkOrderLineItems.fetchJson(`/work-orders/lookup/vehicles/${oldCustomerId}`);
                    WorkOrderLineItems.fillSelect(vehicleSelect, vehicles, '-- Pilih Kendaraan --', 'id', function (i) { return i.plate_number || i.frame_number; });
                    vehicleSelect.disabled = false;
                    const oldVehicleId = @json(old('vehicle_id'));
                    if (oldVehicleId) vehicleSelect.value = oldVehicleId;
                }
            });
        } else {
            customerSelect.disabled = true;
            mechanicSelect.disabled = true;
            addSparepartButton.disabled = true;
            initPickers();
            replayOldLines();
        }
    })();
    </script>
    @endpush
```

- [ ] **Step 6: Wire the Sparepart line-item picker in `_line_item_scripts.blade.php`**

In `resources/views/work-orders/_line_item_scripts.blade.php`, replace the `addSparepartLine` function and the `window.WorkOrderLineItems` export with:

```blade
    function addSparepartLine(branchId) {
        const template = document.getElementById('sparepartLineTemplate');
        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector('.sparepart-line');
        const index = sparepartLineCount++;
        const select = wrapper.querySelector('.sparepart-select');
        select.name = `spareparts[${index}][sparepart_branch_id]`;
        wrapper.querySelector('.sparepart-qty').name = `spareparts[${index}][qty]`;
        wrapper.querySelector('.sparepart-unit-price').name = `spareparts[${index}][unit_price]`;
        wrapper.querySelector('.remove-line').addEventListener('click', function () {
            if ($(select).data('select2')) $(select).select2('destroy');
            wrapper.remove();
        });
        document.getElementById('sparepartLines').appendChild(wrapper);

        initAjaxSelect(select, {
            endpoint: '{{ route('lookup.spareparts') }}',
            extraParams: function () { return { branch_id: branchId }; },
            placeholder: '-- Pilih Sparepart --',
            onSelect: function (item) {
                const row = select.closest('.sparepart-line');
                row.querySelector('.sparepart-unit-price').value = item.selling_price;
                row.querySelector('.sparepart-availability').textContent = 'Stok tersedia: ' + item.available_qty;
            },
        });

        return wrapper;
    }

    async function preselectSparepartLine(row, sparepartBranchId, branchId) {
        const select = row.querySelector('.sparepart-select');
        const item = await preselectAjaxOption(select, {
            endpoint: '{{ route('lookup.spareparts') }}',
            id: sparepartBranchId,
            extraParams: function () { return { branch_id: branchId }; },
        });
        if (item) {
            row.querySelector('.sparepart-unit-price').value = item.selling_price;
            row.querySelector('.sparepart-availability').textContent = 'Stok tersedia: ' + item.available_qty;
        }
        $(select).trigger('change');
    }

    document.getElementById('addServiceLine').addEventListener('click', addServiceLine);
    document.getElementById('addSparepartLine').addEventListener('click', function () {
        addSparepartLine(window.currentWorkOrderBranchId || null);
    });

    window.WorkOrderLineItems = {
        addServiceLine: addServiceLine,
        addSparepartLine: addSparepartLine,
        preselectSparepartLine: preselectSparepartLine,
        fetchJson: fetchJson,
        fillSelect: fillSelect,
    };
```

Remove the now-unused `setSparepartOptions` function and the `sparepartOptionsCache` variable entirely from this file (the sparepart `<select>` no longer holds a pre-fetched option list — each row fetches its own results on demand via Select2).

`resources/views/work-orders/create.blade.php`'s inline script (Step 5) already sets `window.currentWorkOrderBranchId` both at initialization and inside `branchSelect`'s `change` listener, so the "+ Tambah Sparepart" click handler registered in this file (`_line_item_scripts.blade.php`, Step 6 above) always reads the current branch correctly, including on the very first click before any `change` event has fired (e.g. when `old('branch_id')` pre-selected a branch on validation-error reload).

- [ ] **Step 7: Wire the pickers in `edit.blade.php`**

In `resources/views/work-orders/edit.blade.php`, replace the entire inline script IIFE with:

```blade
    @push('scripts')
    <script>
    (function () {
        const customerSelect = document.getElementById('customerSelect');
        const vehicleSelect = document.getElementById('vehicleSelect');
        const mechanicSelect = document.getElementById('mechanicSelect');
        const branchId = {{ $workOrder->branch_id }};
        window.currentWorkOrderBranchId = branchId;

        initAjaxSelect(customerSelect, {
            endpoint: '{{ route('lookup.customers') }}',
            extraParams: function () { return { branch_id: branchId }; },
            placeholder: '-- Pilih Customer --',
        });
        initAjaxSelect(mechanicSelect, {
            endpoint: '{{ route('lookup.mechanics') }}',
            extraParams: function () { return { branch_id: branchId }; },
            placeholder: '-- Pilih Mekanik --',
        });
        preselectAjaxOption(customerSelect, {
            endpoint: '{{ route('lookup.customers') }}',
            id: {{ $workOrder->customer_id }},
            extraParams: function () { return { branch_id: branchId }; },
        }).then(function () { $(customerSelect).trigger('change'); });
        preselectAjaxOption(mechanicSelect, {
            endpoint: '{{ route('lookup.mechanics') }}',
            id: {{ $workOrder->mechanic_id }},
            extraParams: function () { return { branch_id: branchId }; },
        }).then(function () { $(mechanicSelect).trigger('change'); });

        const existingServiceLines = @json($existingServiceLines);
        existingServiceLines.forEach(function (line) {
            WorkOrderLineItems.addServiceLine();
            const rows = document.querySelectorAll('#serviceLines .service-line');
            const row = rows[rows.length - 1];
            if (line.service_catalog_id) row.querySelector('.service-catalog-select').value = line.service_catalog_id;
            row.querySelector('.service-description').value = line.description;
            row.querySelector('.service-qty').value = line.qty;
            row.querySelector('.service-unit-price').value = line.unit_price;
        });

        const existingSparepartLines = @json($existingSparepartLines);
        existingSparepartLines.forEach(function (line) {
            const row = WorkOrderLineItems.addSparepartLine(branchId);
            row.querySelector('.sparepart-qty').value = line.qty;
            row.querySelector('.sparepart-unit-price').value = line.unit_price;
            WorkOrderLineItems.preselectSparepartLine(row, line.sparepart_branch_id, branchId);
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
```

Replace the `<select name="customer_id" ...>` and `<select name="mechanic_id" ...>` elements at the top of `edit.blade.php` (currently full `@foreach` loops) with empty selects matching the pattern already used elsewhere in this file's `<select name="vehicle_id">` — i.e.:

```blade
                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <select name="customer_id" id="customerSelect" class="form-select @error('customer_id') is-invalid @enderror" required></select>
                        @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
```
```blade
                    <div class="col-md-3">
                        <label class="form-label">Mekanik</label>
                        <select name="mechanic_id" id="mechanicSelect" class="form-select @error('mechanic_id') is-invalid @enderror" required></select>
                        @error('mechanic_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
```

(The `vehicle_id` select stays exactly as-is — Vehicle is out of scope for this plan, it keeps its full `@foreach ($vehicles as $vehicle)` server-rendered loop.)

- [ ] **Step 8: Simplify `WorkOrderController::edit()`**

In `app/Http/Controllers/WorkOrderController.php`, the `$customers`/`$mechanics` full-list-building blocks (lines 178-190 per the plan's research) are no longer needed by the view (Select2 preselect replaces the "build full list + push if missing" pattern). Replace:

```php
        $customers = Customer::whereHas('customerBranches', function ($query) use ($workOrder) {
            $query->where('branch_id', $workOrder->branch_id)->where('is_active', true);
        })->where('is_active', true)->orderBy('name')->get();
        if ($workOrder->customer && ! $customers->contains('id', $workOrder->customer->id)) {
            $customers->push($workOrder->customer);
        }

        $mechanics = Mechanic::whereHas('mechanicBranches', function ($query) use ($workOrder) {
            $query->where('branch_id', $workOrder->branch_id)->where('is_active', true);
        })->where('is_active', true)->orderBy('name')->get();
        if ($workOrder->mechanic && ! $mechanics->contains('id', $workOrder->mechanic->id)) {
            $mechanics->push($workOrder->mechanic);
        }
```

with nothing (delete these two blocks entirely). Remove `'customers'` and `'mechanics'` from the `compact(...)` call in this method's `return view(...)` line. Also remove the now-unused `$sparepartBranches` variable and its "missing ids" backfill block (lines 192-204 per the research — it was already unused by the view even before this change, a pre-existing dead-code leftover; safe to delete since it has no output consumer) — but **keep** `$sparepartOptionsForEdit` (still used, feeds nothing now since the view no longer calls `setSparepartOptions`, so also remove `$sparepartOptionsForEdit`'s computation block and its entry in `compact(...)`, since `existingSparepartLines` alone is now sufficient — the view resolves each line's display text itself via `preselectSparepartLine`).

Also remove the `Customer` and `Mechanic` model imports from this controller if they become unused after this edit (check remaining usages in the file first — `Customer` is likely still used elsewhere for `$workOrder->customer` type hints or not at all as a bare import; only remove the `use` statement if grep confirms zero remaining references to the bare class name in this file).

- [ ] **Step 9: Remove the 3 deleted lookup methods and routes**

In `app/Http/Controllers/WorkOrderLookupController.php`, delete the `customersByBranch()`, `mechanicsByBranch()`, and `sparepartsByBranch()` methods entirely. Keep `vehiclesByCustomer()` unchanged. Remove now-unused `Customer`, `Mechanic`, `SparepartBranch` imports from this file if `vehiclesByCustomer()` doesn't reference them (it only uses `Customer` — keep that import, remove `Mechanic` and `SparepartBranch`).

In `routes/web.php`, inside the `work-orders` prefix group, remove these 3 lines:

```php
        Route::get('/lookup/customers/{branch}', [WorkOrderLookupController::class, 'customersByBranch'])->name('lookup.customers');
        Route::get('/lookup/mechanics/{branch}', [WorkOrderLookupController::class, 'mechanicsByBranch'])->name('lookup.mechanics');
        Route::get('/lookup/spareparts/{branch}', [WorkOrderLookupController::class, 'sparepartsByBranch'])->name('lookup.spareparts');
```

Keep `Route::get('/lookup/vehicles/{customer}', [WorkOrderLookupController::class, 'vehiclesByCustomer'])->name('lookup.vehicles');` unchanged.

- [ ] **Step 10: Update `WorkOrderLookupTest.php`**

Open `tests/Feature/WorkOrderLookupTest.php`. Remove every test method that calls `/work-orders/lookup/customers/...`, `/work-orders/lookup/mechanics/...`, or `/work-orders/lookup/spareparts/...` (per the plan's research, these are lines referencing those three URL patterns — remove the entire `public function test_...(): void { ... }` block for each). Keep every test method that calls `/work-orders/lookup/vehicles/...` unchanged. If the file's class-level `use` imports or helper methods (like a local `userWithPermissions`) become entirely unused after removing those tests, leave them — do not chase unrelated cleanup in this step, only remove what directly tested the 3 deleted endpoints.

- [ ] **Step 11: Run tests to verify they pass**

Run: `php artisan test --filter=WorkOrderManagementTest`
Expected: PASS, including the 2 new tests from Step 1.

Run: `php artisan test --filter=WorkOrderLookupTest`
Expected: PASS (only vehicle-lookup tests remain).

Run: `php artisan test --filter=LookupControllerTest`
Expected: still PASS, 14/14 (unaffected by this task).

- [ ] **Step 12: Commit**

```bash
git add public/js/select2-ajax-picker.js \
        resources/views/work-orders/create.blade.php \
        resources/views/work-orders/edit.blade.php \
        resources/views/work-orders/_line_item_scripts.blade.php \
        app/Http/Controllers/WorkOrderController.php \
        app/Http/Controllers/WorkOrderLookupController.php \
        routes/web.php \
        tests/Feature/WorkOrderLookupTest.php \
        tests/Feature/WorkOrderManagementTest.php
git commit -m "feat: convert PKB customer/mechanic/sparepart pickers to Select2 AJAX search"
```

---

### Task 3: Vehicle form — Customer picker

**Files:**
- Modify: `resources/views/vehicles/_form.blade.php`
- Modify: `resources/views/vehicles/create.blade.php`
- Modify: `resources/views/vehicles/edit.blade.php`
- Modify: `app/Http/Controllers/VehicleController.php` (`create()` and `edit()` — stop passing the full `$customers` collection)
- Test: `tests/Feature/VehicleManagementTest.php`

**Interfaces:**
- Consumes: `LookupController::customers()` (Task 1, no `branch_id` — Vehicle's customer picker is deliberately scope-free, matching current behavior), `initAjaxSelect`/`preselectAjaxOption` (Task 2).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/VehicleManagementTest.php` (new methods):

```php
    public function test_create_page_loads_select2_for_customer_picker(): void
    {
        $user = $this->userWithPermissions(['vehicle.create']);

        $response = $this->actingAs($user)->get('/vehicles/create');

        $response->assertOk();
        $response->assertSee('select2-ajax-picker.js', false);
        $response->assertSee('id="customer_id"', false);
    }

    public function test_edit_page_preselects_existing_customer(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 ABC',
        ]);
        $user = $this->userWithPermissions(['vehicle.edit']);

        $response = $this->actingAs($user)->get("/vehicles/{$vehicle->id}/edit");

        $response->assertOk();
        $response->assertSee('select2-ajax-picker.js', false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=VehicleManagementTest`
Expected: the 2 new tests FAIL.

- [ ] **Step 3: Update `VehicleController`**

In `app/Http/Controllers/VehicleController.php`, in both `create()` and `edit()`, remove the line that builds `$customers` (`Customer::where('is_active', true)->orderBy('name')->get()`) and remove `'customers'` from each method's `compact(...)`/`return view(...)` call. `create()` keeps passing `$selectedCustomerId` unchanged (still used to preselect from the `?customer_id=` query param, per the existing Kendaraan-tab "Tambah Kendaraan" link). `edit()`'s `$vehicle->customer_id` is already available via the `$vehicle` model binding, no new variable needed.

- [ ] **Step 4: Update `_form.blade.php`**

In `resources/views/vehicles/_form.blade.php`, replace:

```blade
<div class="mb-3">
    <label for="customer_id" class="form-label">Customer</label>
    <select name="customer_id" id="customer_id" class="form-select @error('customer_id') is-invalid @enderror" required>
        <option value="">-- Pilih Customer --</option>
        @foreach ($customers as $customer)
            <option value="{{ $customer->id }}" {{ (int) old('customer_id', $vehicle->customer_id ?? $selectedCustomerId) === $customer->id ? 'selected' : '' }}>
                {{ $customer->name }}
            </option>
        @endforeach
    </select>
    @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
```

with:

```blade
<div class="mb-3">
    <label for="customer_id" class="form-label">Customer</label>
    <select name="customer_id" id="customer_id" class="form-select @error('customer_id') is-invalid @enderror" required></select>
    @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
```

At the bottom of this same file, inside its existing `@push('scripts')` block (the one already there for the category/brand/type cascading dropdowns), add jQuery/Select2 CDN tags (same as Task 2 Step 4) plus initialization:

```blade
@push('scripts')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
<script>
(function () {
    const customerSelect = document.getElementById('customer_id');
    initAjaxSelect(customerSelect, {
        endpoint: '{{ route('lookup.customers') }}',
        placeholder: '-- Pilih Customer --',
    });
    const existingCustomerId = {{ (int) old('customer_id', $vehicle->customer_id ?? $selectedCustomerId ?? 0) }};
    if (existingCustomerId) {
        preselectAjaxOption(customerSelect, { endpoint: '{{ route('lookup.customers') }}', id: existingCustomerId })
            .then(function () { $(customerSelect).trigger('change'); });
    }
})();
</script>
@endpush
```

(This is a **new, separate** `@push('scripts')` block placed physically before the file's existing cascading-dropdown script block, since `@push` content renders in declaration order and the category/brand/type script doesn't depend on Select2 — order between them doesn't functionally matter here, but keep this new block first for readability, matching the pattern of "dependencies load before their consumers" used in Task 2.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=VehicleManagementTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/views/vehicles/_form.blade.php app/Http/Controllers/VehicleController.php tests/Feature/VehicleManagementTest.php
git commit -m "feat: convert Vehicle form's customer picker to Select2 AJAX search"
```

---

### Task 4: Goods Receipt — Sparepart line-item picker

**Files:**
- Modify: `resources/views/goods-receipts/create.blade.php`
- Modify: `resources/views/goods-receipts/edit.blade.php`
- Modify: `resources/views/goods-receipts/_line_item_scripts.blade.php`
- Modify: `app/Http/Controllers/GoodsReceiptController.php` (remove `sparepartsByBranch()`; simplify `edit()`'s sparepart-options building)
- Modify: `routes/web.php` (remove `goods-receipts.lookup.spareparts`)
- Modify: `tests/Feature/GoodsReceiptManagementTest.php` (remove the 2 tests for the deleted endpoint)
- Test: `tests/Feature/GoodsReceiptManagementTest.php` (add create/edit page Select2 coverage, same shape as Task 2/3)

**Interfaces:**
- Consumes: `LookupController::spareparts()` (Task 1), `initAjaxSelect`/`preselectAjaxOption` (Task 2).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/GoodsReceiptManagementTest.php` (new methods; remove the two existing `test_lookup_spareparts_by_branch_...` methods identified in this plan's research as part of this same step, since they test the endpoint this task deletes):

```php
    public function test_create_page_loads_select2_for_sparepart_picker(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = $this->userWithBranchPermission('receipt.create', $branch);

        $response = $this->actingAs($user)->get('/goods-receipts/create');

        $response->assertOk();
        $response->assertSee('select2-ajax-picker.js', false);
    }
```

Remove `test_lookup_spareparts_by_branch_returns_only_active_configs_for_that_branch` and `test_lookup_spareparts_by_branch_is_forbidden_without_receipt_create_permission` from this same file (they assert against `/goods-receipts/lookup/spareparts/{branch}`, which this task deletes).

- [ ] **Step 2: Run tests to verify the new test fails**

Run: `php artisan test --filter=GoodsReceiptManagementTest`
Expected: the new test FAILs; the two removed tests are gone from the run entirely (not failing, just absent).

- [ ] **Step 3: Update `GoodsReceiptController`**

In `app/Http/Controllers/GoodsReceiptController.php`, delete the `sparepartsByBranch()` method entirely. In `edit()`, replace the `$sparepartBranches`-building block and `$sparepartOptions` computation (per this plan's research, lines 100-110) with nothing — the view no longer needs a pre-fetched options list, only `$existingLines` (unchanged, still needed to drive `preselectAjaxOption` per line). Remove `'sparepartOptions'` from the `compact(...)` call; keep `'goodsReceipt'` and `'existingLines'`.

- [ ] **Step 4: Update routes**

In `routes/web.php`, remove this line from the `goods-receipts` prefix group:

```php
        Route::get('/lookup/spareparts/{branch}', [GoodsReceiptController::class, 'sparepartsByBranch'])->name('lookup.spareparts');
```

- [ ] **Step 5: Wire Select2 in `create.blade.php`, `edit.blade.php`, `_line_item_scripts.blade.php`**

Following the exact same shape as Task 2 Steps 4-7, adapted to this module's variable/function names (`GoodsReceiptLineItems` instead of `WorkOrderLineItems`, no customer/mechanic pickers — sparepart only, `branchSelect`/`currentBranchId` pattern identical to PKB's since Goods Receipt's create form also gates line-adding on a branch selection the same way):

- Add the jQuery/Select2/helper `<script>`/`<link>` tags (Task 2 Step 4's block) to both `create.blade.php` and `edit.blade.php`.
- In `create.blade.php`'s branch-change handler, remove the `fetchJson('/goods-receipts/lookup/spareparts/${branchId}')` + `GoodsReceiptLineItems.setSparepartOptions(...)` call; the add-sparepart-line button's enable/disable-on-branch-selection logic stays, just without pre-fetching.
- In `_line_item_scripts.blade.php`, replace the sparepart-select population inside `addLine()` (or whatever this file's line-add function is named per the research — confirm exact name from the file before editing) with an `initAjaxSelect()` call mirroring Task 2 Step 6's `addSparepartLine()`, using `endpoint: '{{ route('lookup.spareparts') }}'`, `extraParams: () => ({ branch_id: branchId })`, and an `onSelect` that fills whatever unit-price-equivalent field this module's line row has (confirm the exact CSS class name from the file — likely `.purchase-price` given `GoodsReceiptLine`'s `purchase_price` field seen in this plan's research). Add a `preselectLine(row, sparepartBranchId, branchId)` function mirroring Task 2's `preselectSparepartLine`, exported on the module's `window.GoodsReceiptLineItems` object.
- In `edit.blade.php`, replace the `setSparepartOptions`+`row.querySelector('.sparepart-select').value = line.sparepart_branch_id` pattern with the `addLine()` + `preselectLine()` pair, mirroring Task 2 Step 7's edit-page wiring (no customer/mechanic pickers here, only the sparepart line loop over `$existingLines`).
- In `create.blade.php`'s validation-error replay logic, apply the same `preselectSparepartLine`-during-replay pattern as Task 2 Step 5's PKB replay, adapted to this module's `old('lines', [])` shape (confirm the exact `old()` key name from the file's current replay code before editing — per this plan's research it's `old('lines', [])`, not `old('spareparts', [])` like PKB).

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=GoodsReceiptManagementTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/views/goods-receipts/ app/Http/Controllers/GoodsReceiptController.php routes/web.php tests/Feature/GoodsReceiptManagementTest.php
git commit -m "feat: convert Goods Receipt sparepart picker to Select2 AJAX search"
```

---

### Task 5: Stock Adjustment — Sparepart line-item picker

**Files:**
- Modify: `resources/views/stock-adjustments/create.blade.php`
- Modify: `resources/views/stock-adjustments/edit.blade.php`
- Modify: `resources/views/stock-adjustments/_line_item_scripts.blade.php`
- Modify: `app/Http/Controllers/StockAdjustmentController.php` (remove `sparepartsByBranch()`; simplify `edit()`)
- Modify: `routes/web.php` (remove `stock-adjustments.lookup.spareparts`)
- Test: `tests/Feature/StockAdjustmentManagementTest.php`

**Interfaces:**
- Consumes: `LookupController::spareparts()` (Task 1), `initAjaxSelect`/`preselectAjaxOption` (Task 2).

**Important module-specific detail** (confirmed by this plan's research): this module's JS uses `select.dispatchEvent(new Event('change'))` (a **native** DOM event) after programmatically setting `.value` on the sparepart select, to drive a readonly `.stock-adjustment-system-qty` field from `option.dataset.onHandQty`. Select2 does not react to native `dispatchEvent(new Event('change'))` — it needs jQuery's `.trigger('change')` instead. Every place this module's existing code fires that native event on the sparepart select must change to `$(select).trigger('change')`, and the system-qty auto-fill itself must move into the `onSelect` callback (reading `item.on_hand_qty` from the AJAX response) exactly like Goods Receipt's price auto-fill in Task 4 — it can no longer read `option.dataset.onHandQty` since Select2-generated options don't carry that dataset the old `fillSelect()` used to set.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/StockAdjustmentManagementTest.php`:

```php
    public function test_create_page_loads_select2_for_sparepart_picker(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = $this->userWithBranchPermission('stock_adjustment.create', $branch);

        $response = $this->actingAs($user)->get('/stock-adjustments/create');

        $response->assertOk();
        $response->assertSee('select2-ajax-picker.js', false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StockAdjustmentManagementTest`
Expected: FAIL.

- [ ] **Step 3: Update `StockAdjustmentController`**

Delete `sparepartsByBranch()`. In `edit()`, remove the `$sparepartBranches`/`$sparepartOptions` building block (per this plan's research, lines 104-114, which included `on_hand_qty`); keep `$existingLines`. Remove `'sparepartOptions'` from `compact(...)`.

- [ ] **Step 4: Update routes**

Remove `Route::get('/lookup/spareparts/{branch}', [StockAdjustmentController::class, 'sparepartsByBranch'])->name('lookup.spareparts');` from the `stock-adjustments` prefix group in `routes/web.php`.

- [ ] **Step 5: Wire Select2**

Same shape as Task 4 Step 5, adapted to this module's names (`StockAdjustmentLineItems`), with the critical addition from this task's header note: replace every `dispatchEvent(new Event('change'))` on the sparepart select with `$(select).trigger('change')`, and move the `.stock-adjustment-system-qty` auto-fill into the `onSelect` callback reading `item.on_hand_qty` (the `spareparts()` lookup endpoint from Task 1 already returns `available_qty`, not `on_hand_qty` — this module needs `on_hand_qty` specifically per its existing behavior, so **add `on_hand_qty` to `LookupController::spareparts()`'s response** as part of this task, sourced the same way the deleted `sparepartsByBranch()` method did: `(float) $sb->stock->on_hand_qty`). This is a small addition to Task 1's endpoint — update `LookupController::spareparts()`'s response array to include `'on_hand_qty' => (float) $sb->stock->on_hand_qty,` alongside the existing fields, and add one assertion for it to `LookupControllerTest::test_spareparts_returns_matches_scoped_to_branch_with_full_shape` (re-run `php artisan test --filter=LookupControllerTest` after this addition to confirm it's still green with the extra field).

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=StockAdjustmentManagementTest`
Expected: PASS.

Run: `php artisan test --filter=LookupControllerTest`
Expected: PASS (still 14/14, now asserting the extra `on_hand_qty` field too).

- [ ] **Step 7: Commit**

```bash
git add resources/views/stock-adjustments/ app/Http/Controllers/StockAdjustmentController.php \
        app/Http/Controllers/LookupController.php routes/web.php \
        tests/Feature/StockAdjustmentManagementTest.php tests/Feature/LookupControllerTest.php
git commit -m "feat: convert Stock Adjustment sparepart picker to Select2 AJAX search"
```

---

### Task 6: Stock Transfer — Sparepart line-item picker

**Files:**
- Modify: `resources/views/stock-transfers/create.blade.php`
- Modify: `resources/views/stock-transfers/edit.blade.php`
- Modify: `resources/views/stock-transfers/_line_item_scripts.blade.php`
- Modify: `app/Http/Controllers/StockTransferController.php` (remove `sparepartsByBranch()`; simplify `edit()`)
- Modify: `routes/web.php` (remove `stock-transfers.lookup.spareparts`)
- Test: `tests/Feature/StockTransferManagementTest.php`

**Interfaces:**
- Consumes: `LookupController::spareparts()` (Task 1), `initAjaxSelect`/`preselectAjaxOption` (Task 2).

**Important module-specific detail** (per this plan's Global Constraints and research): `StockTransferLine` stores a bare `sparepart_id`, not `sparepart_branch_id`. This module's line-item `<select>` must use `.sparepart_id` from the AJAX response (not `.id`) both for the field's submitted `value` and for `preselectAjaxOption`'s resolve call. Concretely: the `<select>` element's `name` stays `spareparts[${index}][sparepart_id]` (already the case today), but Select2 itself still tracks/searches by `id` (`sparepart_branch_id`) for display purposes — the `onSelect` callback must write `item.sparepart_id` into a **hidden** input alongside the visible Select2 (since a single `<select>` can only submit one value, and that value needs to be `sparepart_branch_id` for Select2's own bookkeeping to stay consistent with the lookup endpoint's `id` field). Concretely, add a hidden `<input type="hidden" class="sparepart-id-hidden">` sibling to each sparepart-line's visible `<select>`, named `spareparts[${index}][sparepart_id]`; the visible `<select>` itself becomes unnamed (no `name` attribute — it's purely the Select2 UI, its value is `sparepart_branch_id` and is never submitted). `onSelect` sets the hidden input's value to `item.sparepart_id`. `preselectAjaxOption`'s resolve-by-id call still uses `sparepart_branch_id` (the `id` field) as before — but this module's `$existingLines`/`old()` data only has `sparepart_id` (the bare identity, since that's what's stored), not `sparepart_branch_id`, so **the `ids[]` resolve call for this module must pass `sparepart_id` values, and `LookupController::spareparts()`'s `ids[]` mode needs to also match against the bare `sparepart_id` column, not only the `SparepartBranch` primary key** — extend Task 1's `ids[]` handling: `$query->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('sparepart_id', $ids))` so a caller can resolve by either identifier depending on which one it already has.

- [ ] **Step 1: Extend `LookupController::spareparts()`'s `ids[]` mode to also match `sparepart_id`**

In `app/Http/Controllers/LookupController.php`, change the `ids[]` branch of `spareparts()` from:

```php
        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        } else {
```

to:

```php
        if (! empty($ids)) {
            $query->where(function ($inner) use ($ids) {
                $inner->whereIn('id', $ids)->orWhereIn('sparepart_id', $ids);
            });
        } else {
```

Add a test to `tests/Feature/LookupControllerTest.php`:

```php
    public function test_spareparts_ids_mode_also_matches_by_bare_sparepart_id(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'SP-A', 'name' => 'Sparepart A']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 10000]);
        $user = $this->userWithBranchPermission('sparepart.view', $branch);

        $response = $this->actingAs($user)->getJson("/lookup/spareparts?ids[]={$sparepart->id}&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonFragment(['id' => $sparepartBranch->id, 'sparepart_id' => $sparepart->id]);
    }
```

Run: `php artisan test --filter=LookupControllerTest` — Expected: PASS, 16/16 (14 from Task 1 + 1 from Task 5 + this one).

- [ ] **Step 2: Write the failing test for Stock Transfer's create page**

Add to `tests/Feature/StockTransferManagementTest.php`:

```php
    public function test_create_page_loads_select2_for_sparepart_picker(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = $this->userWithBranchPermission('stock_transfer.create', $branch);

        $response = $this->actingAs($user)->get('/stock-transfers/create');

        $response->assertOk();
        $response->assertSee('select2-ajax-picker.js', false);
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=StockTransferManagementTest`
Expected: FAIL.

- [ ] **Step 4: Update `StockTransferController`**

Delete `sparepartsByBranch()`. In `edit()`, remove the `$spareparts`/`$sparepartOptions` building block (per this plan's research, lines 114-121); keep `$existingLines`. Remove `'sparepartOptions'` from `compact(...)`.

- [ ] **Step 5: Update routes**

Remove `Route::get('/lookup/spareparts/{branch}', [StockTransferController::class, 'sparepartsByBranch'])->name('lookup.spareparts');` from the `stock-transfers` prefix group in `routes/web.php`.

- [ ] **Step 6: Wire Select2 with the hidden-input `sparepart_id` pattern**

In `resources/views/stock-transfers/_line_item_scripts.blade.php`'s line template, add a hidden input sibling to the sparepart select and remove the `name` attribute from the visible select itself (it becomes Select2-only UI, never submitted directly):

```blade
<select class="form-select sparepart-select">
    <option value="">-- Pilih Sparepart --</option>
</select>
<input type="hidden" class="sparepart-id-hidden">
```

In the line-add function, set both elements' names when a row is created:

```js
select.removeAttribute('name'); // Select2 UI only, sparepart_branch_id is never submitted
wrapper.querySelector('.sparepart-id-hidden').name = `spareparts[${index}][sparepart_id]`;
```

Wire `initAjaxSelect` with `onSelect` writing to the hidden input instead of the qty/price auto-fill Goods Receipt/Stock Adjustment have (Stock Transfer lines have no price field per this plan's research — only `qty`):

```js
initAjaxSelect(select, {
    endpoint: '{{ route('lookup.spareparts') }}',
    extraParams: function () { return { branch_id: fromBranchId }; },
    placeholder: '-- Pilih Sparepart --',
    onSelect: function (item) {
        wrapper.querySelector('.sparepart-id-hidden').value = item.sparepart_id;
    },
});
```

(`fromBranchId` — confirm the exact variable name this module's create-page script already uses for the source branch, per this plan's research it's driven by `fromBranchSelect`; the search must scope to the *source* branch since that's whose stock is being moved out, not the destination.)

For `preselectAjaxOption` on this module's lines (edit-page load and create-page validation replay), pass the line's stored `sparepart_id` as the `id` parameter to `preselectAjaxOption` — Step 1's `ids[]` extension makes this resolve correctly even though the endpoint's primary `id` field is `sparepart_branch_id`. After preselecting, also set the hidden input directly from the known `sparepart_id` (no need to wait for the async resolve to complete for this part, since the value is already known): `wrapper.querySelector('.sparepart-id-hidden').value = line.sparepart_id;`.

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=StockTransferManagementTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/LookupController.php tests/Feature/LookupControllerTest.php \
        resources/views/stock-transfers/ app/Http/Controllers/StockTransferController.php routes/web.php \
        tests/Feature/StockTransferManagementTest.php
git commit -m "feat: convert Stock Transfer sparepart picker to Select2 AJAX search"
```

---

### Task 7: Sparepart Master "Tambah dari Cabang Lain" — Sparepart picker

**Files:**
- Modify: `resources/views/sparepart-branches/create-existing.blade.php`
- Modify: `app/Http/Controllers/SparepartBranchController.php` (`createExisting()` — stop passing the full `$availableSpareparts` collection)
- Test: `tests/Feature/SparepartBranchIndexAndCreateTest.php`

**Interfaces:**
- Consumes: `LookupController::spareparts()` (Task 1) — **but this screen picks from `Sparepart`s NOT yet configured for the current branch**, which the shared endpoint (built to search *configured* `SparepartBranch` rows) cannot express as-is. This screen therefore does **not** reuse `LookupController::spareparts()` directly — it needs its own small search endpoint on `SparepartBranchController`, since "spareparts excluded from this branch" is a query shape unique to this one screen (not shared with any other module in this plan's scope).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/SparepartBranchIndexAndCreateTest.php`:

```php
    public function test_create_existing_page_loads_select2(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = $this->userWithBranchPermission('sparepart.create', $branch);
        session(['current_sparepart_branch_id' => $branch->id]);

        $response = $this->actingAs($user)->get('/sparepart-branches/create-existing');

        $response->assertOk();
        $response->assertSee('select2-ajax-picker.js', false);
    }

    public function test_lookup_unconfigured_spareparts_excludes_already_configured_ones(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $configured = Sparepart::create(['code' => 'SP-CFG', 'name' => 'Sparepart Configured']);
        SparepartBranch::create(['sparepart_id' => $configured->id, 'branch_id' => $branch->id, 'selling_price' => 10000]);
        $unconfigured = Sparepart::create(['code' => 'SP-NEW', 'name' => 'Sparepart Baru']);
        $user = $this->userWithBranchPermission('sparepart.create', $branch);

        $response = $this->actingAs($user)->getJson("/sparepart-branches/lookup/unconfigured?q=Sparepart&branch_id={$branch->id}");

        $response->assertOk();
        $response->assertJsonFragment(['text' => 'SP-NEW — Sparepart Baru']);
        $response->assertJsonMissing(['text' => 'SP-CFG — Sparepart Configured']);
    }

    public function test_lookup_unconfigured_spareparts_is_forbidden_without_sparepart_create_in_that_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/sparepart-branches/lookup/unconfigured?q=Spa&branch_id={$branch->id}")->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest`
Expected: the 3 new tests FAIL.

- [ ] **Step 3: Add the lookup action to `SparepartBranchController`**

In `app/Http/Controllers/SparepartBranchController.php`, add a new method:

```php
    public function lookupUnconfigured(Request $request)
    {
        $branchId = (int) $request->query('branch_id');
        abort_if($branchId <= 0, 400, 'branch_id is required.');
        abort_unless(auth()->user()->hasPermissionToInBranch('sparepart.create', $branchId), 403);

        $q = $request->query('q');
        if (! is_string($q) || mb_strlen(trim($q)) < 3) {
            return response()->json([]);
        }
        $escaped = addcslashes(trim($q), '%_\\');

        $spareparts = Sparepart::where('is_active', true)
            ->whereDoesntHave('sparepartBranches', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->where(function ($inner) use ($escaped) {
                $inner->where('name', 'like', "%{$escaped}%")->orWhere('code', 'like', "%{$escaped}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json(
            $spareparts->map(fn (Sparepart $sparepart) => [
                'id' => $sparepart->id,
                'text' => $sparepart->code . ' — ' . $sparepart->name,
            ])->values()
        );
    }
```

Add `use Illuminate\Http\Request;` to this file's imports if not already present.

- [ ] **Step 4: Add the route**

In `routes/web.php`, add inside the `sparepart-branches` prefix group, near the other `create-existing`/`existing` routes:

```php
        Route::get('/lookup/unconfigured', [SparepartBranchController::class, 'lookupUnconfigured'])->name('lookup.unconfigured');
```

- [ ] **Step 5: Update `createExisting()` and the view**

In `SparepartBranchController::createExisting()`, remove the `$availableSpareparts` query block entirely; keep `$branch`. Update `return view('sparepart-branches.create-existing', compact('branch'));`.

In `resources/views/sparepart-branches/create-existing.blade.php`, replace:

```blade
<div class="mb-3">
    <label for="sparepart_id" class="form-label">Sparepart</label>
    <select name="sparepart_id" id="sparepart_id" class="form-select @error('sparepart_id') is-invalid @enderror" required>
        <option value="">-- Pilih Sparepart --</option>
        @foreach ($availableSpareparts as $sparepart)
            <option value="{{ $sparepart->id }}" {{ (int) old('sparepart_id') === $sparepart->id ? 'selected' : '' }}>
                {{ $sparepart->code }} — {{ $sparepart->name }}
            </option>
        @endforeach
    </select>
    @error('sparepart_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
```

with:

```blade
<div class="mb-3">
    <label for="sparepart_id" class="form-label">Sparepart</label>
    <select name="sparepart_id" id="sparepart_id" class="form-select @error('sparepart_id') is-invalid @enderror" required></select>
    @error('sparepart_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
```

Add a `@push('scripts')` block at the bottom of this view (it currently has no scripts section — confirm this before adding, per this plan's research it's a plain form with no JS):

```blade
@push('scripts')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
<script>
(function () {
    initAjaxSelect(document.getElementById('sparepart_id'), {
        endpoint: '{{ route('sparepart-branches.lookup.unconfigured') }}',
        extraParams: function () { return { branch_id: {{ $branch->id }} }; },
        placeholder: '-- Pilih Sparepart --',
    });
})();
</script>
@endpush
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/SparepartBranchController.php routes/web.php \
        resources/views/sparepart-branches/create-existing.blade.php \
        tests/Feature/SparepartBranchIndexAndCreateTest.php
git commit -m "feat: convert Sparepart 'Tambah dari Cabang Lain' picker to Select2 AJAX search"
```

---

### Task 8: Full-suite verification

**Files:** None (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: PASS, all tests green.

- [ ] **Step 2: Grep for any remaining references to deleted routes**

Run: `grep -rn "lookup/customers/\|lookup/mechanics/\|lookup/spareparts/{branch}" resources/views/ app/ routes/ tests/`
Expected: no matches outside of `vehicles/lookup/brands`/`vehicles/lookup/types` (a different, untouched lookup family) and the Vehicle Reference lookup tests — if anything else matches, it's a leftover reference to a deleted endpoint that needs fixing before this plan is considered done.

- [ ] **Step 3: Manual verification checklist**

1. `php artisan migrate` (no new migrations in this plan, this is just a sanity check nothing broke) then confirm `.env` `DB_DATABASE=laravel`, not `bengkel_testing`, per project memory.
2. Log in as `faiz_rahmat`. Open PKB create, pick a branch, type 1-2 characters into Customer — confirm "Ketik minimal 3 huruf..." shows. Type 3+ characters of an existing demo customer's name — confirm it appears and is selectable. Repeat for Mekanik and a Sparepart line — confirm selecting a sparepart auto-fills unit price and shows available stock.
3. Submit the PKB with a deliberately invalid field (e.g. leave tanggal empty) — confirm the page reloads with the previously-picked customer/mechanic/sparepart lines still shown with correct names, not blank.
4. Open an existing PKB for edit — confirm its customer/mechanic/sparepart lines show with correct names immediately on load (not blank, no manual re-search needed).
5. Repeat steps 2-4's spirit (search, auto-fill, edit-preselect) for Goods Receipt, Stock Adjustment, and Stock Transfer's sparepart pickers.
6. Open Vehicle create/edit — confirm the Customer picker searches instead of showing a giant preloaded list.
7. Open Master Sparepart → "Tambah dari Cabang Lain" — confirm searching only shows spareparts not yet configured for the current branch.
