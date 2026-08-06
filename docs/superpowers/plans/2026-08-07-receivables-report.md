# Laporan Piutang (Receivables Report) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only, filterable Receivables report (Cabang, Customer, Status, date range on `invoice_date`) with 3 summary cards (Total Tagihan / Total Terbayar / Total Sisa Piutang) and a per-invoice table including aging, activating the disabled "Laporan Piutang" sidebar placeholder.

**Architecture:** New standalone, single-action module (`ReceivableReportController@index`) — pure Eloquent query over the already-shipped `Invoice` model (`paid_amount`/`outstanding_amount` from Migration 010), no new tables/migrations, no new Policy. Design doc: `docs/superpowers/specs/2026-08-07-receivables-report-design.md`.

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL 8 (tests run against real MySQL — `phpunit.xml` sets `DB_CONNECTION=mysql`, `DB_DATABASE=bengkel_testing`), Blade + Bootstrap 5, no SPA/build step.

## Global Constraints

- PHP 7.4.33 — no PHP 8+ syntax anywhere.
- Every list endpoint uses `->simplePaginate()`, never `->paginate()`.
- **Correction to the approved design doc's Decision 7**: the design doc says authorization is `$this->authorize('report.receivable.view')` (a bare permission-code check). This is factually wrong for this permission code and must **not** be implemented as written — `report.receivable.view` is seeded `is_branch_scoped => true` (`database/seeders/MenuPermissionSeeder.php:240-244`), and a bare `$this->authorize('code')` call resolves through `Gate::before`'s zero-argument fast path, which checks `hasPermissionTo()` (a **global** grant), not `hasPermissionToInBranch()`. A user granted this permission only in one branch (the normal case, per `UserBranchPermission`) would have no global grant row at all and would be incorrectly denied entirely. Every other branch-scoped module's `index()` in this codebase (`InvoiceController`, `GoodsReceiptController`, `StockCardController`, etc.) instead checks `auth()->user()->branchesWithPermission('code')->isEmpty()` and renders a `no-access` view when true — **this plan follows that same established pattern, not the design doc's literal wording.** (Caught during this plan's own self-review — see writing-plans skill's "spec self-review" step; flagged to the user in the plan summary, not silently changed.)
- Money/aggregate SQL uses `COALESCE(SUM(...), 0)` to avoid `NULL` when a filtered query matches zero rows.
- Status badge markup (`status-dot status-inactive/active/warning`) must exactly mirror what already ships on `invoices/show.blade.php`/`index.blade.php` (Migration 010, Task 5) — `partially_paid`→`status-warning` "Dibayar Sebagian", `paid`/`posted`→`status-active`.
- This module does not reuse `partials/list-filter-bar.blade.php` (per the approved design's Decision 3) but **does** reuse `partials/branch-multiselect-filter.blade.php` directly — which means this plan must also replicate that partial's companion JS (the "select-all" checkbox behavior and the on-submit checked→`branch_ids[]`-hidden-inputs conversion), scoped to this page's own form id, copied from `partials/list-filter-bar.blade.php`'s `@push('scripts')` block rather than reinvented.
- Reuse the `.stat-card`/`.stat-value`/`.stat-label`/`.stat-icon` component classes already defined in the design tokens partial and used on the Dashboard — do not invent new summary-card markup.

---

## Task 1: `ReceivableReportController`, query/filter/aging logic, routes, tests

**Files:**
- Create: `app/Http/Controllers/ReceivableReportController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ReceivableReportControllerTest.php` (new)

**Interfaces:**
- Consumes: `Invoice` (`branch_id`, `customer_id`, `invoice_date`, `due_date`, `grand_total`, `paid_amount`, `status`, relations `branch()`/`customer()`, accessor `outstanding_amount`), `App\Support\InvoiceStatus::{POSTED,PARTIALLY_PAID,PAID}`, `User::branchesWithPermission(string): Collection`.
- Produces: route `reports.receivables.index`. Task 2's view consumes the exact view-data keys this controller passes: `invoices` (paginator, each row additionally carrying `aging_label` — a plain string set via `$invoice->aging_label = ...` before the view renders, not a model attribute), `summary` (object with `total_billed`/`total_paid`/`total_outstanding`), `branches`, `selectedBranchIds`, `customerSearch`, `status`, `dateFrom`, `dateTo`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ReceivableReportControllerTest.php`:

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceivableReportControllerTest extends TestCase
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

    protected function makeInvoice(Branch $branch, Customer $customer, float $grandTotal, string $invoiceDate, bool $post = true): Invoice
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
        if ($post) {
            $invoice = (new InvoiceService())->postInvoice($invoice);
        }
        $invoice->update(['invoice_date' => $invoiceDate]);

        return $invoice->fresh();
    }

    public function test_index_defaults_to_unpaid_only_and_computes_summary_totals(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $unpaid = $this->makeInvoice($branch, $customer, 100000, now()->toDateString());
        $paid = $this->makeInvoice($branch, $customer, 50000, now()->toDateString());
        (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
            'reference_number' => null, 'amount' => 50000, 'notes' => null,
            'allocations' => [['invoice_id' => $paid->id, 'allocated_amount' => 50000]],
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables');

        $response->assertOk();
        $response->assertSee($unpaid->number);
        $response->assertDontSee($paid->number);
        $response->assertSee('100.000');
    }

    public function test_index_status_paid_shows_only_fully_paid_invoices(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $unpaid = $this->makeInvoice($branch, $customer, 100000, now()->toDateString());
        $paid = $this->makeInvoice($branch, $customer, 50000, now()->toDateString());
        (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
            'reference_number' => null, 'amount' => 50000, 'notes' => null,
            'allocations' => [['invoice_id' => $paid->id, 'allocated_amount' => 50000]],
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables?status=paid');

        $response->assertOk();
        $response->assertSee($paid->number);
        $response->assertDontSee($unpaid->number);
    }

    public function test_index_status_all_shows_both(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $unpaid = $this->makeInvoice($branch, $customer, 100000, now()->toDateString());
        $paid = $this->makeInvoice($branch, $customer, 50000, now()->toDateString());
        (new PaymentService())->createPaymentReceipt([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
            'reference_number' => null, 'amount' => 50000, 'notes' => null,
            'allocations' => [['invoice_id' => $paid->id, 'allocated_amount' => 50000]],
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables?status=all');

        $response->assertOk();
        $response->assertSee($unpaid->number);
        $response->assertSee($paid->number);
    }

    public function test_index_excludes_draft_invoices_even_with_status_all(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $posted = $this->makeInvoice($branch, $customer, 100000, now()->toDateString());
        // A distinctive, never-posted second invoice (createFromWorkOrder() alone yields DRAFT,
        // deliberately not calling postInvoice() here) with a grand_total large/distinctive
        // enough that it couldn't coincidentally appear elsewhere on the page if it leaked in.
        $draftInvoice = $this->makeInvoice($branch, $customer, 987654, now()->toDateString(), false);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables?status=all');

        $response->assertOk();
        $response->assertSee($posted->number);
        $response->assertDontSee($draftInvoice->number);
        $response->assertDontSee('987.654');
    }

    public function test_index_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $invoiceA = $this->makeInvoice($branchA, $customerA, 100000, now()->toDateString());
        $invoiceB = $this->makeInvoice($branchB, $customerB, 100000, now()->toDateString());
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'report.receivable.view');
        $this->grantBranchPermission($user, $branchB, 'report.receivable.view');

        $response = $this->actingAs($user)->get("/reports/receivables?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_by_customer_search(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $invoiceA = $this->makeInvoice($branch, $customerA, 100000, now()->toDateString());
        $invoiceB = $this->makeInvoice($branch, $customerB, 100000, now()->toDateString());
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables?customer=Budi');

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_by_invoice_date_range(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $old = $this->makeInvoice($branch, $customer, 100000, '2025-01-01');
        $recent = $this->makeInvoice($branch, $customer, 100000, now()->toDateString());
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables?date_from=' . now()->subDay()->toDateString());

        $response->assertOk();
        $response->assertSee($recent->number);
        $response->assertDontSee($old->number);
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/receivables');

        $response->assertOk();
        $response->assertSee('belum memiliki akses', false);
    }

    public function test_index_is_scoped_to_permitted_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoiceA = $this->makeInvoice($branchA, $customer, 100000, now()->toDateString());
        $invoiceB = $this->makeInvoice($branchB, $customer, 100000, now()->toDateString());
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables?status=all');

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/ReceivableReportControllerTest.php`
Expected: FAIL — route `reports.receivables.index` does not exist (404 / `RouteNotFoundException`).

- [ ] **Step 3: Add the route**

In `routes/web.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\ReceivableReportController;
```

Add a new route group (placed after the `payment-receipts` group):

```php
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/receivables', [ReceivableReportController::class, 'index'])->name('receivables.index');
    });
```

- [ ] **Step 4: Implement `ReceivableReportController`**

`app/Http/Controllers/ReceivableReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\InvoiceStatus;

class ReceivableReportController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.receivable.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.receivables.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $customerSearch = is_string(request('customer')) ? trim(request('customer')) : null;

        $status = request('status');
        $status = in_array($status, ['unpaid', 'paid', 'all'], true) ? $status : 'unpaid';

        $dateFrom = $this->parseDate(request('date_from'));
        $dateTo = $this->parseDate(request('date_to'));

        if ($status === 'paid') {
            $statuses = [InvoiceStatus::PAID];
        } elseif ($status === 'all') {
            $statuses = [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID];
        } else {
            $statuses = [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID];
        }

        $query = Invoice::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->whereIn('status', $statuses)
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when($customerSearch, function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('customer', function ($inner) use ($escaped) {
                    $inner->where('name', 'like', "%{$escaped}%");
                });
            })
            ->when($dateFrom, fn ($q) => $q->whereDate('invoice_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('invoice_date', '<=', $dateTo));

        $summary = (clone $query)->selectRaw(
            'COALESCE(SUM(grand_total), 0) as total_billed, ' .
            'COALESCE(SUM(paid_amount), 0) as total_paid, ' .
            'COALESCE(SUM(grand_total - paid_amount), 0) as total_outstanding'
        )->first();

        $invoices = $query->with(['branch', 'customer'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        $invoices->getCollection()->transform(function (Invoice $invoice) {
            $referenceDate = $invoice->due_date ?? $invoice->invoice_date;
            $daysOverdue = (int) $referenceDate->diffInDays(now(), false);
            $invoice->aging_label = $daysOverdue >= 0 ? "{$daysOverdue} hari" : 'Belum jatuh tempo';

            return $invoice;
        });

        return view('reports.receivables.index', [
            'invoices' => $invoices,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $branchIds,
            'customerSearch' => $customerSearch,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
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

- [ ] **Step 5: Create a minimal placeholder view so the tests can render**

Since Task 2 builds the real view, create a minimal placeholder now so Step 6 below can pass. Create `resources/views/reports/receivables/no-access.blade.php`:

```php
@extends('layouts.app')
@section('title', 'Laporan Piutang')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-minus me-2"></i>Laporan Piutang</h1>
    </div>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0">Anda belum memiliki akses laporan piutang di cabang manapun.</p>
        </div>
    </div>
@endsection
```

Create a minimal `resources/views/reports/receivables/index.blade.php` (Task 2 will replace this with the full filter/summary/table UI — this minimal version only needs to render every value the tests in this task assert on, so Step 6 goes green without doing Task 2's UI work early):

```php
@extends('layouts.app')
@section('title', 'Laporan Piutang')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-minus me-2"></i>Laporan Piutang</h1>
    </div>
    <table class="table">
        <tbody>
            @foreach ($invoices as $invoice)
                <tr>
                    <td>{{ $invoice->number }}</td>
                    <td>{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
```

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/ReceivableReportControllerTest.php`
Expected: PASS (10 tests).

- [ ] **Step 7: Run the full test suite to confirm no regression**

Run: `php artisan test`
Expected: PASS, no failures outside this task's own new tests.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/ReceivableReportController.php routes/web.php \
        resources/views/reports/receivables/no-access.blade.php \
        resources/views/reports/receivables/index.blade.php \
        tests/Feature/ReceivableReportControllerTest.php
git commit -m "feat: add receivables report controller with filters, summary totals, and aging"
```

---

## Task 2: Filter card, summary cards, results table UI, sidebar wiring, browser verification

**Files:**
- Modify: `resources/views/reports/receivables/index.blade.php` (replace Task 1's minimal placeholder)
- Modify: `resources/views/partials/sidebar.blade.php`
- Test: `tests/Feature/ReceivableReportControllerTest.php` (extend), `tests/Feature/AppShellTest.php` (extend)

**Interfaces:**
- Consumes: view-data keys produced by Task 1's controller (`invoices`, `summary`, `branches`, `selectedBranchIds`, `customerSearch`, `status`, `dateFrom`, `dateTo`), `partials/branch-multiselect-filter.blade.php`, `partials/empty-state.blade.php`, the `.stat-card` CSS component.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ReceivableReportControllerTest.php` (inside the class):

```php
    public function test_index_renders_summary_cards_and_filter_form(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, now()->toDateString());
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.receivable.view');

        $response = $this->actingAs($user)->get('/reports/receivables');

        $response->assertOk();
        $response->assertSee('Total Tagihan');
        $response->assertSee('Total Terbayar');
        $response->assertSee('Total Sisa Piutang');
        $response->assertSee('Umur Piutang');
        $response->assertSee('name="customer"', false);
        $response->assertSee('name="date_from"', false);
        $response->assertSee('name="date_to"', false);
    }
```

Append to `tests/Feature/AppShellTest.php` (inside the class, following the same shape as `test_sidebar_links_directly_to_payment_receipts_when_permitted`):

```php
    public function test_sidebar_links_directly_to_receivables_report_when_permitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'report.receivable.view', 'resource' => 'report', 'action' => 'receivable.view', 'description' => 'Melihat laporan piutang']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('reports.receivables.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/ReceivableReportControllerTest.php tests/Feature/AppShellTest.php`
Expected: FAIL — the minimal Task 1 view has none of the summary/filter markup; sidebar still shows the disabled placeholder.

- [ ] **Step 3: Replace the placeholder view with the full UI**

`resources/views/reports/receivables/index.blade.php`:

```php
@extends('layouts.app')
@section('title', 'Laporan Piutang')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-minus me-2"></i>Laporan Piutang</h1>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.receivables.index') }}" id="receivablesFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Customer</label>
                    <input type="text" name="customer" value="{{ $customerSearch }}" class="form-control form-control-sm" placeholder="Cari nama customer...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="unpaid" {{ $status === 'unpaid' ? 'selected' : '' }}>Belum Lunas</option>
                        <option value="paid" {{ $status === 'paid' ? 'selected' : '' }}>Lunas</option>
                        <option value="all" {{ $status === 'all' ? 'selected' : '' }}>Semua</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tanggal Invoice Dari</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sampai</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Terapkan Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_billed, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Tagihan</div>
                </div>
                <i class="bi bi-receipt stat-icon"></i>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_paid, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Terbayar</div>
                </div>
                <i class="bi bi-cash-coin stat-icon"></i>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_outstanding, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Sisa Piutang</div>
                </div>
                <i class="bi bi-exclamation-triangle stat-icon"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Invoice</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Cabang</th>
                        <th>Grand Total</th>
                        <th>Sudah Dibayar</th>
                        <th>Sisa Piutang</th>
                        <th>Umur Piutang</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr>
                            <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                            <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ $invoice->branch->name }}</td>
                            <td>{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->paid_amount, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</td>
                            <td>{{ $invoice->aging_label }}</td>
                            <td>
                                @if ($invoice->status === \App\Support\InvoiceStatus::POSTED)
                                    <span class="status-dot status-active">Diposting</span>
                                @elseif ($invoice->status === \App\Support\InvoiceStatus::PARTIALLY_PAID)
                                    <span class="status-dot status-warning">Dibayar Sebagian</span>
                                @else
                                    <span class="status-dot status-active">Lunas</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-minus',
                                    'title' => 'Tidak ada data piutang',
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
        </div>
    </div>

    <div class="mt-3">
        {{ $invoices->links() }}
    </div>

    @push('scripts')
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('receivablesFilterForm');
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

- [ ] **Step 4: Wire the sidebar placeholder to a real link**

In `resources/views/partials/sidebar.blade.php`, find the "Laporan Piutang" disabled placeholder (`@if ($user->branchesWithPermission('report.receivable.view')->isNotEmpty()) ... @endif` block) and replace:

```php
        @if ($user->branchesWithPermission('report.receivable.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-file-earmark-minus me-2"></i> Laporan Piutang
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
```

with:

```php
        @if ($user->branchesWithPermission('report.receivable.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('reports.receivables.index') }}" class="nav-link {{ request()->routeIs('reports.receivables.*') ? 'active' : '' }}">
                <i class="bi bi-file-earmark-minus me-2"></i> Laporan Piutang
            </a>
        </li>
        @endif
```

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/ReceivableReportControllerTest.php tests/Feature/AppShellTest.php`
Expected: PASS.

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 7: Manual browser verification**

Start the dev server (`preview_start` with the existing `.claude/launch.json` config). Seed via tinker: a branch, a demo user with `report.receivable.view` (plus `pkb.*`/nothing else needed to create invoices — reuse `InvoiceService` directly in tinker rather than the full PKB HTTP flow, matching the pattern used for Migration 010's own manual verification), 2-3 posted invoices at different `paid_amount` levels (one fully outstanding, one partially paid via `PaymentService::createPaymentReceipt()`, one fully paid), and at least one with a `due_date` in the past (to see a real "N hari" aging value) and one with `due_date` in the future or null (to see "Belum jatuh tempo").
- Load `/reports/receivables`, confirm the 3 summary cards show correct totals matching the default "Belum Lunas" filter.
- Change Status to "Lunas", confirm only the fully-paid invoice shows and cards update.
- Change Status to "Semua", confirm all show.
- Filter by Customer text, confirm narrowing works.
- Filter by date range, confirm narrowing works.
- Confirm the aging column shows "N hari" for the overdue one and "Belum jatuh tempo" for the not-yet-due one.
- Confirm clicking a No. Invoice link navigates to that invoice's detail page.
- Clean up all demo data afterward via tinker, stop the server.

- [ ] **Step 8: Commit**

```bash
git add resources/views/reports/receivables/index.blade.php resources/views/partials/sidebar.blade.php \
        tests/Feature/ReceivableReportControllerTest.php tests/Feature/AppShellTest.php
git commit -m "feat: add receivables report filter/summary/table UI and wire sidebar link"
```

---

## After both tasks

Report final test count and a short summary (filters exercised, aging behavior, summary-card correctness), matching the sign-off format used for prior plans this session. Do not start the next Laporan placeholder (PKB/Invoice/Invoice-PKB Gap/Sparepart) or Migration 011 without explicit user instruction.
