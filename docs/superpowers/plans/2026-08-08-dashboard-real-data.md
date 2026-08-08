# Dashboard Real-Time Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ganti seluruh data dummy di `/dashboard` (Card 3, Card 4, kedua chart, Tab 1, Tab 3) dengan query
real ke database, sambil mempertahankan kontrak AJAX yang sudah ada (payload JSON, ID elemen DOM, alur
`fetchDashboard()`/`applyPayload()`).

**Architecture:** Setiap widget menghitung *scoped branch ids*-nya sendiri lewat `scopedBranchIdsFor($user,
$selectedBranchIds, $permissionCode)` generik (permission per modul: `pkb.view`, `invoice.view`,
`sparepart.view`; Audit Log pakai permission global `audit_log.view` via `hasPermissionTo()`, bukan
per-cabang). Tren "Invoice Posted" mingguan memakai `audit_logs` (event `invoice.posted`) sebagai sumber
waktu posting yang akurat karena `Invoice` tidak punya kolom `posted_at`. Severity Audit Log di-derive dari
mapping statis `AuditEvent::SEVERITIES` (kolom fisik tidak ada di skema).

**Tech Stack:** Laravel 8.75, PHP 7.4.33, MySQL, Chart.js (sudah terpasang di view), vanilla JS (tanpa
framework tambahan, mengikuti pola AJAX yang sudah ada di `dashboard/index.blade.php`).

## Global Constraints

- PHP 7.4.33 runtime — jangan pakai sintaks PHP8.
- MySQL, branch-scoped permission per modul (`hasPermissionToInBranch`/`branchesWithPermission`) kecuali
  Audit Log yang permission-nya global (`hasPermissionTo`).
- Endpoint AJAX tetap satu: `GET /dashboard` (mendukung HTML & JSON via `$request->wantsJson()`) — jangan
  buat endpoint baru.
- ID elemen DOM yang sudah ada (`kpiStockAvailable`, `kpiCriticalStock`, `trendChart`, `receivablesChart`,
  `kartuStokSparepartSelect`, dst) **tidak boleh berubah nama** — JS lama yang menggantungkan diri padanya
  harus tetap jalan.
- Semua string yang berasal dari data user (nama customer, dsb.) yang dirender lewat JS **wajib** lewat
  `textContent`/DOM API, bukan `innerHTML` dengan concatenation string (cegah XSS).
- Setiap test baru memakai pola helper (`grantBranchPermission`, dst.) yang sudah konsisten dipakai di
  seluruh test suite proyek ini — duplikasi helper per file test, bukan trait bersama (mengikuti
  konvensi yang sudah ada).

---

### Task 1: `AuditEvent::SEVERITIES` + Refactor Card 3, Card 4, Kedua Chart

**Files:**
- Modify: `app/Support/AuditEvent.php`
- Modify: `app/Http/Controllers/DashboardController.php`
- Test: `tests/Unit/AuditEventTest.php`
- Test: `tests/Feature/DashboardCardsTest.php`

**Interfaces:**
- Produces: `DashboardController::scopedBranchIdsFor(User $user, array $selectedBranchIds, string
  $permissionCode): array` — dipakai Task 2 & Task 3 (tidak langsung, lewat `buildPayload()`).
- Produces: `AuditEvent::SEVERITIES` — dipakai Task 2 (`computeAuditLogRows()`).

- [ ] **Step 1: Tulis test `AuditEventTest`**

`tests/Unit/AuditEventTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\AuditEvent;
use Tests\TestCase;

class AuditEventTest extends TestCase
{
    public function test_every_known_event_has_a_severity_mapping(): void
    {
        foreach (array_keys(AuditEvent::LABELS) as $event) {
            $this->assertArrayHasKey($event, AuditEvent::SEVERITIES, "Event {$event} belum punya mapping severity.");
        }
    }

    public function test_severity_values_are_valid(): void
    {
        foreach (AuditEvent::SEVERITIES as $event => $severity) {
            $this->assertContains($severity, ['LOW', 'MEDIUM', 'HIGH'], "Event {$event} punya severity tidak valid: {$severity}.");
        }
    }

    public function test_permission_grant_and_revoke_are_high_severity(): void
    {
        $this->assertSame('HIGH', AuditEvent::SEVERITIES[AuditEvent::USER_BRANCH_PERMISSION_GRANTED]);
        $this->assertSame('HIGH', AuditEvent::SEVERITIES[AuditEvent::USER_BRANCH_PERMISSION_REVOKED]);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=AuditEventTest`
Expected: FAIL/ERROR — `AuditEvent::SEVERITIES` belum ada (undefined constant).

- [ ] **Step 3: Tambahkan `AuditEvent::SEVERITIES`**

Edit `app/Support/AuditEvent.php`, tambahkan setelah blok `const LABELS = [...]`:

```php
    const SEVERITIES = [
        self::INVOICE_POSTED => 'LOW',
        self::INVOICE_CANCELLED => 'MEDIUM',
        self::PAYMENT_RECEIPT_CREATED => 'LOW',
        self::PAYMENT_RECEIPT_VOIDED => 'MEDIUM',
        self::STOCK_ADJUSTMENT_POSTED => 'MEDIUM',
        self::STOCK_TRANSFER_DISPATCHED => 'LOW',
        self::STOCK_TRANSFER_RECEIVED => 'LOW',
        self::STOCK_TRANSFER_VOIDED => 'MEDIUM',
        self::USER_BRANCH_PERMISSION_GRANTED => 'HIGH',
        self::USER_BRANCH_PERMISSION_REVOKED => 'HIGH',
    ];
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=AuditEventTest`
Expected: 3 test PASS.

- [ ] **Step 5: Tulis test `DashboardCardsTest`**

