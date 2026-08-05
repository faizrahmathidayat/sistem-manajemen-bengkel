# Sales Invoice Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user mark a PKB (work order) as done, generate a draft Invoice from it, edit the invoice's discount/PPN before posting, and post it — which deducts physical stock, releases the PKB's reservations, and writes a kartu stok entry.

**Architecture:** Thin Laravel MVC on top of the already-built `App\Services\InvoiceService` (see Design doc). This plan adds: one new `WorkOrder` status transition (`complete`), a full `InvoiceController` (index/show/store/edit/update/post) with its `Policy`/`FormRequest`, three new Blade views, and the routes/sidebar/permission wiring to reach all of it from the browser.

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL 8 (tests run against real MySQL, not sqlite — `phpunit.xml` points `DB_CONNECTION=mysql`, `DB_DATABASE=bengkel_testing`, so DB `CHECK` constraints are enforced during tests too), Blade + Bootstrap 5 (CDN) + jQuery, no SPA/build step.

## Global Constraints

- PHP 7.4.33 — no PHP 8+ syntax (nullsafe `?->`, named args, `match`, enums, constructor promotion).
- No roles table — authorization is direct-to-user via `Gate::before` + `$user->hasPermissionToInBranch('code', $branchId)` / `$user->branchesWithPermission('code')`, exactly as used by every existing Policy/Controller in this codebase.
- Policies are registered manually in `app/Providers/AuthServiceProvider.php`'s `$policies` array — not auto-discovered. Every new Policy in this plan must be added there.
- The permission catalog for this module (`invoice.view`, `invoice.create`, `invoice.edit`, `invoice.post`, `invoice.void`, `invoice.print`, `invoice.email`) and a disabled "Invoice — Segera Hadir" sidebar placeholder were already seeded in the Foundation phase (`database/seeders/MenuPermissionSeeder.php:56-69`, `resources/views/partials/sidebar.blade.php:13-19`). Do not re-seed those seven codes — this plan only adds the one missing `pkb.complete` code and wires the existing sidebar placeholder to a real link.
- **Already implemented in a prior session — do not redo:** `database/migrations/2026_08_05_000003_create_invoices_table.php`, `..._000004_create_invoice_details_table.php`, `App\Models\Invoice`, `App\Models\InvoiceDetail`, `App\Support\InvoiceStatus` (`DRAFT`/`POSTED`/`CANCELLED`), `App\Support\InvoiceDetailItemType` (`SERVICE`/`SPAREPART`), `App\Support\WorkOrderStatus::COMPLETED`, `App\Support\InventoryMovementType::USAGE_OUT`, and `App\Services\InvoiceService::createFromWorkOrder(WorkOrder $wo): Invoice` / `App\Services\InvoiceService::postInvoice(Invoice $invoice): Invoice`. Both service methods throw `\DomainException` on invalid-state calls (not-completed PKB, duplicate invoice, non-draft invoice, insufficient stock) — every controller action that calls them must catch `DomainException` and flash `with('error', $e->getMessage())`, matching how `StockAdjustmentController::post()` already surfaces `$reservationViolations`.
- Any new code that locks `sparepart_branch_stocks` rows must follow the codebase's established deadlock-avoidance convention: lock rows in ascending `sparepart_branch_id` order (see `WorkOrderController::confirm()`/`cancel()`, `GoodsReceiptController::post()`, `StockAdjustmentController::post()`). This plan does not add any new stock-mutating code (that's already in `InvoiceService`), but keep this in mind if a task step needs adjusting during implementation.
- Follow existing file conventions exactly: controllers redirect with `->with('status', ...)` on success and `->with('error', ...)` on a recoverable failure; FormRequests put `authorize()` as `$this->user()->can('ability', $this->route('paramName'))`; Blade status badges use `<span class="status-dot status-active|status-inactive">`; list pages use `partials.list-filter-bar` and `partials.empty-state`.

---

## Task 1: PKB "Tandai Selesai" (mark-as-completed) transition

**Files:**
- Modify: `database/seeders/MenuPermissionSeeder.php:50-51`
- Modify: `app/Policies/WorkOrderPolicy.php`
- Modify: `app/Http/Controllers/WorkOrderController.php`
- Modify: `routes/web.php:134-135`
- Modify: `resources/views/work-orders/show.blade.php`
- Test: `tests/Feature/WorkOrderManagementTest.php`

**Interfaces:**
- Produces: route `PATCH /work-orders/{workOrder}/complete` (name `work-orders.complete`); `WorkOrderPolicy::complete(User $user, WorkOrder $workOrder): bool`; permission code `pkb.complete`. Task 3 relies on a PKB being able to reach `WorkOrderStatus::COMPLETED` through this endpoint.

