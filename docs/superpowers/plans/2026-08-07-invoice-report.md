# Laporan Invoice (Sales Invoice Report) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only, filterable Invoice report — Cabang, Status Invoice, Customer/No. Invoice search, Tanggal Invoice range — with 4 summary cards (Total Invoice / Total Nominal Invoice / Total Terbayar / Total Sisa Piutang) and a dual-mode results table: Rekap (one row per invoice with its stored money columns) and Detail (one row per invoice line item), activating the disabled "Laporan Invoice" sidebar placeholder.

**Architecture:** New standalone, single-action module (`InvoiceReportController@index`) — pure Eloquent query over the already-shipped `Invoice` model and its single `invoice_details` line table, no new tables/migrations, no new Policy. Design doc: `docs/superpowers/specs/2026-08-07-invoice-report-design.md`.

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL 8 (tests run against real MySQL — `phpunit.xml` sets `DB_CONNECTION=mysql`, `DB_DATABASE=bengkel_testing`), Blade + Bootstrap 5, no SPA/build step.

## Global Constraints

- PHP 7.4.33 — no PHP 8+ syntax anywhere.
- Every list endpoint uses `->simplePaginate()`, never `->paginate()` — 15 per page, based on `Invoice` count in **both** Rekap and Detail mode (design Decision 10).
- Authorization is branch-scoped: `auth()->user()->branchesWithPermission('report.invoice.view')`, rendering `reports.invoices.no-access` when empty — **not** a bare `$this->authorize()` call (design Decision 2; `report.invoice.view` is seeded `is_branch_scoped => true`).
- The base query is always first scoped to `whereIn('branch_id', $permittedBranches->pluck('id'))`; `branch_ids[]` is applied only as a narrowing filter intersected against that permitted set, never trusted alone.
- **No `withSum()` anywhere, in either mode.** Unlike `WorkOrder`, `invoices` already stores `subtotal_service`, `subtotal_sparepart`, `discount_amount`, `grand_total`, `paid_amount` as first-class columns — Rekap mode reads them as plain attributes. "Sisa Piutang" per row uses the existing model accessor `$invoice->outstanding_amount` (`Invoice::getOutstandingAmountAttribute()`, already defined at `app/Models/Invoice.php:71-74`) — do not add a new accessor or a `withSum`/subquery for it (design Decision 3).
- The page-level summary card is one `selectRaw` aggregate over the filtered (pre-pagination) query, mirroring `ReceivableReportController`:
  ```php
  $summary = (clone $query)->selectRaw(
      'COUNT(*) as total_invoice, ' .
      'COALESCE(SUM(grand_total), 0) as total_nominal, ' .
      'COALESCE(SUM(paid_amount), 0) as total_paid, ' .
      'COALESCE(SUM(grand_total - paid_amount), 0) as total_remaining'
  )->first();
  ```