`tests/Feature/DashboardCardsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\UserBranchService;
use App\Support\AuditEvent;
use App\Support\InvoiceStatus;
use App\Support\WorkOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardCardsTest extends TestCase
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

    protected function makeCustomerVehicleMechanic(Branch $branch): array
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

        return [$customer, $vehicle, $mechanic];
    }

    protected function makeWorkOrderRow(Branch $branch, Customer $customer, Vehicle $vehicle, Mechanic $mechanic, string $status, string $workOrderDate, string $numberSuffix): WorkOrder
    {
        return WorkOrder::create([
            'number' => "PKB-TEST-{$numberSuffix}",
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => $workOrderDate,
            'status' => $status,
        ]);
    }

    protected function makeInvoiceRow(Branch $branch, Customer $customer, WorkOrder $workOrder, string $status, float $grandTotal, float $paidAmount, ?string $dueDate = null, ?string $invoiceDate = null): Invoice
    {
        return Invoice::create([
            'number' => 'INV-TEST-' . $workOrder->id,
            'work_order_id' => $workOrder->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => $invoiceDate ?? now()->toDateString(),
            'due_date' => $dueDate,
            'status' => $status,
            'subtotal_service' => $grandTotal,
            'subtotal_sparepart' => 0,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_percent' => 0,
            'tax_amount' => 0,
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
        ]);
    }

    public function test_pkb_status_today_breaks_down_by_status_excluding_cancelled_and_other_days(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::DRAFT, $today, '1');
        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::OPEN, $today, '2');
        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::SHORTAGE, $today, '3');
        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, $today, '4');
        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::CANCELLED, $today, '5');
        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::OPEN, $yesterday, '6');

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $response->assertJson(['pkbStatus' => ['draft' => 1, 'open' => 1, 'shortage' => 1, 'completed' => 1]]);
    }

    public function test_receivables_summary_computes_revenue_and_unpaid_per_definition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);

        $woDraft = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'd');
        $this->makeInvoiceRow($branch, $customer, $woDraft, InvoiceStatus::DRAFT, 100000, 0);

        $woPosted = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'p');
        $this->makeInvoiceRow($branch, $customer, $woPosted, InvoiceStatus::POSTED, 200000, 0);

        $woPartial = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'pp');
        $this->makeInvoiceRow($branch, $customer, $woPartial, InvoiceStatus::PARTIALLY_PAID, 300000, 100000);

        $woPaid = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'pd');
        $this->makeInvoiceRow($branch, $customer, $woPaid, InvoiceStatus::PAID, 400000, 400000);

        $woCancelled = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'c');
        $this->makeInvoiceRow($branch, $customer, $woCancelled, InvoiceStatus::CANCELLED, 500000, 0);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        // revenue = 200000 (posted) + 300000 (partial) + 400000 (paid) = 900000
        // unpaid = (200000-0) + (300000-100000) = 400000
        $response->assertJson(['receivables' => ['revenue' => 900000, 'unpaid' => 400000]]);
    }

    public function test_receivables_aging_buckets_unpaid_invoices_by_days_overdue(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);

        $woNotDue = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'nd');
        $this->makeInvoiceRow($branch, $customer, $woNotDue, InvoiceStatus::POSTED, 100000, 0, now()->addDays(5)->toDateString());

        $wo1to30 = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 't1');
        $this->makeInvoiceRow($branch, $customer, $wo1to30, InvoiceStatus::POSTED, 200000, 0, now()->subDays(10)->toDateString());

        $wo31to60 = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 't2');
        $this->makeInvoiceRow($branch, $customer, $wo31to60, InvoiceStatus::POSTED, 300000, 0, now()->subDays(45)->toDateString());

        $wo60plus = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 't3');
        $this->makeInvoiceRow($branch, $customer, $wo60plus, InvoiceStatus::POSTED, 400000, 0, now()->subDays(90)->toDateString());

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $response->assertJson(['chartReceivables' => [
            'labels' => ['Belum Jatuh Tempo', '1-30 Hari', '31-60 Hari', '>60 Hari'],
            'values' => [100000, 200000, 300000, 400000],
        ]]);
    }

    public function test_weekly_trend_counts_work_orders_created_and_invoices_posted_via_audit_log(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);

        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::DRAFT, now()->toDateString(), 'w0');
        $threeWeeksAgoWo = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::DRAFT, now()->toDateString(), 'w3');
        DB::table('work_orders')->where('id', $threeWeeksAgoWo->id)->update(['created_at' => now()->subWeeks(3)]);

        AuditLog::create(['branch_id' => $branch->id, 'event' => AuditEvent::INVOICE_POSTED]);
        $oldLog = AuditLog::create(['branch_id' => $branch->id, 'event' => AuditEvent::INVOICE_POSTED]);
        DB::table('audit_logs')->where('id', $oldLog->id)->update(['created_at' => now()->subWeeks(5)]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $data = $response->json();
        $this->assertCount(8, $data['chartTrend']['labels']);
        $this->assertSame(1, $data['chartTrend']['pkb'][7]); // pekan ini
        $this->assertSame(1, $data['chartTrend']['pkb'][4]); // 3 pekan lalu
        $this->assertSame(1, $data['chartTrend']['invoice'][7]);
        $this->assertSame(1, $data['chartTrend']['invoice'][2]); // 5 pekan lalu
    }

    public function test_each_widget_scopes_branches_by_its_own_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);
        $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::OPEN, now()->toDateString(), 'x');

        $user = User::factory()->create();
        // Hanya sparepart.view, TIDAK pkb.view — Card 3 (PKB) harus tetap nol karena widget itu
        // butuh pkb.view sendiri, terlepas dari sparepart.view yang dimiliki.
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $response->assertJson(['pkbStatus' => ['draft' => 0, 'open' => 0, 'shortage' => 0, 'completed' => 0]]);
    }
}
```

- [ ] **Step 6: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=DashboardCardsTest`
Expected: FAIL — `pkbStatus`/`receivables`/`chartTrend`/`chartReceivables` masih dummy, tidak cocok
dengan assertion.

- [ ] **Step 7: Refactor `DashboardController`**

Edit `app/Http/Controllers/DashboardController.php`. Tambahkan import setelah baris `use
App\Support\InventoryMovementType;`:

```php
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Support\AuditEvent;
use App\Support\InvoiceStatus;
use App\Support\WorkOrderStatus;
```

Hapus method `scopedBranchIds()`, ganti dengan:

```php
    protected function scopedBranchIdsFor(User $user, array $selectedBranchIds, string $permissionCode): array
    {
        $permittedBranchIds = $user->branchesWithPermission($permissionCode)->pluck('id')->all();

        return array_values(array_intersect($selectedBranchIds, $permittedBranchIds));
    }
```

Hapus `dummyPkbStatus()`, `dummyReceivables()`, `dummyChartTrend()`, `dummyChartReceivables()`. Tambahkan
setelah `computeCriticalStockCount()`:

```php
    protected function computePkbStatusToday(array $scopedBranchIds): array
    {
        $defaults = ['draft' => 0, 'open' => 0, 'shortage' => 0, 'completed' => 0];
        if (empty($scopedBranchIds)) {
            return $defaults;
        }

        $counts = WorkOrder::whereIn('branch_id', $scopedBranchIds)
            ->whereDate('work_order_date', now()->toDateString())
            ->whereIn('status', [WorkOrderStatus::DRAFT, WorkOrderStatus::OPEN, WorkOrderStatus::SHORTAGE, WorkOrderStatus::COMPLETED])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'draft' => (int) ($counts[WorkOrderStatus::DRAFT] ?? 0),
            'open' => (int) ($counts[WorkOrderStatus::OPEN] ?? 0),
            'shortage' => (int) ($counts[WorkOrderStatus::SHORTAGE] ?? 0),
            'completed' => (int) ($counts[WorkOrderStatus::COMPLETED] ?? 0),
        ];
    }

    protected function computeReceivablesSummary(array $scopedBranchIds): array
    {
        if (empty($scopedBranchIds)) {
            return ['revenue' => 0.0, 'unpaid' => 0.0];
        }

        $revenue = Invoice::whereIn('branch_id', $scopedBranchIds)
            ->whereIn('status', [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID])
            ->sum('grand_total');

        $unpaid = Invoice::whereIn('branch_id', $scopedBranchIds)
            ->whereIn('status', [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID])
            ->selectRaw('COALESCE(SUM(grand_total - paid_amount), 0) as total')
            ->value('total');

        return ['revenue' => (float) $revenue, 'unpaid' => (float) $unpaid];
    }

    protected function computeWeeklyTrend(array $pkbScopedBranchIds, array $invoiceScopedBranchIds): array
    {
        $weekStarts = collect(range(7, 0))->map(fn ($i) => now()->subWeeks($i)->startOfWeek());
        $labels = $weekStarts->map(fn ($d) => $d->translatedFormat('d M'))->all();

        $pkbCounts = empty($pkbScopedBranchIds) ? collect() : WorkOrder::whereIn('branch_id', $pkbScopedBranchIds)
            ->where('created_at', '>=', $weekStarts->first())
            ->selectRaw('YEARWEEK(created_at, 3) as yw, COUNT(*) as total')
            ->groupBy('yw')->pluck('total', 'yw');

        $invoiceCounts = empty($invoiceScopedBranchIds) ? collect() : AuditLog::whereIn('branch_id', $invoiceScopedBranchIds)
            ->where('event', AuditEvent::INVOICE_POSTED)
            ->where('created_at', '>=', $weekStarts->first())
            ->selectRaw('YEARWEEK(created_at, 3) as yw, COUNT(*) as total')
            ->groupBy('yw')->pluck('total', 'yw');

        $pkb = $weekStarts->map(fn ($d) => (int) ($pkbCounts[(int) $d->format('oW')] ?? 0))->all();
        $invoice = $weekStarts->map(fn ($d) => (int) ($invoiceCounts[(int) $d->format('oW')] ?? 0))->all();

        return ['labels' => $labels, 'pkb' => $pkb, 'invoice' => $invoice];
    }

    protected function computeReceivablesAging(array $scopedBranchIds): array
    {
        $labels = ['Belum Jatuh Tempo', '1-30 Hari', '31-60 Hari', '>60 Hari'];
        if (empty($scopedBranchIds)) {
            return ['labels' => $labels, 'values' => [0, 0, 0, 0]];
        }

        $row = Invoice::whereIn('branch_id', $scopedBranchIds)
            ->whereIn('status', [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, invoice_date)) < 0 THEN grand_total - paid_amount ELSE 0 END), 0) as not_due,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, invoice_date)) BETWEEN 0 AND 30 THEN grand_total - paid_amount ELSE 0 END), 0) as d1_30,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, invoice_date)) BETWEEN 31 AND 60 THEN grand_total - paid_amount ELSE 0 END), 0) as d31_60,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, invoice_date)) > 60 THEN grand_total - paid_amount ELSE 0 END), 0) as d60_plus
            ")->first();

        return ['labels' => $labels, 'values' => [(float) $row->not_due, (float) $row->d1_30, (float) $row->d31_60, (float) $row->d60_plus]];
    }