- [ ] **Step 1: Write the failing tests**

Append these four methods to `tests/Feature/WorkOrderManagementTest.php`, right before the final closing `}` of the class (i.e. immediately after `test_show_hides_override_form_after_shortage_is_overridden`):

```php
    public function test_complete_transitions_open_work_order_to_completed(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $this->assertSame(WorkOrderStatus::OPEN, $workOrder->status);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.complete');

        $response = $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $response->assertRedirect("/work-orders/{$workOrder->id}");
        $this->assertSame(WorkOrderStatus::COMPLETED, $workOrder->fresh()->status);
    }

    public function test_complete_transitions_overridden_shortage_work_order_to_completed(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        // on_hand_qty stays at its default 0, so confirm() below produces SHORTAGE.
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->status);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/override-shortage", ['reason' => 'Customer setuju tunggu part.']);

        $response = $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $response->assertRedirect("/work-orders/{$workOrder->id}");
        $this->assertSame(WorkOrderStatus::COMPLETED, $workOrder->fresh()->status);
    }

    public function test_complete_rejected_when_shortage_not_yet_overridden(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->status);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.complete');

        $response = $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $response->assertForbidden();
        $this->assertSame(WorkOrderStatus::SHORTAGE, $workOrder->fresh()->status);
    }

    public function test_complete_requires_pkb_complete_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $response->assertForbidden();
    }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test --filter=test_complete_ tests/Feature/WorkOrderManagementTest.php`
Expected: FAIL — route `work-orders.complete` does not exist (`RouteNotFoundException` / 404), since neither the route nor the controller method exist yet.

- [ ] **Step 3: Add the `pkb.complete` permission to the seeder**

In `database/seeders/MenuPermissionSeeder.php`, insert a new line between the existing `pkb.confirm` and `pkb.cancel` entries:

```php
                    ['code' => 'pkb.confirm', 'resource' => 'pkb', 'action' => 'confirm', 'description' => 'Mengonfirmasi PKB'],
                    ['code' => 'pkb.complete', 'resource' => 'pkb', 'action' => 'complete', 'description' => 'Menandai PKB selesai dikerjakan'],
                    ['code' => 'pkb.cancel', 'resource' => 'pkb', 'action' => 'cancel', 'description' => 'Membatalkan PKB'],
```

- [ ] **Step 4: Add the `complete` ability to `WorkOrderPolicy`**

In `app/Policies/WorkOrderPolicy.php`, add this method (after `confirm()`, before `overrideShortage()`):

```php
    public function complete(User $user, WorkOrder $workOrder): bool
    {
        $eligible = $workOrder->status === WorkOrderStatus::OPEN
            || ($workOrder->status === WorkOrderStatus::SHORTAGE && ! is_null($workOrder->shortage_overridden_at));

        return $eligible && $user->hasPermissionToInBranch('pkb.complete', $workOrder->branch_id);
    }
```

- [ ] **Step 5: Add `WorkOrderController::complete()`**

In `app/Http/Controllers/WorkOrderController.php`, add this method (after `confirm()`, before `overrideShortage()`):

```php
    public function complete(WorkOrder $workOrder)
    {
        $this->authorize('complete', $workOrder);

        $notEligible = false;

        DB::transaction(function () use ($workOrder, &$notEligible) {
            $fresh = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->first();

            $eligible = $fresh->status === WorkOrderStatus::OPEN
                || ($fresh->status === WorkOrderStatus::SHORTAGE && ! is_null($fresh->shortage_overridden_at));

            if (! $eligible) {
                $notEligible = true;

                return;
            }

            $fresh->status = WorkOrderStatus::COMPLETED;
            $fresh->save();
        });

        if ($notEligible) {
            return redirect()->route('work-orders.show', $workOrder)->with('error', 'PKB belum bisa ditandai selesai. Pastikan PKB sudah dikonfirmasi dan kekurangan stok (jika ada) sudah disetujui.');
        }

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB berhasil ditandai selesai.');
    }
```

- [ ] **Step 6: Add the route**

In `routes/web.php`, inside the `work-orders` group, add a line right after the `confirm` route:

```php
        Route::patch('/{workOrder}/confirm', [WorkOrderController::class, 'confirm'])->name('confirm');
        Route::patch('/{workOrder}/complete', [WorkOrderController::class, 'complete'])->name('complete');
```

- [ ] **Step 7: Add the button and status label to the show view**

In `resources/views/work-orders/show.blade.php`, add the "Tandai Selesai" button right after the "Konfirmasi" button:

```blade
            @can('confirm', $workOrder)
                <form method="POST" action="{{ route('work-orders.confirm', $workOrder) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Konfirmasi</button>
                </form>
            @endcan
            @can('complete', $workOrder)
                <form method="POST" action="{{ route('work-orders.complete', $workOrder) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Tandai Selesai</button>
                </form>
            @endcan
```

And add a `COMPLETED` branch to the status badge (insert before the final `@else`):

```blade
                        @if ($workOrder->status === \App\Support\WorkOrderStatus::DRAFT)
                            <span class="status-dot status-active">Draft</span>
                        @elseif ($workOrder->status === \App\Support\WorkOrderStatus::OPEN)
                            <span class="status-dot status-active">Dikonfirmasi</span>
                        @elseif ($workOrder->status === \App\Support\WorkOrderStatus::SHORTAGE)
                            <span class="status-dot status-inactive">Kurang Stok</span>
                        @elseif ($workOrder->status === \App\Support\WorkOrderStatus::COMPLETED)
                            <span class="status-dot status-active">Selesai</span>
                        @else
                            <span class="status-dot status-inactive">Dibatalkan</span>
                        @endif
```

- [ ] **Step 8: Run the tests and confirm they pass**

Run: `php artisan test --filter=test_complete_ tests/Feature/WorkOrderManagementTest.php`
Expected: PASS (4 tests).

- [ ] **Step 9: Commit**

```bash
git add database/seeders/MenuPermissionSeeder.php app/Policies/WorkOrderPolicy.php app/Http/Controllers/WorkOrderController.php routes/web.php resources/views/work-orders/show.blade.php tests/Feature/WorkOrderManagementTest.php
git commit -m "feat: add PKB mark-as-completed transition"
```

---

## Task 2: Invoice authorization, index/show views, sidebar wiring

**Files:**
- Create: `app/Policies/InvoicePolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Create: `app/Http/Controllers/InvoiceController.php` (index, show only — store/edit/update/post added in later tasks)
- Modify: `routes/web.php` (new `invoices` group, index+show only)
- Create: `resources/views/invoices/index.blade.php`
- Create: `resources/views/invoices/show.blade.php`
- Create: `resources/views/invoices/no-access.blade.php`
- Modify: `resources/views/partials/sidebar.blade.php:13-19`
- Modify: `tests/Feature/AppShellTest.php`
- Create: `tests/Feature/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `App\Services\InvoiceService::createFromWorkOrder(WorkOrder $wo): Invoice` (already built) — used only by this task's test helper, not by production code yet.
- Produces: `InvoicePolicy::view/create/update/post`; routes `invoices.index` (`GET /invoices`), `invoices.show` (`GET /invoices/{invoice}`); test helpers `grantBranchPermission()`, `makeWorkOrder(Branch $branch, bool $complete = true): WorkOrder`, `makeInvoice(Branch $branch): Invoice` in `InvoiceControllerTest`, which Tasks 3-5 extend.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/InvoiceControllerTest.php`:

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
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceControllerTest extends TestCase
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

    // Mirrors WorkOrderManagementTest::makeScenario()+baseStorePayload()+confirmWorkOrder(), extended
    // with an optional final "complete" step since this file's tests need PKBs all the way through
    // to COMPLETED (to create invoices from), not just OPEN/SHORTAGE.
    protected function makeWorkOrder(Branch $branch, bool $complete = true): WorkOrder
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

        if ($complete) {
            $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        }

        return $workOrder->fresh();
    }

    protected function makeInvoice(Branch $branch): Invoice
    {
        return (new InvoiceService())->createFromWorkOrder($this->makeWorkOrder($branch));
    }

    public function test_show_displays_invoice_header_and_snapshot_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee($invoice->number);
        $response->assertSee('Ganti Oli');
        $response->assertSee('Oli Mesin');
    }

    public function test_show_is_forbidden_without_invoice_view_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertForbidden();
    }

    public function test_index_lists_invoices_for_permitted_branch_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $invoiceA = $this->makeInvoice($branchA);
        $invoiceB = $this->makeInvoice($branchB);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'invoice.view');

        $response = $this->actingAs($user)->get('/invoices');

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_shows_no_access_view_without_any_invoice_view_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/invoices');

        $response->assertOk();
        $response->assertSee('Anda belum memiliki akses invoice');
    }
}
```

