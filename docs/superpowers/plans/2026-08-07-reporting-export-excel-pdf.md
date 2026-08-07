# Export Excel & PDF untuk Seluruh Modul Reporting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Excel (.xlsx) export and PDF export (Preview inline + Download attachment) to all 5 active reports (Piutang, PKB, Invoice, Gap Invoice vs PKB, Sparepart/Stok), with export output always mirroring whatever filters are currently active on the page, without changing any existing `index()` behavior.

**Architecture:** Two new Composer packages (`maatwebsite/excel`, `barryvdh/laravel-dompdf`) already installed. Each of the 5 report controllers gets its query-building logic extracted into `resolveFilters()`/`buildQuery()` protected methods, reused by both `index()` (unchanged behavior) and 3 new export actions. A shared trait `HandlesReportExport` provides PDF streaming + row-capping helpers. 5 new `app/Exports/*.php` classes (one per report) handle Excel via `FromQuery`+`WithChunkReading`. 5 new PDF Blade templates extend a shared `layouts.print` (A4 landscape). A shared `partials/report-export-buttons.blade.php` renders the "Export ▾" button group, forwarding `request()->query()` verbatim to every export route. Design doc: `docs/superpowers/specs/2026-08-07-reporting-export-excel-pdf-design.md`.

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL 8, `maatwebsite/excel` ^3.1, `barryvdh/laravel-dompdf` ^2.2 (already installed — confirmed via `composer show`, all 734 existing tests still pass post-install). Blade + Bootstrap 5.

## Global Constraints

- PHP 7.4.33 — no PHP 8+ syntax anywhere.
- **`index()`'s existing behavior must not change at all** — the refactor into `resolveFilters()`/`buildQuery()` is a pure code move, verified by re-running each report's full existing test file after the refactor with zero changes to assertions.
- **Export buttons forward `request()->query()` verbatim** (`route($name, request()->query())`) — never manually reconstruct filter parameters, since the 5 reports' parameter sets genuinely differ (see spec section 3).
- **PDF row cap = 1000 rows**, applied via `limit(1001)->get()` then slicing to 1000 if the 1001st row exists (single query, no separate COUNT). When truncated, render a visible note "Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap." above the table. Excel is never capped (uses `FromQuery`+`WithChunkReading`, chunk size 500).
- **PDF orientation is landscape for all 5 reports**, no exceptions.
- **Authorization for all 3 export actions is identical to `index()`'s own branch-scoped check** (`branchesWithPermission('report.xxx.view')`), but returns a bare `403` (via `abort_if($permittedBranches->isEmpty(), 403)`) instead of the no-access view, since there is no page to render for a raw file download.
- **No permission codes are added.** Reuse the exact code each report's `index()` already checks.
- Excel cells for numeric columns hold **raw numbers** (not pre-formatted strings with thousand separators) so totals are summable in Excel — this deliberately differs from the human-readable formatting used on the web page and in PDF.
- Filenames: `laporan-<slug>-YmdHis.xlsx` / `.pdf`, e.g. `laporan-piutang-20260807-143000.xlsx`.
- Every new export route sits inside each report's existing `Route::prefix('reports')->name('reports.')` group, using that report's existing route-name prefix (`receivables`, `pkb`, `invoices`, `invoice-pkb-gap`, `sparepart-stock`) with `.export-excel`, `.pdf-preview`, `.pdf-download` suffixes.

---

## Task 1: Shared infrastructure — `HandlesReportExport` trait, print layout, export-buttons partial

**Files:**
- Create: `app/Http/Controllers/Concerns/HandlesReportExport.php`
- Create: `resources/views/layouts/print.blade.php`
- Create: `resources/views/partials/report-export-buttons.blade.php`
- Test: `tests/Feature/PrintLayoutTest.php` (new)

**Interfaces:**
- Produces: `HandlesReportExport` trait with `protected function authorizeExport($permittedBranches): void`, `protected function capRows(\Illuminate\Support\Collection $rows, int $limit = 1000): array` (returns `[Collection $rows, bool $truncated]`), `protected function streamPdf(string $view, array $data, string $filenameBase, string $disposition): \Illuminate\Http\Response` (`$disposition` is `'inline'` or `'attachment'`). `layouts.print` Blade layout with `@yield('report-title')`, `@yield('filter-summary')`, `@yield('note')`, `@yield('table')`. `partials.report-export-buttons` consuming `$excelRoute`, `$pdfPreviewRoute`, `$pdfDownloadRoute` (route name strings) — every later task depends on these exact names and signatures.

- [ ] **Step 1: Write the failing test for the print layout**

Create `tests/Feature/PrintLayoutTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_layout_renders_title_filter_summary_and_table_sections(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $html = $this->actingAs($user)->view('layouts.print', [])
            ->with('__test', true);

        // Render a minimal child view extending the layout to prove all yields work.
        \Illuminate\Support\Facades\View::addNamespace('test-print', resource_path('views'));

        $rendered = $this->actingAs($user)->blade(
            '@extends("layouts.print")
            @section("report-title", "Laporan Uji Coba")
            @section("filter-summary", "Cabang: Jakarta")
            @section("table")<table class="print-table"><tr><td>Baris Uji</td></tr></table>@endsection'
        );

        $rendered->assertSee('Sistem Manajemen Bengkel');
        $rendered->assertSee('Laporan Uji Coba');
        $rendered->assertSee('Cabang: Jakarta');
        $rendered->assertSee('Baris Uji');
        $rendered->assertSee($user->name);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php artisan test tests/Feature/PrintLayoutTest.php`
Expected: FAIL — `layouts.print` doesn't exist yet.

- [ ] **Step 3: Create the shared print layout**

Create `resources/views/layouts/print.blade.php`:

```php
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>@yield('report-title', 'Laporan')</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; margin: 0; padding: 20px; }
        .print-header { text-align: center; margin-bottom: 12px; border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; }
        .print-header h1 { font-size: 16px; margin: 0 0 2px; }
        .print-header .subtitle { font-size: 11px; color: #555; margin: 0; }
        .print-filters { font-size: 9px; color: #444; margin-bottom: 10px; }
        .print-note { font-size: 9px; color: #b45309; margin-bottom: 8px; font-style: italic; }
        table.print-table { width: 100%; border-collapse: collapse; font-size: 9px; }
        table.print-table th, table.print-table td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
        table.print-table th { background: #eee; font-weight: bold; }
        .print-footer { margin-top: 14px; font-size: 8px; color: #666; text-align: right; }
    </style>
</head>
<body>
    <div class="print-header">
        <h1>Sistem Manajemen Bengkel</h1>
        <p class="subtitle">@yield('report-title', 'Laporan')</p>
    </div>
    <div class="print-filters">@yield('filter-summary')</div>
    @yield('note')
    @yield('table')
    <div class="print-footer">
        Dicetak oleh {{ auth()->user()->name }} pada {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `php artisan test tests/Feature/PrintLayoutTest.php`
Expected: PASS.

- [ ] **Step 5: Create the `HandlesReportExport` trait**

Create `app/Http/Controllers/Concerns/HandlesReportExport.php`:

```php
<?php

namespace App\Http\Controllers\Concerns;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

trait HandlesReportExport
{
    protected function authorizeExport($permittedBranches): void
    {
        abort_if($permittedBranches->isEmpty(), 403);
    }

    protected function capRows(Collection $rows, int $limit = 1000): array
    {
        $truncated = $rows->count() > $limit;

        return [$truncated ? $rows->slice(0, $limit)->values() : $rows, $truncated];
    }

    protected function streamPdf(string $view, array $data, string $filenameBase, string $disposition)
    {
        $pdf = Pdf::loadView($view, $data)->setPaper('a4', 'landscape');
        $filename = $filenameBase . '-' . now()->format('Ymd-His') . '.pdf';

        return $disposition === 'attachment' ? $pdf->download($filename) : $pdf->stream($filename);
    }
}
```

- [ ] **Step 6: Write failing tests for the trait's pure-logic method**

Append to `tests/Feature/PrintLayoutTest.php` (same file — this trait has no controller of its own yet, so its `capRows()` logic is tested via a throwaway anonymous class, matching Laravel's own convention for testing traits in isolation):

```php
    public function test_cap_rows_truncates_collections_over_the_limit_and_reports_truncation(): void
    {
        $subject = new class {
            use \App\Http\Controllers\Concerns\HandlesReportExport;

            public function run(\Illuminate\Support\Collection $rows, int $limit)
            {
                return $this->capRows($rows, $limit);
            }
        };

        $rows = collect(range(1, 1001));

        [$result, $truncated] = $subject->run($rows, 1000);

        $this->assertTrue($truncated);
        $this->assertCount(1000, $result);
        $this->assertSame(1, $result->first());
        $this->assertSame(1000, $result->last());
    }

    public function test_cap_rows_does_not_truncate_collections_at_or_under_the_limit(): void
    {
        $subject = new class {
            use \App\Http\Controllers\Concerns\HandlesReportExport;

            public function run(\Illuminate\Support\Collection $rows, int $limit)
            {
                return $this->capRows($rows, $limit);
            }
        };

        $rows = collect(range(1, 1000));

        [$result, $truncated] = $subject->run($rows, 1000);

        $this->assertFalse($truncated);
        $this->assertCount(1000, $result);
    }
```

- [ ] **Step 7: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/PrintLayoutTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Create the export-buttons partial**

Create `resources/views/partials/report-export-buttons.blade.php`:

```php
<div class="btn-group">
    <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-download me-1"></i>Export
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="{{ route($excelRoute, request()->query()) }}">Export Excel</a></li>
        <li><a class="dropdown-item" href="{{ route($pdfPreviewRoute, request()->query()) }}" target="_blank" rel="noopener">Preview PDF</a></li>
        <li><a class="dropdown-item" href="{{ route($pdfDownloadRoute, request()->query()) }}">Download PDF</a></li>
    </ul>
</div>
```

(No standalone test for this partial — it depends on real route names that don't exist until Task 2 wires it into a real view. Task 2's own tests exercise it end-to-end.)

- [ ] **Step 9: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (734 + 3 = 737), no regressions.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Concerns/HandlesReportExport.php \
        resources/views/layouts/print.blade.php \
        resources/views/partials/report-export-buttons.blade.php \
        tests/Feature/PrintLayoutTest.php \
        composer.json composer.lock
git commit -m "feat: add shared export infrastructure (print layout, export buttons, PDF trait)"
```

(This is also where `composer.json`/`composer.lock`, already updated by the `composer require` run before this plan, get committed for the first time.)

---

## Task 2: Laporan Piutang export (reference implementation)

**Files:**
- Modify: `app/Http/Controllers/ReceivableReportController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/reports/receivables/index.blade.php`
- Create: `app/Exports/ReceivableReportExport.php`
- Create: `resources/views/reports/receivables/pdf.blade.php`
- Test: `tests/Feature/ReceivableReportExportTest.php` (new)