```

Ubah `buildPayload()` (masih memakai `dummyPkbInvoiceRows()`/`dummyAuditLogRows()` sementara — diganti
Task 2):

```php
    protected function buildPayload(User $user, array $selectedBranchIds, ?int $sparepartId = null): array
    {
        $stockScopedIds = $this->scopedBranchIdsFor($user, $selectedBranchIds, 'sparepart.view');
        $pkbScopedIds = $this->scopedBranchIdsFor($user, $selectedBranchIds, 'pkb.view');
        $invoiceScopedIds = $this->scopedBranchIdsFor($user, $selectedBranchIds, 'invoice.view');

        return [
            'selectedBranchIds' => $selectedBranchIds,
            'stockOverview' => $this->computeStockOverview($stockScopedIds),
            'criticalStockCount' => $this->computeCriticalStockCount($stockScopedIds),
            'pkbStatus' => $this->computePkbStatusToday($pkbScopedIds),
            'receivables' => $this->computeReceivablesSummary($invoiceScopedIds),
            'chartTrend' => $this->computeWeeklyTrend($pkbScopedIds, $invoiceScopedIds),
            'chartReceivables' => $this->computeReceivablesAging($invoiceScopedIds),
            'pkbInvoiceRows' => $this->dummyPkbInvoiceRows(),
            'auditLogRows' => $this->dummyAuditLogRows(),
            'kartuStok' => $this->computeKartuStok($stockScopedIds, $sparepartId),
        ];
    }
```

- [ ] **Step 8: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=DashboardCardsTest`
Expected: 5 test PASS.

- [ ] **Step 9: Jalankan test lama yang menyentuh dashboard (jika ada) untuk cek regresi**

Run: `php artisan test --filter=Dashboard`
Expected: semua PASS (belum ada test dashboard lain selain yang baru dibuat).

- [ ] **Step 10: Commit**

```bash
git add app/Support/AuditEvent.php app/Http/Controllers/DashboardController.php tests/Unit/AuditEventTest.php tests/Feature/DashboardCardsTest.php
git commit -m "feat: replace dashboard PKB/revenue/chart cards with real queries"
```

---

### Task 2: Tab 1 (PKB + Invoice Gabungan) & Tab 3 (Audit Log)

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Test: `tests/Feature/DashboardTabsTest.php`

**Interfaces:**
- Consumes: `scopedBranchIdsFor()` (Task 1).
- Produces: payload keys `pkbInvoiceRows` (array baris `{type, typeLabel, number, customer, plate,
  branch, status, statusLabel, date, url}`), `canViewAuditLog` (bool), `auditLogRows` (array baris
  `{timestamp, user, event, eventLabel, description, severity}`) — dikonsumsi Task 3 (view) & Task 4.

- [ ] **Step 1: Tulis test `DashboardTabsTest`**

`tests/Feature/DashboardTabsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\UserBranchService;
use App\Support\AuditEvent;
use App\Support\InvoiceStatus;
use App\Support\WorkOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTabsTest extends TestCase
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

    protected function makeCustomerVehicleMechanic(Branch $branch, string $customerName = 'Budi Santoso', string $plateSuffix = '1'): array
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => $customerName, 'stnk_name' => $customerName]);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => "Mobil {$branch->code}{$plateSuffix}"]);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => "B {$plateSuffix}234 {$branch->code}",
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);

        return [$customer, $vehicle, $mechanic];
    }

    protected function makeWorkOrderRow(Branch $branch, Customer $customer, Vehicle $vehicle, Mechanic $mechanic, string $status, string $workOrderDate, string $numberSuffix): WorkOrder
    {
        return WorkOrder::create([
            'number' => "PKB-TEST-{$numberSuffix}",
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => $workOrderDate,
            'status' => $status,
        ]);
    }

    protected function makeInvoiceRow(Branch $branch, Customer $customer, WorkOrder $workOrder, string $status, float $grandTotal, float $paidAmount): Invoice
    {
        return Invoice::create([
            'number' => 'INV-TEST-' . $workOrder->id,
            'work_order_id' => $workOrder->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'status' => $status,
            'subtotal_service' => $grandTotal,
            'subtotal_sparepart' => 0,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_percent' => 0,
            'tax_amount' => 0,
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
        ]);
    }

    public function test_pkb_invoice_search_matches_number_customer_or_plate_across_both_types(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customerA, $vehicleA, $mechanic] = $this->makeCustomerVehicleMechanic($branch, 'Andi Wijaya', '9');
        [$customerB, $vehicleB] = $this->makeCustomerVehicleMechanic($branch, 'Siti Aminah', '8');
        $woA = $this->makeWorkOrderRow($branch, $customerA, $vehicleA, $mechanic, WorkOrderStatus::OPEN, now()->toDateString(), 'a');
        $woB = $this->makeWorkOrderRow($branch, $customerB, $vehicleB, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'b');
        $this->makeInvoiceRow($branch, $customerB, $woB, InvoiceStatus::POSTED, 100000, 0);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id . '&pkb_invoice_q=Andi');

        $response->assertOk();
        $numbers = collect($response->json('pkbInvoiceRows'))->pluck('number');
        $this->assertTrue($numbers->contains($woA->number));
        $this->assertFalse($numbers->contains($woB->number));
    }

    public function test_pkb_invoice_status_filter_pkb_prefix_only_returns_pkb_rows(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);
        $wo = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::SHORTAGE, now()->toDateString(), 'a');
        $woForInvoice = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::COMPLETED, now()->toDateString(), 'b');
        $this->makeInvoiceRow($branch, $customer, $woForInvoice, InvoiceStatus::POSTED, 100000, 0);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id . '&pkb_invoice_status=pkb:shortage');

        $response->assertOk();
        $rows = collect($response->json('pkbInvoiceRows'));
        $this->assertTrue($rows->every(fn ($row) => $row['type'] === 'pkb'));
        $this->assertSame([$wo->number], $rows->pluck('number')->all());
    }

    public function test_pkb_invoice_date_range_filters_each_type_by_its_own_date_column(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);
        $woInRange = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::OPEN, '2026-08-05', 'in');
        $woOutOfRange = $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::OPEN, '2026-07-01', 'out');

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->getJson(
            '/dashboard?branch_ids[]=' . $branch->id . '&pkb_invoice_date_from=2026-08-01&pkb_invoice_date_to=2026-08-10'
        );

        $response->assertOk();
        $numbers = collect($response->json('pkbInvoiceRows'))->pluck('number');
        $this->assertTrue($numbers->contains($woInRange->number));
        $this->assertFalse($numbers->contains($woOutOfRange->number));
    }

    public function test_pkb_invoice_rows_are_merged_sorted_desc_and_limited_to_15(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);
        for ($i = 0; $i < 20; $i++) {
            $this->makeWorkOrderRow($branch, $customer, $vehicle, $mechanic, WorkOrderStatus::OPEN, now()->subDays($i)->toDateString(), "n{$i}");
        }

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $rows = $response->json('pkbInvoiceRows');
        $this->assertCount(15, $rows);
        $this->assertSame('PKB-TEST-n0', $rows[0]['number']);
    }

    public function test_audit_log_tab_hidden_and_empty_without_audit_log_view_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        AuditLog::create(['branch_id' => $branch->id, 'event' => AuditEvent::INVOICE_POSTED]);

        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $response->assertJson(['canViewAuditLog' => false, 'auditLogRows' => []]);
    }

    public function test_audit_log_rows_include_severity_mapped_from_event_and_filtered_by_selected_branches(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        AuditLog::create(['branch_id' => $branchA->id, 'event' => AuditEvent::USER_BRANCH_PERMISSION_GRANTED]);
        AuditLog::create(['branch_id' => $branchB->id, 'event' => AuditEvent::INVOICE_POSTED]);

        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branchA);
        (new UserBranchService())->assign($user, $branchB);
        $permission = Permission::firstOrCreate(
            ['code' => 'audit_log.view'],
            ['resource' => 'audit_log', 'action' => 'view', 'description' => 'audit_log.view']
        );
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branchA->id);

        $response->assertOk();
        $rows = $response->json('auditLogRows');
        $this->assertCount(1, $rows);
        $this->assertSame('HIGH', $rows[0]['severity']);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=DashboardTabsTest`