Also append this method to `tests/Feature/AppShellTest.php`, right after `test_sidebar_shows_reporting_placeholder_when_user_has_report_pkb_view_permission` (it reuses that test's already-imported `Branch`/`Permission`/`User`/`UserBranchPermission`/`UserBranchService`):

```php
    public function test_sidebar_links_directly_to_invoices_when_permitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'invoice.view', 'resource' => 'invoice', 'action' => 'view', 'description' => 'Melihat invoice']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('invoices.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test tests/Feature/InvoiceControllerTest.php --filter=test_sidebar_links_directly_to_invoices_when_permitted`
Expected: FAIL — `InvoiceControllerTest` fails because `InvoiceService::createFromWorkOrder` calls into routes (`/work-orders`, `/work-orders/{id}/complete`) that exist after Task 1, but `route('invoices.index')` doesn't exist yet (`RouteNotFoundException`).

- [ ] **Step 3: Create `InvoicePolicy`**

Create `app/Policies/InvoicePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\InvoiceStatus;
use App\Support\WorkOrderStatus;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->hasPermissionToInBranch('invoice.view', $invoice->branch_id);
    }

    public function create(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->status === WorkOrderStatus::COMPLETED
            && $user->hasPermissionToInBranch('invoice.create', $workOrder->branch_id);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::DRAFT
            && $user->hasPermissionToInBranch('invoice.edit', $invoice->branch_id);
    }

    public function post(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::DRAFT
            && $user->hasPermissionToInBranch('invoice.post', $invoice->branch_id);
    }
}
```

- [ ] **Step 4: Register the policy**

In `app/Providers/AuthServiceProvider.php`, add a line to the `$policies` array right after the `StockTransfer` entry:

```php
        \App\Models\StockTransfer::class => \App\Policies\StockTransferPolicy::class,
        \App\Models\Invoice::class => \App\Policies\InvoicePolicy::class,
```

- [ ] **Step 5: Create `InvoiceController` (index + show)**

Create `app/Http/Controllers/InvoiceController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;

class InvoiceController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('invoice.view');

        if ($permittedBranches->isEmpty()) {
            return view('invoices.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $invoices = Invoice::with(['branch', 'customer'])
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($search, function ($query, $q) {
                $query->where('number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('invoices.index', compact('invoices'))
            ->with('branches', $permittedBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $invoice->load(['branch', 'customer', 'workOrder', 'details']);

        return view('invoices.show', compact('invoice'));
    }
}
```

- [ ] **Step 6: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\InvoiceController;` to the alphabetized `use` block (between `GoodsReceiptController` and `LookupController`), and add a new route group right after the `stock-transfers` group closes (before the `users` group):

```php
    Route::prefix('invoices')->name('invoices.')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('show');
    });
```

- [ ] **Step 7: Create the views**

Create `resources/views/invoices/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Invoice')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-receipt me-2"></i>Invoice</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari nomor invoice...',
        'searchValue' => $search,
        'branchFilterBranches' => $branches,
        'branchFilterSelected' => $selectedBranchIds,
        'actionsHtml' => '',
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
                        <th>Total</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr>
                            <td><code>{{ $invoice->number }}</code></td>
                            <td>{{ $invoice->branch->name }}</td>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                            <td>{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                            <td>
                                @if ($invoice->status === \App\Support\InvoiceStatus::DRAFT)
                                    <span class="status-dot status-active">Draft</span>
                                @elseif ($invoice->status === \App\Support\InvoiceStatus::POSTED)
                                    <span class="status-dot status-active">Diposting</span>
                                @else
                                    <span class="status-dot status-inactive">Dibatalkan</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-receipt',
                                    'title' => 'Belum ada invoice',
                                    'description' => 'Invoice dibuat dari PKB yang sudah ditandai selesai.',
                                    'ctaVisible' => false,
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $invoices->links() }}
    </div>