- **There is no `invoice_service_lines`/`invoice_sparepart_lines` split.** Line items live in one `invoice_details` table with an `item_type` column (`App\Support\InvoiceDetailItemType::SERVICE` / `::SPAREPART`), and `description`/`item_code_snapshot`/`qty`/`unit_price`/`line_total` are already snapshot columns on that table — no join to `WorkOrderServiceLine`/`WorkOrderSparepartLine` or any master-data table is needed for display (design Decision 4). Detail mode eager-loads only `with(['details'])`.
- Status badge markup must exactly mirror what already ships on `invoices/index.blade.php:39-49`: `draft`→`status-inactive` "Draft", `posted`→`status-active` "Diposting", `partially_paid`→`status-warning` "Dibayar Sebagian", `paid`→`status-active` "Lunas", `cancelled`→`status-danger` "Dibatalkan".
- The Status Invoice filter defaults to **no filter applied** ("Semua Status") and the summary cards reflect whatever the current filter state is — no hidden status exclusion, matching PKB's precedent, not Receivables' `unpaid`-by-default precedent (design Decision 6).
- The Customer/No. Invoice filter is a single text field (query param `search`) that matches *either* condition — `where('number', 'like', ...)->orWhereHas('customer', ...)` — with the same `addcslashes($term, '%_\\')` escaping used by every other report's text search.
- This module reuses `partials/branch-multiselect-filter.blade.php` directly, with its companion JS copied inline into this view's own `@push('scripts')` — same convention as every other report (each view carries its own copy, not a shared script).
- Reuse the `.stat-card`/`.stat-value`/`.stat-label`/`.stat-icon` component classes already defined and used on Dashboard/Receivables/PKB — do not invent new summary-card markup.
- Mode toggle (`mode=rekap` default / `mode=detail`) uses the exact same reject-to-safe-default rule as PKB Detail Mode: `$mode = request('mode') === 'detail' ? 'detail' : 'rekap';` any other value silently falls back to `rekap`.
- Detail-mode table rows repeat the invoice-identifying columns (No. Invoice, Tanggal, Customer, Status) on every line row — no `rowspan`, same reasoning as PKB Detail Mode. An invoice with zero `details` rows still renders exactly one row with `—` in the item columns rather than disappearing.
- **Sidebar note:** unlike PKB's placeholder, grep of `tests/Feature/AppShellTest.php` confirms there is **no existing test** asserting on the "Laporan Invoice" sidebar placeholder specifically — Task 2 only *adds* a new sidebar test, there is nothing stale to replace here.

---

## Task 1: `InvoiceReportController`, dual-mode query/filter/summary logic, routes, tests

**Files:**
- Create: `app/Http/Controllers/InvoiceReportController.php`
- Modify: `routes/web.php`
- Create: `resources/views/reports/invoices/no-access.blade.php` (final content)
- Create: `resources/views/reports/invoices/index.blade.php` (minimal placeholder — Task 2 replaces with full UI)
- Test: `tests/Feature/InvoiceReportControllerTest.php` (new)