Expected: FAIL — `pkbInvoiceRows`/`auditLogRows`/`canViewAuditLog` masih dari method dummy lama.

- [ ] **Step 3: Tambahkan method query Tab 1 & Tab 3 di `DashboardController`**

Hapus `dummyPkbInvoiceRows()` dan `dummyAuditLogRows()`. Tambahkan:

```php
    protected function computePkbInvoiceRows(array $pkbScopedBranchIds, array $invoiceScopedBranchIds, array $filters): array
    {
        [$type, $status] = $this->splitTypeStatus($filters['status'] ?? null);

        $pkbRows = collect();
        if (! empty($pkbScopedBranchIds) && $type !== 'invoice') {
            $pkbRows = WorkOrder::whereIn('branch_id', $pkbScopedBranchIds)
                ->with(['customer', 'vehicle', 'branch'])
                ->when($status && $type === 'pkb', fn ($q) => $q->where('status', $status))
                ->when($filters['q'] ?? null, function ($q, $term) {
                    $escaped = addcslashes($term, '%_\\');
                    $q->where(function ($inner) use ($escaped) {
                        $inner->where('number', 'like', "%{$escaped}%")
                            ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$escaped}%"))
                            ->orWhereHas('vehicle', fn ($v) => $v->where('plate_number', 'like', "%{$escaped}%"));
                    });
                })
                ->when($filters['dateFrom'] ?? null, fn ($q, $d) => $q->whereDate('work_order_date', '>=', $d))
                ->when($filters['dateTo'] ?? null, fn ($q, $d) => $q->whereDate('work_order_date', '<=', $d))
                ->orderByDesc('work_order_date')->orderByDesc('id')
                ->limit(15)
                ->get()
                ->map(fn (WorkOrder $wo) => [
                    'type' => 'pkb',
                    'typeLabel' => 'PKB',
                    'number' => $wo->number,
                    'customer' => optional($wo->customer)->name ?? '-',
                    'plate' => optional($wo->vehicle)->plate_number ?? '-',
                    'branch' => optional($wo->branch)->name ?? '-',
                    'status' => $wo->status,
                    'statusLabel' => $this->workOrderStatusLabel($wo->status),
                    'date' => $wo->work_order_date->toDateString(),
                    'url' => route('work-orders.show', $wo),
                ]);
        }

        $invoiceRows = collect();
        if (! empty($invoiceScopedBranchIds) && $type !== 'pkb') {
            $invoiceRows = Invoice::whereIn('branch_id', $invoiceScopedBranchIds)
                ->with(['customer', 'branch', 'workOrder.vehicle'])
                ->when($status && $type === 'invoice', fn ($q) => $q->where('status', $status))
                ->when($filters['q'] ?? null, function ($q, $term) {
                    $escaped = addcslashes($term, '%_\\');
                    $q->where(function ($inner) use ($escaped) {
                        $inner->where('number', 'like', "%{$escaped}%")
                            ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$escaped}%"))
                            ->orWhereHas('workOrder.vehicle', fn ($v) => $v->where('plate_number', 'like', "%{$escaped}%"));
                    });
                })
                ->when($filters['dateFrom'] ?? null, fn ($q, $d) => $q->whereDate('invoice_date', '>=', $d))
                ->when($filters['dateTo'] ?? null, fn ($q, $d) => $q->whereDate('invoice_date', '<=', $d))
                ->orderByDesc('invoice_date')->orderByDesc('id')
                ->limit(15)
                ->get()
                ->map(fn (Invoice $invoice) => [
                    'type' => 'invoice',
                    'typeLabel' => 'Invoice',
                    'number' => $invoice->number,
                    'customer' => optional($invoice->customer)->name ?? '-',
                    'plate' => optional(optional($invoice->workOrder)->vehicle)->plate_number ?? '-',
                    'branch' => optional($invoice->branch)->name ?? '-',
                    'status' => $invoice->status,
                    'statusLabel' => $this->invoiceStatusLabel($invoice->status),
                    'date' => $invoice->invoice_date->toDateString(),
                    'url' => route('invoices.show', $invoice),
                ]);
        }

        return $pkbRows->concat($invoiceRows)
            ->sortByDesc('date')
            ->take(15)
            ->values()
            ->all();
    }

    protected function splitTypeStatus(?string $value): array
    {
        if (! $value || ! str_contains($value, ':')) {
            return [null, null];
        }

        [$type, $status] = explode(':', $value, 2);

        return in_array($type, ['pkb', 'invoice'], true) ? [$type, $status] : [null, null];
    }

    protected function workOrderStatusLabel(string $status): string
    {
        return [
            WorkOrderStatus::DRAFT => 'Draft',
            WorkOrderStatus::OPEN => 'Dikonfirmasi',
            WorkOrderStatus::SHORTAGE => 'Kurang Stok',
            WorkOrderStatus::COMPLETED => 'Selesai',
            WorkOrderStatus::CANCELLED => 'Dibatalkan',
        ][$status] ?? $status;
    }

    protected function invoiceStatusLabel(string $status): string
    {
        return [
            InvoiceStatus::DRAFT => 'Draft',
            InvoiceStatus::POSTED => 'Diposting',
            InvoiceStatus::PARTIALLY_PAID => 'Dibayar Sebagian',
            InvoiceStatus::PAID => 'Lunas',
            InvoiceStatus::CANCELLED => 'Dibatalkan',
        ][$status] ?? $status;
    }

    protected function computeAuditLogRows(array $selectedBranchIds): array
    {
        if (empty($selectedBranchIds)) {
            return [];
        }

        return AuditLog::with('user')
            ->whereIn('branch_id', $selectedBranchIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn (AuditLog $log) => [
                'timestamp' => $log->created_at->format('d/m/Y H:i'),
                'user' => optional($log->user)->name ?? 'Sistem',
                'event' => $log->event,
                'eventLabel' => AuditEvent::LABELS[$log->event] ?? $log->event,
                'description' => $this->describeAuditLog($log),
                'severity' => AuditEvent::SEVERITIES[$log->event] ?? 'LOW',
            ])
            ->all();
    }

    protected function describeAuditLog(AuditLog $log): string
    {
        $label = AuditEvent::LABELS[$log->event] ?? $log->event;
        $reference = $log->auditable_type && $log->auditable_id
            ? " ({$log->auditable_type} #{$log->auditable_id})"
            : '';

        return $label . $reference;
    }
```