@endsection
```

Create `resources/views/invoices/show.blade.php` (the empty `d-flex gap-2` action bar is intentional — Tasks 4 and 5 each add one button into it):

```blade
@extends('layouts.app')
@section('title', 'Detail Invoice')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-receipt me-2"></i>{{ $invoice->number }}</h1>
        <div class="d-flex gap-2">
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><strong>Cabang</strong><div>{{ $invoice->branch->name }}</div></div>
                <div class="col-md-3"><strong>Customer</strong><div>{{ $invoice->customer->name }}</div></div>
                <div class="col-md-3"><strong>PKB</strong><div>{{ $invoice->workOrder->number }}</div></div>
                <div class="col-md-3"><strong>Tanggal</strong><div>{{ $invoice->invoice_date->format('d/m/Y') }}</div></div>
                <div class="col-md-3">
                    <strong>Status</strong>
                    <div>
                        @if ($invoice->status === \App\Support\InvoiceStatus::DRAFT)
                            <span class="status-dot status-active">Draft</span>
                        @elseif ($invoice->status === \App\Support\InvoiceStatus::POSTED)
                            <span class="status-dot status-active">Diposting</span>
                        @else
                            <span class="status-dot status-inactive">Dibatalkan</span>
                        @endif
                    </div>
                </div>
                <div class="col-md-12"><strong>Catatan</strong><div>{{ $invoice->notes ?? '-' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Baris Invoice</h2>
            <table class="table table-sm">
                <thead><tr><th>Tipe</th><th>Kode</th><th>Deskripsi</th><th>Qty</th><th>Harga</th><th>Total</th></tr></thead>
                <tbody>
                    @forelse ($invoice->details as $detail)
                        <tr>
                            <td>{{ $detail->item_type === \App\Support\InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart' }}</td>
                            <td><code>{{ $detail->item_code_snapshot ?? '-' }}</code></td>
                            <td>{{ $detail->description }}</td>
                            <td>{{ number_format($detail->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                            <td>{{ number_format($detail->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">Tidak ada baris invoice.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Ringkasan</h2>
            <div class="row g-2">
                <div class="col-md-3"><strong>Subtotal Jasa</strong><div>{{ number_format($invoice->subtotal_service, 0, ',', '.') }}</div></div>
                <div class="col-md-3"><strong>Subtotal Sparepart</strong><div>{{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}</div></div>
                <div class="col-md-3"><strong>Diskon ({{ number_format($invoice->discount_percent, 2, ',', '.') }}%)</strong><div>{{ number_format($invoice->discount_amount, 0, ',', '.') }}</div></div>
                <div class="col-md-3"><strong>PPN ({{ number_format($invoice->tax_percent, 2, ',', '.') }}%)</strong><div>{{ number_format($invoice->tax_amount, 0, ',', '.') }}</div></div>
                <div class="col-md-3"><strong>Grand Total</strong><div>{{ number_format($invoice->grand_total, 0, ',', '.') }}</div></div>
            </div>
        </div>
    </div>

    <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary btn-sm">Kembali</a>
@endsection
```

Create `resources/views/invoices/no-access.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Invoice')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-receipt me-2"></i>Invoice</h1>
    </div>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            Anda belum memiliki akses invoice di cabang manapun. Hubungi admin untuk meminta akses.
        </div>
    </div>
@endsection
```

- [ ] **Step 8: Wire up the sidebar placeholder**

In `resources/views/partials/sidebar.blade.php`, replace the disabled Invoice placeholder:

```blade
        @if ($user->branchesWithPermission('invoice.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-receipt me-2"></i> Invoice
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
```

with:

```blade
        @if ($user->branchesWithPermission('invoice.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('invoices.index') }}" class="nav-link {{ request()->routeIs('invoices.*') ? 'active' : '' }}">
                <i class="bi bi-receipt me-2"></i> Invoice
            </a>
        </li>
        @endif
```

- [ ] **Step 9: Run the tests and confirm they pass**

Run: `php artisan test tests/Feature/InvoiceControllerTest.php tests/Feature/AppShellTest.php`
Expected: PASS. Also run the full suite once here to catch any regression from the sidebar change: `php artisan test` — expected: all pass (this is the first task that touches a file, `sidebar.blade.php`, rendered on every authenticated page).

- [ ] **Step 10: Commit**

```bash
git add app/Policies/InvoicePolicy.php app/Providers/AuthServiceProvider.php app/Http/Controllers/InvoiceController.php routes/web.php resources/views/invoices resources/views/partials/sidebar.blade.php tests/Feature/InvoiceControllerTest.php tests/Feature/AppShellTest.php
git commit -m "feat: add Invoice authorization, list, and detail views"
```

---

## Task 3: Create draft Invoice from a completed PKB

**Files:**
- Modify: `app/Http/Controllers/InvoiceController.php`
- Modify: `routes/web.php` (invoices group)
- Modify: `app/Http/Controllers/WorkOrderController.php` (`show()` eager-load)
- Modify: `resources/views/work-orders/show.blade.php`
- Modify: `tests/Feature/InvoiceControllerTest.php`
- Modify: `tests/Feature/WorkOrderManagementTest.php`

**Interfaces:**
- Consumes: `App\Services\InvoiceService::createFromWorkOrder()` (already built); `InvoicePolicy::create()` and route `invoices.show` (Task 2).
- Produces: route `invoices.store` (`POST /invoices`, body `work_order_id`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/InvoiceControllerTest.php`, right before the final closing `}`:

```php
    public function test_store_creates_draft_invoice_from_completed_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);

        $invoice = Invoice::latest('id')->first();
        $response->assertRedirect("/invoices/{$invoice->id}");
        $this->assertSame(\App\Support\InvoiceStatus::DRAFT, $invoice->status);
        $this->assertSame($workOrder->id, $invoice->work_order_id);
        $this->assertCount(2, $invoice->details);
    }

    public function test_store_rejects_work_order_that_is_not_completed(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch, false);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);

        $response->assertForbidden();
        $this->assertSame(0, Invoice::count());
    }

    public function test_store_rejects_when_work_order_already_has_an_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        (new InvoiceService())->createFromWorkOrder($workOrder);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);

        $response->assertRedirect(route('work-orders.show', $workOrder));
        $response->assertSessionHas('error');
        $this->assertSame(1, Invoice::count());
    }

    public function test_store_requires_invoice_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);

        $response->assertForbidden();
    }
```

Also add `use App\Services\InvoiceService;` to the `use` block at the top of `tests/Feature/WorkOrderManagementTest.php` (alphabetized, right after `use App\Models\WorkOrder;`), then append these two methods right before that file's final closing `}` (i.e. after `test_complete_requires_pkb_complete_permission`, added in Task 1):

```php
    public function test_show_offers_create_invoice_button_when_completed_without_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.complete');
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee('Buat Invoice');
    }

    public function test_show_links_to_existing_invoice_instead_of_create_button(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $scenario['sparepartBranch']->id)->update(['on_hand_qty' => 10]);
        $workOrder = $this->confirmWorkOrder($branch, $scenario);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.complete');
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        $invoice = (new InvoiceService())->createFromWorkOrder($workOrder->fresh());
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->get("/work-orders/{$workOrder->id}");

        $response->assertOk();
        $response->assertSee($invoice->number);
        $response->assertDontSee('Buat Invoice');
    }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test --filter=test_store_ tests/Feature/InvoiceControllerTest.php` and `php artisan test --filter=test_show_offers_create_invoice_button_when_completed_without_invoice tests/Feature/WorkOrderManagementTest.php`
Expected: FAIL — `invoices.store` route doesn't exist yet; the two `work-orders` tests fail on `assertSee('Buat Invoice')` (text not present).

- [ ] **Step 3: Add `InvoiceController::store()`**

In `app/Http/Controllers/InvoiceController.php`, update the `use` imports:

```php
use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Services\InvoiceService;
use DomainException;
use Illuminate\Http\Request;

class InvoiceController extends Controller
```

Then insert the `store()` method right before `show()`:

```php
    public function store(Request $request)
    {
        $workOrder = WorkOrder::findOrFail($request->input('work_order_id'));
        $this->authorize('create', [Invoice::class, $workOrder]);

        try {
            $invoice = (new InvoiceService())->createFromWorkOrder($workOrder);
        } catch (DomainException $e) {
            return redirect()->route('work-orders.show', $workOrder)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice draft berhasil dibuat.');
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, update the `invoices` group:

```php
    Route::prefix('invoices')->name('invoices.')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::post('/', [InvoiceController::class, 'store'])->name('store');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('show');
    });
```

- [ ] **Step 5: Eager-load the PKB's invoice on the show page**

In `app/Http/Controllers/WorkOrderController.php`, update `show()`'s eager-load list:

```php
        $workOrder->load(['branch', 'customer', 'vehicle', 'mechanic', 'serviceLines', 'sparepartLines.reservations', 'invoice']);
```

- [ ] **Step 6: Add the "Buat Invoice" block to the PKB show view**

In `resources/views/work-orders/show.blade.php`, add this block right after the shortage-override `@if` block, before the final "Kembali" link:

```blade
    @if ($workOrder->status === \App\Support\WorkOrderStatus::COMPLETED)
        <div class="card mb-3">
            <div class="card-body d-flex justify-content-between align-items-center">
                @if ($workOrder->invoice)
                    <div>Invoice: <strong>{{ $workOrder->invoice->number }}</strong></div>
                    <a href="{{ route('invoices.show', $workOrder->invoice) }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-receipt"></i> Lihat Invoice
                    </a>
                @else
                    <div>PKB sudah selesai dan siap diinvoice.</div>
                    @can('create', [\App\Models\Invoice::class, $workOrder])
                        <form method="POST" action="{{ route('invoices.store') }}">
                            @csrf
                            <input type="hidden" name="work_order_id" value="{{ $workOrder->id }}">
                            <button type="submit" class="btn btn-primary btn-sm">Buat Invoice</button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>
    @endif

```

- [ ] **Step 7: Run the tests and confirm they pass**

Run: `php artisan test tests/Feature/InvoiceControllerTest.php tests/Feature/WorkOrderManagementTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/InvoiceController.php routes/web.php app/Http/Controllers/WorkOrderController.php resources/views/work-orders/show.blade.php tests/Feature/InvoiceControllerTest.php tests/Feature/WorkOrderManagementTest.php
git commit -m "feat: create draft Invoice from a completed PKB"
```

---

## Task 4: Edit Invoice discount/PPN before posting

**Files:**
- Create: `app/Http/Requests/UpdateInvoiceRequest.php`
- Modify: `app/Http/Controllers/InvoiceController.php`
- Modify: `routes/web.php` (invoices group)
- Create: `resources/views/invoices/edit.blade.php`
- Modify: `resources/views/invoices/show.blade.php`
- Modify: `tests/Feature/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `InvoicePolicy::update()` (Task 2).
- Produces: routes `invoices.edit` (`GET /invoices/{invoice}/edit`), `invoices.update` (`PUT /invoices/{invoice}`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/InvoiceControllerTest.php`, right before the final closing `}`:

```php
    public function test_update_recalculates_discount_tax_and_grand_total(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        // subtotal_service=50000 (1 x 50000), subtotal_sparepart=120000 (2 x 60000) -> subtotal=170000
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 10,
            'tax_percent' => 11,
            'notes' => 'Diskon member',
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

    public function test_update_rejects_discount_percent_over_100(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 150,
            'tax_percent' => 0,
        ]);

        $response->assertSessionHasErrors('discount_percent');
    }

    public function test_update_is_forbidden_once_invoice_is_posted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        (new InvoiceService())->postInvoice($invoice);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.edit');

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 5,
            'tax_percent' => 11,
        ]);

        $response->assertForbidden();
    }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test --filter=test_update_ tests/Feature/InvoiceControllerTest.php`
Expected: FAIL — `invoices.update` route doesn't exist yet.

- [ ] **Step 3: Create `UpdateInvoiceRequest`**

Create `app/Http/Requests/UpdateInvoiceRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('update', $this->route('invoice'));
    }

    public function rules()
    {
        return [
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_percent' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
```

- [ ] **Step 4: Add `InvoiceController::edit()`/`update()`**

Update the `use` imports at the top of `app/Http/Controllers/InvoiceController.php`:

```php
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Services\InvoiceService;
use App\Support\InvoiceStatus;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
```

Then add `edit()` and `update()` after `show()` (at the end of the class):

```php
    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $invoice->load(['branch', 'customer', 'workOrder', 'details']);

        return view('invoices.show', compact('invoice'));
    }

    public function edit(Invoice $invoice)
    {
        $this->authorize('update', $invoice);

        return view('invoices.edit', compact('invoice'));
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        $data = $request->validated();

        $noLongerDraft = false;

        DB::transaction(function () use ($data, $invoice, &$noLongerDraft) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($fresh->status !== InvoiceStatus::DRAFT) {
                $noLongerDraft = true;

                return;
            }

            $subtotal = (float) $fresh->subtotal_service + (float) $fresh->subtotal_sparepart;
            $discountPercent = (float) $data['discount_percent'];
            $taxPercent = (float) $data['tax_percent'];
            $discountAmount = round($subtotal * $discountPercent / 100, 2);
            $taxableBase = $subtotal - $discountAmount;
            $taxAmount = round($taxableBase * $taxPercent / 100, 2);

            $fresh->update([
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
                'tax_percent' => $taxPercent,
                'tax_amount' => $taxAmount,
                'grand_total' => round($taxableBase + $taxAmount, 2),
                'notes' => $data['notes'] ?? null,
            ]);
        });

        if ($noLongerDraft) {
            return redirect()->route('invoices.show', $invoice)->with('error', 'Invoice sudah tidak berstatus draft, tidak bisa diubah lagi.');
        }

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice berhasil diperbarui.');
    }
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, update the `invoices` group:

```php
    Route::prefix('invoices')->name('invoices.')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::post('/', [InvoiceController::class, 'store'])->name('store');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('show');
        Route::get('/{invoice}/edit', [InvoiceController::class, 'edit'])->name('edit');
        Route::put('/{invoice}', [InvoiceController::class, 'update'])->name('update');
    });
```

- [ ] **Step 6: Create the edit view**

Create `resources/views/invoices/edit.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Ubah Invoice')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-receipt me-2"></i>Ubah {{ $invoice->number }}</h1>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <p class="text-muted">Subtotal Jasa: {{ number_format($invoice->subtotal_service, 0, ',', '.') }} &middot; Subtotal Sparepart: {{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}</p>
            <form method="POST" action="{{ route('invoices.update', $invoice) }}">
                @csrf
                @method('PUT')
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
                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                    <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-outline-secondary btn-sm">Batal</a>
                </div>
            </form>
        </div>
    </div>
@endsection
```

- [ ] **Step 7: Add the "Ubah" button to the show view**

In `resources/views/invoices/show.blade.php`, fill in the empty action bar:

```blade
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-receipt me-2"></i>{{ $invoice->number }}</h1>
        <div class="d-flex gap-2">
            @can('update', $invoice)
                <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
        </div>
    </div>
```

- [ ] **Step 8: Run the tests and confirm they pass**

Run: `php artisan test tests/Feature/InvoiceControllerTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/UpdateInvoiceRequest.php app/Http/Controllers/InvoiceController.php routes/web.php resources/views/invoices/edit.blade.php resources/views/invoices/show.blade.php tests/Feature/InvoiceControllerTest.php
git commit -m "feat: allow editing Invoice discount and PPN before posting"
```

---

## Task 5: Post Invoice — deduct stock, release PKB reservation, record kartu stok

**Files:**
- Modify: `app/Http/Controllers/InvoiceController.php`
- Modify: `routes/web.php` (invoices group)
- Modify: `resources/views/invoices/show.blade.php`
- Modify: `tests/Feature/InvoiceControllerTest.php`

**Interfaces:**
- Consumes: `App\Services\InvoiceService::postInvoice()` (already built); `InvoicePolicy::post()` (Task 2).
- Produces: route `invoices.post` (`PATCH /invoices/{invoice}/post`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/InvoiceControllerTest.php`, right before the final closing `}`:

```php
    public function test_post_transitions_invoice_to_posted_and_deducts_stock(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrder($branch);
        $invoice = (new InvoiceService())->createFromWorkOrder($workOrder);
        $sparepartBranchId = $workOrder->sparepartLines->first()->sparepart_branch_id;
        $stockBefore = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchId)->first();

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.post');

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");

        $response->assertRedirect("/invoices/{$invoice->id}");
        $this->assertSame(\App\Support\InvoiceStatus::POSTED, $invoice->fresh()->status);
        $stockAfter = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchId)->first();
        $this->assertSame((float) $stockBefore->on_hand_qty - 2.0, (float) $stockAfter->on_hand_qty);
        $this->assertSame(0.0, (float) $stockAfter->reserved_qty);
        $this->assertDatabaseHas('inventory_movements', [
            'sparepart_branch_id' => $sparepartBranchId,
            'movement_type' => 'usage_out',
            'reference_type' => 'invoice_detail',
        ]);
    }

    public function test_post_is_forbidden_when_invoice_already_posted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        (new InvoiceService())->postInvoice($invoice);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.post');

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");

        $response->assertForbidden();
    }

    public function test_post_requires_invoice_post_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");

        $response->assertForbidden();
    }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test --filter=test_post_ tests/Feature/InvoiceControllerTest.php`
Expected: FAIL — `invoices.post` route doesn't exist yet.

- [ ] **Step 3: Add `InvoiceController::post()`**

In `app/Http/Controllers/InvoiceController.php`, add this method at the end of the class, right after `update()`:

```php
    public function post(Invoice $invoice)
    {
        $this->authorize('post', $invoice);

        try {
            (new InvoiceService())->postInvoice($invoice);
        } catch (DomainException $e) {
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice berhasil diposting.');
    }
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, update the `invoices` group:

```php
    Route::prefix('invoices')->name('invoices.')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::post('/', [InvoiceController::class, 'store'])->name('store');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('show');
        Route::get('/{invoice}/edit', [InvoiceController::class, 'edit'])->name('edit');
        Route::put('/{invoice}', [InvoiceController::class, 'update'])->name('update');
        Route::patch('/{invoice}/post', [InvoiceController::class, 'post'])->name('post');
    });
```

- [ ] **Step 5: Add the "Posting" button to the show view**

In `resources/views/invoices/show.blade.php`, add the post button next to the edit button:

```blade
        <div class="d-flex gap-2">
            @can('update', $invoice)
                <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil"></i> Ubah
                </a>
            @endcan
            @can('post', $invoice)
                <form method="POST" action="{{ route('invoices.post', $invoice) }}" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary btn-sm">Posting</button>
                </form>
            @endcan
        </div>
```

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `php artisan test tests/Feature/InvoiceControllerTest.php`
Expected: PASS.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all pass — this is the last task in the plan, so this is the final full-suite regression check for the whole module (Tasks 1-5 combined).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/InvoiceController.php routes/web.php resources/views/invoices/show.blade.php tests/Feature/InvoiceControllerTest.php
git commit -m "feat: post Invoice - deduct stock, release PKB reservation, record kartu stok"
```