**Interfaces:**
- Consumes: `Invoice` (`number`, `branch_id`, `customer_id`, `invoice_date`, `status`, `subtotal_service`, `subtotal_sparepart`, `discount_amount`, `grand_total`, `paid_amount`, accessor `outstanding_amount`, relations `branch()`/`customer()`/`details()`), `InvoiceDetail` (`item_type`, `description`, `qty`, `unit_price`, `line_total`), `App\Support\InvoiceStatus::{DRAFT,POSTED,PARTIALLY_PAID,PAID,CANCELLED}`, `App\Support\InvoiceDetailItemType::{SERVICE,SPAREPART}`, `User::branchesWithPermission(string): Collection`.
- Produces: route `reports.invoices.index`. Task 2's view consumes the exact view-data keys this controller passes: `invoices` (simplePaginate result; in Detail mode each row has `details` eager-loaded), `summary` (object with `total_invoice`/`total_nominal`/`total_paid`/`total_remaining`), `branches`, `selectedBranchIds`, `search`, `status`, `dateFrom`, `dateTo`, `mode`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/InvoiceReportControllerTest.php`:

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
use App\Support\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceReportControllerTest extends TestCase
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

    protected function makeInvoice(Branch $branch, Customer $customer, float $serviceAmount, float $sparepartAmount, string $invoiceDate, bool $post = true): Invoice
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
        $catalog = ServiceCatalog::create(['code' => 'SVC-' . random_int(1000, 9999), 'name' => 'Ganti Oli', 'default_price' => $serviceAmount]);

        $spareparts = [];
        if ($sparepartAmount > 0) {
            $sparepart = Sparepart::create(['code' => 'OLI-' . random_int(1000, 9999), 'name' => 'Oli Mesin']);
            $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => $sparepartAmount]);
            DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);
            $spareparts = [['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 1, 'unit_price' => $sparepartAmount]];
        }

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
                ['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $serviceAmount],
            ],
            'spareparts' => $spareparts,
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $invoice = (new InvoiceService())->createFromWorkOrder($workOrder->fresh());
        if ($post) {
            $invoice = (new InvoiceService())->postInvoice($invoice);
        }
        $invoice->update(['invoice_date' => $invoiceDate]);

        return $invoice->fresh();
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee('belum memiliki akses', false);
    }

    public function test_index_lists_invoices_for_permitted_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee($invoice->number);
    }

    public function test_index_is_scoped_to_permitted_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $invoiceA = $this->makeInvoice($branchA, $customerA, 100000, 0, now()->toDateString());
        $invoiceB = $this->makeInvoice($branchB, $customerB, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $invoiceA = $this->makeInvoice($branchA, $customerA, 100000, 0, now()->toDateString());
        $invoiceB = $this->makeInvoice($branchB, $customerB, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.invoice.view');
        $this->grantBranchPermission($viewer, $branchB, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get("/reports/invoices?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_by_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $draft = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString(), false);
        $posted = $this->makeInvoice($branch, $customer, 50000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?status=' . InvoiceStatus::POSTED);

        $response->assertOk();
        $response->assertSee($posted->number);
        $response->assertDontSee($draft->number);
    }

    public function test_index_filters_by_search_matching_invoice_number(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoiceA = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $invoiceB = $this->makeInvoice($branch, $customer, 50000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?search=' . urlencode($invoiceA->number));

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_by_search_matching_customer_name(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $invoiceA = $this->makeInvoice($branch, $customerA, 100000, 0, now()->toDateString());
        $invoiceB = $this->makeInvoice($branch, $customerB, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?search=Budi');

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_by_invoice_date_range(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $old = $this->makeInvoice($branch, $customer, 100000, 0, '2025-01-01');
        $recent = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?date_from=' . now()->subDay()->toDateString());

        $response->assertOk();
        $response->assertSee($recent->number);
        $response->assertDontSee($old->number);
    }

    public function test_index_computes_summary_cards_across_all_statuses_by_default(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $this->makeInvoice($branch, $customer, 50000, 0, now()->toDateString(), false);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertViewHas('summary', function ($summary) {
            return (int) $summary->total_invoice === 2
                && (float) $summary->total_nominal === 210000.0
                && (float) $summary->total_paid === 0.0
                && (float) $summary->total_remaining === 210000.0;
        });
    }

    public function test_index_shows_stored_money_columns_per_row(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee('100.000');
        $response->assertSee('60.000');
        $response->assertSee('160.000');
    }

    public function test_index_defaults_to_rekap_mode_and_does_not_eager_load_details(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
        $response->assertViewHas('invoices', function ($invoices) {
            return $invoices->first()->relationLoaded('details') === false;
        });
    }

    public function test_index_detail_mode_eager_loads_details_with_snapshot_fields(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?mode=detail');

        $response->assertOk();
        $response->assertViewHas('mode', 'detail');
        $response->assertViewHas('invoices', function ($invoices) {
            $invoice = $invoices->first();
            $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
            $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);

            return $invoice->relationLoaded('details') === true
                && $serviceDetail->description === 'Ganti Oli'
                && (float) $serviceDetail->line_total === 100000.0
                && $sparepartDetail->description === 'Oli Mesin'
                && (float) $sparepartDetail->line_total === 60000.0;
        });
    }

    public function test_index_invalid_mode_value_falls_back_to_rekap(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?mode=bogus');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/InvoiceReportControllerTest.php`
Expected: FAIL — route `reports.invoices.index` doesn't exist yet (the no-access test will also fail: `reports.invoices.no-access` view doesn't exist).

- [ ] **Step 3: Add the route**

In `routes/web.php`, add the import (alphabetically placed among the other controller imports):

```php
use App\Http\Controllers\InvoiceReportController;
```

Inside the existing `Route::prefix('reports')->name('reports.')->group(...)` block (`routes/web.php:205-208`), add:

```php
        Route::get('/invoices', [InvoiceReportController::class, 'index'])->name('invoices.index');
```

- [ ] **Step 4: Implement `InvoiceReportController`**

`app/Http/Controllers/InvoiceReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\InvoiceStatus;

class InvoiceReportController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.invoice.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.invoices.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('search')) ? trim(request('search')) : null;

        $status = request('status');
        $status = in_array($status, [
            InvoiceStatus::DRAFT,
            InvoiceStatus::POSTED,
            InvoiceStatus::PARTIALLY_PAID,
            InvoiceStatus::PAID,
            InvoiceStatus::CANCELLED,
        ], true) ? $status : null;

        $dateFrom = $this->parseDate(request('date_from'));
        $dateTo = $this->parseDate(request('date_to'));

        $mode = request('mode') === 'detail' ? 'detail' : 'rekap';

        $query = Invoice::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when($search, function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->where(function ($inner) use ($escaped) {
                    $inner->where('number', 'like', "%{$escaped}%")
                        ->orWhereHas('customer', function ($c) use ($escaped) {
                            $c->where('name', 'like', "%{$escaped}%");
                        });
                });
            })
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($dateFrom, fn ($q) => $q->whereDate('invoice_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('invoice_date', '<=', $dateTo));

        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_invoice, ' .
            'COALESCE(SUM(grand_total), 0) as total_nominal, ' .
            'COALESCE(SUM(paid_amount), 0) as total_paid, ' .
            'COALESCE(SUM(grand_total - paid_amount), 0) as total_remaining'
        )->first();

        $invoices = $query->with(['branch', 'customer']);

        if ($mode === 'detail') {
            $invoices->with(['details']);
        }

        $invoices = $invoices->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('reports.invoices.index', [
            'invoices' => $invoices,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $branchIds,
            'search' => $search,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'mode' => $mode,
        ]);
    }

    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
```

- [ ] **Step 5: Create the no-access view and a minimal placeholder view**

Create `resources/views/reports/invoices/no-access.blade.php` (final content — this is not a throwaway, Task 2 does not touch this file again):

```php
@extends('layouts.app')
@section('title', 'Laporan Invoice')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-text me-2"></i>Laporan Invoice</h1>
    </div>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0">Anda belum memiliki akses laporan invoice di cabang manapun.</p>
        </div>
    </div>
@endsection
```

Create a minimal `resources/views/reports/invoices/index.blade.php` so `test_index_*` requests render successfully (Task 2 replaces this with the full dual-mode filter/summary/table UI):

```php
@extends('layouts.app')
@section('title', 'Laporan Invoice')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-text me-2"></i>Laporan Invoice</h1>
    </div>
    @foreach ($invoices as $invoice)
        <div>
            {{ $invoice->number }}
            {{ number_format($invoice->subtotal_service, 0, ',', '.') }}
            {{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}
            {{ number_format($invoice->grand_total, 0, ',', '.') }}
        </div>
    @endforeach
@endsection
```

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/InvoiceReportControllerTest.php`
Expected: PASS (13 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (666 + 13 = 679), no regressions.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/InvoiceReportController.php routes/web.php \
        resources/views/reports/invoices/index.blade.php resources/views/reports/invoices/no-access.blade.php \
        tests/Feature/InvoiceReportControllerTest.php
git commit -m "feat: add invoice report controller with dual-mode query and summary totals"
```

---

## Task 2: Filter card, summary cards, dual-mode results table, sidebar wiring, browser verification

**Files:**
- Modify: `resources/views/reports/invoices/index.blade.php`
- Modify: `resources/views/partials/sidebar.blade.php`
- Test: `tests/Feature/InvoiceReportControllerTest.php` (append)
- Test: `tests/Feature/AppShellTest.php` (append — no existing test to replace, see Global Constraints)

**Interfaces:**
- Consumes: `mode`, `invoices` (Rekap: plain stored columns + `outstanding_amount` accessor; Detail: `details` eager-loaded per invoice, each with `item_type`/`description`/`qty`/`unit_price`/`line_total`), `summary`, `branches`, `selectedBranchIds`, `search`, `status`, `dateFrom`, `dateTo` — all from Task 1.
- Produces: no new interfaces — this is the final task in the plan.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/InvoiceReportControllerTest.php` (inside the class, after `test_index_invalid_mode_value_falls_back_to_rekap`):

```php
    public function test_index_renders_filter_form_and_summary_cards(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee('Total Invoice');
        $response->assertSee('Total Nominal Invoice');
        $response->assertSee('Total Terbayar');
        $response->assertSee('Total Sisa Piutang');
        $response->assertSee('name="search"', false);
        $response->assertSee('name="date_from"', false);
        $response->assertSee('name="date_to"', false);
        $response->assertSee('<option value="rekap" selected>Rekap</option>', false);
    }

    public function test_index_rekap_mode_shows_money_columns_and_status_badge(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee('Subtotal Jasa');
        $response->assertSee('Subtotal Sparepart');
        $response->assertSee('Sisa Piutang');
        $response->assertSee('100.000');
        $response->assertSee('60.000');
        $response->assertSee('160.000');
        $response->assertSee('Diposting');
    }

    public function test_index_detail_mode_shows_line_item_columns_and_rows(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?mode=detail');

        $response->assertOk();
        $response->assertSee('Tipe Item');
        $response->assertSee('Nama Item');
        $response->assertSee('Subtotal Line');
        $response->assertSee('Jasa');
        $response->assertSee('Sparepart');
        $response->assertSee('Ganti Oli');
        $response->assertSee('Oli Mesin');
        $response->assertSee('100.000');
        $response->assertSee('60.000');
    }

    public function test_index_rekap_mode_does_not_show_detail_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertDontSee('Tipe Item');
        $response->assertDontSee('Nama Item');
        $response->assertSee('Subtotal Jasa');
    }

    public function test_index_detail_mode_shows_placeholder_row_for_invoice_with_no_details(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $invoice->details()->delete();
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?mode=detail');

        $response->assertOk();
        $response->assertSee($invoice->number);
        $response->assertSee('—');
    }

    public function test_index_shows_empty_state_when_no_results_match_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee('Tidak ada invoice yang cocok dengan filter saat ini.');
    }

    public function test_index_detail_mode_shows_empty_state_when_no_results_match_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?mode=detail');

        $response->assertOk();
        $response->assertSee('Tidak ada invoice yang cocok dengan filter saat ini.');
    }
```

Append to `tests/Feature/AppShellTest.php` (inside the class, near `test_sidebar_links_directly_to_receivables_report_when_permitted` — see Global Constraints, this is a new test, nothing is replaced):

```php
    public function test_sidebar_links_directly_to_invoice_report_when_permitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'report.invoice.view', 'resource' => 'report', 'action' => 'invoice.view', 'description' => 'Melihat laporan invoice']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('reports.invoices.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }
```

Also update `test_sidebar_hides_all_new_placeholder_headings_without_any_permission` in the same file: add one more assertion after the existing `$response->assertDontSee('Laporan PKB', false);` line:

```php
        $response->assertDontSee('Laporan Invoice', false);
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/InvoiceReportControllerTest.php`
Run: `php artisan test tests/Feature/AppShellTest.php`

Expected: the 7 new `InvoiceReportControllerTest` tests FAIL (no "Tampilan"/detail columns/summary labels exist yet in the placeholder view), and `test_sidebar_links_directly_to_invoice_report_when_permitted` FAILS (sidebar still shows the disabled placeholder). `test_sidebar_hides_all_new_placeholder_headings_without_any_permission` still PASSES (its new assertion is trivially true with no permission granted) — that's fine, it's a regression guard being strengthened, not a TDD-red step.

- [ ] **Step 3: Replace the placeholder view with the full dual-mode UI**

Replace `resources/views/reports/invoices/index.blade.php` entirely with:

```php
@extends('layouts.app')
@section('title', 'Laporan Invoice')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-text me-2"></i>Laporan Invoice</h1>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.invoices.index') }}" id="invoiceReportFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Status Invoice</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Semua Status</option>
                        <option value="{{ \App\Support\InvoiceStatus::DRAFT }}" {{ $status === \App\Support\InvoiceStatus::DRAFT ? 'selected' : '' }}>Draft</option>
                        <option value="{{ \App\Support\InvoiceStatus::POSTED }}" {{ $status === \App\Support\InvoiceStatus::POSTED ? 'selected' : '' }}>Diposting</option>
                        <option value="{{ \App\Support\InvoiceStatus::PARTIALLY_PAID }}" {{ $status === \App\Support\InvoiceStatus::PARTIALLY_PAID ? 'selected' : '' }}>Dibayar Sebagian</option>
                        <option value="{{ \App\Support\InvoiceStatus::PAID }}" {{ $status === \App\Support\InvoiceStatus::PAID ? 'selected' : '' }}>Lunas</option>
                        <option value="{{ \App\Support\InvoiceStatus::CANCELLED }}" {{ $status === \App\Support\InvoiceStatus::CANCELLED ? 'selected' : '' }}>Dibatalkan</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Customer / No. Invoice</label>
                    <input type="text" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Cari customer atau No. Invoice...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tanggal Dari</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sampai</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tampilan</label>
                    <select name="mode" class="form-select form-select-sm">
                        <option value="rekap" {{ $mode === 'rekap' ? 'selected' : '' }}>Rekap</option>
                        <option value="detail" {{ $mode === 'detail' ? 'selected' : '' }}>Detail</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Terapkan Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_invoice, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Invoice</div>
                </div>
                <i class="bi bi-file-earmark-text stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_nominal, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Nominal Invoice</div>
                </div>
                <i class="bi bi-cash-stack stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_paid, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Terbayar</div>
                </div>
                <i class="bi bi-check-circle stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_remaining, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Sisa Piutang</div>
                </div>
                <i class="bi bi-file-earmark-minus stat-icon"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
        @if ($mode === 'detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Invoice</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Tipe Item</th>
                        <th>Nama Item</th>
                        <th>Qty</th>
                        <th>Harga Satuan</th>
                        <th>Subtotal Line</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        @php
                            switch ($invoice->status) {
                                case \App\Support\InvoiceStatus::DRAFT:
                                    $statusBadge = '<span class="status-dot status-inactive">Draft</span>';
                                    break;
                                case \App\Support\InvoiceStatus::POSTED:
                                    $statusBadge = '<span class="status-dot status-active">Diposting</span>';
                                    break;
                                case \App\Support\InvoiceStatus::PARTIALLY_PAID:
                                    $statusBadge = '<span class="status-dot status-warning">Dibayar Sebagian</span>';
                                    break;
                                case \App\Support\InvoiceStatus::PAID:
                                    $statusBadge = '<span class="status-dot status-active">Lunas</span>';
                                    break;
                                default:
                                    $statusBadge = '<span class="status-dot status-danger">Dibatalkan</span>';
                            }
                        @endphp
                        @forelse ($invoice->details as $detail)
                            <tr>
                                <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                                <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td>{{ $invoice->customer->name }}</td>
                                <td>{{ $detail->item_type === \App\Support\InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart' }}</td>
                                <td>{{ $detail->description }}</td>
                                <td>{{ number_format($detail->qty, 0, ',', '.') }}</td>
                                <td>{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                                <td>{{ number_format($detail->line_total, 0, ',', '.') }}</td>
                                <td>{!! $statusBadge !!}</td>
                            </tr>
                        @empty
                            <tr>
                                <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                                <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td>{{ $invoice->customer->name }}</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>{!! $statusBadge !!}</td>
                            </tr>
                        @endforelse
                    @empty
                        <tr>
                            <td colspan="9" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-text',
                                    'title' => 'Belum ada data invoice',
                                    'description' => 'Tidak ada invoice yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Invoice</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Subtotal Jasa</th>
                        <th>Subtotal Sparepart</th>
                        <th>Discount</th>
                        <th>Grand Total</th>
                        <th>Terbayar</th>
                        <th>Sisa Piutang</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr>
                            <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                            <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ number_format($invoice->subtotal_service, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->discount_amount, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->paid_amount, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</td>
                            <td>
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
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-text',
                                    'title' => 'Belum ada data invoice',
                                    'description' => 'Tidak ada invoice yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @endif
        </div>
    </div>

    <div class="mt-3">
        {{ $invoices->links() }}
    </div>

    @push('scripts')
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('invoiceReportFilterForm');
        if (!menu || !form) return;

        menu.addEventListener('click', function (event) { event.stopPropagation(); });

        const selectAll = document.getElementById('branchFilterSelectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
        }

        form.addEventListener('submit', function () {
            form.querySelectorAll('input[data-branch-hidden]').forEach(function (el) { el.remove(); });
            document.querySelectorAll('.branch-filter-checkbox:checked').forEach(function (checkbox) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'branch_ids[]';
                hidden.value = checkbox.value;
                hidden.setAttribute('data-branch-hidden', '1');
                form.appendChild(hidden);
            });
        });
    })();
    </script>
    @endpush
@endsection
```

- [ ] **Step 4: Wire the sidebar link**

In `resources/views/partials/sidebar.blade.php`, replace the disabled Laporan Invoice placeholder (currently lines 147-153):

```blade
        @if ($user->branchesWithPermission('report.invoice.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-file-earmark-text me-2"></i> Laporan Invoice
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
```

with:

```blade
        @if ($user->branchesWithPermission('report.invoice.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('reports.invoices.index') }}" class="nav-link {{ request()->routeIs('reports.invoices.*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-text me-2"></i> Laporan Invoice
            </a>
        </li>
        @endif
```

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/InvoiceReportControllerTest.php`
Run: `php artisan test tests/Feature/AppShellTest.php`
Expected: all PASS (20 + 25 respectively — run separately if combined output looks truncated, a known Windows quirk from prior reports in this project).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (679 + 8 = 687), no regressions.

- [ ] **Step 7: Manual browser verification**

Using the dev server and real existing Invoice data in the dev database (reuse the same 6 real `WorkOrder`-derived invoices used for PKB Report verification if they already have posted `Invoice` rows; otherwise create 1-2 short-lived ones through the real HTTP flow, same as this plan's test helper):
1. Log in as a user with `report.invoice.view` on at least one branch (short-lived demo user + grant, cleaned up after).
2. Load `/reports/invoices` — confirm "Tampilan" shows "Rekap" selected, summary cards show correct totals, Rekap table shows all 10 columns with correct stored-column values (no `withSum`/derived recomputation needed to verify — the values must match `invoices.subtotal_service`/`subtotal_sparepart`/`grand_total`/`paid_amount` exactly, and Sisa Piutang must equal `grand_total - paid_amount`).
3. Switch "Tampilan" to "Detail" via real UI interaction (set the select, submit the form) — confirm 9 columns, one row per `invoice_details` row, correct Tipe Item/Nama Item per row, correct repeated No. Invoice/Tanggal/Customer/Status per line row.
4. Confirm Status Invoice, Customer/No. Invoice search, and Tanggal filters narrow results correctly in both modes, and that summary cards recompute consistently with what's listed.
5. Confirm the empty-state renders correctly in both modes when filters match nothing.
6. Confirm the sidebar "Laporan Invoice" link is real (not "Segera Hadir") and No. Invoice links point to the correct `invoices/{id}` route.
7. Clean up any demo user/permission created for verification; leave existing Invoice data untouched.

- [ ] **Step 8: Commit**

```bash
git add resources/views/reports/invoices/index.blade.php resources/views/partials/sidebar.blade.php \
        tests/Feature/InvoiceReportControllerTest.php tests/Feature/AppShellTest.php
git commit -m "feat: add invoice report dual-mode UI and wire sidebar link"
```

---

## Final Step

After Task 2 passes and the full suite is green, report the final test count and a short end-to-end summary (what shipped, what's next). Do not start any of the remaining 2 Laporan placeholders (PKB vs Invoice, Laporan Sparepart) or any other scope without explicit user instruction.