- [ ] **Step 4: Sambungkan filter Tab 1 dari request & wire ke `buildPayload()`**

Edit `index()`:

```php
    public function index(Request $request)
    {
        $user = $request->user();
        $allowedBranches = $user->branches;

        $selectedBranchIds = $this->resolveSelectedBranchIds($request, $user, $allowedBranches);
        $sparepartId = filter_var($request->input('sparepart_id'), FILTER_VALIDATE_INT) ?: null;
        $pkbInvoiceFilters = [
            'q' => is_string($request->input('pkb_invoice_q')) ? trim($request->input('pkb_invoice_q')) : null,
            'status' => $request->input('pkb_invoice_status') ?: null,
            'dateFrom' => $this->parseDate($request->input('pkb_invoice_date_from')),
            'dateTo' => $this->parseDate($request->input('pkb_invoice_date_to')),
        ];

        $payload = $this->buildPayload($user, $selectedBranchIds, $sparepartId, $pkbInvoiceFilters);

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return view('dashboard.index', array_merge($payload, [
            'allowedBranches' => $allowedBranches,
            'selectedBranchIds' => $selectedBranchIds,
            'pkbInvoiceFilters' => $pkbInvoiceFilters,
        ]));
    }
```

Tambahkan helper `parseDate()` (pola sama seperti controller lain di proyek ini):

```php
    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
```

Ubah tanda tangan & isi `buildPayload()`:

```php
    protected function buildPayload(User $user, array $selectedBranchIds, ?int $sparepartId, array $pkbInvoiceFilters): array
    {
        $stockScopedIds = $this->scopedBranchIdsFor($user, $selectedBranchIds, 'sparepart.view');
        $pkbScopedIds = $this->scopedBranchIdsFor($user, $selectedBranchIds, 'pkb.view');
        $invoiceScopedIds = $this->scopedBranchIdsFor($user, $selectedBranchIds, 'invoice.view');
        $canViewAuditLog = $user->hasPermissionTo('audit_log.view');

        return [
            'selectedBranchIds' => $selectedBranchIds,
            'stockOverview' => $this->computeStockOverview($stockScopedIds),
            'criticalStockCount' => $this->computeCriticalStockCount($stockScopedIds),
            'pkbStatus' => $this->computePkbStatusToday($pkbScopedIds),
            'receivables' => $this->computeReceivablesSummary($invoiceScopedIds),
            'chartTrend' => $this->computeWeeklyTrend($pkbScopedIds, $invoiceScopedIds),
            'chartReceivables' => $this->computeReceivablesAging($invoiceScopedIds),
            'pkbInvoiceRows' => $this->computePkbInvoiceRows($pkbScopedIds, $invoiceScopedIds, $pkbInvoiceFilters),
            'canViewAuditLog' => $canViewAuditLog,
            'auditLogRows' => $canViewAuditLog ? $this->computeAuditLogRows($selectedBranchIds) : [],
            'kartuStok' => $this->computeKartuStok($stockScopedIds, $sparepartId),
        ];
    }
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=DashboardTabsTest`
Expected: 6 test PASS.

- [ ] **Step 6: Jalankan ulang `DashboardCardsTest` untuk cek regresi dari perubahan tanda tangan `buildPayload()`**

