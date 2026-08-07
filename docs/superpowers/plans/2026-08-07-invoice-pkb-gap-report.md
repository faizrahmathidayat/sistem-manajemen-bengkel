# Laporan Gap Invoice vs PKB Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only, filterable report comparing every Invoice against its originating PKB (Work Order) — Cabang, Status Selisih (Ada Selisih default / Invoice > PKB / Invoice < PKB / Sesuai / Semua), Customer/No. PKB/No. Invoice search, Tanggal Invoice range — with 4 summary cards and a dual-mode results table: Rekap (one row per Invoice-PKB pair) and Detail (one row per line-item comparison, categorized Sesuai/Berubah/Dihapus/Ditambahkan). Activates the disabled "PKB vs Invoice" sidebar placeholder.

**Architecture:** New standalone, single-action module (`InvoicePkbGapReportController@index`) — pure Eloquent/query-builder over the already-shipped `Invoice`/`WorkOrder`/`WorkOrderServiceLine`/`WorkOrderSparepartLine`/`InvoiceDetail` models, no new tables/migrations, no new Policy. `pkb_total` (a value `work_orders` never stores) is computed via a correlated SQL subquery reused across filtering, per-row display, and summary aggregation. Design doc: `docs/superpowers/specs/2026-08-07-invoice-pkb-gap-report-design.md`.

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL 8 (tests run against real MySQL — `phpunit.xml` sets `DB_CONNECTION=mysql`, `DB_DATABASE=bengkel_testing`), Blade + Bootstrap 5, no SPA/build step.

## Global Constraints

- PHP 7.4.33 — no PHP 8+ syntax anywhere.
- Every list endpoint uses `->simplePaginate()`, never `->paginate()` — 15 per page, at the **Invoice** level even in Detail mode (one Invoice-PKB pair per page slot; line-comparison rows per invoice vary in count).
- Authorization is branch-scoped: `auth()->user()->branchesWithPermission('report.invoice_pkb_gap.view')`, rendering `reports.invoice-pkb-gap.no-access` when empty — **not** a bare `$this->authorize()` call (permission is seeded `is_branch_scoped => true` in `MenuPermissionSeeder`).
- The base query is always first scoped to `whereIn('invoices.branch_id', $permittedBranches->pluck('id'))`; `branch_ids[]` is applied only as a narrowing filter intersected against that permitted set, never trusted alone.
- Base dataset is `Invoice::whereNotNull('work_order_id')` — every column reference on the `invoices` table in raw SQL fragments must be table-qualified (`invoices.grand_total`, not bare `grand_total`) since correlated subqueries reference `invoices.work_order_id` and ambiguous column names inside a subquery would otherwise silently resolve to the wrong table.
- **`pkb_total` is always computed via the correlated subquery expression below — never via `withSum`, never via an eager-loaded relation sum in PHP for filtering/summary purposes** (`work_orders` has no stored total column; PHP-side summing across paginated rows would silently be wrong for the summary cards, which must reflect the full filtered set, not just the current page):
  ```php
  protected function pkbTotalExpression(): string
  {
      return '(
          COALESCE((SELECT SUM(line_total) FROM work_order_service_lines WHERE work_order_service_lines.work_order_id = invoices.work_order_id), 0)
          +
          COALESCE((SELECT SUM(line_total) FROM work_order_sparepart_lines WHERE work_order_sparepart_lines.work_order_id = invoices.work_order_id), 0)
      )';
  }
  ```