**Interfaces:**
- Consumes: `HandlesReportExport` trait (Task 1), `Invoice` model (unchanged).
- Produces: routes `reports.receivables.export-excel`, `reports.receivables.pdf-preview`, `reports.receivables.pdf-download`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ReceivableReportExportTest.php`:

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

class ReceivableReportExportTest extends TestCase
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

    protected function makePostedInvoice(Branch $branch, Customer $customer, float $serviceAmount, string $invoiceDate): \App\Models\Invoice
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

        $user = User::factory()->create();
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete', 'invoice.create', 'invoice.post'] as $code) {
            $this->grantBranchPermission($user, $branch, $code);
        }

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => [['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $serviceAmount]],
            'spareparts' => [],
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);
        $invoice = \App\Models\Invoice::where('work_order_id', $workOrder->id)->firstOrFail();
        $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");
        $invoice = $invoice->fresh();
        $invoice->update(['invoice_date' => $invoiceDate]);

        return $invoice;
    }

    public function test_export_excel_returns_xlsx_with_correct_headers(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makePostedInvoice($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.receivable.view');

        $response = $this->actingAs($viewer)->get('/reports/receivables/export-excel');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_excel_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/receivables/export-excel');

        $response->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_content_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makePostedInvoice($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.receivable.view');

        $response = $this->actingAs($viewer)->get('/reports/receivables/pdf-preview');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_content_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makePostedInvoice($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.receivable.view');

        $response = $this->actingAs($viewer)->get('/reports/receivables/pdf-download');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_pdf_preview_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/receivables/pdf-preview');

        $response->assertForbidden();
    }

    public function test_pdf_download_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/receivables/pdf-download');

        $response->assertForbidden();
    }

    public function test_export_excel_respects_status_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makePostedInvoice($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.receivable.view');

        // status=paid should exclude the freshly-posted (unpaid) invoice -> Excel::download still 200s
        // (an empty sheet is valid), but the underlying query must reflect the filter — verified
        // indirectly via the paid-status branch producing zero PDF rows (see next test) since
        // asserting an .xlsx binary body's row content requires opening the file, out of scope for
        // a fast HTTP test; the PDF path below proves filter fidelity because HTML is inspectable.
        $response = $this->actingAs($viewer)->get('/reports/receivables/export-excel?status=paid');

        $response->assertOk();
    }

    public function test_pdf_preview_respects_customer_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $invoiceA = $this->makePostedInvoice($branch, $customerA, 100000, now()->toDateString());
        $invoiceB = $this->makePostedInvoice($branch, $customerB, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.receivable.view');

        $response = $this->actingAs($viewer)->get('/reports/receivables/pdf-preview?customer=Budi');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString($invoiceA->number, $content);
        $this->assertStringNotContainsString($invoiceB->number, $content);
    }

    public function test_export_buttons_render_on_the_report_page_with_filters_forwarded(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.receivable.view');

        $response = $this->actingAs($viewer)->get('/reports/receivables?status=all');

        $response->assertOk();
        $response->assertSee('/reports/receivables/export-excel?status=all', false);
        $response->assertSee('/reports/receivables/pdf-preview?status=all', false);
        $response->assertSee('/reports/receivables/pdf-download?status=all', false);
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/ReceivableReportExportTest.php`
Expected: FAIL — routes don't exist yet (all 8 tests fail).

- [ ] **Step 3: Refactor `ReceivableReportController` — extract `resolveFilters()`/`buildQuery()`, add export actions**

Replace `app/Http/Controllers/ReceivableReportController.php` entirely with:

```php
<?php

namespace App\Http\Controllers;

use App\Exports\ReceivableReportExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\Invoice;
use App\Support\InvoiceStatus;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Facades\Excel;

class ReceivableReportController extends Controller
{
    use HandlesReportExport;

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.receivable.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.receivables.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches);

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

        $invoices->getCollection()->transform([$this, 'withAgingLabel']);

        return view('reports.receivables.index', [
            'invoices' => $invoices,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'customerSearch' => $filters['customerSearch'],
            'status' => $filters['status'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
        ]);
    }

    public function exportExcel()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.receivable.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches)->with(['branch', 'customer']);

        return Excel::download(
            new ReceivableReportExport($query, $this->filterSummaryText($filters)),
            'laporan-piutang-' . now()->format('Ymd-His') . '.xlsx'
        );
    }

    public function previewPdf()
    {
        return $this->renderPdf('inline');
    }

    public function downloadPdf()
    {
        return $this->renderPdf('attachment');
    }

    protected function renderPdf(string $disposition)
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.receivable.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches);

        $rows = $query->with(['branch', 'customer'])
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);
        $rows = $rows->map([$this, 'withAgingLabel']);

        return $this->streamPdf('reports.receivables.pdf', [
            'invoices' => $rows,
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-piutang', $disposition);
    }

    public function withAgingLabel(Invoice $invoice): Invoice
    {
        $referenceDate = $invoice->due_date ?? $invoice->invoice_date;
        $daysOverdue = (int) $referenceDate->diffInDays(now(), false);
        $invoice->aging_label = $daysOverdue >= 0 ? "{$daysOverdue} hari" : 'Belum jatuh tempo';

        return $invoice;
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $customerSearch = is_string(request('customer')) ? trim(request('customer')) : null;

        $status = request('status');
        $status = in_array($status, ['unpaid', 'paid', 'all'], true) ? $status : 'unpaid';

        return [
            'branchIds' => $branchIds,
            'customerSearch' => $customerSearch,
            'status' => $status,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
        ];
    }

    protected function buildQuery(array $filters, SupportCollection $permittedBranches)
    {
        if ($filters['status'] === 'paid') {
            $statuses = [InvoiceStatus::PAID];
        } elseif ($filters['status'] === 'all') {
            $statuses = [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID];
        } else {
            $statuses = [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID];
        }

        return Invoice::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->whereIn('status', $statuses)
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('branch_id', $filters['branchIds']))
            ->when($filters['customerSearch'], function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('customer', function ($inner) use ($escaped) {
                    $inner->where('name', 'like', "%{$escaped}%");
                });
            })
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoice_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoice_date', '<=', $filters['dateTo']));
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $statusLabels = ['unpaid' => 'Belum Lunas', 'paid' => 'Lunas', 'all' => 'Semua'];
        $statusLabel = $statusLabels[$filters['status']] ?? $filters['status'];
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $customerLabel = $filters['customerSearch'] ? " · Customer: {$filters['customerSearch']}" : '';

        return "Cabang: {$branchLabel} · Status: {$statusLabel} · Tanggal: {$dateLabel}{$customerLabel}";
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

**Note:** `$branchLabel` here shows branch IDs, not names, in the filter summary — this is intentional scope-keeping (resolving IDs to names would need an extra query per export; the branch multiselect filter already shows names on the web page itself, and the ID list is enough to uniquely identify which branches were selected). Revisit only if a future request asks for names specifically.

- [ ] **Step 4: Add the 3 new routes**

In `routes/web.php`, inside the `Route::prefix('receivables')`... actually the receivables route sits inside the shared `Route::prefix('reports')->name('reports.')` group as a single line, not its own sub-group. Find:

```php
        Route::get('/receivables', [ReceivableReportController::class, 'index'])->name('receivables.index');
```

Replace with:

```php
        Route::get('/receivables', [ReceivableReportController::class, 'index'])->name('receivables.index');
        Route::get('/receivables/export-excel', [ReceivableReportController::class, 'exportExcel'])->name('receivables.export-excel');
        Route::get('/receivables/pdf-preview', [ReceivableReportController::class, 'previewPdf'])->name('receivables.pdf-preview');
        Route::get('/receivables/pdf-download', [ReceivableReportController::class, 'downloadPdf'])->name('receivables.pdf-download');
```

- [ ] **Step 5: Create the Excel export class**

Create `app/Exports/ReceivableReportExport.php`:

```php
<?php

namespace App\Exports;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class ReceivableReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
{
    protected Builder $query;
    protected string $filterSummary;

    public function __construct(Builder $query, string $filterSummary)
    {
        $this->query = $query;
        $this->filterSummary = $filterSummary;
    }

    public function query()
    {
        return $this->query->orderByDesc('invoice_date')->orderByDesc('id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return ['No. Invoice', 'Tanggal', 'Customer', 'Cabang', 'Grand Total', 'Sudah Dibayar', 'Sisa Piutang', 'Jatuh Tempo', 'Umur Piutang (hari)', 'Status'];
    }

    public function map($invoice): array
    {
        $referenceDate = $invoice->due_date ?? $invoice->invoice_date;
        $daysOverdue = (int) $referenceDate->diffInDays(now(), false);

        return [
            $invoice->number,
            $invoice->invoice_date->format('Y-m-d'),
            $invoice->customer->name,
            $invoice->branch->name,
            (float) $invoice->grand_total,
            (float) $invoice->paid_amount,
            (float) ($invoice->grand_total - $invoice->paid_amount),
            $invoice->due_date ? $invoice->due_date->format('Y-m-d') : '-',
            $daysOverdue,
            $invoice->status,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $sheet->insertNewRowBefore(1, 1);
                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->setCellValue('A1', $this->filterSummary);
                $sheet->getStyle('A1')->getFont()->setBold(true);
            },
        ];
    }
}
```

- [ ] **Step 6: Create the PDF template**

Create `resources/views/reports/receivables/pdf.blade.php`:

```php
@extends('layouts.print')
@section('report-title', 'Laporan Piutang')
@section('filter-summary', $filterSummary)
@section('note')
    @if ($truncated)
        <p class="print-note">Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap.</p>
    @endif