Run: `php artisan test --filter=DashboardCardsTest`
Expected: 5 test tetap PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/DashboardController.php tests/Feature/DashboardTabsTest.php
git commit -m "feat: replace dashboard PKB/Invoice and audit log tabs with real queries"
```

---

### Task 3: Update View & JS (Cards, Tab 1, Tab 3)

**Files:**
- Modify: `resources/views/dashboard/index.blade.php`
- Modify: `resources/views/dashboard/_tab_pkb_invoice.blade.php`
- Modify: `resources/views/dashboard/_tab_audit_log.blade.php`
- Test: `tests/Feature/DashboardViewTest.php`

**Interfaces:**
- Consumes: payload keys dari Task 1 & 2 (`pkbStatus.draft`, `receivables.unpaid`, `pkbInvoiceRows`,
  `canViewAuditLog`, `auditLogRows`, `pkbInvoiceFilters`).

- [ ] **Step 1: Tulis test `DashboardViewTest`**

`tests/Feature/DashboardViewTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\UserBranchService;
use App\Support\WorkOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardViewTest extends TestCase
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

    public function test_pkb_invoice_filter_inputs_are_enabled(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $content = $response->getContent();
        preg_match('/<input[^>]*id="pkbInvoiceSearch"[^>]*>/', $content, $matches);
        $this->assertNotEmpty($matches, 'Input pencarian Tab 1 tidak ditemukan.');
        $this->assertStringNotContainsString('disabled', $matches[0]);
    }

    public function test_pkb_row_shows_type_badge_and_action_link(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create(['customer_id' => $customer->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $wo = WorkOrder::create([
            'number' => 'PKB-VIEW-1', 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id, 'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->toDateString(), 'status' => WorkOrderStatus::OPEN,
        ]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('PKB-VIEW-1');
        $response->assertSee(route('work-orders.show', $wo), false);
    }

    public function test_audit_log_tab_absent_from_html_without_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('tab-audit-log', false);
    }

    public function test_audit_log_tab_present_in_html_with_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        $permission = Permission::firstOrCreate(
            ['code' => 'audit_log.view'],
            ['resource' => 'audit_log', 'action' => 'view', 'description' => 'audit_log.view']
        );
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('tab-audit-log', false);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=DashboardViewTest`
Expected: FAIL — input Tab 1 masih `disabled`, tab Audit Log selalu dirender.

- [ ] **Step 3: Update `_tab_pkb_invoice.blade.php`**

Ganti seluruh isi file dengan:

```blade
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div class="row g-2 flex-grow-1">
        <div class="col-md-4">
            <input type="text" class="form-control form-control-sm" id="pkbInvoiceSearch" placeholder="Cari No. PKB/Invoice, Customer, No. Polisi..." value="{{ $pkbInvoiceFilters['q'] ?? '' }}">
        </div>
        <div class="col-md-3">
            <select class="form-select form-select-sm" id="pkbInvoiceStatus">
                <option value="">Semua Status</option>
                <optgroup label="PKB">
                    <option value="pkb:draft" {{ ($pkbInvoiceFilters['status'] ?? '') === 'pkb:draft' ? 'selected' : '' }}>Draft</option>
                    <option value="pkb:open" {{ ($pkbInvoiceFilters['status'] ?? '') === 'pkb:open' ? 'selected' : '' }}>Dikonfirmasi</option>
                    <option value="pkb:shortage" {{ ($pkbInvoiceFilters['status'] ?? '') === 'pkb:shortage' ? 'selected' : '' }}>Kurang Stok</option>
                    <option value="pkb:completed" {{ ($pkbInvoiceFilters['status'] ?? '') === 'pkb:completed' ? 'selected' : '' }}>Selesai</option>
                </optgroup>
                <optgroup label="Invoice">
                    <option value="invoice:draft" {{ ($pkbInvoiceFilters['status'] ?? '') === 'invoice:draft' ? 'selected' : '' }}>Draft</option>
                    <option value="invoice:posted" {{ ($pkbInvoiceFilters['status'] ?? '') === 'invoice:posted' ? 'selected' : '' }}>Diposting</option>
                    <option value="invoice:partially_paid" {{ ($pkbInvoiceFilters['status'] ?? '') === 'invoice:partially_paid' ? 'selected' : '' }}>Dibayar Sebagian</option>
                    <option value="invoice:paid" {{ ($pkbInvoiceFilters['status'] ?? '') === 'invoice:paid' ? 'selected' : '' }}>Lunas</option>
                </optgroup>
            </select>
        </div>
        <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" id="pkbInvoiceDateFrom" value="{{ $pkbInvoiceFilters['dateFrom'] ?? '' }}">
        </div>
        <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" id="pkbInvoiceDateTo" value="{{ $pkbInvoiceFilters['dateTo'] ?? '' }}">
        </div>
    </div>
    <div class="d-flex gap-1">
        <a href="{{ route('work-orders.index') }}" class="btn btn-outline-secondary btn-sm">Semua PKB</a>
        <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary btn-sm">Semua Invoice</a>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>No. PKB/Invoice</th>
                <th>Customer &amp; No. Polisi</th>
                <th>Cabang</th>
                <th>Status</th>
                <th class="text-end">Aksi</th>
            </tr>
        </thead>
        <tbody id="pkbInvoiceTabBody">
            @forelse ($pkbInvoiceRows as $row)
                <tr>
                    <td><span class="badge {{ $row['type'] === 'pkb' ? 'bg-primary' : 'bg-success' }} me-1">{{ $row['typeLabel'] }}</span><code>{{ $row['number'] }}</code></td>
                    <td>{{ $row['customer'] }} &middot; {{ $row['plate'] }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td><span class="status-dot status-active">{{ $row['statusLabel'] }}</span></td>
                    <td class="text-end"><a href="{{ $row['url'] }}" class="btn btn-outline-secondary btn-sm">Lihat</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted text-center py-3">Tidak ada data PKB/Invoice yang cocok.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
```

- [ ] **Step 4: Update `_tab_audit_log.blade.php`**

Ganti seluruh isi file dengan:

```blade
<div class="row g-2 mb-3">
    <div class="col-md-4">
        <select class="form-select form-select-sm" disabled>
            <option>Semua User</option>
        </select>
    </div>
    <div class="col-md-4">
        <select class="form-select form-select-sm" disabled>
            <option>Semua Jenis Event</option>
        </select>
    </div>
    <div class="col-md-4 text-md-end">
        <a href="{{ route('audit-logs.index') }}" class="btn btn-outline-secondary btn-sm">Lihat Semua Audit Log</a>
    </div>
</div>
<ul class="list-group list-group-flush" id="auditLogFeed">
    @forelse ($auditLogRows as $row)
        @php
            $severityClass = ['LOW' => 'status-active', 'MEDIUM' => 'status-warning', 'HIGH' => 'status-inactive'][$row['severity']] ?? 'status-active';
        @endphp
        <li class="list-group-item px-0">
            <div class="d-flex justify-content-between">
                <span class="fw-semibold">{{ $row['user'] }}</span>
                <span class="small" style="color: var(--color-ink-muted);">{{ $row['timestamp'] }}</span>
            </div>
            <div class="small mb-1">
                <code>{{ $row['event'] }}</code>
            </div>
            <div>{{ $row['description'] }}</div>
            <span class="status-dot {{ $severityClass }}">{{ $row['severity'] }}</span>
        </li>
    @empty
        <li class="list-group-item px-0 text-muted text-center py-3">Belum ada aktivitas untuk cabang terpilih.</li>
    @endforelse
</ul>
```

- [ ] **Step 5: Update `dashboard/index.blade.php`**

Ubah Card 3 (breakdown status PKB, tambah Draft + `id` per angka supaya bisa disinkron JS):

```blade
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" id="kpiPkbTotal">{{ $pkbStatus['draft'] + $pkbStatus['open'] + $pkbStatus['shortage'] + $pkbStatus['completed'] }}</div>
                        <div class="stat-label">Status PKB Hari Ini</div>
                        <div class="small mt-1" style="color: var(--color-ink-muted);">Draft <span id="kpiPkbDraft">{{ $pkbStatus['draft'] }}</span> &middot; Open <span id="kpiPkbOpen">{{ $pkbStatus['open'] }}</span> &middot; Shortage <span id="kpiPkbShortage">{{ $pkbStatus['shortage'] }}</span> &middot; Selesai <span id="kpiPkbCompleted">{{ $pkbStatus['completed'] }}</span></div>
                    </div>
                    <i class="bi bi-clipboard-check stat-icon"></i>
                </div>
            </div>
```

Ubah Card 4 (tambah `id` pada angka piutang):

```blade
            <div class="col-sm-6 col-lg-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" id="kpiRevenue">{{ number_format($receivables['revenue'], 0, ',', '.') }}</div>
                        <div class="stat-label">Pendapatan & Piutang</div>
                        <div class="small mt-1" style="color: var(--color-ink-muted);">Piutang belum lunas <span id="kpiUnpaid">{{ number_format($receivables['unpaid'], 0, ',', '.') }}</span></div>
                    </div>
                    <i class="bi bi-cash-coin stat-icon"></i>
                </div>
            </div>
```

Ubah tab nav & tab content supaya Audit Log kondisional (`$canViewAuditLog`):

```blade
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pkb-invoice" type="button" role="tab">Status PKB & Invoice</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-kartu-stok" type="button" role="tab">Kartu Stok</button>
                    </li>
                    @if ($canViewAuditLog)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-audit-log" type="button" role="tab">Audit Log</button>
                        </li>
                    @endif
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tab-pkb-invoice" role="tabpanel">
                        @include('dashboard._tab_pkb_invoice')
                    </div>
                    <div class="tab-pane fade" id="tab-kartu-stok" role="tabpanel">
                        @include('dashboard._tab_kartu_stok')
                    </div>
                    @if ($canViewAuditLog)
                        <div class="tab-pane fade" id="tab-audit-log" role="tabpanel">
                            @include('dashboard._tab_audit_log')
                        </div>
                    @endif
                </div>
```

Di blok `@push('scripts')` JS, ubah `applyPayload()` — tambah sinkronisasi Card 3/4 yang belum
tersambung sebelumnya, render ulang tabel Tab 1, dan render ulang daftar Tab 3 (semua lewat DOM API,
bukan `innerHTML` dengan string customer/plat mentah, untuk mencegah XSS):

```js
    function applyPayload(data) {
        clearFilterError();
        document.getElementById('kpiStockAvailable').textContent = Math.round(data.stockOverview.available).toLocaleString('id-ID');
        document.getElementById('kpiStockOnHand').textContent = Math.round(data.stockOverview.onHand).toLocaleString('id-ID');
        document.getElementById('kpiStockReserved').textContent = Math.round(data.stockOverview.reserved).toLocaleString('id-ID');
        document.getElementById('kpiCriticalStock').textContent = data.criticalStockCount;

        document.getElementById('kpiPkbDraft').textContent = data.pkbStatus.draft;
        document.getElementById('kpiPkbOpen').textContent = data.pkbStatus.open;
        document.getElementById('kpiPkbShortage').textContent = data.pkbStatus.shortage;
        document.getElementById('kpiPkbCompleted').textContent = data.pkbStatus.completed;
        document.getElementById('kpiPkbTotal').textContent = data.pkbStatus.draft + data.pkbStatus.open + data.pkbStatus.shortage + data.pkbStatus.completed;

        document.getElementById('kpiRevenue').textContent = Math.round(data.receivables.revenue).toLocaleString('id-ID');
        document.getElementById('kpiUnpaid').textContent = Math.round(data.receivables.unpaid).toLocaleString('id-ID');

        trendChart.data.labels = data.chartTrend.labels;
        trendChart.data.datasets[0].data = data.chartTrend.pkb;
        trendChart.data.datasets[1].data = data.chartTrend.invoice;
        trendChart.update();

        receivablesChart.data.labels = data.chartReceivables.labels;
        receivablesChart.data.datasets[0].data = data.chartReceivables.values;
        receivablesChart.update();

        document.getElementById('kartuStokOnHand').textContent = Math.round(data.kartuStok.selected.onHand).toLocaleString('id-ID');
        document.getElementById('kartuStokReserved').textContent = Math.round(data.kartuStok.selected.reserved).toLocaleString('id-ID');
        document.getElementById('kartuStokAvailable').textContent = Math.round(data.kartuStok.selected.available).toLocaleString('id-ID');

        const sparepartSelect = document.getElementById('kartuStokSparepartSelect');
        if (sparepartSelect) {
            sparepartSelect.innerHTML = '';
            data.kartuStok.spareparts.forEach(function (sparepart) {
                const option = document.createElement('option');
                option.value = sparepart.id;
                option.textContent = sparepart.code + ' — ' + sparepart.name;
                option.selected = sparepart.id === data.kartuStok.selected.id;
                sparepartSelect.appendChild(option);
            });
        }

        renderPkbInvoiceRows(data.pkbInvoiceRows);
        renderAuditLogRows(data.auditLogRows);
        updateBranchFilterSummary(data.selectedBranchIds);
    }

    function renderPkbInvoiceRows(rows) {
        const body = document.getElementById('pkbInvoiceTabBody');
        if (!body) return;
        body.innerHTML = '';

        if (rows.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 5;
            td.className = 'text-muted text-center py-3';
            td.textContent = 'Tidak ada data PKB/Invoice yang cocok.';
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }

        rows.forEach(function (row) {
            const tr = document.createElement('tr');

            const tdNumber = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = 'badge me-1 ' + (row.type === 'pkb' ? 'bg-primary' : 'bg-success');
            badge.textContent = row.typeLabel;
            const code = document.createElement('code');
            code.textContent = row.number;
            tdNumber.appendChild(badge);
            tdNumber.appendChild(code);

            const tdCustomer = document.createElement('td');
            tdCustomer.textContent = row.customer + ' · ' + row.plate;

            const tdBranch = document.createElement('td');
            tdBranch.textContent = row.branch;

            const tdStatus = document.createElement('td');
            const statusBadge = document.createElement('span');
            statusBadge.className = 'status-dot status-active';
            statusBadge.textContent = row.statusLabel;
            tdStatus.appendChild(statusBadge);

            const tdAction = document.createElement('td');
            tdAction.className = 'text-end';
            const link = document.createElement('a');
            link.href = row.url;
            link.className = 'btn btn-outline-secondary btn-sm';
            link.textContent = 'Lihat';
            tdAction.appendChild(link);

            tr.appendChild(tdNumber);
            tr.appendChild(tdCustomer);
            tr.appendChild(tdBranch);
            tr.appendChild(tdStatus);
            tr.appendChild(tdAction);
            body.appendChild(tr);
        });
    }

    function renderAuditLogRows(rows) {
        const feed = document.getElementById('auditLogFeed');
        if (!feed) return;
        feed.innerHTML = '';

        if (rows.length === 0) {
            const li = document.createElement('li');
            li.className = 'list-group-item px-0 text-muted text-center py-3';
            li.textContent = 'Belum ada aktivitas untuk cabang terpilih.';
            feed.appendChild(li);
            return;
        }

        const severityClass = { LOW: 'status-active', MEDIUM: 'status-warning', HIGH: 'status-inactive' };
        rows.forEach(function (row) {
            const li = document.createElement('li');
            li.className = 'list-group-item px-0';

            const headerRow = document.createElement('div');
            headerRow.className = 'd-flex justify-content-between';
            const userSpan = document.createElement('span');
            userSpan.className = 'fw-semibold';
            userSpan.textContent = row.user;
            const timeSpan = document.createElement('span');
            timeSpan.className = 'small';
            timeSpan.style.color = 'var(--color-ink-muted)';
            timeSpan.textContent = row.timestamp;
            headerRow.appendChild(userSpan);
            headerRow.appendChild(timeSpan);

            const eventDiv = document.createElement('div');
            eventDiv.className = 'small mb-1';
            const eventCode = document.createElement('code');
            eventCode.textContent = row.event;
            eventDiv.appendChild(eventCode);

            const descDiv = document.createElement('div');
            descDiv.textContent = row.description;

            const severityBadge = document.createElement('span');
            severityBadge.className = 'status-dot ' + (severityClass[row.severity] || 'status-active');
            severityBadge.textContent = row.severity;

            li.appendChild(headerRow);
            li.appendChild(eventDiv);
            li.appendChild(descDiv);
            li.appendChild(severityBadge);
            feed.appendChild(li);
        });
    }
```

Tambahkan pemicu filter Tab 1 (debounce 400ms untuk kotak pencarian, langsung untuk dropdown/tanggal) —
sisipkan sebelum baris penutup `})();` di IIFE JS yang sama:

```js
    const pkbInvoiceSearch = document.getElementById('pkbInvoiceSearch');
    const pkbInvoiceStatus = document.getElementById('pkbInvoiceStatus');
    const pkbInvoiceDateFrom = document.getElementById('pkbInvoiceDateFrom');
    const pkbInvoiceDateTo = document.getElementById('pkbInvoiceDateTo');
    let pkbInvoiceDebounceTimer = null;

    function applyPkbInvoiceFilter() {
        const params = new URLSearchParams();
        currentBranchIds().forEach(function (id) { params.append('branch_ids[]', id); });
        if (pkbInvoiceSearch && pkbInvoiceSearch.value) params.append('pkb_invoice_q', pkbInvoiceSearch.value);
        if (pkbInvoiceStatus && pkbInvoiceStatus.value) params.append('pkb_invoice_status', pkbInvoiceStatus.value);
        if (pkbInvoiceDateFrom && pkbInvoiceDateFrom.value) params.append('pkb_invoice_date_from', pkbInvoiceDateFrom.value);
        if (pkbInvoiceDateTo && pkbInvoiceDateTo.value) params.append('pkb_invoice_date_to', pkbInvoiceDateTo.value);
        fetchDashboard(params);
    }

    if (pkbInvoiceSearch) {
        pkbInvoiceSearch.addEventListener('input', function () {
            clearTimeout(pkbInvoiceDebounceTimer);
            pkbInvoiceDebounceTimer = setTimeout(applyPkbInvoiceFilter, 400);
        });
    }
    [pkbInvoiceStatus, pkbInvoiceDateFrom, pkbInvoiceDateTo].forEach(function (el) {
        if (el) el.addEventListener('change', applyPkbInvoiceFilter);
    });
```

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=DashboardViewTest`
Expected: 4 test PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/views/dashboard/index.blade.php resources/views/dashboard/_tab_pkb_invoice.blade.php resources/views/dashboard/_tab_audit_log.blade.php tests/Feature/DashboardViewTest.php
git commit -m "feat: wire dashboard view and JS to real Tab 1/Tab 3 data and Card 3/4 breakdown"
```

---

### Task 4: End-to-End Test Suite `DashboardControllerTest` & Verifikasi Manual

**Files:**
- Test: `tests/Feature/DashboardControllerTest.php`

**Interfaces:**
- Consumes: seluruh hasil Task 1-3.

- [ ] **Step 1: Tulis `DashboardControllerTest`**

`tests/Feature/DashboardControllerTest.php` — skenario integrasi menyeluruh (bukan pengulangan test
Task 1-3 yang sudah granular, tapi memverifikasi payload HTML awal & JSON konsisten, serta interaksi
lintas-widget dalam satu request):

```php
<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\UserBranchService;
use App\Support\AuditEvent;
use App\Support\InvoiceStatus;
use App\Support\WorkOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
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

    protected function makeCustomerVehicleMechanic(Branch $branch): array
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

        return [$customer, $vehicle, $mechanic];
    }

    public function test_html_and_json_response_return_the_same_payload_values(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);
        WorkOrder::create([
            'number' => 'PKB-E2E-1', 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id, 'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->toDateString(), 'status' => WorkOrderStatus::OPEN,
        ]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');

        $htmlResponse = $this->actingAs($user)->get('/dashboard?branch_ids[]=' . $branch->id);
        $jsonResponse = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $htmlResponse->assertOk();
        $jsonResponse->assertOk();
        $jsonResponse->assertJson(['pkbStatus' => ['draft' => 0, 'open' => 1, 'shortage' => 0, 'completed' => 0]]);
        $htmlResponse->assertSee('PKB-E2E-1');
    }

    public function test_user_with_multiple_branches_only_sees_data_for_selected_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        [$customerA, $vehicleA, $mechanicA] = $this->makeCustomerVehicleMechanic($branchA);
        [$customerB, $vehicleB, $mechanicB] = $this->makeCustomerVehicleMechanic($branchB);
        WorkOrder::create([
            'number' => 'PKB-A', 'branch_id' => $branchA->id, 'customer_id' => $customerA->id,
            'vehicle_id' => $vehicleA->id, 'mechanic_id' => $mechanicA->id,
            'work_order_date' => now()->toDateString(), 'status' => WorkOrderStatus::OPEN,
        ]);
        WorkOrder::create([
            'number' => 'PKB-B', 'branch_id' => $branchB->id, 'customer_id' => $customerB->id,
            'vehicle_id' => $vehicleB->id, 'mechanic_id' => $mechanicB->id,
            'work_order_date' => now()->toDateString(), 'status' => WorkOrderStatus::OPEN,
        ]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'pkb.view');
        $this->grantBranchPermission($user, $branchB, 'pkb.view');

        $response = $this->actingAs($user)->getJson('/dashboard?branch_ids[]=' . $branchA->id);

        $response->assertOk();
        $numbers = collect($response->json('pkbInvoiceRows'))->pluck('number');
        $this->assertTrue($numbers->contains('PKB-A'));
        $this->assertFalse($numbers->contains('PKB-B'));
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_full_widget_permission_matrix_end_to_end(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        [$customer, $vehicle, $mechanic] = $this->makeCustomerVehicleMechanic($branch);

        $wo = WorkOrder::create([
            'number' => 'PKB-MATRIX', 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id, 'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->toDateString(), 'status' => WorkOrderStatus::COMPLETED,
        ]);
        Invoice::create([
            'number' => 'INV-MATRIX', 'work_order_id' => $wo->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(), 'status' => InvoiceStatus::POSTED,
            'subtotal_service' => 150000, 'subtotal_sparepart' => 0, 'discount_percent' => 0, 'discount_amount' => 0,
            'tax_percent' => 0, 'tax_amount' => 0, 'grand_total' => 150000, 'paid_amount' => 0,
        ]);
        AuditLog::create(['branch_id' => $branch->id, 'event' => AuditEvent::INVOICE_POSTED]);

        // User dengan SEMUA permission relevan — semua widget harus terisi.
        $fullUser = User::factory()->create();
        $this->grantBranchPermission($fullUser, $branch, 'pkb.view');
        $this->grantBranchPermission($fullUser, $branch, 'invoice.view');
        $this->grantBranchPermission($fullUser, $branch, 'sparepart.view');
        $permission = Permission::firstOrCreate(
            ['code' => 'audit_log.view'],
            ['resource' => 'audit_log', 'action' => 'view', 'description' => 'audit_log.view']
        );
        UserPermission::create(['user_id' => $fullUser->id, 'permission_id' => $permission->id]);

        $fullResponse = $this->actingAs($fullUser)->getJson('/dashboard?branch_ids[]=' . $branch->id);
        $fullResponse->assertOk();
        $fullData = $fullResponse->json();
        $this->assertGreaterThan(0, count($fullData['pkbInvoiceRows']));
        $this->assertTrue($fullData['canViewAuditLog']);
        $this->assertCount(1, $fullData['auditLogRows']);
        $this->assertSame(150000.0, $fullData['receivables']['revenue']);

        // User dengan HANYA sparepart.view — PKB/Invoice/Audit Log widget harus kosong.
        $limitedUser = User::factory()->create();
        $this->grantBranchPermission($limitedUser, $branch, 'sparepart.view');

        $limitedResponse = $this->actingAs($limitedUser)->getJson('/dashboard?branch_ids[]=' . $branch->id);
        $limitedResponse->assertOk();
        $limitedData = $limitedResponse->json();
        $this->assertSame([], $limitedData['pkbInvoiceRows']);
        $this->assertFalse($limitedData['canViewAuditLog']);
        $this->assertSame([], $limitedData['auditLogRows']);
        $this->assertSame(0.0, $limitedData['receivables']['revenue']);
    }
}
```

- [ ] **Step 2: Jalankan test file baru**

Run: `php artisan test --filter=DashboardControllerTest`
Expected: 4 test PASS.

- [ ] **Step 3: Jalankan full test suite**

Run: `php artisan test`
Expected: seluruh test (suite lama + `AuditEventTest` + `DashboardCardsTest` + `DashboardTabsTest` +
`DashboardViewTest` + `DashboardControllerTest`) PASS tanpa regresi.

- [ ] **Step 4: Verifikasi manual di browser**

Login sebagai `faiz_rahmat` / `faiz_rahmat` (akses penuh semua cabang & permission). Di `/dashboard`:
1. Pastikan Card 3 menampilkan breakdown `Draft · Open · Shortage · Selesai` dengan angka wajar (bukan
   `8/2/15` yang statis).
2. Pastikan Card 4 menampilkan pendapatan & piutang yang berubah kalau filter cabang diganti.
3. Pastikan kedua chart menampilkan minggu-minggu kalender riil (label tanggal, bukan "Pekan 1..6").
4. Ketik di kotak pencarian Tab 1 — pastikan tabel ter-update otomatis (debounce ~400ms) tanpa reload
   halaman penuh, cek juga filter status & rentang tanggal.
5. Klik tab Audit Log — pastikan daftar aktivitas riil muncul (bukan 3 baris statis), dan badge severity
   berwarna sesuai LOW/MEDIUM/HIGH.
6. Ganti filter cabang di dropdown atas — pastikan **semua** bagian (cards, chart, ketiga tab) ikut
   ter-refresh konsisten, termasuk Tab 1 & Tab 3 yang sebelumnya tidak tersambung ke AJAX.
7. (Jika akun demo tidak punya `audit_log.view`) pastikan tab "Audit Log" memang tidak muncul sama
   sekali di UI.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/DashboardControllerTest.php
git commit -m "test: add end-to-end coverage for dashboard real data widgets"
```