- **"Sesuai" threshold is exact equality** (`invoices.grand_total = pkb_total`) — both are `decimal` columns already rounded to 2dp at write time, no epsilon needed (unlike qty comparisons elsewhere in this project).
- Selisih % (Rekap mode, per row, computed in PHP not SQL — only 15 rows/page): `($invoice->grand_total - $invoice->pkb_total) / $invoice->pkb_total * 100`, with a divide-by-zero guard rendering `—` when `pkb_total == 0.0`.
- The Status Selisih filter (`gap_status` query param) defaults to **`ada_selisih`** (not "no filter" — this report's useful default is showing only mismatches), reject-to-safe-default: any value outside `{ada_selisih, invoice_gt_pkb, invoice_lt_pkb, sesuai, semua}` falls back to `ada_selisih`.
- Mode toggle (`mode=rekap` default / `mode=detail`) uses the exact same reject-to-safe-default rule as every other dual-mode report in this project: `$mode = request('mode') === 'detail' ? 'detail' : 'rekap';`.
- The Customer/No. PKB/No. Invoice filter is a single text field (query param `search`) matching *any* of: `invoices.number`, `work_orders.number` (via `whereHas('workOrder', ...)`), or customer name (via `whereHas('customer', ...)`) — same `addcslashes($term, '%_\\')` escaping used by every other report's text search.
- Date range filter (`date_from`/`date_to`) applies to `invoices.invoice_date` (not `work_order_date`).
- This module reuses `partials/branch-multiselect-filter.blade.php` directly, with its companion JS copied inline into this view's own `@push('scripts')` — same convention as every other report.
- Reuse the `.stat-card`/`.stat-value`/`.stat-label`/`.stat-icon` component classes already defined and used elsewhere — do not invent new summary-card markup.
- Detail-mode line-comparison rows repeat the invoice-identifying columns (No. PKB, No. Invoice, Tanggal, Customer) on every line row — no `rowspan`, same reasoning as every other Detail mode in this project.
- **Sidebar note:** the existing placeholder text is "PKB vs Invoice" (not "Gap Invoice vs PKB") and its icon is `bi-bar-chart-steps` — Task 2 must keep both exactly as-is, only removing the `nav-link-disabled`/`badge-soon` wrapper and adding a real `href`.

---

## Task 1: `InvoicePkbGapReportController`, `pkb_total` subquery logic, dual-mode query/filter/summary, line-comparison algorithm, routes, tests

**Files:**
- Create: `app/Http/Controllers/InvoicePkbGapReportController.php`
- Modify: `routes/web.php`
- Create: `resources/views/reports/invoice-pkb-gap/no-access.blade.php` (final content)
- Create: `resources/views/reports/invoice-pkb-gap/index.blade.php` (minimal placeholder — Task 2 replaces with full UI)
- Test: `tests/Feature/InvoicePkbGapReportControllerTest.php` (new)

**Interfaces:**
- Consumes: `Invoice` (`number`, `branch_id`, `customer_id`, `work_order_id`, `invoice_date`, `grand_total`, relations `branch()`/`customer()`/`workOrder()`/`details()`), `WorkOrder` (`number`, relations `serviceLines()`/`sparepartLines()`), `WorkOrderServiceLine` (`description`, `qty`, `unit_price`), `WorkOrderSparepartLine` (`item_name_snapshot`, `qty`, `unit_price`), `InvoiceDetail` (`item_type`, `description`, `qty`, `unit_price`, `work_order_service_line_id`, `work_order_sparepart_line_id`), `App\Support\InvoiceDetailItemType::{SERVICE,SPAREPART}`, `User::branchesWithPermission(string): Collection`.
- Produces: route `reports.invoice-pkb-gap.index`. Task 2's view consumes the exact view-data keys this controller passes: `invoices` (simplePaginate result; each row has a `pkb_total` computed attribute; in Detail mode each row additionally has a `comparisonLines` array attribute — see Step 4), `summary` (object with `total_transaksi`/`total_nilai_pkb`/`total_nilai_invoice`/`total_varian_netto`), `branches`, `selectedBranchIds`, `search`, `gapStatus`, `dateFrom`, `dateTo`, `mode`. `comparisonLines` array shape: each element is `['item_type' => 'Jasa'|'Sparepart', 'item_name' => string, 'pkb_qty' => float|null, 'pkb_price' => float|null, 'invoice_qty' => float|null, 'invoice_price' => float|null, 'category' => 'sesuai'|'changed'|'removed'|'added']`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/InvoicePkbGapReportControllerTest.php`:

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
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoicePkbGapReportControllerTest extends TestCase
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

    /**
     * Creates a real PKB -> Invoice pair via the actual HTTP flow (work order create/confirm/complete,
     * invoice create, optional edit to introduce a gap, optional post). Returns the fresh Invoice,
     * fresh WorkOrder, and the expected pkb_total (sum of the ORIGINAL PKB line amounts — pkb_total
     * never changes after PKB completion regardless of any later invoice edit).
     *
     * @return array{invoice: Invoice, workOrder: WorkOrder, pkbTotal: float}
     */
    protected function makeGapPair(
        Branch $branch,
        Customer $customer,
        float $serviceAmount,
        float $sparepartAmount,
        string $invoiceDate,
        ?array $editPayload = null,
        bool $post = true
    ): array {
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

        $pkbUser = User::factory()->create();
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete', 'invoice.create', 'invoice.edit', 'invoice.post'] as $code) {
            $this->grantBranchPermission($pkbUser, $branch, $code);
        }

        $this->actingAs($pkbUser)->post('/work-orders', [
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
        $this->actingAs($pkbUser)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($pkbUser)->patch("/work-orders/{$workOrder->id}/complete");

        $this->actingAs($pkbUser)->post('/invoices', ['work_order_id' => $workOrder->id]);
        $invoice = Invoice::where('work_order_id', $workOrder->id)->firstOrFail();

        if ($editPayload) {
            $this->actingAs($pkbUser)->put("/invoices/{$invoice->id}", $editPayload);
            $invoice = $invoice->fresh();
        }

        if ($post) {
            $this->actingAs($pkbUser)->patch("/invoices/{$invoice->id}/post");
            $invoice = $invoice->fresh();
        }

        $invoice->update(['invoice_date' => $invoiceDate]);

        return [
            'invoice' => $invoice->fresh('details'),
            'workOrder' => $workOrder->fresh(),
            'pkbTotal' => round($serviceAmount + $sparepartAmount, 2),
        ];
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertSee('belum memiliki akses', false);
    }

    public function test_index_lists_gap_pairs_for_permitted_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $pair = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertSee($pair['workOrder']->number);
        $response->assertSee($pair['invoice']->number);
    }

    public function test_index_is_scoped_to_permitted_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $editPayload = fn ($amount) => [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $amount]],
            'spareparts' => [],
        ];
        $pairA = $this->makeGapPair($branchA, $customerA, 100000, 0, now()->toDateString(), $editPayload(100000));
        $pairB = $this->makeGapPair($branchB, $customerB, 100000, 0, now()->toDateString(), $editPayload(100000));
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertSee($pairA['invoice']->number);
        $response->assertDontSee($pairB['invoice']->number);
    }

    public function test_index_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $editPayload = fn ($amount) => [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $amount]],
            'spareparts' => [],
        ];
        $pairA = $this->makeGapPair($branchA, $customerA, 100000, 0, now()->toDateString(), $editPayload(100000));
        $pairB = $this->makeGapPair($branchB, $customerB, 100000, 0, now()->toDateString(), $editPayload(100000));
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.invoice_pkb_gap.view');
        $this->grantBranchPermission($viewer, $branchB, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get("/reports/invoice-pkb-gap?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee($pairA['invoice']->number);
        $response->assertDontSee($pairB['invoice']->number);
    }

    public function test_index_filters_by_search_matching_invoice_number(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $editPayload = ['discount_percent' => 0, 'tax_percent' => 10, 'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]], 'spareparts' => []];
        $pairA = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), $editPayload);
        $pairB = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), $editPayload);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?search=' . urlencode($pairA['invoice']->number));

        $response->assertOk();
        $response->assertSee($pairA['invoice']->number);
        $response->assertDontSee($pairB['invoice']->number);
    }

    public function test_index_filters_by_search_matching_work_order_number(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $editPayload = ['discount_percent' => 0, 'tax_percent' => 10, 'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]], 'spareparts' => []];
        $pairA = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), $editPayload);
        $pairB = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), $editPayload);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?search=' . urlencode($pairA['workOrder']->number));

        $response->assertOk();
        $response->assertSee($pairA['invoice']->number);
        $response->assertDontSee($pairB['invoice']->number);
    }

    public function test_index_filters_by_search_matching_customer_name(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $editPayload = ['discount_percent' => 0, 'tax_percent' => 10, 'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]], 'spareparts' => []];
        $pairA = $this->makeGapPair($branch, $customerA, 100000, 0, now()->toDateString(), $editPayload);
        $pairB = $this->makeGapPair($branch, $customerB, 100000, 0, now()->toDateString(), $editPayload);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?search=Budi');

        $response->assertOk();
        $response->assertSee($pairA['invoice']->number);
        $response->assertDontSee($pairB['invoice']->number);
    }

    public function test_index_filters_by_invoice_date_range(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $editPayload = ['discount_percent' => 0, 'tax_percent' => 10, 'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]], 'spareparts' => []];
        $old = $this->makeGapPair($branch, $customer, 100000, 0, '2025-01-01', $editPayload);
        $recent = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), $editPayload);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?date_from=' . now()->subDay()->toDateString());

        $response->assertOk();
        $response->assertSee($recent['invoice']->number);
        $response->assertDontSee($old['invoice']->number);
    }

    public function test_index_gap_status_default_ada_selisih_excludes_exact_match(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $exact = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $gt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertSee($gt['invoice']->number);
        $response->assertDontSee($exact['invoice']->number);
    }

    public function test_index_gap_status_sesuai_shows_only_exact_matches(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $exact = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $gt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=sesuai');

        $response->assertOk();
        $response->assertSee($exact['invoice']->number);
        $response->assertDontSee($gt['invoice']->number);
    }

    public function test_index_gap_status_invoice_gt_pkb(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $gt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $lt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 10, 'tax_percent' => 0,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=invoice_gt_pkb');

        $response->assertOk();
        $response->assertSee($gt['invoice']->number);
        $response->assertDontSee($lt['invoice']->number);
    }

    public function test_index_gap_status_invoice_lt_pkb(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $gt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $lt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 10, 'tax_percent' => 0,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=invoice_lt_pkb');

        $response->assertOk();
        $response->assertSee($lt['invoice']->number);
        $response->assertDontSee($gt['invoice']->number);
    }

    public function test_index_gap_status_semua_shows_all(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $exact = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $gt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=semua');

        $response->assertOk();
        $response->assertSee($exact['invoice']->number);
        $response->assertSee($gt['invoice']->number);
    }

    public function test_index_invalid_gap_status_value_falls_back_to_ada_selisih(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=bogus');

        $response->assertOk();
        $response->assertViewHas('gapStatus', 'ada_selisih');
    }

    public function test_index_computes_summary_cards_correctly(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        // pkb_total=100000, grand_total=110000 (tax 10%, no discount) -> varian +10000
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=semua');

        $response->assertOk();
        $response->assertViewHas('summary', function ($summary) {
            return (int) $summary->total_transaksi === 1
                && (float) $summary->total_nilai_pkb === 100000.0
                && (float) $summary->total_nilai_invoice === 110000.0
                && (float) $summary->total_varian_netto === 10000.0;
        });
    }

    public function test_index_defaults_to_rekap_mode_and_does_not_eager_load_work_order_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
        $response->assertViewHas('invoices', function ($invoices) {
            $invoice = $invoices->first();
            return $invoice->relationLoaded('workOrder') === false || $invoice->workOrder->relationLoaded('serviceLines') === false;
        });
    }

    public function test_index_invalid_mode_value_falls_back_to_rekap(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?mode=bogus');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
    }

    public function test_index_detail_mode_categorizes_line_comparisons_correctly(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        // PKB: 1 service (Ganti Oli, 100000) + 1 sparepart (Oli Mesin, 60000) = pkb_total 160000.
        $pair = $this->makeGapPair($branch, $customer, 100000, 60000, now()->toDateString(), null, false);
        $serviceDetail = $pair['invoice']->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $pair['invoice']->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);

        $viewer = User::factory()->create();
        $editor = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');
        $this->grantBranchPermission($editor, $branch, 'invoice.edit');

        // Edit: service line price changed (100000 -> 120000, same PKB line = "changed"),
        // sparepart line dropped entirely ("removed"), new free-form service line added ("added").
        $this->actingAs($editor)->put("/invoices/{$pair['invoice']->id}", [
            'discount_percent' => 0, 'tax_percent' => 0,
            'services' => [
                [
                    'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                    'description' => 'Ganti Oli',
                    'qty' => 1,
                    'unit_price' => 120000,
                ],
                [
                    'description' => 'Jasa Tambahan',
                    'qty' => 1,
                    'unit_price' => 25000,
                ],
            ],
            'spareparts' => [],
        ]);

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?mode=detail&gap_status=semua');

        $response->assertOk();
        $response->assertViewHas('invoices', function ($invoices) {
            $lines = $invoices->first()->comparisonLines;
            $changed = collect($lines)->firstWhere('item_name', 'Ganti Oli');
            $removed = collect($lines)->firstWhere('item_name', 'Oli Mesin');
            $added = collect($lines)->firstWhere('item_name', 'Jasa Tambahan');

            return $changed['category'] === 'changed'
                && (float) $changed['pkb_price'] === 100000.0
                && (float) $changed['invoice_price'] === 120000.0
                && $removed['category'] === 'removed'
                && $removed['invoice_qty'] === null
                && $added['category'] === 'added'
                && $added['pkb_qty'] === null;
        });
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/InvoicePkbGapReportControllerTest.php`
Expected: FAIL — route `reports.invoice-pkb-gap.index` doesn't exist yet (all 17 tests fail with a 404-related assertion failure).

- [ ] **Step 3: Add the route**

In `routes/web.php`, add the import (alphabetically placed among the other controller imports, right after `InvoiceController`):

```php
use App\Http\Controllers\InvoicePkbGapReportController;
```

Inside the existing `Route::prefix('reports')->name('reports.')->group(...)` block, add (after the `invoices.index` line):

```php
        Route::get('/invoice-pkb-gap', [InvoicePkbGapReportController::class, 'index'])->name('invoice-pkb-gap.index');