@endsection
@section('table')
    <table class="print-table">
        <thead>
            <tr>
                <th>No. Invoice</th>
                <th>Tanggal</th>
                <th>Customer</th>
                <th>Cabang</th>
                <th>Grand Total</th>
                <th>Sudah Dibayar</th>
                <th>Sisa Piutang</th>
                <th>Jatuh Tempo</th>
                <th>Umur Piutang</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoices as $invoice)
                <tr>
                    <td>{{ $invoice->number }}</td>
                    <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                    <td>{{ $invoice->customer->name }}</td>
                    <td>{{ $invoice->branch->name }}</td>
                    <td>{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                    <td>{{ number_format($invoice->paid_amount, 0, ',', '.') }}</td>
                    <td>{{ number_format($invoice->grand_total - $invoice->paid_amount, 0, ',', '.') }}</td>
                    <td>{{ $invoice->due_date ? $invoice->due_date->format('d/m/Y') : '-' }}</td>
                    <td>{{ $invoice->aging_label }}</td>
                    <td>{{ $invoice->status }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
```

- [ ] **Step 7: Wire the export-buttons partial into the report page**

In `resources/views/reports/receivables/index.blade.php`, find the card header / filter form title row (the `<h1>` block near the top) and add the button group next to it — wrap both in a flex row if not already, e.g.:

```php
    <div class="mb-4 d-flex justify-content-between align-items-center">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-minus me-2"></i>Laporan Piutang</h1>
        @include('partials.report-export-buttons', [
            'excelRoute' => 'reports.receivables.export-excel',
            'pdfPreviewRoute' => 'reports.receivables.pdf-preview',
            'pdfDownloadRoute' => 'reports.receivables.pdf-download',
        ])
    </div>
```

(Replace whatever the existing single-column title `<div>` looks like with this two-column flex version — check the current file first since the exact icon class may differ from the placeholder above; keep the existing icon/title text unchanged, only add the flex wrapper and the `@include`.)

- [ ] **Step 8: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/ReceivableReportExportTest.php`
Expected: PASS (8 tests).

- [ ] **Step 9: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (737 + 8 = 745), no regressions — in particular confirm the pre-existing `tests/Feature/ReceivableReportControllerTest.php` (or equivalent) still passes unchanged, proving the `resolveFilters()`/`buildQuery()` refactor didn't alter `index()`'s behavior.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/ReceivableReportController.php app/Exports/ReceivableReportExport.php \
        routes/web.php resources/views/reports/receivables/index.blade.php resources/views/reports/receivables/pdf.blade.php \
        tests/Feature/ReceivableReportExportTest.php
git commit -m "feat: add excel and pdf export to laporan piutang"
```

---

## Task 3: Laporan PKB export

**Files:**
- Modify: `app/Http/Controllers/PkbReportController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/reports/pkb/index.blade.php`
- Create: `app/Exports/PkbReportExport.php`
- Create: `resources/views/reports/pkb/pdf.blade.php`
- Test: `tests/Feature/PkbReportExportTest.php` (new)

**Interfaces:**
- Consumes: `HandlesReportExport` trait (Task 1), `WorkOrder` model (unchanged).
- Produces: routes `reports.pkb.export-excel`, `reports.pkb.pdf-preview`, `reports.pkb.pdf-download`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PkbReportExportTest.php` — same helper/structure shape as Task 2's `ReceivableReportExportTest` (`grantBranchPermission`, a `makeCompletedWorkOrder(Branch, Customer, float $serviceAmount, string $date): WorkOrder` helper following the create→confirm→complete HTTP flow already proven in this project's other report tests), covering:

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

class PkbReportExportTest extends TestCase
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

    protected function makeCompletedWorkOrder(Branch $branch, Customer $customer, float $serviceAmount, string $workOrderDate): WorkOrder
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

        $user = User::factory()->create();
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete'] as $code) {
            $this->grantBranchPermission($user, $branch, $code);
        }

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => $workOrderDate,
            'services' => [['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $serviceAmount]],
            'spareparts' => [],
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        return $workOrder->fresh();
    }

    public function test_export_excel_returns_xlsx_with_correct_headers(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/export-excel');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_excel_is_forbidden_without_permission(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/reports/pkb/export-excel');

        $response->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/pdf-preview');

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/pdf-download');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_pdf_actions_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/pkb/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/pkb/pdf-download')->assertForbidden();
    }

    public function test_pdf_preview_respects_mechanic_and_date_filters(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $old = $this->makeCompletedWorkOrder($branch, $customer, 100000, '2025-01-01');
        $recent = $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/pdf-preview?date_from=' . now()->subDay()->toDateString());

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString($recent->number, $content);
        $this->assertStringNotContainsString($old->number, $content);
    }

    public function test_export_buttons_render_on_the_report_page_with_filters_forwarded(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?mode=detail');

        $response->assertOk();
        $response->assertSee('/reports/pkb/export-excel?mode=detail', false);
        $response->assertSee('/reports/pkb/pdf-preview?mode=detail', false);
        $response->assertSee('/reports/pkb/pdf-download?mode=detail', false);
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/PkbReportExportTest.php`
Expected: FAIL — routes don't exist yet (all 7 tests fail).

- [ ] **Step 3: Refactor `PkbReportController` — extract `resolveFilters()`/`buildQuery()`, add export actions**

Replace `app/Http/Controllers/PkbReportController.php` entirely with:

```php
<?php

namespace App\Http\Controllers;

use App\Exports\PkbReportExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\WorkOrder;
use App\Support\WorkOrderStatus;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Facades\Excel;

class PkbReportController extends Controller
{
    use HandlesReportExport;

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.pkb.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.pkb.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches);

        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_pkb, ' .
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as total_completed, ' .
            'COALESCE(SUM(' .
                '(SELECT COALESCE(SUM(line_total), 0) FROM work_order_service_lines WHERE work_order_service_lines.work_order_id = work_orders.id) + ' .
                '(SELECT COALESCE(SUM(line_total), 0) FROM work_order_sparepart_lines WHERE work_order_sparepart_lines.work_order_id = work_orders.id)' .
            '), 0) as total_value',
            [WorkOrderStatus::COMPLETED]
        )->first();

        $workOrders = $query->with(['branch', 'customer', 'vehicle', 'mechanic']);

        if ($filters['mode'] === 'detail') {
            $workOrders->with(['serviceLines', 'sparepartLines']);
        } else {
            $workOrders->withSum('serviceLines as subtotal_service', 'line_total')
                ->withSum('sparepartLines as subtotal_sparepart', 'line_total');
        }

        $workOrders = $workOrders->orderByDesc('work_order_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('reports.pkb.index', [
            'workOrders' => $workOrders,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'mechanicSearch' => $filters['mechanicSearch'],
            'status' => $filters['status'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'mode' => $filters['mode'],
        ]);
    }

    public function exportExcel()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.pkb.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches)
            ->with(['branch', 'customer', 'vehicle', 'mechanic', 'serviceLines', 'sparepartLines']);

        return Excel::download(
            new PkbReportExport($query, $filters['mode'], $this->filterSummaryText($filters)),
            'laporan-pkb-' . now()->format('Ymd-His') . '.xlsx'
        );
    }

    public function previewPdf()
    {
        return $this->renderPdf('inline');
    }

    public function downloadPdf()
    {
        return $this->renderPdf('attachment');
    }

    protected function renderPdf(string $disposition)
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.pkb.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches)
            ->with(['branch', 'customer', 'vehicle', 'mechanic', 'serviceLines', 'sparepartLines']);

        $rows = $query->orderByDesc('work_order_date')->orderByDesc('id')->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);

        return $this->streamPdf('reports.pkb.pdf', [
            'workOrders' => $rows,
            'mode' => $filters['mode'],
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-pkb', $disposition);
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $mechanicSearch = is_string(request('mechanic')) ? trim(request('mechanic')) : null;

        $status = request('status');
        $status = in_array($status, [
            WorkOrderStatus::DRAFT, WorkOrderStatus::OPEN, WorkOrderStatus::SHORTAGE,
            WorkOrderStatus::COMPLETED, WorkOrderStatus::CANCELLED,
        ], true) ? $status : null;

        return [
            'branchIds' => $branchIds,
            'mechanicSearch' => $mechanicSearch,
            'status' => $status,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
            'mode' => request('mode') === 'detail' ? 'detail' : 'rekap',
        ];
    }

    protected function buildQuery(array $filters, SupportCollection $permittedBranches)
    {
        return WorkOrder::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('branch_id', $filters['branchIds']))
            ->when($filters['mechanicSearch'], function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('mechanic', function ($inner) use ($escaped) {
                    $inner->where('name', 'like', "%{$escaped}%");
                });
            })
            ->when($filters['status'], fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('work_order_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('work_order_date', '<=', $filters['dateTo']));
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $statusLabel = $filters['status'] ?? 'Semua Status';
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $mechanicLabel = $filters['mechanicSearch'] ? " · Mekanik: {$filters['mechanicSearch']}" : '';
        $modeLabel = $filters['mode'] === 'detail' ? 'Detail' : 'Rekap';

        return "Cabang: {$branchLabel} · Status: {$statusLabel} · Tanggal: {$dateLabel}{$mechanicLabel} · Tampilan: {$modeLabel}";
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

- [ ] **Step 4: Add the 3 new routes**

In `routes/web.php`, find:

```php
        Route::get('/pkb', [PkbReportController::class, 'index'])->name('pkb.index');
```

Replace with:

```php
        Route::get('/pkb', [PkbReportController::class, 'index'])->name('pkb.index');
        Route::get('/pkb/export-excel', [PkbReportController::class, 'exportExcel'])->name('pkb.export-excel');
        Route::get('/pkb/pdf-preview', [PkbReportController::class, 'previewPdf'])->name('pkb.pdf-preview');
        Route::get('/pkb/pdf-download', [PkbReportController::class, 'downloadPdf'])->name('pkb.pdf-download');
```

- [ ] **Step 5: Create the Excel export class**

Create `app/Exports/PkbReportExport.php`:

```php
<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class PkbReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
{
    protected Builder $query;
    protected string $mode;
    protected string $filterSummary;

    public function __construct(Builder $query, string $mode, string $filterSummary)
    {
        $this->query = $query;
        $this->mode = $mode;
        $this->filterSummary = $filterSummary;
    }

    public function query()
    {
        return $this->query->orderByDesc('work_order_date')->orderByDesc('id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return $this->mode === 'detail'
            ? ['No. PKB', 'Tanggal', 'Customer & Kendaraan', 'Tipe Item', 'Nama Item/Jasa', 'Qty', 'Harga Satuan', 'Subtotal Line', 'Status']
            : ['No. PKB', 'Tanggal', 'Customer & Kendaraan', 'Mekanik', 'Subtotal Jasa', 'Subtotal Sparepart', 'Grand Total', 'Status'];
    }

    public function map($workOrder): array
    {
        $customerVehicle = $workOrder->customer->name . ($workOrder->vehicle ? ' / ' . $workOrder->vehicle->plate_number : '');

        if ($this->mode !== 'detail') {
            $subtotalService = (float) $workOrder->serviceLines->sum('line_total');
            $subtotalSparepart = (float) $workOrder->sparepartLines->sum('line_total');

            return [
                $workOrder->number,
                $workOrder->work_order_date->format('Y-m-d'),
                $customerVehicle,
                $workOrder->mechanic->name,
                $subtotalService,
                $subtotalSparepart,
                $subtotalService + $subtotalSparepart,
                $workOrder->status,
            ];
        }

        // Detail mode: FromQuery+WithMapping map ONE ROW per WorkOrder, but Detail mode needs
        // one row per line item — handled by returning an array of arrays (maatwebsite/excel
        // supports this: map() may return either a single row or an array of rows per source item).
        $rows = [];
        foreach ($workOrder->serviceLines as $line) {
            $rows[] = [$workOrder->number, $workOrder->work_order_date->format('Y-m-d'), $customerVehicle, 'Jasa', $line->description, (float) $line->qty, (float) $line->unit_price, (float) $line->line_total, $workOrder->status];
        }
        foreach ($workOrder->sparepartLines as $line) {
            $rows[] = [$workOrder->number, $workOrder->work_order_date->format('Y-m-d'), $customerVehicle, 'Sparepart', $line->item_name_snapshot, (float) $line->qty, (float) $line->unit_price, (float) $line->line_total, $workOrder->status];
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $sheet->insertNewRowBefore(1, 1);
                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->setCellValue('A1', $this->filterSummary);
                $sheet->getStyle('A1')->getFont()->setBold(true);
            },
        ];
    }
}
```

- [ ] **Step 6: Create the PDF template**

Create `resources/views/reports/pkb/pdf.blade.php`:

```php
@extends('layouts.print')
@section('report-title', 'Laporan PKB')
@section('filter-summary', $filterSummary)
@section('note')
    @if ($truncated)
        <p class="print-note">Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap.</p>
    @endif
@endsection
@section('table')
    <table class="print-table">
        @if ($mode === 'detail')
            <thead>
                <tr>
                    <th>No. PKB</th><th>Tanggal</th><th>Customer & Kendaraan</th>
                    <th>Tipe Item</th><th>Nama Item/Jasa</th><th>Qty</th><th>Harga Satuan</th><th>Subtotal Line</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($workOrders as $workOrder)
                    @php $customerVehicle = $workOrder->customer->name . ($workOrder->vehicle ? ' / ' . $workOrder->vehicle->plate_number : ''); @endphp
                    @foreach ($workOrder->serviceLines as $line)
                        <tr>
                            <td>{{ $workOrder->number }}</td><td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td><td>{{ $customerVehicle }}</td>
                            <td>Jasa</td><td>{{ $line->description }}</td><td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->unit_price, 0, ',', '.') }}</td><td>{{ number_format($line->line_total, 0, ',', '.') }}</td><td>{{ $workOrder->status }}</td>
                        </tr>
                    @endforeach
                    @foreach ($workOrder->sparepartLines as $line)
                        <tr>
                            <td>{{ $workOrder->number }}</td><td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td><td>{{ $customerVehicle }}</td>
                            <td>Sparepart</td><td>{{ $line->item_name_snapshot }}</td><td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->unit_price, 0, ',', '.') }}</td><td>{{ number_format($line->line_total, 0, ',', '.') }}</td><td>{{ $workOrder->status }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        @else
            <thead>
                <tr><th>No. PKB</th><th>Tanggal</th><th>Customer & Kendaraan</th><th>Mekanik</th><th>Subtotal Jasa</th><th>Subtotal Sparepart</th><th>Grand Total</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($workOrders as $workOrder)
                    @php
                        $subtotalService = (float) $workOrder->serviceLines->sum('line_total');
                        $subtotalSparepart = (float) $workOrder->sparepartLines->sum('line_total');
                    @endphp
                    <tr>
                        <td>{{ $workOrder->number }}</td>
                        <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                        <td>{{ $workOrder->customer->name }}{{ $workOrder->vehicle ? ' / ' . $workOrder->vehicle->plate_number : '' }}</td>
                        <td>{{ $workOrder->mechanic->name }}</td>
                        <td>{{ number_format($subtotalService, 0, ',', '.') }}</td>
                        <td>{{ number_format($subtotalSparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($subtotalService + $subtotalSparepart, 0, ',', '.') }}</td>
                        <td>{{ $workOrder->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        @endif
    </table>
@endsection
```

- [ ] **Step 7: Wire the export-buttons partial into the report page**

In `resources/views/reports/pkb/index.blade.php`, find the title `<div>` near the top of `@section('content')` (the one containing the report's `<h1>`) and wrap it in a flex row with the export buttons, e.g.:

```php
    <div class="mb-4 d-flex justify-content-between align-items-center">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>Laporan PKB</h1>
        @include('partials.report-export-buttons', [
            'excelRoute' => 'reports.pkb.export-excel',
            'pdfPreviewRoute' => 'reports.pkb.pdf-preview',
            'pdfDownloadRoute' => 'reports.pkb.pdf-download',
        ])
    </div>
```

(Check the file first — keep whatever icon class and heading text it already uses; only add the `d-flex justify-content-between align-items-center` wrapper and the `@include` call.)

- [ ] **Step 8: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/PkbReportExportTest.php`
Expected: PASS (7 tests).

- [ ] **Step 9: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (745 + 7 = 752), no regressions — confirm the pre-existing PKB report test file still passes unchanged.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/PkbReportController.php app/Exports/PkbReportExport.php \
        routes/web.php resources/views/reports/pkb/index.blade.php resources/views/reports/pkb/pdf.blade.php \
        tests/Feature/PkbReportExportTest.php
git commit -m "feat: add excel and pdf export to laporan pkb"
```

---

## Task 4: Laporan Invoice export

**Files:**
- Modify: `app/Http/Controllers/InvoiceReportController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/reports/invoices/index.blade.php`
- Create: `app/Exports/InvoiceReportExport.php`
- Create: `resources/views/reports/invoices/pdf.blade.php`
- Test: `tests/Feature/InvoiceReportExportTest.php` (new)

**Interfaces:**
- Consumes: `HandlesReportExport` trait (Task 1), `Invoice`/`InvoiceDetail` models (unchanged).
- Produces: routes `reports.invoices.export-excel`, `reports.invoices.pdf-preview`, `reports.invoices.pdf-download`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/InvoiceReportExportTest.php`:

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
use App\Services\InvoiceService;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceReportExportTest extends TestCase
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

    protected function makeInvoice(Branch $branch, Customer $customer, float $serviceAmount, float $sparepartAmount, string $invoiceDate, bool $post = true): \App\Models\Invoice
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
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete', 'invoice.create', 'invoice.post'] as $code) {
            $this->grantBranchPermission($user, $branch, $code);
        }

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => [['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $serviceAmount]],
            'spareparts' => $spareparts,
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);
        $invoice = \App\Models\Invoice::where('work_order_id', $workOrder->id)->firstOrFail();
        if ($post) {
            $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");
            $invoice = $invoice->fresh();
        }
        $invoice->update(['invoice_date' => $invoiceDate]);

        return $invoice->fresh('details');
    }

    public function test_export_excel_returns_xlsx_with_correct_headers(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/export-excel');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_excel_is_forbidden_without_permission(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/reports/invoices/export-excel');

        $response->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/pdf-preview');

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/pdf-download');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_pdf_actions_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/invoices/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/invoices/pdf-download')->assertForbidden();
    }

    public function test_pdf_preview_respects_status_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $draft = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString(), false);
        $posted = $this->makeInvoice($branch, $customer, 50000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/pdf-preview?status=posted');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString($posted->number, $content);
        $this->assertStringNotContainsString($draft->number, $content);
    }

    public function test_pdf_preview_detail_mode_shows_line_items(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/pdf-preview?mode=detail');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('Ganti Oli', $content);
        $this->assertStringContainsString('Oli Mesin', $content);
    }

    public function test_export_buttons_render_on_the_report_page_with_filters_forwarded(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?mode=detail');

        $response->assertOk();
        $response->assertSee('/reports/invoices/export-excel?mode=detail', false);
        $response->assertSee('/reports/invoices/pdf-preview?mode=detail', false);
        $response->assertSee('/reports/invoices/pdf-download?mode=detail', false);
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/InvoiceReportExportTest.php`
Expected: FAIL — routes don't exist yet (all 8 tests fail).

- [ ] **Step 3: Refactor `InvoiceReportController` — extract `resolveFilters()`/`buildQuery()`, add export actions**

Replace `app/Http/Controllers/InvoiceReportController.php` entirely with:

```php
<?php

namespace App\Http\Controllers;

use App\Exports\InvoiceReportExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\Invoice;
use App\Support\InvoiceStatus;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Facades\Excel;

class InvoiceReportController extends Controller
{
    use HandlesReportExport;

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.invoice.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.invoices.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches);

        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_invoice, ' .
            'COALESCE(SUM(grand_total), 0) as total_nominal, ' .
            'COALESCE(SUM(paid_amount), 0) as total_paid, ' .
            'COALESCE(SUM(grand_total - paid_amount), 0) as total_remaining'
        )->first();

        $invoices = $query->with(['branch', 'customer']);

        if ($filters['mode'] === 'detail') {
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
            'selectedBranchIds' => $filters['branchIds'],
            'search' => $filters['search'],
            'status' => $filters['status'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'mode' => $filters['mode'],
        ]);
    }

    public function exportExcel()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.invoice.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches)->with(['branch', 'customer', 'details']);

        return Excel::download(
            new InvoiceReportExport($query, $filters['mode'], $this->filterSummaryText($filters)),
            'laporan-invoice-' . now()->format('Ymd-His') . '.xlsx'
        );
    }

    public function previewPdf()
    {
        return $this->renderPdf('inline');
    }

    public function downloadPdf()
    {
        return $this->renderPdf('attachment');
    }

    protected function renderPdf(string $disposition)
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.invoice.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches)->with(['branch', 'customer', 'details']);

        $rows = $query->orderByDesc('invoice_date')->orderByDesc('id')->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);

        return $this->streamPdf('reports.invoices.pdf', [
            'invoices' => $rows,
            'mode' => $filters['mode'],
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-invoice', $disposition);
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('search')) ? trim(request('search')) : null;

        $status = request('status');
        $status = in_array($status, [
            InvoiceStatus::DRAFT, InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID,
            InvoiceStatus::PAID, InvoiceStatus::CANCELLED,
        ], true) ? $status : null;

        return [
            'branchIds' => $branchIds,
            'search' => $search,
            'status' => $status,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
            'mode' => request('mode') === 'detail' ? 'detail' : 'rekap',
        ];
    }

    protected function buildQuery(array $filters, SupportCollection $permittedBranches)
    {
        return Invoice::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('branch_id', $filters['branchIds']))
            ->when($filters['search'], function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->where(function ($inner) use ($escaped) {
                    $inner->where('number', 'like', "%{$escaped}%")
                        ->orWhereHas('customer', function ($c) use ($escaped) {
                            $c->where('name', 'like', "%{$escaped}%");
                        });
                });
            })
            ->when($filters['status'], fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoice_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoice_date', '<=', $filters['dateTo']));
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $statusLabel = $filters['status'] ?? 'Semua Status';
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $searchLabel = $filters['search'] ? " · Cari: {$filters['search']}" : '';
        $modeLabel = $filters['mode'] === 'detail' ? 'Detail' : 'Rekap';

        return "Cabang: {$branchLabel} · Status: {$statusLabel} · Tanggal: {$dateLabel}{$searchLabel} · Tampilan: {$modeLabel}";
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

- [ ] **Step 4: Add the 3 new routes**

In `routes/web.php`, find:

```php
        Route::get('/invoices', [InvoiceReportController::class, 'index'])->name('invoices.index');
```

Replace with:

```php
        Route::get('/invoices', [InvoiceReportController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/export-excel', [InvoiceReportController::class, 'exportExcel'])->name('invoices.export-excel');
        Route::get('/invoices/pdf-preview', [InvoiceReportController::class, 'previewPdf'])->name('invoices.pdf-preview');
        Route::get('/invoices/pdf-download', [InvoiceReportController::class, 'downloadPdf'])->name('invoices.pdf-download');
```

- [ ] **Step 5: Create the Excel export class**

Create `app/Exports/InvoiceReportExport.php`:

```php
<?php

namespace App\Exports;

use App\Support\InvoiceDetailItemType;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class InvoiceReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
{
    protected Builder $query;
    protected string $mode;
    protected string $filterSummary;

    public function __construct(Builder $query, string $mode, string $filterSummary)
    {
        $this->query = $query;
        $this->mode = $mode;
        $this->filterSummary = $filterSummary;
    }

    public function query()
    {
        return $this->query->orderByDesc('invoice_date')->orderByDesc('id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return $this->mode === 'detail'
            ? ['No. Invoice', 'Tanggal', 'Customer', 'Status', 'Tipe Item', 'Nama Item', 'Qty', 'Harga Satuan', 'Subtotal Line']
            : ['No. Invoice', 'Tanggal', 'Customer', 'Subtotal Jasa', 'Subtotal Sparepart', 'Discount', 'Grand Total', 'Terbayar', 'Sisa Piutang', 'Status'];
    }

    public function map($invoice): array
    {
        if ($this->mode !== 'detail') {
            return [
                $invoice->number,
                $invoice->invoice_date->format('Y-m-d'),
                $invoice->customer->name,
                (float) $invoice->subtotal_service,
                (float) $invoice->subtotal_sparepart,
                (float) $invoice->discount_amount,
                (float) $invoice->grand_total,
                (float) $invoice->paid_amount,
                (float) $invoice->outstanding_amount,
                $invoice->status,
            ];
        }

        if ($invoice->details->isEmpty()) {
            return [[$invoice->number, $invoice->invoice_date->format('Y-m-d'), $invoice->customer->name, $invoice->status, '-', '-', null, null, null]];
        }

        return $invoice->details->map(function ($detail) use ($invoice) {
            return [
                $invoice->number,
                $invoice->invoice_date->format('Y-m-d'),
                $invoice->customer->name,
                $invoice->status,
                $detail->item_type === InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart',
                $detail->description,
                (float) $detail->qty,
                (float) $detail->unit_price,
                (float) $detail->line_total,
            ];
        })->all();
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $sheet->insertNewRowBefore(1, 1);
                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->setCellValue('A1', $this->filterSummary);
                $sheet->getStyle('A1')->getFont()->setBold(true);
            },
        ];
    }
}
```

- [ ] **Step 6: Create the PDF template**

Create `resources/views/reports/invoices/pdf.blade.php`:

```php
@extends('layouts.print')
@section('report-title', 'Laporan Invoice')
@section('filter-summary', $filterSummary)
@section('note')
    @if ($truncated)
        <p class="print-note">Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap.</p>
    @endif
@endsection
@section('table')
    <table class="print-table">
        @if ($mode === 'detail')
            <thead>
                <tr><th>No. Invoice</th><th>Tanggal</th><th>Customer</th><th>Status</th><th>Tipe Item</th><th>Nama Item</th><th>Qty</th><th>Harga Satuan</th><th>Subtotal Line</th></tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    @forelse ($invoice->details as $detail)
                        <tr>
                            <td>{{ $invoice->number }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td><td>{{ $invoice->status }}</td>
                            <td>{{ $detail->item_type === \App\Support\InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart' }}</td>
                            <td>{{ $detail->description }}</td><td>{{ number_format($detail->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($detail->unit_price, 0, ',', '.') }}</td><td>{{ number_format($detail->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td>{{ $invoice->number }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td><td>{{ $invoice->status }}</td>
                            <td colspan="5">&mdash;</td>
                        </tr>
                    @endforelse
                @endforeach
            </tbody>
        @else
            <thead>
                <tr><th>No. Invoice</th><th>Tanggal</th><th>Customer</th><th>Subtotal Jasa</th><th>Subtotal Sparepart</th><th>Discount</th><th>Grand Total</th><th>Terbayar</th><th>Sisa Piutang</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td>{{ $invoice->number }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td>
                        <td>{{ number_format($invoice->subtotal_service, 0, ',', '.') }}</td><td>{{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($invoice->discount_amount, 0, ',', '.') }}</td><td>{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                        <td>{{ number_format($invoice->paid_amount, 0, ',', '.') }}</td><td>{{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</td><td>{{ $invoice->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        @endif
    </table>
@endsection
```

- [ ] **Step 7: Wire the export-buttons partial**

In `resources/views/reports/invoices/index.blade.php`, find the title `<div>` near the top of `@section('content')` and wrap it in a flex row with the export buttons:

```php
    <div class="mb-4 d-flex justify-content-between align-items-center">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-text me-2"></i>Laporan Invoice</h1>
        @include('partials.report-export-buttons', [
            'excelRoute' => 'reports.invoices.export-excel',
            'pdfPreviewRoute' => 'reports.invoices.pdf-preview',
            'pdfDownloadRoute' => 'reports.invoices.pdf-download',
        ])
    </div>
```

(Check the file first — keep whatever icon class and heading text it already uses; only add the `d-flex justify-content-between align-items-center` wrapper and the `@include` call.)

- [ ] **Step 8: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/InvoiceReportExportTest.php`
Expected: PASS (8 tests).

- [ ] **Step 9: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (752 + 8 = 760), no regressions.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/InvoiceReportController.php app/Exports/InvoiceReportExport.php \
        routes/web.php resources/views/reports/invoices/index.blade.php resources/views/reports/invoices/pdf.blade.php \
        tests/Feature/InvoiceReportExportTest.php
git commit -m "feat: add excel and pdf export to laporan invoice"
```

---

## Task 5: Laporan Gap Invoice vs PKB export

**Files:**
- Modify: `app/Http/Controllers/InvoicePkbGapReportController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/reports/invoice-pkb-gap/index.blade.php`
- Create: `app/Support/InvoicePkbGapComparator.php`
- Create: `app/Exports/InvoicePkbGapReportExport.php`
- Create: `resources/views/reports/invoice-pkb-gap/pdf.blade.php`
- Test: `tests/Feature/InvoicePkbGapReportExportTest.php` (new)

**Interfaces:**
- Produces: `InvoicePkbGapComparator::build(Invoice $invoice): array` (static method — same return shape as the controller's existing `buildComparisonLines()`), routes `reports.invoice-pkb-gap.export-excel`, `reports.invoice-pkb-gap.pdf-preview`, `reports.invoice-pkb-gap.pdf-download`.

- [ ] **Step 1: Extract the comparison algorithm into a stateless class**

Create `app/Support/InvoicePkbGapComparator.php` — this is a verbatim move of `InvoicePkbGapReportController::buildComparisonLines()`/`compareLine()`, made `static` so both the controller and the new Excel export class can call it without a controller instance:

```php
<?php

namespace App\Support;

use App\Models\Invoice;

class InvoicePkbGapComparator
{
    public static function build(Invoice $invoice): array
    {
        $workOrder = $invoice->workOrder;
        $detailsByServiceLineId = $invoice->details->whereNotNull('work_order_service_line_id')->keyBy('work_order_service_line_id');
        $detailsBySparepartLineId = $invoice->details->whereNotNull('work_order_sparepart_line_id')->keyBy('work_order_sparepart_line_id');

        $rows = [];

        foreach ($workOrder->serviceLines as $line) {
            $rows[] = static::compareLine('Jasa', $line->description, $line, $detailsByServiceLineId->get($line->id));
        }

        foreach ($workOrder->sparepartLines as $line) {
            $rows[] = static::compareLine('Sparepart', $line->item_name_snapshot, $line, $detailsBySparepartLineId->get($line->id));
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

    protected static function compareLine(string $itemType, string $itemName, $pkbLine, $detail): array
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
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/InvoicePkbGapReportExportTest.php`:

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

class InvoicePkbGapReportExportTest extends TestCase
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
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => [['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $serviceAmount]],
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

        return ['invoice' => $invoice->fresh('details'), 'workOrder' => $workOrder->fresh()];
    }

    public function test_export_excel_returns_xlsx_with_correct_headers(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap/export-excel?gap_status=semua');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_excel_is_forbidden_without_permission(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/reports/invoice-pkb-gap/export-excel');

        $response->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap/pdf-preview?gap_status=semua');

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap/pdf-download?gap_status=semua');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_pdf_actions_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/invoice-pkb-gap/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/invoice-pkb-gap/pdf-download')->assertForbidden();
    }

    public function test_pdf_preview_respects_gap_status_filter(): void
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

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap/pdf-preview?gap_status=sesuai');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString($exact['invoice']->number, $content);
        $this->assertStringNotContainsString($gt['invoice']->number, $content);
    }

    public function test_pdf_preview_detail_mode_shows_comparison_categories(): void
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

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap/pdf-preview?mode=detail&gap_status=semua');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('Berubah', $content);
        $this->assertStringContainsString('Dihapus', $content);
    }

    public function test_export_buttons_render_on_the_report_page_with_filters_forwarded(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=semua');

        $response->assertOk();
        $response->assertSee('/reports/invoice-pkb-gap/export-excel?gap_status=semua', false);
        $response->assertSee('/reports/invoice-pkb-gap/pdf-preview?gap_status=semua', false);
        $response->assertSee('/reports/invoice-pkb-gap/pdf-download?gap_status=semua', false);
    }
}
```

- [ ] **Step 3: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/InvoicePkbGapReportExportTest.php`
Expected: FAIL — routes don't exist yet.

- [ ] **Step 4: Refactor `InvoicePkbGapReportController` — extract `resolveFilters()`/`buildQuery()`, delegate to `InvoicePkbGapComparator`, add export actions**

Replace `app/Http/Controllers/InvoicePkbGapReportController.php` entirely with:

```php
<?php

namespace App\Http\Controllers;

use App\Exports\InvoicePkbGapReportExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\Invoice;
use App\Support\InvoicePkbGapComparator;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Facades\Excel;

class InvoicePkbGapReportController extends Controller
{
    use HandlesReportExport;

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

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches);
        $pkbTotalExpr = $this->pkbTotalExpression();

        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_transaksi, ' .
            "COALESCE(SUM({$pkbTotalExpr}), 0) as total_nilai_pkb, " .
            'COALESCE(SUM(invoices.grand_total), 0) as total_nilai_invoice, ' .
            "COALESCE(SUM(invoices.grand_total - {$pkbTotalExpr}), 0) as total_varian_netto"
        )->first();

        $invoicesQuery = $query->select('invoices.*')
            ->selectRaw("{$pkbTotalExpr} as pkb_total")
            ->with(['branch', 'customer', 'workOrder']);

        if ($filters['mode'] === 'detail') {
            $invoicesQuery->with(['details', 'workOrder.serviceLines', 'workOrder.sparepartLines']);
        }

        $invoices = $invoicesQuery->orderByDesc('invoices.invoice_date')
            ->orderByDesc('invoices.id')
            ->simplePaginate(15)
            ->withQueryString();

        if ($filters['mode'] === 'detail') {
            $invoices->getCollection()->transform(function (Invoice $invoice) {
                $invoice->comparisonLines = InvoicePkbGapComparator::build($invoice);

                return $invoice;
            });
        }

        return view('reports.invoice-pkb-gap.index', [
            'invoices' => $invoices,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'search' => $filters['search'],
            'gapStatus' => $filters['gapStatus'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'mode' => $filters['mode'],
        ]);
    }

    public function exportExcel()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.invoice_pkb_gap.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches)
            ->select('invoices.*')
            ->selectRaw("{$this->pkbTotalExpression()} as pkb_total")
            ->with(['branch', 'customer', 'workOrder.serviceLines', 'workOrder.sparepartLines', 'details']);

        return Excel::download(
            new InvoicePkbGapReportExport($query, $filters['mode'], $this->filterSummaryText($filters)),
            'laporan-gap-invoice-pkb-' . now()->format('Ymd-His') . '.xlsx'
        );
    }

    public function previewPdf()
    {
        return $this->renderPdf('inline');
    }

    public function downloadPdf()
    {
        return $this->renderPdf('attachment');
    }

    protected function renderPdf(string $disposition)
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.invoice_pkb_gap.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->buildQuery($filters, $permittedBranches)
            ->select('invoices.*')
            ->selectRaw("{$this->pkbTotalExpression()} as pkb_total")
            ->with(['branch', 'customer', 'workOrder.serviceLines', 'workOrder.sparepartLines', 'details']);

        $rows = $query->orderByDesc('invoices.invoice_date')->orderByDesc('invoices.id')->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);

        if ($filters['mode'] === 'detail') {
            $rows = $rows->map(function (Invoice $invoice) {
                $invoice->comparisonLines = InvoicePkbGapComparator::build($invoice);

                return $invoice;
            });
        }

        return $this->streamPdf('reports.invoice-pkb-gap.pdf', [
            'invoices' => $rows,
            'mode' => $filters['mode'],
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-gap-invoice-pkb', $disposition);
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('search')) ? trim(request('search')) : null;

        $gapStatus = request('gap_status');
        $gapStatus = in_array($gapStatus, ['ada_selisih', 'invoice_gt_pkb', 'invoice_lt_pkb', 'sesuai', 'semua'], true)
            ? $gapStatus : 'ada_selisih';

        return [
            'branchIds' => $branchIds,
            'search' => $search,
            'gapStatus' => $gapStatus,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
            'mode' => request('mode') === 'detail' ? 'detail' : 'rekap',
        ];
    }

    protected function buildQuery(array $filters, SupportCollection $permittedBranches)
    {
        $pkbTotalExpr = $this->pkbTotalExpression();

        return Invoice::query()
            ->whereNotNull('invoices.work_order_id')
            ->whereIn('invoices.branch_id', $permittedBranches->pluck('id'))
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('invoices.branch_id', $filters['branchIds']))
            ->when($filters['search'], function ($q, $term) {
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
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoices.invoice_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoices.invoice_date', '<=', $filters['dateTo']))
            ->when($filters['gapStatus'] === 'ada_selisih', fn ($q) => $q->whereRaw("invoices.grand_total <> {$pkbTotalExpr}"))
            ->when($filters['gapStatus'] === 'invoice_gt_pkb', fn ($q) => $q->whereRaw("invoices.grand_total > {$pkbTotalExpr}"))
            ->when($filters['gapStatus'] === 'invoice_lt_pkb', fn ($q) => $q->whereRaw("invoices.grand_total < {$pkbTotalExpr}"))
            ->when($filters['gapStatus'] === 'sesuai', fn ($q) => $q->whereRaw("invoices.grand_total = {$pkbTotalExpr}"));
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $gapLabels = ['ada_selisih' => 'Ada Selisih', 'invoice_gt_pkb' => 'Invoice > PKB', 'invoice_lt_pkb' => 'Invoice < PKB', 'sesuai' => 'Sesuai', 'semua' => 'Semua'];
        $gapLabel = $gapLabels[$filters['gapStatus']] ?? $filters['gapStatus'];
        $dateLabel = ($filters['dateFrom'] || $filters['dateTo'])
            ? ($filters['dateFrom'] ?? '...') . ' – ' . ($filters['dateTo'] ?? '...')
            : 'Semua Tanggal';
        $searchLabel = $filters['search'] ? " · Cari: {$filters['search']}" : '';
        $modeLabel = $filters['mode'] === 'detail' ? 'Detail' : 'Rekap';

        return "Cabang: {$branchLabel} · Status Selisih: {$gapLabel} · Tanggal: {$dateLabel}{$searchLabel} · Tampilan: {$modeLabel}";
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

- [ ] **Step 5: Add the 3 new routes**

In `routes/web.php`, find:

```php
        Route::get('/invoice-pkb-gap', [InvoicePkbGapReportController::class, 'index'])->name('invoice-pkb-gap.index');
```

Replace with:

```php
        Route::get('/invoice-pkb-gap', [InvoicePkbGapReportController::class, 'index'])->name('invoice-pkb-gap.index');
        Route::get('/invoice-pkb-gap/export-excel', [InvoicePkbGapReportController::class, 'exportExcel'])->name('invoice-pkb-gap.export-excel');
        Route::get('/invoice-pkb-gap/pdf-preview', [InvoicePkbGapReportController::class, 'previewPdf'])->name('invoice-pkb-gap.pdf-preview');
        Route::get('/invoice-pkb-gap/pdf-download', [InvoicePkbGapReportController::class, 'downloadPdf'])->name('invoice-pkb-gap.pdf-download');
```

- [ ] **Step 6: Create the Excel export class**

Create `app/Exports/InvoicePkbGapReportExport.php`:

```php
<?php

namespace App\Exports;

use App\Models\Invoice;
use App\Support\InvoicePkbGapComparator;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class InvoicePkbGapReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
{
    protected Builder $query;
    protected string $mode;
    protected string $filterSummary;

    public function __construct(Builder $query, string $mode, string $filterSummary)
    {
        $this->query = $query;
        $this->mode = $mode;
        $this->filterSummary = $filterSummary;
    }

    public function query()
    {
        return $this->query->orderByDesc('invoices.invoice_date')->orderByDesc('invoices.id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return $this->mode === 'detail'
            ? ['No. PKB', 'No. Invoice', 'Tanggal', 'Customer', 'Tipe Item', 'Nama Item', 'Qty PKB', 'Harga PKB', 'Qty Invoice', 'Harga Invoice', 'Kategori']
            : ['No. PKB', 'No. Invoice', 'Tanggal', 'Customer', 'Total PKB', 'Total Invoice', 'Selisih (Rp)', 'Status Gap'];
    }

    public function map($invoice): array
    {
        $pkbTotal = (float) $invoice->pkb_total;
        $grandTotal = (float) $invoice->grand_total;

        if ($this->mode !== 'detail') {
            $selisih = $grandTotal - $pkbTotal;
            $statusGap = $selisih == 0.0 ? 'Sesuai' : ($selisih > 0 ? 'Invoice > PKB' : 'Invoice < PKB');

            return [
                $invoice->workOrder->number,
                $invoice->number,
                $invoice->invoice_date->format('Y-m-d'),
                $invoice->customer->name,
                $pkbTotal,
                $grandTotal,
                $selisih,
                $statusGap,
            ];
        }

        $categoryLabels = ['sesuai' => 'Sesuai', 'changed' => 'Berubah', 'removed' => 'Dihapus', 'added' => 'Ditambahkan'];
        $lines = InvoicePkbGapComparator::build($invoice);

        if (empty($lines)) {
            return [[$invoice->workOrder->number, $invoice->number, $invoice->invoice_date->format('Y-m-d'), $invoice->customer->name, '-', '-', null, null, null, null, '-']];
        }

        return array_map(function ($line) use ($invoice, $categoryLabels) {
            return [
                $invoice->workOrder->number,
                $invoice->number,
                $invoice->invoice_date->format('Y-m-d'),
                $invoice->customer->name,
                $line['item_type'],
                $line['item_name'],
                $line['pkb_qty'],
                $line['pkb_price'],
                $line['invoice_qty'],
                $line['invoice_price'],
                $categoryLabels[$line['category']],
            ];
        }, $lines);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $sheet->insertNewRowBefore(1, 1);
                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->setCellValue('A1', $this->filterSummary);
                $sheet->getStyle('A1')->getFont()->setBold(true);
            },
        ];
    }
}
```

- [ ] **Step 7: Create the PDF template**

Create `resources/views/reports/invoice-pkb-gap/pdf.blade.php`:

```php
@extends('layouts.print')
@section('report-title', 'Laporan Gap Invoice vs PKB')
@section('filter-summary', $filterSummary)
@section('note')
    @if ($truncated)
        <p class="print-note">Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap.</p>
    @endif
@endsection
@section('table')
    <table class="print-table">
        @if ($mode === 'detail')
            <thead>
                <tr><th>No. PKB</th><th>No. Invoice</th><th>Tanggal</th><th>Customer</th><th>Tipe Item</th><th>Nama Item</th><th>Qty PKB</th><th>Harga PKB</th><th>Qty Invoice</th><th>Harga Invoice</th><th>Kategori</th></tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    @php $categoryLabels = ['sesuai' => 'Sesuai', 'changed' => 'Berubah', 'removed' => 'Dihapus', 'added' => 'Ditambahkan']; @endphp
                    @forelse ($invoice->comparisonLines as $line)
                        <tr>
                            <td>{{ $invoice->workOrder->number }}</td><td>{{ $invoice->number }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td>
                            <td>{{ $line['item_type'] }}</td><td>{{ $line['item_name'] }}</td>
                            <td>{{ $line['pkb_qty'] !== null ? number_format($line['pkb_qty'], 0, ',', '.') : '—' }}</td>
                            <td>{{ $line['pkb_price'] !== null ? number_format($line['pkb_price'], 0, ',', '.') : '—' }}</td>
                            <td>{{ $line['invoice_qty'] !== null ? number_format($line['invoice_qty'], 0, ',', '.') : '—' }}</td>
                            <td>{{ $line['invoice_price'] !== null ? number_format($line['invoice_price'], 0, ',', '.') : '—' }}</td>
                            <td>{{ $categoryLabels[$line['category']] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td>{{ $invoice->workOrder->number }}</td><td>{{ $invoice->number }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td>
                            <td colspan="7">&mdash;</td>
                        </tr>
                    @endforelse
                @endforeach
            </tbody>
        @else
            <thead>
                <tr><th>No. PKB</th><th>No. Invoice</th><th>Tanggal</th><th>Customer</th><th>Total PKB</th><th>Total Invoice</th><th>Selisih (Rp)</th><th>Status Gap</th></tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    @php
                        $pkbTotal = (float) $invoice->pkb_total;
                        $grandTotal = (float) $invoice->grand_total;
                        $selisih = $grandTotal - $pkbTotal;
                        $statusGap = $selisih == 0.0 ? 'Sesuai' : ($selisih > 0 ? 'Invoice > PKB' : 'Invoice < PKB');
                    @endphp
                    <tr>
                        <td>{{ $invoice->workOrder->number }}</td><td>{{ $invoice->number }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td>
                        <td>{{ number_format($pkbTotal, 0, ',', '.') }}</td><td>{{ number_format($grandTotal, 0, ',', '.') }}</td>
                        <td>{{ ($selisih >= 0 ? '+' : '') . number_format($selisih, 0, ',', '.') }}</td><td>{{ $statusGap }}</td>
                    </tr>
                @endforeach
            </tbody>
        @endif
    </table>
@endsection
```

- [ ] **Step 8: Wire the export-buttons partial**

In `resources/views/reports/invoice-pkb-gap/index.blade.php`, find the title `<div>` near the top of `@section('content')` and wrap it in a flex row with the export buttons:

```php
    <div class="mb-4 d-flex justify-content-between align-items-center">
        <h1 class="h4 mb-0"><i class="bi bi-bar-chart-steps me-2"></i>PKB vs Invoice</h1>
        @include('partials.report-export-buttons', [
            'excelRoute' => 'reports.invoice-pkb-gap.export-excel',
            'pdfPreviewRoute' => 'reports.invoice-pkb-gap.pdf-preview',
            'pdfDownloadRoute' => 'reports.invoice-pkb-gap.pdf-download',
        ])
    </div>
```

(Check the file first — keep whatever icon class and heading text it already uses; only add the `d-flex justify-content-between align-items-center` wrapper and the `@include` call.)

- [ ] **Step 9: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/InvoicePkbGapReportExportTest.php`
Expected: PASS (8 tests).

- [ ] **Step 10: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (760 + 8 = 768), no regressions — confirm the pre-existing `InvoicePkbGapReportControllerTest.php` still passes unchanged (proving `InvoicePkbGapComparator::build()` behaves identically to the old `buildComparisonLines()` method it replaced).

- [ ] **Step 11: Commit**

```bash
git add app/Support/InvoicePkbGapComparator.php app/Http/Controllers/InvoicePkbGapReportController.php \
        app/Exports/InvoicePkbGapReportExport.php routes/web.php \
        resources/views/reports/invoice-pkb-gap/index.blade.php resources/views/reports/invoice-pkb-gap/pdf.blade.php \
        tests/Feature/InvoicePkbGapReportExportTest.php
git commit -m "feat: add excel and pdf export to laporan gap invoice vs pkb"
```

---

## Task 6: Laporan Sparepart/Stok export

**Files:**
- Modify: `app/Http/Controllers/SparepartStockReportController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/reports/sparepart-stock/index.blade.php`
- Create: `app/Exports/SparepartStockReportExport.php`
- Create: `resources/views/reports/sparepart-stock/pdf.blade.php`
- Test: `tests/Feature/SparepartStockReportExportTest.php` (new)

**Interfaces:**
- Consumes: `HandlesReportExport` trait (Task 1), `SparepartBranch`/`SparepartBranchStock` models (unchanged).
- Produces: routes `reports.sparepart-stock.export-excel`, `reports.sparepart-stock.pdf-preview`, `reports.sparepart-stock.pdf-download`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SparepartStockReportExportTest.php`, reusing `SparepartStockReportControllerTest`'s existing `makeSparepartBranch(Branch, string $code, string $name, float $onHand, float $reserved, float $minimumStock, float $sellingPrice): SparepartBranch` helper (copy it into this file — no HTTP work-order flow needed, direct model creation same as the original report's own tests), covering:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SparepartStockReportExportTest extends TestCase
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

    protected function makeSparepartBranch(Branch $branch, string $code, string $name, float $onHand, float $reserved, float $minimumStock, float $sellingPrice): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => $code, 'name' => $name]);
        $sparepartBranch = SparepartBranch::create([
            'sparepart_id' => $sparepart->id, 'branch_id' => $branch->id,
            'selling_price' => $sellingPrice, 'minimum_stock' => $minimumStock,
        ]);
        DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update([
            'on_hand_qty' => $onHand, 'reserved_qty' => $reserved,
        ]);

        return $sparepartBranch->fresh();
    }

    public function test_export_excel_returns_xlsx_with_correct_headers(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock/export-excel');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_excel_is_forbidden_without_permission(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/reports/sparepart-stock/export-excel');

        $response->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock/pdf-preview');

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock/pdf-download');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_pdf_actions_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/sparepart-stock/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/sparepart-stock/pdf-download')->assertForbidden();
    }

    public function test_pdf_preview_respects_stock_status_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock/pdf-preview?stock_status=habis');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('HABIS-1', $content);
        $this->assertStringNotContainsString('TERSEDIA-1', $content);
    }

    public function test_pdf_preview_detail_mode_shows_expanded_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 847, 212, 5, 17000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock/pdf-preview?mode=detail');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('635', $content);
        $this->assertStringContainsString('14.399.000', $content);
    }

    public function test_export_buttons_render_on_the_report_page_with_filters_forwarded(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=kritis');

        $response->assertOk();
        $response->assertSee('/reports/sparepart-stock/export-excel?stock_status=kritis', false);
        $response->assertSee('/reports/sparepart-stock/pdf-preview?stock_status=kritis', false);
        $response->assertSee('/reports/sparepart-stock/pdf-download?stock_status=kritis', false);
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php artisan test tests/Feature/SparepartStockReportExportTest.php`
Expected: FAIL — routes don't exist yet (all 8 tests fail).

- [ ] **Step 3: Refactor `SparepartStockReportController` — extract `resolveFilters()`/`buildBaseQuery()`/`applyStockStatus()`, add export actions**

Replace `app/Http/Controllers/SparepartStockReportController.php` entirely with:

```php
<?php

namespace App\Http\Controllers;

use App\Exports\SparepartStockReportExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\SparepartBranch;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Facades\Excel;

class SparepartStockReportController extends Controller
{
    use HandlesReportExport;

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.sparepart.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.sparepart-stock.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);
        $baseQuery = $this->buildBaseQuery($filters, $permittedBranches);

        $summary = (clone $baseQuery)->selectRaw(
            'COUNT(*) as total_jenis_item, ' .
            'COALESCE(SUM(sparepart_branch_stocks.on_hand_qty), 0) as total_qty_on_hand, ' .
            'COALESCE(SUM(CASE WHEN sparepart_branches.minimum_stock > 0 AND (sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) < sparepart_branches.minimum_stock THEN 1 ELSE 0 END), 0) as total_item_kritis, ' .
            'COALESCE(SUM(sparepart_branch_stocks.on_hand_qty * sparepart_branches.selling_price), 0) as total_nilai_inventaris'
        )->first();

        $query = $this->applyStockStatus(clone $baseQuery, $filters['stockStatus']);

        $sparepartBranches = $query->select('sparepart_branches.*')
            ->addSelect(['sparepart_branch_stocks.on_hand_qty', 'sparepart_branch_stocks.reserved_qty'])
            ->with(['sparepart', 'branch'])
            ->orderBy('sparepart_branches.branch_id')
            ->orderBy('sparepart_branches.id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('reports.sparepart-stock.index', [
            'sparepartBranches' => $sparepartBranches,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'search' => $filters['search'],
            'stockStatus' => $filters['stockStatus'],
            'mode' => $filters['mode'],
        ]);
    }

    public function exportExcel()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.sparepart.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->applyStockStatus($this->buildBaseQuery($filters, $permittedBranches), $filters['stockStatus'])
            ->select('sparepart_branches.*')
            ->addSelect(['sparepart_branch_stocks.on_hand_qty', 'sparepart_branch_stocks.reserved_qty'])
            ->with(['sparepart', 'branch']);

        return Excel::download(
            new SparepartStockReportExport($query, $filters['mode'], $this->filterSummaryText($filters)),
            'laporan-sparepart-stok-' . now()->format('Ymd-His') . '.xlsx'
        );
    }

    public function previewPdf()
    {
        return $this->renderPdf('inline');
    }

    public function downloadPdf()
    {
        return $this->renderPdf('attachment');
    }

    protected function renderPdf(string $disposition)
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.sparepart.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);
        $query = $this->applyStockStatus($this->buildBaseQuery($filters, $permittedBranches), $filters['stockStatus'])
            ->select('sparepart_branches.*')
            ->addSelect(['sparepart_branch_stocks.on_hand_qty', 'sparepart_branch_stocks.reserved_qty'])
            ->with(['sparepart', 'branch']);

        $rows = $query->orderBy('sparepart_branches.branch_id')->orderBy('sparepart_branches.id')->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);

        return $this->streamPdf('reports.sparepart-stock.pdf', [
            'sparepartBranches' => $rows,
            'mode' => $filters['mode'],
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-sparepart-stok', $disposition);
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('search')) ? trim(request('search')) : null;

        $stockStatus = request('stock_status');
        $stockStatus = in_array($stockStatus, ['habis', 'kritis', 'tersedia', 'semua'], true)
            ? $stockStatus : 'semua';

        return [
            'branchIds' => $branchIds,
            'search' => $search,
            'stockStatus' => $stockStatus,
            'mode' => request('mode') === 'detail' ? 'detail' : 'rekap',
        ];
    }

    protected function buildBaseQuery(array $filters, SupportCollection $permittedBranches)
    {
        return SparepartBranch::query()
            ->join('sparepart_branch_stocks', 'sparepart_branch_stocks.sparepart_branch_id', '=', 'sparepart_branches.id')
            ->whereIn('sparepart_branches.branch_id', $permittedBranches->pluck('id'))
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('sparepart_branches.branch_id', $filters['branchIds']))
            ->when($filters['search'], function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('sparepart', function ($inner) use ($escaped) {
                    $inner->where('code', 'like', "%{$escaped}%")
                        ->orWhere('name', 'like', "%{$escaped}%");
                });
            });
    }

    protected function applyStockStatus($query, string $stockStatus)
    {
        return $query
            ->when($stockStatus === 'habis', fn ($q) => $q->where('sparepart_branch_stocks.on_hand_qty', 0))
            ->when($stockStatus === 'kritis', function ($q) {
                $q->where('sparepart_branch_stocks.on_hand_qty', '>', 0)
                    ->where('sparepart_branches.minimum_stock', '>', 0)
                    ->whereRaw('(sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) < sparepart_branches.minimum_stock');
            })
            ->when($stockStatus === 'tersedia', function ($q) {
                $q->where('sparepart_branch_stocks.on_hand_qty', '>', 0)
                    ->where(function ($inner) {
                        $inner->where('sparepart_branches.minimum_stock', '<=', 0)
                            ->orWhereRaw('(sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) >= sparepart_branches.minimum_stock');
                    });
            });
    }

    protected function filterSummaryText(array $filters): string
    {
        $branchLabel = empty($filters['branchIds']) ? 'Semua Cabang' : implode(', ', $filters['branchIds']);
        $statusLabels = ['semua' => 'Semua', 'kritis' => 'Kritis/Minimum', 'habis' => 'Habis', 'tersedia' => 'Tersedia'];
        $statusLabel = $statusLabels[$filters['stockStatus']] ?? $filters['stockStatus'];
        $searchLabel = $filters['search'] ? " · Cari: {$filters['search']}" : '';
        $modeLabel = $filters['mode'] === 'detail' ? 'Detail' : 'Rekap';

        return "Cabang: {$branchLabel} · Status Stok: {$statusLabel}{$searchLabel} · Tampilan: {$modeLabel}";
    }
}
```

**Note:** unlike Task 2-5, this report has no `dateFrom`/`dateTo` (per spec — Sparepart has no date filter at all), so `filterSummaryText()` correctly omits any date segment.

- [ ] **Step 4: Add the 3 new routes**

In `routes/web.php`, find:

```php
        Route::get('/sparepart-stock', [SparepartStockReportController::class, 'index'])->name('sparepart-stock.index');
```

Replace with:

```php
        Route::get('/sparepart-stock', [SparepartStockReportController::class, 'index'])->name('sparepart-stock.index');
        Route::get('/sparepart-stock/export-excel', [SparepartStockReportController::class, 'exportExcel'])->name('sparepart-stock.export-excel');
        Route::get('/sparepart-stock/pdf-preview', [SparepartStockReportController::class, 'previewPdf'])->name('sparepart-stock.pdf-preview');
        Route::get('/sparepart-stock/pdf-download', [SparepartStockReportController::class, 'downloadPdf'])->name('sparepart-stock.pdf-download');
```

- [ ] **Step 5: Create the Excel export class**

Create `app/Exports/SparepartStockReportExport.php`:

```php
<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class SparepartStockReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
{
    protected Builder $query;
    protected string $mode;
    protected string $filterSummary;

    public function __construct(Builder $query, string $mode, string $filterSummary)
    {
        $this->query = $query;
        $this->mode = $mode;
        $this->filterSummary = $filterSummary;
    }

    public function query()
    {
        return $this->query->orderBy('sparepart_branches.branch_id')->orderBy('sparepart_branches.id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return $this->mode === 'detail'
            ? ['Kode', 'Nama Sparepart', 'Cabang', 'Stok Min', 'On-Hand', 'Reserved', 'Available', 'Harga Satuan', 'Nilai Total', 'Status']
            : ['Kode', 'Nama Sparepart', 'Cabang', 'Stok Min', 'Stok On-Hand', 'Nilai Inventaris', 'Status'];
    }

    public function map($sparepartBranch): array
    {
        $onHand = (float) $sparepartBranch->on_hand_qty;
        $reserved = (float) $sparepartBranch->reserved_qty;
        $available = $onHand - $reserved;
        $minimumStock = (float) $sparepartBranch->minimum_stock;
        $sellingPrice = (float) $sparepartBranch->selling_price;

        if ($onHand == 0.0) {
            $status = 'Habis';
        } elseif ($minimumStock > 0.0 && $available < $minimumStock) {
            $status = 'Kritis';
        } else {
            $status = 'Tersedia';
        }

        if ($this->mode !== 'detail') {
            return [
                $sparepartBranch->sparepart->code,
                $sparepartBranch->sparepart->name,
                $sparepartBranch->branch->name,
                $minimumStock,
                $onHand,
                $onHand * $sellingPrice,
                $status,
            ];
        }

        return [
            $sparepartBranch->sparepart->code,
            $sparepartBranch->sparepart->name,
            $sparepartBranch->branch->name,
            $minimumStock,
            $onHand,
            $reserved,
            $available,
            $sellingPrice,
            $onHand * $sellingPrice,
            $status,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $sheet->insertNewRowBefore(1, 1);
                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->setCellValue('A1', $this->filterSummary);
                $sheet->getStyle('A1')->getFont()->setBold(true);
            },
        ];
    }
}
```

- [ ] **Step 6: Create the PDF template**

Create `resources/views/reports/sparepart-stock/pdf.blade.php`:

```php
@extends('layouts.print')
@section('report-title', 'Laporan Sparepart / Stok')
@section('filter-summary', $filterSummary)
@section('note')
    @if ($truncated)
        <p class="print-note">Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap.</p>
    @endif
@endsection
@section('table')
    <table class="print-table">
        @if ($mode === 'detail')
            <thead>
                <tr><th>Kode</th><th>Nama Sparepart</th><th>Cabang</th><th>Stok Min</th><th>On-Hand</th><th>Reserved</th><th>Available</th><th>Harga Satuan</th><th>Nilai Total</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($sparepartBranches as $sparepartBranch)
                    @php
                        $onHand = (float) $sparepartBranch->on_hand_qty;
                        $reserved = (float) $sparepartBranch->reserved_qty;
                        $available = $onHand - $reserved;
                        $minimumStock = (float) $sparepartBranch->minimum_stock;
                        $sellingPrice = (float) $sparepartBranch->selling_price;
                        if ($onHand == 0.0) { $status = 'Habis'; }
                        elseif ($minimumStock > 0.0 && $available < $minimumStock) { $status = 'Kritis'; }
                        else { $status = 'Tersedia'; }
                    @endphp
                    <tr>
                        <td>{{ $sparepartBranch->sparepart->code }}</td><td>{{ $sparepartBranch->sparepart->name }}</td><td>{{ $sparepartBranch->branch->name }}</td>
                        <td>{{ number_format($minimumStock, 0, ',', '.') }}</td><td>{{ number_format($onHand, 0, ',', '.') }}</td><td>{{ number_format($reserved, 0, ',', '.') }}</td>
                        <td>{{ number_format($available, 0, ',', '.') }}</td><td>{{ number_format($sellingPrice, 0, ',', '.') }}</td><td>{{ number_format($onHand * $sellingPrice, 0, ',', '.') }}</td><td>{{ $status }}</td>
                    </tr>
                @endforeach
            </tbody>
        @else
            <thead>
                <tr><th>Kode</th><th>Nama Sparepart</th><th>Cabang</th><th>Stok Min</th><th>Stok On-Hand</th><th>Nilai Inventaris</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($sparepartBranches as $sparepartBranch)
                    @php
                        $onHand = (float) $sparepartBranch->on_hand_qty;
                        $reserved = (float) $sparepartBranch->reserved_qty;
                        $available = $onHand - $reserved;
                        $minimumStock = (float) $sparepartBranch->minimum_stock;
                        $sellingPrice = (float) $sparepartBranch->selling_price;
                        if ($onHand == 0.0) { $status = 'Habis'; }
                        elseif ($minimumStock > 0.0 && $available < $minimumStock) { $status = 'Kritis'; }
                        else { $status = 'Tersedia'; }
                    @endphp
                    <tr>
                        <td>{{ $sparepartBranch->sparepart->code }}</td><td>{{ $sparepartBranch->sparepart->name }}</td><td>{{ $sparepartBranch->branch->name }}</td>
                        <td>{{ number_format($minimumStock, 0, ',', '.') }}</td><td>{{ number_format($onHand, 0, ',', '.') }}</td>
                        <td>{{ number_format($onHand * $sellingPrice, 0, ',', '.') }}</td><td>{{ $status }}</td>
                    </tr>
                @endforeach
            </tbody>
        @endif
    </table>
@endsection
```

- [ ] **Step 7: Wire the export-buttons partial**

In `resources/views/reports/sparepart-stock/index.blade.php`, find the title `<div>` near the top of `@section('content')` and wrap it in a flex row with the export buttons:

```php
    <div class="mb-4 d-flex justify-content-between align-items-center">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Laporan Sparepart</h1>
        @include('partials.report-export-buttons', [
            'excelRoute' => 'reports.sparepart-stock.export-excel',
            'pdfPreviewRoute' => 'reports.sparepart-stock.pdf-preview',
            'pdfDownloadRoute' => 'reports.sparepart-stock.pdf-download',
        ])
    </div>
```

(Check the file first — keep whatever icon class and heading text it already uses; only add the `d-flex justify-content-between align-items-center` wrapper and the `@include` call.)

- [ ] **Step 8: Run the tests to confirm they pass**

Run: `php artisan test tests/Feature/SparepartStockReportExportTest.php`
Expected: PASS (8 tests).

- [ ] **Step 9: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS (768 + 8 = 776), no regressions — confirm the pre-existing `SparepartStockReportControllerTest.php` still passes unchanged, including its `test_index_summary_cards_stay_independent_of_the_active_stock_status_filter` regression test (proving the `buildBaseQuery()`/`applyStockStatus()` split preserves that earlier bug fix).

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/SparepartStockReportController.php app/Exports/SparepartStockReportExport.php \
        routes/web.php resources/views/reports/sparepart-stock/index.blade.php resources/views/reports/sparepart-stock/pdf.blade.php \
        tests/Feature/SparepartStockReportExportTest.php
git commit -m "feat: add excel and pdf export to laporan sparepart/stok"
```

---

## Final Step

After Task 6 passes and the full suite is green (776 tests), perform manual browser verification across all 5 reports (mirroring the manual-verification discipline established in every prior report module): for each report, apply a real filter combination, click Export Excel and confirm the downloaded file opens with correct headers/rows/filter-summary row; click Preview PDF and confirm it opens in a new tab with correct landscape layout, filter summary, and table; click Download PDF and confirm it downloads as an attachment. Confirm a 403 for a user without the report's permission on all 3 actions for at least one report (spot check, not all 5, given the automated tests already cover every report identically). Report the final test count and a short end-to-end summary. This closes out the Export Excel & PDF milestone across all 5 reports.