---

## Self-Review Notes

- **Cakupan spek:** Card 3/4 & scoping (§3.1, 3.2, 3.3) → Task 1. Tren mingguan via audit_logs (§3.4) →
  Task 1. Audit Log permission global + severity mapping (§3.5) → Task 1 (mapping) + Task 2 (query &
  gating). Tab 1 gabungan & filter (§3.6) → Task 2. Endpoint tunggal + parameter baru (§3.7) → Task 2.
  Tab 3 filter tetap nonaktif (§3.8) → sengaja tidak diaktifkan di Task 3 (dropdown User/Event tetap
  `disabled`), sesuai keputusan spec.
- **Placeholder scan:** tidak ada `TBD`/`TODO`; seluruh step berisi kode nyata.
- **Konsistensi tipe:** `scopedBranchIdsFor()` dipakai dengan signature sama persis di semua pemanggilan
  (`buildPayload()`, tidak ada tempat lain). Nama key payload (`pkbStatus.draft`, `receivables.unpaid`,
  `pkbInvoiceRows[].type`, `auditLogRows[].severity`, dst.) konsisten dipakai oleh Controller (Task 1-2),
  View (Task 3), dan test (Task 1, 2, 3, 4) — tidak ada mismatch nama field.
- **Keamanan:** rendering ulang `pkbInvoiceRows`/`auditLogRows` di JS (Task 3) memakai `textContent`/DOM
  API, bukan `innerHTML` dengan string customer/plat mentah — mencegah XSS dari data master yang bisa
  diisi user (nama customer, dsb.).