```

- [ ] **Step 4: Implement `InvoicePkbGapReportController`**

`app/Http/Controllers/InvoicePkbGapReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\InvoiceDetailItemType;

class InvoicePkbGapReportController extends Controller
{
    protected function pkbTotalExpression(): string
    {
        return '(
            COALESCE((SELECT SUM(line_total) FROM work_order_service_lines WHERE work_order_service_lines.work_order_id = invoices.work_order_id), 0)
            +
            COALESCE((SELECT SUM(line_total) FROM work_order_sparepart_lines WHERE work_order_sparepart_lines.work_order_id = invoices.work_order_id), 0)
        )';
    }

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.invoice_pkb_gap.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.invoice-pkb-gap.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('search')) ? trim(request('search')) : null;

        $gapStatus = request('gap_status');
        $gapStatus = in_array($gapStatus, ['ada_selisih', 'invoice_gt_pkb', 'invoice_lt_pkb', 'sesuai', 'semua'], true)
            ? $gapStatus : 'ada_selisih';

        $dateFrom = $this->parseDate(request('date_from'));
        $dateTo = $this->parseDate(request('date_to'));

        $mode = request('mode') === 'detail' ? 'detail' : 'rekap';

        $pkbTotalExpr = $this->pkbTotalExpression();

        $query = Invoice::query()
            ->whereNotNull('invoices.work_order_id')
            ->whereIn('invoices.branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($q) => $q->whereIn('invoices.branch_id', $branchIds))
            ->when($search, function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->where(function ($inner) use ($escaped) {
                    $inner->where('invoices.number', 'like', "%{$escaped}%")
                        ->orWhereHas('customer', function ($c) use ($escaped) {
                            $c->where('name', 'like', "%{$escaped}%");
                        })
                        ->orWhereHas('workOrder', function ($w) use ($escaped) {
                            $w->where('number', 'like', "%{$escaped}%");
                        });
                });
            })
            ->when($dateFrom, fn ($q) => $q->whereDate('invoices.invoice_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('invoices.invoice_date', '<=', $dateTo))
            ->when($gapStatus === 'ada_selisih', fn ($q) => $q->whereRaw("invoices.grand_total <> {$pkbTotalExpr}"))
            ->when($gapStatus === 'invoice_gt_pkb', fn ($q) => $q->whereRaw("invoices.grand_total > {$pkbTotalExpr}"))
            ->when($gapStatus === 'invoice_lt_pkb', fn ($q) => $q->whereRaw("invoices.grand_total < {$pkbTotalExpr}"))
            ->when($gapStatus === 'sesuai', fn ($q) => $q->whereRaw("invoices.grand_total = {$pkbTotalExpr}"));

        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_transaksi, ' .
            "COALESCE(SUM({$pkbTotalExpr}), 0) as total_nilai_pkb, " .
            'COALESCE(SUM(invoices.grand_total), 0) as total_nilai_invoice, ' .
            "COALESCE(SUM(invoices.grand_total - {$pkbTotalExpr}), 0) as total_varian_netto"
        )->first();

        $invoicesQuery = $query->select('invoices.*')
            ->selectRaw("{$pkbTotalExpr} as pkb_total")
            ->with(['branch', 'customer', 'workOrder']);

        if ($mode === 'detail') {
            $invoicesQuery->with(['details', 'workOrder.serviceLines', 'workOrder.sparepartLines']);
        }

        $invoices = $invoicesQuery->orderByDesc('invoices.invoice_date')
            ->orderByDesc('invoices.id')
            ->simplePaginate(15)
            ->withQueryString();

        if ($mode === 'detail') {
            $invoices->getCollection()->transform(function (Invoice $invoice) {
                $invoice->comparisonLines = $this->buildComparisonLines($invoice);

                return $invoice;
            });
        }

        return view('reports.invoice-pkb-gap.index', [
            'invoices' => $invoices,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $branchIds,
            'search' => $search,
            'gapStatus' => $gapStatus,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'mode' => $mode,
        ]);
    }

    protected function buildComparisonLines(Invoice $invoice): array
    {
        $workOrder = $invoice->workOrder;
        $detailsByServiceLineId = $invoice->details->whereNotNull('work_order_service_line_id')->keyBy('work_order_service_line_id');
        $detailsBySparepartLineId = $invoice->details->whereNotNull('work_order_sparepart_line_id')->keyBy('work_order_sparepart_line_id');

        $rows = [];

        foreach ($workOrder->serviceLines as $line) {
            $rows[] = $this->compareLine('Jasa', $line->description, $line, $detailsByServiceLineId->get($line->id));
        }

        foreach ($workOrder->sparepartLines as $line) {
            $rows[] = $this->compareLine('Sparepart', $line->item_name_snapshot, $line, $detailsBySparepartLineId->get($line->id));
        }

        $addedDetails = $invoice->details
            ->whereNull('work_order_service_line_id')
            ->whereNull('work_order_sparepart_line_id');

        foreach ($addedDetails as $detail) {
            $rows[] = [
                'item_type' => $detail->item_type === InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart',
                'item_name' => $detail->description,
                'pkb_qty' => null,
                'pkb_price' => null,
                'invoice_qty' => (float) $detail->qty,
                'invoice_price' => (float) $detail->unit_price,
                'category' => 'added',
            ];
        }

        return $rows;
    }

    protected function compareLine(string $itemType, string $itemName, $pkbLine, $detail): array
    {
        if (! $detail) {
            return [
                'item_type' => $itemType,
                'item_name' => $itemName,
                'pkb_qty' => (float) $pkbLine->qty,
                'pkb_price' => (float) $pkbLine->unit_price,
                'invoice_qty' => null,
                'invoice_price' => null,
                'category' => 'removed',
            ];
        }

        $unchanged = (float) $pkbLine->qty === (float) $detail->qty
            && (float) $pkbLine->unit_price === (float) $detail->unit_price;

        return [
            'item_type' => $itemType,
            'item_name' => $itemName,
            'pkb_qty' => (float) $pkbLine->qty,
            'pkb_price' => (float) $pkbLine->unit_price,
            'invoice_qty' => (float) $detail->qty,
            'invoice_price' => (float) $detail->unit_price,
            'category' => $unchanged ? 'sesuai' : 'changed',
        ];
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

Create `resources/views/reports/invoice-pkb-gap/no-access.blade.php` (final content — this is not a throwaway, Task 2 does not touch this file again):

```php
@extends('layouts.app')
@section('title', 'Laporan Gap Invoice vs PKB')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-bar-chart-steps me-2"></i>PKB vs Invoice</h1>
    </div>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0">Anda belum memiliki akses laporan gap invoice vs PKB di cabang manapun.</p>
        </div>
    </div>
@endsection
```

Create a minimal `resources/views/reports/invoice-pkb-gap/index.blade.php` so `test_index_*` requests render successfully (Task 2 replaces this with the full dual-mode filter/summary/table UI):

```php
@extends('layouts.app')
@section('title', 'Laporan Gap Invoice vs PKB')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-bar-chart-steps me-2"></i>PKB vs Invoice</h1>
    </div>
    @foreach ($invoices as $invoice)
        <div>
            {{ $invoice->workOrder->number }}
            {{ $invoice->number }}
            {{ number_format($invoice->pkb_total, 0, ',', '.') }}
            {{ number_format($invoice->grand_total, 0, ',', '.') }}
        </div>
    @endforeach
@endsection
```

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/InvoicePkbGapReportControllerTest.php`
Expected: PASS (18 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (687 + 18 = 705), no regressions.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/InvoicePkbGapReportController.php routes/web.php \
        resources/views/reports/invoice-pkb-gap/index.blade.php resources/views/reports/invoice-pkb-gap/no-access.blade.php \
        tests/Feature/InvoicePkbGapReportControllerTest.php
git commit -m "feat: add invoice vs pkb gap report controller with dual-mode query and summary totals"
```

---

## Task 2: Filter card, summary cards, dual-mode results table, sidebar wiring, browser verification

**Files:**
- Modify: `resources/views/reports/invoice-pkb-gap/index.blade.php`
- Modify: `resources/views/partials/sidebar.blade.php`
- Test: `tests/Feature/InvoicePkbGapReportControllerTest.php` (append)
- Test: `tests/Feature/AppShellTest.php` (append)

**Interfaces:**
- Consumes: `mode`, `invoices` (Rekap: `pkb_total` computed attribute + `grand_total`/`number` + `workOrder.number`/`customer.name`/`invoice_date`; Detail: additionally `comparisonLines` array per invoice, shape defined in Task 1), `summary`, `branches`, `selectedBranchIds`, `search`, `gapStatus`, `dateFrom`, `dateTo` — all from Task 1.
- Produces: no new interfaces — this is the final task in the plan.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/InvoicePkbGapReportControllerTest.php` (inside the class, after `test_index_detail_mode_categorizes_line_comparisons_correctly`):

```php
    public function test_index_renders_filter_form_and_summary_cards(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertSee('Total Transaksi Terhubung');
        $response->assertSee('Total Nilai PKB');
        $response->assertSee('Total Nilai Invoice');
        $response->assertSee('Total Varian Netto');
        $response->assertSee('name="search"', false);
        $response->assertSee('name="date_from"', false);
        $response->assertSee('name="date_to"', false);
        $response->assertSee('<option value="ada_selisih" selected>Ada Selisih</option>', false);
        $response->assertSee('<option value="rekap" selected>Rekap</option>', false);
    }

    public function test_index_rekap_mode_shows_gap_columns_and_status_badge(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertSee('Total PKB');
        $response->assertSee('Total Invoice');
        $response->assertSee('Selisih');
        $response->assertSee('100.000');
        $response->assertSee('110.000');
        $response->assertSee('Invoice &gt; PKB', false);
    }

    public function test_index_detail_mode_shows_line_comparison_columns_and_categories(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $pair = $this->makeGapPair($branch, $customer, 100000, 60000, now()->toDateString(), null, false);
        $serviceDetail = $pair['invoice']->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $editor = User::factory()->create();
        $this->grantBranchPermission($editor, $branch, 'invoice.edit');
        $this->actingAs($editor)->put("/invoices/{$pair['invoice']->id}", [
            'discount_percent' => 0, 'tax_percent' => 0,
            'services' => [[
                'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 120000,
            ]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?mode=detail&gap_status=semua');

        $response->assertOk();
        $response->assertSee('Nama Item');
        $response->assertSee('Qty PKB');
        $response->assertSee('Qty Invoice');
        $response->assertSee('Ganti Oli');
        $response->assertSee('Oli Mesin');
        $response->assertSee('Berubah');
        $response->assertSee('Dihapus');
    }

    public function test_index_rekap_mode_does_not_show_detail_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertDontSee('Qty PKB');
        $response->assertDontSee('Qty Invoice');
        $response->assertSee('Total PKB');
    }

    public function test_index_shows_empty_state_when_no_results_match_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertSee('Tidak ada transaksi yang cocok dengan filter saat ini.');
    }

    public function test_index_detail_mode_shows_empty_state_when_no_results_match_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?mode=detail');

        $response->assertOk();
        $response->assertSee('Tidak ada transaksi yang cocok dengan filter saat ini.');
    }
```

Append to `tests/Feature/AppShellTest.php` (inside the class, near `test_sidebar_links_directly_to_invoice_report_when_permitted`):

```php
    public function test_sidebar_links_directly_to_invoice_pkb_gap_report_when_permitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'report.invoice_pkb_gap.view', 'resource' => 'report', 'action' => 'invoice_pkb_gap.view', 'description' => 'Melihat laporan selisih PKB vs invoice']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('reports.invoice-pkb-gap.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }
```

Also update `test_sidebar_hides_all_new_placeholder_headings_without_any_permission` in the same file — confirmed (via grep) that no existing assertion targets `bi-bar-chart-steps` or "PKB vs Invoice" text yet, so add a new line after the existing `$response->assertDontSee('Laporan Invoice', false);` line:

```php
        $response->assertDontSee('bi-bar-chart-steps', false);
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/InvoicePkbGapReportControllerTest.php`
Run: `php artisan test tests/Feature/AppShellTest.php`

Expected: the 6 new `InvoicePkbGapReportControllerTest` tests FAIL (placeholder view lacks the new UI), and `test_sidebar_links_directly_to_invoice_pkb_gap_report_when_permitted` FAILS (sidebar still shows the disabled placeholder).

- [ ] **Step 3: Replace the placeholder view with the full dual-mode UI**

Replace `resources/views/reports/invoice-pkb-gap/index.blade.php` entirely with:

```php
@extends('layouts.app')
@section('title', 'Laporan Gap Invoice vs PKB')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-bar-chart-steps me-2"></i>PKB vs Invoice</h1>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.invoice-pkb-gap.index') }}" id="invoicePkbGapFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Status Selisih</label>
                    <select name="gap_status" class="form-select form-select-sm">
                        <option value="ada_selisih" {{ $gapStatus === 'ada_selisih' ? 'selected' : '' }}>Ada Selisih</option>
                        <option value="invoice_gt_pkb" {{ $gapStatus === 'invoice_gt_pkb' ? 'selected' : '' }}>Invoice &gt; PKB</option>
                        <option value="invoice_lt_pkb" {{ $gapStatus === 'invoice_lt_pkb' ? 'selected' : '' }}>Invoice &lt; PKB</option>
                        <option value="sesuai" {{ $gapStatus === 'sesuai' ? 'selected' : '' }}>Sesuai</option>
                        <option value="semua" {{ $gapStatus === 'semua' ? 'selected' : '' }}>Semua</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Customer / No. PKB / No. Invoice</label>
                    <input type="text" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Cari...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tanggal Invoice Dari</label>
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
                    <div class="stat-value">{{ number_format($summary->total_transaksi, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Transaksi Terhubung</div>
                </div>
                <i class="bi bi-link-45deg stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_nilai_pkb, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Nilai PKB</div>
                </div>
                <i class="bi bi-clipboard-check stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_nilai_invoice, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Nilai Invoice</div>
                </div>
                <i class="bi bi-file-earmark-text stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_varian_netto, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Varian Netto</div>
                </div>
                <i class="bi bi-graph-up-arrow stat-icon"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
        @if ($mode === 'detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. PKB</th>
                        <th>No. Invoice</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Tipe Item</th>
                        <th>Nama Item</th>
                        <th>Qty PKB</th>
                        <th>Harga PKB</th>
                        <th>Qty Invoice</th>
                        <th>Harga Invoice</th>
                        <th>Kategori</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        @forelse ($invoice->comparisonLines as $line)
                            <tr>
                                <td><a href="{{ route('work-orders.show', $invoice->work_order_id) }}"><code>{{ $invoice->workOrder->number }}</code></a></td>
                                <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                                <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td>{{ $invoice->customer->name }}</td>
                                <td>{{ $line['item_type'] }}</td>
                                <td>{{ $line['item_name'] }}</td>
                                <td>{{ $line['pkb_qty'] !== null ? number_format($line['pkb_qty'], 0, ',', '.') : '—' }}</td>
                                <td>{{ $line['pkb_price'] !== null ? number_format($line['pkb_price'], 0, ',', '.') : '—' }}</td>
                                <td>{{ $line['invoice_qty'] !== null ? number_format($line['invoice_qty'], 0, ',', '.') : '—' }}</td>
                                <td>{{ $line['invoice_price'] !== null ? number_format($line['invoice_price'], 0, ',', '.') : '—' }}</td>
                                <td>
                                    @if ($line['category'] === 'sesuai')
                                        <span class="status-dot status-active">Sesuai</span>
                                    @elseif ($line['category'] === 'changed')
                                        <span class="status-dot status-warning">Berubah</span>
                                    @elseif ($line['category'] === 'removed')
                                        <span class="status-dot status-danger">Dihapus</span>
                                    @else
                                        <span class="status-dot status-warning">Ditambahkan</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td><a href="{{ route('work-orders.show', $invoice->work_order_id) }}"><code>{{ $invoice->workOrder->number }}</code></a></td>
                                <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                                <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td>{{ $invoice->customer->name }}</td>
                                <td colspan="7">&mdash;</td>
                            </tr>
                        @endforelse
                    @empty
                        <tr>
                            <td colspan="11" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-bar-chart-steps',
                                    'title' => 'Belum ada data transaksi',
                                    'description' => 'Tidak ada transaksi yang cocok dengan filter saat ini.',
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
                        <th>No. PKB</th>
                        <th>No. Invoice</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Total PKB</th>
                        <th>Total Invoice</th>
                        <th>Selisih (Rp)</th>
                        <th>Selisih (%)</th>
                        <th>Status Gap</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        @php
                            $pkbTotal = (float) $invoice->pkb_total;
                            $grandTotal = (float) $invoice->grand_total;
                            $selisihAmount = $grandTotal - $pkbTotal;
                            $selisihPercent = $pkbTotal != 0.0 ? ($selisihAmount / $pkbTotal) * 100 : null;

                            if ($selisihAmount == 0.0) {
                                $gapBadge = '<span class="status-dot status-active">Sesuai</span>';
                            } elseif ($selisihAmount > 0.0) {
                                $gapBadge = '<span class="status-dot status-warning">Invoice &gt; PKB</span>';
                            } else {
                                $gapBadge = '<span class="status-dot status-danger">Invoice &lt; PKB</span>';
                            }
                        @endphp
                        <tr>
                            <td><a href="{{ route('work-orders.show', $invoice->work_order_id) }}"><code>{{ $invoice->workOrder->number }}</code></a></td>
                            <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                            <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ number_format($pkbTotal, 0, ',', '.') }}</td>
                            <td>{{ number_format($grandTotal, 0, ',', '.') }}</td>
                            <td>{{ ($selisihAmount >= 0 ? '+' : '') . number_format($selisihAmount, 0, ',', '.') }}</td>
                            <td>{{ $selisihPercent !== null ? (($selisihPercent >= 0 ? '+' : '') . number_format($selisihPercent, 1, ',', '.') . '%') : '—' }}</td>
                            <td>{!! $gapBadge !!}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-bar-chart-steps',
                                    'title' => 'Belum ada data transaksi',
                                    'description' => 'Tidak ada transaksi yang cocok dengan filter saat ini.',
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
        const form = document.getElementById('invoicePkbGapFilterForm');
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

In `resources/views/partials/sidebar.blade.php`, replace the disabled placeholder (currently lines 161-167):

```blade
        @if ($user->branchesWithPermission('report.invoice_pkb_gap.view')->isNotEmpty())
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-bar-chart-steps me-2"></i> PKB vs Invoice
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
        @endif
```

with:

```blade
        @if ($user->branchesWithPermission('report.invoice_pkb_gap.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('reports.invoice-pkb-gap.index') }}" class="nav-link {{ request()->routeIs('reports.invoice-pkb-gap.*') ? 'active' : '' }}">
                <i class="bi bi-bar-chart-steps me-2"></i> PKB vs Invoice
            </a>
        </li>
        @endif
```

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/InvoicePkbGapReportControllerTest.php`
Run: `php artisan test tests/Feature/AppShellTest.php`
Expected: all PASS (24 + 26 respectively — run separately if combined output looks truncated, a known Windows quirk from prior reports in this project).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (705 + 7 = 712), no regressions.

- [ ] **Step 7: Manual browser verification**

Using the dev server and real data created through the actual HTTP flow (mirroring this plan's test helper — work order create/confirm/complete, invoice create, edit to introduce a gap, post), verify against a short-lived demo user/branch (cleaned up after, per this project's established manual-verification discipline — scope any cleanup DELETE precisely by the IDs created in this session, never by a loose type/category filter on a shared table like `inventory_reservations` or `inventory_movements`):

1. Log in as a user with `report.invoice_pkb_gap.view` on at least one branch.
2. Load `/reports/invoice-pkb-gap` — confirm "Status Selisih" defaults to "Ada Selisih" selected, "Tampilan" defaults to "Rekap" selected, summary cards show correct totals.
3. Create 2-3 real PKB→Invoice pairs with different gap shapes (exact match, invoice > PKB via tax, invoice < PKB via discount, a line added/removed/price-changed) and confirm each appears/disappears correctly as "Status Selisih" is switched through all 5 options via real UI interaction.
4. Switch "Tampilan" to "Detail" — confirm line-comparison rows render with correct category badges (Sesuai/Berubah/Dihapus/Ditambahkan) for the edited pair.
5. Confirm Customer/No. PKB/No. Invoice search and Tanggal filters narrow results correctly in both modes.
6. Confirm the empty-state renders correctly in both modes when filters match nothing.
7. Confirm the sidebar "PKB vs Invoice" link is real (not "Segera Hadir"), and No. PKB / No. Invoice links point to the correct `work-orders/{id}` / `invoices/{id}` routes.
8. Clean up any demo user/branch/PKB/invoice data created for verification; leave existing data untouched.

- [ ] **Step 8: Commit**

```bash
git add resources/views/reports/invoice-pkb-gap/index.blade.php resources/views/partials/sidebar.blade.php \
        tests/Feature/InvoicePkbGapReportControllerTest.php tests/Feature/AppShellTest.php
git commit -m "feat: add invoice vs pkb gap report dual-mode UI and wire sidebar link"
```

---

## Final Step

After Task 2 passes and the full suite is green, report the final test count and a short end-to-end summary (what shipped, what's next). Do not start the last remaining Laporan placeholder (Sparepart) or any other scope without explicit user instruction.
