# Laporan Performance Bengkel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Laporan baru "Laporan Performance Bengkel" dengan dua tampilan (`view_type=mechanic` / `invoice_detail`), lengkap dengan index view, PDF preview/download, dan Excel export yang memuat formula Excel asli (bukan sekadar nilai statis) — sesuai spec `docs/superpowers/specs/2026-08-11-workshop-performance-report-design.md`.

**Architecture:** Controller baru `WorkshopPerformanceReportController` (pola `HandlesReportExport` trait, identik laporan lain) dengan dua query builder terpisah: query agregat (JOIN + GROUP BY mekanik) untuk Tampilan Mekanik, dan query Eloquent biasa (identik `InvoiceReportController`) untuk Tampilan Invoice Detail. Helper baru `WorkshopPerformanceLinePairer` memasangkan baris Jasa/Sparepart per invoice (pola sama seperti `InvoicePkbGapComparator` yang sudah ada), dipakai bersama oleh index Blade, PDF Blade, dan Excel export. Kedua export Excel memakai pola `FromArray([]) + AfterSheet` (bukan `WithMapping` biasa) supaya bisa menulis formula Excel (`=...`) dengan referensi sel yang presisi.

**Tech Stack:** Laravel 8.75, PHP 7.4, Blade, Maatwebsite Excel (PhpSpreadsheet di baliknya), DomPDF (lewat `layouts.print`).

## Global Constraints

- PHP 7.4 syntax only — tidak ada named arguments, tidak ada `match`, pakai `optional()->` bukan `?->`.
- Tidak ada migration baru — semua kolom sumber (`mechanics.nip`, `invoice_details.item_type/discount_percent/discount_amount/line_total`, dst) sudah ada.
- Parameter filter: `branch_ids[]`, `status`, `date_from`, `date_to` (identik laporan lain) + `view_type` (`mechanic` default / `invoice_detail`) — **bukan** `mode`.
- Format angka: `number_format($value, 0, ',', '.')` (titik ribuan, tanpa desimal) di semua tempat kecuali kolom yang memang literal (deskripsi item, nomor invoice, dsb).
- Nilai kosong pada agregat Mekanik: karena hasil `INNER JOIN`, tidak ada baris kosong untuk ditangani — mekanik tanpa invoice otomatis tidak muncul (bukan bug).
- Sisi Jasa/Sparepart yang lebih pendek pada pairing: deskripsi `-`, angka `0` (lihat `WorkshopPerformanceLinePairer`).
- Setiap task diakhiri `php artisan test` (terfilter ke file yang berubah) lalu commit dengan pesan seperti tercantum di task.
- Full `php artisan test` di Task 5 sebelum commit terakhir — wajib hijau tanpa regresi.

---

## File Structure

- **Create:** `app/Support/WorkshopPerformanceLinePairer.php` — helper pairing Jasa/Sparepart per invoice.
- **Modify:** `database/seeders/MenuPermissionSeeder.php` — entry menu + permission baru.
- **Modify:** `resources/views/partials/sidebar.blade.php` — link baru di blok Reporting.
- **Create:** `app/Http/Controllers/WorkshopPerformanceReportController.php`.
- **Modify:** `routes/web.php` — 4 route baru di grup `reports`.
- **Create:** `resources/views/reports/workshop-performance/index.blade.php`, `.../no-access.blade.php`.
- **Create:** `resources/views/reports/workshop-performance/pdf.blade.php`.
- **Create:** `app/Exports/WorkshopPerformanceMechanicExport.php`, `app/Exports/WorkshopPerformanceInvoiceDetailExport.php`.
- **Create (tests):** `tests/Unit/WorkshopPerformanceLinePairerTest.php`, `tests/Feature/WorkshopPerformanceReportControllerTest.php`, `tests/Feature/WorkshopPerformanceReportExportTest.php`, `tests/Feature/WorkshopPerformanceReportIntegrationTest.php`.
- **Modify (tests):** `tests/Feature/MenuPermissionSeederTest.php`, `tests/Feature/AppShellTest.php`.

---

### Task 1: Foundation — Permission, Sidebar, `WorkshopPerformanceLinePairer`

**Files:**
- Modify: `database/seeders/MenuPermissionSeeder.php`
- Modify: `resources/views/partials/sidebar.blade.php`
- Modify: `tests/Feature/MenuPermissionSeederTest.php`, `tests/Feature/AppShellTest.php`
- Create: `app/Support/WorkshopPerformanceLinePairer.php`
- Test: `tests/Unit/WorkshopPerformanceLinePairerTest.php`

**Interfaces:**
- Produces: permission `report.workshop_performance.view`; route `reports.workshop-performance.index` **belum ada** sampai Task 2 — link sidebar akan `route()`-error sampai Task 2 selesai, jadi Task 1 menambahkan link sidebar TAPI test sidebar yang mengklik link tersebut baru ditulis penuh di Task 1 langkah 1-4 dengan cara yang aman: gunakan `route('reports.workshop-performance.index')` yang HANYA dievaluasi setelah Task 2 route terdaftar. **Untuk menghindari error di Task 1** (route belum ada), test sidebar Task 1 memakai pendekatan yang sama seperti test lain: langsung assert `route(...)`. Karena ini akan gagal sampai Task 2 wire routes, method test sidebar+link ditulis di sini tapi **dijalankan/diverifikasi hijau di akhir Task 2** — lihat catatan di Step 4.
- Produces: `App\Support\WorkshopPerformanceLinePairer::build(Invoice $invoice): array` — dipakai Task 2 (index Blade), Task 3 (PDF Blade), Task 4 (Excel export).

> **Catatan urutan:** karena link sidebar butuh route yang baru didaftarkan di Task 2, langkah sidebar di task ini HANYA menambahkan markup blade (tidak dites langsung). Test sidebar (`AppShellTest`) ditambahkan sebagai bagian dari Task 1 tapi dijalankan pertama kali sebagai bagian dari Task 2 Step 4 (setelah route ada). Seeder test tidak bergantung route, jadi dites penuh di sini.

- [ ] **Step 1: Tulis failing test untuk permission seeder**

Tambahkan method baru di `tests/Feature/MenuPermissionSeederTest.php`, setelah `test_seeder_creates_vehicle_reference_menu_and_permissions()`:

```php
    public function test_seeder_creates_workshop_performance_report_menu_and_permission(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $this->assertDatabaseHas('menus', ['code' => 'reporting.workshop_performance', 'is_branch_scoped' => true]);
        $this->assertDatabaseHas('permissions', ['code' => 'report.workshop_performance.view']);
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=test_seeder_creates_workshop_performance_report_menu_and_permission`
Expected: FAIL — menu/permission belum ada.

- [ ] **Step 3: Implementasi entry menu & permission**

Di `database/seeders/MenuPermissionSeeder.php`, sisipkan array entry baru persis setelah entry `'reporting.invoice'` (sebelum `'reporting.receivable'`):

```php
            [
                'code' => 'reporting.workshop_performance',
                'name' => 'Laporan Performance Bengkel',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'report.workshop_performance.view', 'resource' => 'report', 'action' => 'workshop_performance.view', 'description' => 'Melihat laporan performance bengkel'],
                ],
            ],
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=test_seeder_creates_workshop_performance_report_menu_and_permission`
Expected: PASS

- [ ] **Step 5: Tulis failing test untuk helper pairing**

Buat file baru `tests/Unit/WorkshopPerformanceLinePairerTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Support\InvoiceDetailItemType;
use App\Support\WorkshopPerformanceLinePairer;
use Tests\TestCase;

class WorkshopPerformanceLinePairerTest extends TestCase
{
    protected function detail(string $itemType, string $description, float $price, float $qty, float $discountPercent): InvoiceDetail
    {
        $gross = $qty * $price;
        $discountAmount = $gross * ($discountPercent / 100);

        return new InvoiceDetail([
            'item_type' => $itemType,
            'description' => $description,
            'qty' => $qty,
            'unit_price' => $price,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'line_total' => $gross - $discountAmount,
        ]);
    }

    protected function invoiceWithDetails(array $details): Invoice
    {
        $invoice = new Invoice();
        $invoice->setRelation('details', collect($details));

        return $invoice;
    }

    public function test_pairs_jasa_and_sparepart_lines_with_equal_counts(): void
    {
        $invoice = $this->invoiceWithDetails([
            $this->detail(InvoiceDetailItemType::SERVICE, 'Ganti Oli', 100000, 1, 10),
            $this->detail(InvoiceDetailItemType::SERVICE, 'Servis Rem', 50000, 1, 0),
            $this->detail(InvoiceDetailItemType::SPAREPART, 'Oli Mesin', 90000, 1, 20),
            $this->detail(InvoiceDetailItemType::SPAREPART, 'Kampas Rem', 40000, 1, 10),
        ]);

        $rows = WorkshopPerformanceLinePairer::build($invoice);

        $this->assertCount(2, $rows);
        $this->assertSame('Ganti Oli', $rows[0]['jasa_desc']);
        $this->assertSame('Oli Mesin', $rows[0]['sparepart_desc']);
        $this->assertEqualsWithDelta(90000.0, $rows[0]['jasa_subtotal'], 0.01);
        $this->assertEqualsWithDelta(72000.0, $rows[0]['sparepart_subtotal'], 0.01);
        $this->assertEqualsWithDelta(162000.0, $rows[0]['subtotal_line'], 0.01);
        $this->assertSame('Servis Rem', $rows[1]['jasa_desc']);
        $this->assertSame('Kampas Rem', $rows[1]['sparepart_desc']);
    }

    public function test_pads_sparepart_side_when_jasa_has_more_lines(): void
    {
        $invoice = $this->invoiceWithDetails([
            $this->detail(InvoiceDetailItemType::SERVICE, 'Ganti Oli', 100000, 1, 0),
            $this->detail(InvoiceDetailItemType::SERVICE, 'Servis Rem', 50000, 1, 0),
            $this->detail(InvoiceDetailItemType::SPAREPART, 'Oli Mesin', 90000, 1, 0),
        ]);

        $rows = WorkshopPerformanceLinePairer::build($invoice);

        $this->assertCount(2, $rows);
        $this->assertSame('Servis Rem', $rows[1]['jasa_desc']);
        $this->assertSame('-', $rows[1]['sparepart_desc']);
        $this->assertSame(0.0, $rows[1]['sparepart_price']);
        $this->assertSame(0.0, $rows[1]['sparepart_subtotal']);
        $this->assertEqualsWithDelta(50000.0, $rows[1]['subtotal_line'], 0.01);
    }

    public function test_pads_jasa_side_when_sparepart_has_more_lines(): void
    {
        $invoice = $this->invoiceWithDetails([
            $this->detail(InvoiceDetailItemType::SPAREPART, 'Oli Mesin', 90000, 1, 0),
            $this->detail(InvoiceDetailItemType::SPAREPART, 'Kampas Rem', 40000, 1, 0),
        ]);

        $rows = WorkshopPerformanceLinePairer::build($invoice);

        $this->assertCount(2, $rows);
        $this->assertSame('-', $rows[0]['jasa_desc']);
        $this->assertSame(0.0, $rows[0]['jasa_subtotal']);
        $this->assertSame('Oli Mesin', $rows[0]['sparepart_desc']);
    }

    public function test_returns_empty_array_when_invoice_has_no_details(): void
    {
        $invoice = $this->invoiceWithDetails([]);

        $rows = WorkshopPerformanceLinePairer::build($invoice);

        $this->assertSame([], $rows);
    }
}
```

- [ ] **Step 6: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=WorkshopPerformanceLinePairerTest`
Expected: FAIL — `App\Support\WorkshopPerformanceLinePairer` belum ada (class not found).

- [ ] **Step 7: Implementasi `WorkshopPerformanceLinePairer`**

Buat file baru `app/Support/WorkshopPerformanceLinePairer.php`:

```php
<?php

namespace App\Support;

use App\Models\Invoice;

class WorkshopPerformanceLinePairer
{
    public static function build(Invoice $invoice): array
    {
        $services = $invoice->details->where('item_type', InvoiceDetailItemType::SERVICE)->values();
        $spareparts = $invoice->details->where('item_type', InvoiceDetailItemType::SPAREPART)->values();
        $rowCount = max($services->count(), $spareparts->count());

        $rows = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $service = $services->get($i);
            $sparepart = $spareparts->get($i);
            $jasaSubtotal = $service ? (float) $service->line_total : 0.0;
            $sparepartSubtotal = $sparepart ? (float) $sparepart->line_total : 0.0;

            $rows[] = [
                'jasa_desc' => $service ? $service->description : '-',
                'jasa_price' => $service ? (float) $service->unit_price : 0.0,
                'jasa_qty' => $service ? (float) $service->qty : 0.0,
                'jasa_discount_percent' => $service ? (float) $service->discount_percent : 0.0,
                'jasa_subtotal' => $jasaSubtotal,
                'sparepart_desc' => $sparepart ? $sparepart->description : '-',
                'sparepart_price' => $sparepart ? (float) $sparepart->unit_price : 0.0,
                'sparepart_qty' => $sparepart ? (float) $sparepart->qty : 0.0,
                'sparepart_discount_percent' => $sparepart ? (float) $sparepart->discount_percent : 0.0,
                'sparepart_subtotal' => $sparepartSubtotal,
                'subtotal_line' => $jasaSubtotal + $sparepartSubtotal,
            ];
        }

        return $rows;
    }
}
```

- [ ] **Step 8: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=WorkshopPerformanceLinePairerTest`
Expected: PASS (4 test)

- [ ] **Step 9: Tambahkan link sidebar (markup saja, belum bisa dites — route belum ada)**

Di `resources/views/partials/sidebar.blade.php`:

1. Update kondisi gabungan baris 154 (tambahkan klausa baru sebelum tanda kurung tutup):

```blade
@if ($user && ($user->branchesWithPermission('report.pkb.view')->isNotEmpty() || $user->branchesWithPermission('report.invoice.view')->isNotEmpty() || $user->branchesWithPermission('report.workshop_performance.view')->isNotEmpty() || $user->branchesWithPermission('report.receivable.view')->isNotEmpty() || $user->branchesWithPermission('report.invoice_pkb_gap.view')->isNotEmpty() || $user->branchesWithPermission('report.sparepart.view')->isNotEmpty()))
```

2. Sisipkan `<li>` baru persis setelah blok "Laporan Invoice" (setelah `@endif` baris 169), sebelum blok "Laporan Piutang":

```blade
        @if ($user->branchesWithPermission('report.workshop_performance.view')->isNotEmpty())
        <li class="nav-item">
            <a href="{{ route('reports.workshop-performance.index') }}" class="nav-link {{ request()->routeIs('reports.workshop-performance.*') ? 'active' : '' }}">
                <i class="bi bi-speedometer2 me-2"></i> Laporan Performance Bengkel
            </a>
        </li>
        @endif
```

- [ ] **Step 10: Tambahkan test sidebar (ditulis sekarang, akan PASS setelah Task 2 selesai)**

Tambahkan di `tests/Feature/AppShellTest.php`, setelah `test_sidebar_links_directly_to_invoice_report_when_permitted()`:

```php
    public function test_sidebar_links_directly_to_workshop_performance_report_when_permitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'report.workshop_performance.view', 'resource' => 'report', 'action' => 'workshop_performance.view', 'description' => 'Melihat laporan performance bengkel']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('reports.workshop-performance.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }
```

Tambahkan juga satu baris di `test_sidebar_hides_all_new_placeholder_headings_without_any_permission()`, setelah `$response->assertDontSee('Laporan Invoice', false);`:

```php
        $response->assertDontSee('Laporan Performance Bengkel', false);
```

**Catatan penting:** dua penambahan test di Step 10 akan **FAIL** jika dijalankan sekarang, karena route `reports.workshop-performance.index` belum terdaftar (`Route [reports.workshop-performance.index] not defined`). Ini disengaja — jalankan `php artisan test --filter=AppShellTest` sekarang HANYA untuk konfirmasi kegagalannya persis karena route belum ada (bukan error lain), lalu **tunda verifikasi PASS sampai akhir Task 2 Step 4** setelah route terdaftar.

- [ ] **Step 11: Commit**

```bash
git add database/seeders/MenuPermissionSeeder.php resources/views/partials/sidebar.blade.php app/Support/WorkshopPerformanceLinePairer.php tests/Unit/WorkshopPerformanceLinePairerTest.php tests/Feature/MenuPermissionSeederTest.php tests/Feature/AppShellTest.php
git commit -m "feat: add workshop performance report permission, sidebar link, and line pairer helper"
```

---

### Task 2: Controller & Web Views

**Files:**
- Create: `app/Http/Controllers/WorkshopPerformanceReportController.php`
- Modify: `routes/web.php`
- Create: `resources/views/reports/workshop-performance/index.blade.php`
- Create: `resources/views/reports/workshop-performance/no-access.blade.php`
- Test: `tests/Feature/WorkshopPerformanceReportControllerTest.php`

**Interfaces:**
- Consumes: `Mechanic::display_label` (existing), `App\Support\WorkshopPerformanceLinePairer::build()` (Task 1), `App\Http\Controllers\Concerns\HandlesReportExport` (existing trait — `authorizeExport()`, `capRows()`, `streamPdf()`).
- Produces: route `reports.workshop-performance.index` (dipakai Task 1 Step 10 & sidebar), `WorkshopPerformanceReportController::buildMechanicQuery()`/`buildInvoiceDetailQuery()`/`resolveFilters()`/`filterSummaryText()` (protected, dipakai ulang oleh `exportExcel()`/`renderPdf()` di Task 3 & 4), Blade view `reports.workshop-performance.index` menerima variabel: `viewType` (string), `mechanicRows` (`LengthAwarePaginator`|null), `invoices` (paginator|null), `branches`, `selectedBranchIds`, `status`, `dateFrom`, `dateTo`.

- [ ] **Step 1: Tulis failing test untuk controller & views**

Buat file baru `tests/Feature/WorkshopPerformanceReportControllerTest.php`:

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
use App\Support\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkshopPerformanceReportControllerTest extends TestCase
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

    protected function makeInvoiceWithLines(Branch $branch, Customer $customer, Mechanic $mechanic, array $serviceAmounts, array $sparepartAmounts, string $invoiceDate): Invoice
    {
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        MechanicBranch::firstOrCreate(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::firstOrCreate(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id,
            'plate_number' => 'B ' . random_int(1000, 9999) . ' ' . $branch->code,
        ]);

        $services = [];
        foreach ($serviceAmounts as $index => $amount) {
            $catalog = ServiceCatalog::create(['code' => 'SVC-' . random_int(10000, 99999), 'name' => "Jasa {$index}", 'default_price' => $amount]);
            $services[] = ['service_catalog_id' => $catalog->id, 'description' => "Jasa {$index}", 'qty' => 1, 'unit_price' => $amount];
        }

        $spareparts = [];
        foreach ($sparepartAmounts as $index => $amount) {
            $sparepart = Sparepart::create(['code' => 'SPR-' . random_int(10000, 99999), 'name' => "Sparepart {$index}"]);
            $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => $amount]);
            DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);
            $spareparts[] = ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 1, 'unit_price' => $amount];
        }

        $user = User::factory()->create();
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete', 'invoice.create', 'invoice.post'] as $code) {
            $this->grantBranchPermission($user, $branch, $code);
        }

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => $services,
            'spareparts' => $spareparts,
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);
        $invoice = Invoice::where('work_order_id', $workOrder->id)->firstOrFail();
        $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");
        $invoice->update(['invoice_date' => $invoiceDate]);

        return $invoice->fresh(['details', 'workOrder.mechanic', 'branch', 'customer']);
    }

    protected function applyDiscountPercent(Invoice $invoice, float $percent): Invoice
    {
        foreach ($invoice->details as $detail) {
            $gross = (float) $detail->qty * (float) $detail->unit_price;
            $discountAmount = round($gross * ($percent / 100), 2);
            $detail->update([
                'discount_percent' => $percent,
                'discount_amount' => $discountAmount,
                'line_total' => round($gross - $discountAmount, 2),
            ]);
        }

        return $invoice->fresh(['details', 'workOrder.mechanic', 'branch', 'customer']);
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/workshop-performance');

        $response->assertOk();
        $response->assertSee('Anda belum memiliki akses laporan performance bengkel di cabang manapun.');
    }

    public function test_mechanic_view_shows_correct_aggregates_per_mechanic(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer1 = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customer2 = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        $mechanicA = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $mechanicB = Mechanic::create(['name' => 'Bambang Wijaya', 'nip' => 'MEK-002']);
        $mechanicC = Mechanic::create(['name' => 'Candra Kusuma', 'nip' => 'MEK-003']);
        MechanicBranch::create(['mechanic_id' => $mechanicC->id, 'branch_id' => $branch->id]);

        $invoice1 = $this->makeInvoiceWithLines($branch, $customer1, $mechanicA, [100000], [], now()->toDateString());
        $invoice2 = $this->makeInvoiceWithLines($branch, $customer2, $mechanicA, [100000], [100000], now()->toDateString());
        $this->applyDiscountPercent($invoice2, 10);
        $this->makeInvoiceWithLines($branch, $customer1, $mechanicB, [80000], [], now()->toDateString());

        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance');

        $response->assertOk();
        $response->assertSee('MEK-001 - Agus Setiawan');
        $response->assertSee('MEK-002 - Bambang Wijaya');
        $response->assertDontSee('MEK-003 - Candra Kusuma');
        $response->assertSee('190.000');
        $response->assertSee('90.000');
        $response->assertSee('280.000');
        $response->assertSee('80.000');
    }

    public function test_mechanic_view_excludes_direct_sale_invoices(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [], now()->toDateString());

        $creator = User::factory()->create();
        $this->grantBranchPermission($creator, $branch, 'invoice.create');
        $this->actingAs($creator)->post('/invoices/direct', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 500000, 'discount_percent' => 0]],
            'spareparts' => [],
        ]);

        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance');

        $response->assertOk();
        $response->assertSee('MEK-001 - Agus Setiawan');
        // Subtotal Jasa mekanik tetap 100.000 (bukan 600.000) — membuktikan invoice Direct
        // Sales (500.000, tanpa mekanik) tidak ikut ter-agregasi lewat INNER JOIN work_orders.
        $response->assertSee('100.000');
    }

    public function test_mechanic_view_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanicA = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $mechanicB = Mechanic::create(['name' => 'Bambang Wijaya', 'nip' => 'MEK-002']);
        $this->makeInvoiceWithLines($branchA, $customer, $mechanicA, [100000], [], '2026-01-10');
        $this->makeInvoiceWithLines($branchB, $customer, $mechanicB, [100000], [], now()->toDateString());

        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.workshop_performance.view');
        $this->grantBranchPermission($viewer, $branchB, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance?branch_ids[]=' . $branchA->id);

        $response->assertOk();
        $response->assertSee('MEK-001 - Agus Setiawan');
        $response->assertDontSee('MEK-002 - Bambang Wijaya');
    }

    public function test_invoice_detail_view_pairs_jasa_and_sparepart_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [90000, 40000], now()->toDateString());

        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance?view_type=invoice_detail');

        $response->assertOk();
        $response->assertSee('MEK-001 - Agus Setiawan');
        $response->assertSee('Jasa 0');
        $response->assertSee('Sparepart 0');
        $response->assertSee('Sparepart 1');
    }

    public function test_invoice_detail_view_shows_dash_mechanic_for_direct_sale(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $creator = User::factory()->create();
        $this->grantBranchPermission($creator, $branch, 'invoice.create');
        $this->actingAs($creator)->post('/invoices/direct', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000, 'discount_percent' => 0]],
            'spareparts' => [],
        ]);
        $directSale = Invoice::latest('id')->first();

        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance?view_type=invoice_detail');

        $response->assertOk();
        $response->assertSee($directSale->number);
        $response->assertSee('Cuci Mobil');
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=WorkshopPerformanceReportControllerTest`
Expected: FAIL — route `reports.workshop-performance.index` belum terdaftar (`RouteNotFoundException` atau 404).

- [ ] **Step 3: Implementasi routes, controller, dan views**

Di `routes/web.php`, tambahkan import setelah `use App\Http\Controllers\PkbReportController;`:

```php
use App\Http\Controllers\WorkshopPerformanceReportController;
```

Tambahkan 4 route di dalam grup `reports` (`routes/web.php:222-243`), setelah baris route `invoices.pdf-download` dan sebelum route `invoice-pkb-gap.index`:

```php
        Route::get('/workshop-performance', [WorkshopPerformanceReportController::class, 'index'])->name('workshop-performance.index');
        Route::get('/workshop-performance/export-excel', [WorkshopPerformanceReportController::class, 'exportExcel'])->name('workshop-performance.export-excel');
        Route::get('/workshop-performance/pdf-preview', [WorkshopPerformanceReportController::class, 'previewPdf'])->name('workshop-performance.pdf-preview');
        Route::get('/workshop-performance/pdf-download', [WorkshopPerformanceReportController::class, 'downloadPdf'])->name('workshop-performance.pdf-download');
```

Buat file baru `app/Http/Controllers/WorkshopPerformanceReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Exports\WorkshopPerformanceInvoiceDetailExport;
use App\Exports\WorkshopPerformanceMechanicExport;
use App\Http\Controllers\Concerns\HandlesReportExport;
use App\Models\Invoice;
use App\Support\InvoiceStatus;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class WorkshopPerformanceReportController extends Controller
{
    use HandlesReportExport;

    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.workshop_performance.view');

        if ($permittedBranches->isEmpty()) {
            return view('reports.workshop-performance.no-access');
        }

        $filters = $this->resolveFilters($permittedBranches);

        $viewData = [
            'viewType' => $filters['viewType'],
            'branches' => $permittedBranches,
            'selectedBranchIds' => $filters['branchIds'],
            'status' => $filters['status'],
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'mechanicRows' => null,
            'invoices' => null,
        ];

        if ($filters['viewType'] === 'mechanic') {
            $rows = $this->buildMechanicQuery($filters, $permittedBranches)->get();
            $page = LengthAwarePaginator::resolveCurrentPage();
            $perPage = 15;
            $viewData['mechanicRows'] = new LengthAwarePaginator(
                $rows->forPage($page, $perPage)->values(),
                $rows->count(),
                $perPage,
                $page,
                ['path' => request()->url(), 'query' => request()->query()]
            );

            return view('reports.workshop-performance.index', $viewData);
        }

        $viewData['invoices'] = $this->buildInvoiceDetailQuery($filters, $permittedBranches)
            ->with(['branch', 'customer', 'workOrder.mechanic', 'details'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('reports.workshop-performance.index', $viewData);
    }

    public function exportExcel()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('report.workshop_performance.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);

        if ($filters['viewType'] === 'mechanic') {
            $rows = $this->buildMechanicQuery($filters, $permittedBranches)->get();

            return Excel::download(
                new WorkshopPerformanceMechanicExport($rows, $this->filterSummaryText($filters)),
                'laporan-performance-mekanik-' . now()->format('Ymd-His') . '.xlsx'
            );
        }

        $query = $this->buildInvoiceDetailQuery($filters, $permittedBranches)
            ->with(['branch', 'customer', 'workOrder.mechanic', 'details']);
        $rows = $query->orderByDesc('invoice_date')->orderByDesc('id')->limit(1001)->get();
        [$rows, ] = $this->capRows($rows);

        return Excel::download(
            new WorkshopPerformanceInvoiceDetailExport($rows, $this->filterSummaryText($filters)),
            'laporan-performance-invoice-detail-' . now()->format('Ymd-His') . '.xlsx'
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
        $permittedBranches = $user->branchesWithPermission('report.workshop_performance.view');
        $this->authorizeExport($permittedBranches);

        $filters = $this->resolveFilters($permittedBranches);

        if ($filters['viewType'] === 'mechanic') {
            $rows = $this->buildMechanicQuery($filters, $permittedBranches)->get();

            return $this->streamPdf('reports.workshop-performance.pdf', [
                'viewType' => $filters['viewType'],
                'mechanicRows' => $rows,
                'invoices' => collect(),
                'truncated' => false,
                'filterSummary' => $this->filterSummaryText($filters),
            ], 'laporan-performance-mekanik', $disposition);
        }

        $query = $this->buildInvoiceDetailQuery($filters, $permittedBranches)
            ->with(['branch', 'customer', 'workOrder.mechanic', 'details']);
        $rows = $query->orderByDesc('invoice_date')->orderByDesc('id')->limit(1001)->get();
        [$rows, $truncated] = $this->capRows($rows);

        return $this->streamPdf('reports.workshop-performance.pdf', [
            'viewType' => $filters['viewType'],
            'mechanicRows' => collect(),
            'invoices' => $rows,
            'truncated' => $truncated,
            'filterSummary' => $this->filterSummaryText($filters),
        ], 'laporan-performance-invoice-detail', $disposition);
    }

    protected function resolveFilters(SupportCollection $permittedBranches): array
    {
        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $status = request('status');
        $status = in_array($status, [
            InvoiceStatus::DRAFT, InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID,
            InvoiceStatus::PAID, InvoiceStatus::CANCELLED,
        ], true) ? $status : null;

        return [
            'branchIds' => $branchIds,
            'status' => $status,
            'dateFrom' => $this->parseDate(request('date_from')),
            'dateTo' => $this->parseDate(request('date_to')),
            'viewType' => request('view_type') === 'invoice_detail' ? 'invoice_detail' : 'mechanic',
        ];
    }

    protected function buildMechanicQuery(array $filters, SupportCollection $permittedBranches)
    {
        return Invoice::query()
            ->join('work_orders', 'work_orders.id', '=', 'invoices.work_order_id')
            ->join('mechanics', 'mechanics.id', '=', 'work_orders.mechanic_id')
            ->join('invoice_details', 'invoice_details.invoice_id', '=', 'invoices.id')
            ->whereIn('invoices.branch_id', $permittedBranches->pluck('id'))
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('invoices.branch_id', $filters['branchIds']))
            ->when($filters['status'], fn ($q) => $q->where('invoices.status', $filters['status']))
            ->when($filters['dateFrom'], fn ($q) => $q->whereDate('invoices.invoice_date', '>=', $filters['dateFrom']))
            ->when($filters['dateTo'], fn ($q) => $q->whereDate('invoices.invoice_date', '<=', $filters['dateTo']))
            ->groupBy('mechanics.id')
            ->select([
                'mechanics.id as mechanic_id',
                'mechanics.name as mechanic_name',
                'mechanics.nip as mechanic_nip',
                DB::raw('COUNT(DISTINCT invoices.customer_id) as total_customer'),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'service' THEN invoice_details.qty ELSE 0 END), 0) as total_qty_jasa"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'service' THEN invoice_details.discount_amount ELSE 0 END), 0) as total_discount_jasa"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'service' THEN invoice_details.line_total ELSE 0 END), 0) as subtotal_jasa"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'sparepart' THEN invoice_details.qty ELSE 0 END), 0) as total_qty_sparepart"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'sparepart' THEN invoice_details.discount_amount ELSE 0 END), 0) as total_discount_sparepart"),
                DB::raw("COALESCE(SUM(CASE WHEN invoice_details.item_type = 'sparepart' THEN invoice_details.line_total ELSE 0 END), 0) as subtotal_sparepart"),
                DB::raw('MAX(invoices.invoice_date) as last_invoice_date'),
            ])
            ->orderByDesc('last_invoice_date');
    }

    protected function buildInvoiceDetailQuery(array $filters, SupportCollection $permittedBranches)
    {
        return Invoice::query()
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($filters['branchIds'], fn ($q) => $q->whereIn('branch_id', $filters['branchIds']))
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
        $viewTypeLabel = $filters['viewType'] === 'invoice_detail' ? 'Invoice Detail' : 'Mekanik';

        return "Cabang: {$branchLabel} · Status: {$statusLabel} · Tanggal: {$dateLabel} · Tampilan: {$viewTypeLabel}";
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

Buat file baru `resources/views/reports/workshop-performance/no-access.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Laporan Performance Bengkel')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-speedometer2 me-2"></i>Laporan Performance Bengkel</h1>
    </div>
    <div class="card">
        <div class="card-body text-center py-5">
            <p class="text-muted mb-0">Anda belum memiliki akses laporan performance bengkel di cabang manapun.</p>
        </div>
    </div>
@endsection
```

Buat file baru `resources/views/reports/workshop-performance/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Laporan Performance Bengkel')
@section('content')
    <div class="mb-4 d-flex justify-content-between align-items-center">
        <h1 class="h4 mb-0"><i class="bi bi-speedometer2 me-2"></i>Laporan Performance Bengkel</h1>
        @include('partials.report-export-buttons', [
            'excelRoute' => 'reports.workshop-performance.export-excel',
            'pdfPreviewRoute' => 'reports.workshop-performance.pdf-preview',
            'pdfDownloadRoute' => 'reports.workshop-performance.pdf-download',
        ])
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.workshop-performance.index') }}" id="workshopPerformanceFilterForm" class="row g-2 align-items-end">
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
                    <label class="form-label small">Tanggal Dari</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sampai</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tampilan</label>
                    <select name="view_type" class="form-select form-select-sm">
                        <option value="mechanic" {{ $viewType === 'mechanic' ? 'selected' : '' }}>Mekanik</option>
                        <option value="invoice_detail" {{ $viewType === 'invoice_detail' ? 'selected' : '' }}>Invoice Detail</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Terapkan Filter</button>
                </div>
            </form>
        </div>
    </div>

    @if ($viewType === 'mechanic')
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Mekanik</th>
                            <th>Total Customer</th>
                            <th>Total Qty Jasa</th>
                            <th>Total Discount Jasa (Rp)</th>
                            <th>Subtotal Jasa</th>
                            <th>Total Qty Sparepart</th>
                            <th>Total Discount Sparepart (Rp)</th>
                            <th>Subtotal Sparepart</th>
                            <th>Grand Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($mechanicRows as $row)
                            @php
                                $mechanicLabel = $row->mechanic_nip ? "{$row->mechanic_nip} - {$row->mechanic_name}" : $row->mechanic_name;
                                $grandTotal = (float) $row->subtotal_jasa + (float) $row->subtotal_sparepart;
                            @endphp
                            <tr>
                                <td>{{ $mechanicLabel }}</td>
                                <td>{{ number_format($row->total_customer, 0, ',', '.') }}</td>
                                <td>{{ number_format($row->total_qty_jasa, 0, ',', '.') }}</td>
                                <td>{{ number_format($row->total_discount_jasa, 0, ',', '.') }}</td>
                                <td>{{ number_format($row->subtotal_jasa, 0, ',', '.') }}</td>
                                <td>{{ number_format($row->total_qty_sparepart, 0, ',', '.') }}</td>
                                <td>{{ number_format($row->total_discount_sparepart, 0, ',', '.') }}</td>
                                <td>{{ number_format($row->subtotal_sparepart, 0, ',', '.') }}</td>
                                <td>{{ number_format($grandTotal, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="p-0">
                                    @include('partials.empty-state', [
                                        'icon' => 'bi-speedometer2',
                                        'title' => 'Belum ada data performance mekanik',
                                        'description' => 'Tidak ada mekanik dengan aktivitas invoice yang cocok dengan filter saat ini.',
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
            {{ $mechanicRows->links() }}
        </div>
    @else
        @forelse ($invoices as $invoice)
            @php
                $mechanicLabel = optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-';
                $pairs = \App\Support\WorkshopPerformanceLinePairer::build($invoice);
                $totalJasa = collect($pairs)->sum('jasa_subtotal');
                $totalSparepart = collect($pairs)->sum('sparepart_subtotal');
                $totalLine = $totalJasa + $totalSparepart;
            @endphp
            <div class="card mb-3">
                <div class="card-header d-flex flex-wrap gap-3 small">
                    <span><strong>No. Invoice:</strong> {{ $invoice->number }}</span>
                    <span><strong>Tanggal:</strong> {{ $invoice->invoice_date->format('d/m/Y') }}</span>
                    <span><strong>Status:</strong> {{ $invoice->status }}</span>
                    <span><strong>Customer:</strong> {{ $invoice->customer->name }}</span>
                    <span><strong>Mekanik:</strong> {{ $mechanicLabel }}</span>
                    <span><strong>Cabang:</strong> {{ $invoice->branch->name }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th colspan="5" class="text-center">Jasa</th>
                                <th colspan="5" class="text-center">Sparepart</th>
                                <th rowspan="2" class="align-middle">Subtotal Line</th>
                            </tr>
                            <tr>
                                <th>Deskripsi</th><th>Harga</th><th>Qty</th><th>Diskon %</th><th>Subtotal</th>
                                <th>Deskripsi</th><th>Harga</th><th>Qty</th><th>Diskon %</th><th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pairs as $pair)
                                <tr>
                                    <td>{{ $pair['jasa_desc'] }}</td>
                                    <td>{{ number_format($pair['jasa_price'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['jasa_qty'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['jasa_discount_percent'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['jasa_subtotal'], 0, ',', '.') }}</td>
                                    <td>{{ $pair['sparepart_desc'] }}</td>
                                    <td>{{ number_format($pair['sparepart_price'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['sparepart_qty'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['sparepart_discount_percent'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['sparepart_subtotal'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($pair['subtotal_line'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="11" class="text-muted">&mdash;</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="4">Total</td>
                                <td>{{ number_format($totalJasa, 0, ',', '.') }}</td>
                                <td colspan="4"></td>
                                <td>{{ number_format($totalSparepart, 0, ',', '.') }}</td>
                                <td>{{ number_format($totalLine, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @empty
            @include('partials.empty-state', [
                'icon' => 'bi-speedometer2',
                'title' => 'Belum ada data invoice',
                'description' => 'Tidak ada invoice yang cocok dengan filter saat ini.',
                'ctaVisible' => false,
                'ctaRoute' => '',
                'ctaLabel' => '',
            ])
        @endforelse
        <div class="mt-3">
            {{ $invoices->links() }}
        </div>
    @endif

    @push('scripts')
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('workshopPerformanceFilterForm');
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

- [ ] **Step 4: Jalankan test, pastikan lulus (termasuk test sidebar Task 1 Step 10)**

Run: `php artisan test --filter=WorkshopPerformanceReportControllerTest`
Expected: PASS (6 test)

Run juga: `php artisan test --filter=AppShellTest`
Expected: PASS — termasuk 2 assertion yang ditambahkan di Task 1 Step 10 (route sekarang sudah terdaftar).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/WorkshopPerformanceReportController.php routes/web.php resources/views/reports/workshop-performance/index.blade.php resources/views/reports/workshop-performance/no-access.blade.php tests/Feature/WorkshopPerformanceReportControllerTest.php
git commit -m "feat: add WorkshopPerformanceReportController with mechanic and invoice detail views"
```

---

### Task 3: Cetak PDF

**Files:**
- Create: `resources/views/reports/workshop-performance/pdf.blade.php`
- Test: `tests/Feature/WorkshopPerformanceReportExportTest.php` (bagian PDF)

**Interfaces:**
- Consumes: `WorkshopPerformanceReportController::previewPdf()`/`downloadPdf()` (sudah ada sejak Task 2, sudah memanggil `streamPdf('reports.workshop-performance.pdf', ...)` — tinggal view-nya yang belum ada), `WorkshopPerformanceLinePairer::build()` (Task 1).
- Produces: view `reports.workshop-performance.pdf` menerima `viewType`, `mechanicRows` (Collection), `invoices` (Collection), `truncated` (bool), `filterSummary` (string) — variabel yang sama yang sudah dikirim controller di Task 2.

- [ ] **Step 1: Tulis failing test untuk PDF**

Buat file baru `tests/Feature/WorkshopPerformanceReportExportTest.php`:

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
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class WorkshopPerformanceReportExportTest extends TestCase
{
    use RefreshDatabase, ExtractsPdfText;

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

    protected function makeInvoiceWithLines(Branch $branch, Customer $customer, Mechanic $mechanic, array $serviceAmounts, array $sparepartAmounts, string $invoiceDate): Invoice
    {
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        MechanicBranch::firstOrCreate(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::firstOrCreate(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id,
            'plate_number' => 'B ' . random_int(1000, 9999) . ' ' . $branch->code,
        ]);

        $services = [];
        foreach ($serviceAmounts as $index => $amount) {
            $catalog = ServiceCatalog::create(['code' => 'SVC-' . random_int(10000, 99999), 'name' => "Jasa {$index}", 'default_price' => $amount]);
            $services[] = ['service_catalog_id' => $catalog->id, 'description' => "Jasa {$index}", 'qty' => 1, 'unit_price' => $amount];
        }

        $spareparts = [];
        foreach ($sparepartAmounts as $index => $amount) {
            $sparepart = Sparepart::create(['code' => 'SPR-' . random_int(10000, 99999), 'name' => "Sparepart {$index}"]);
            $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => $amount]);
            DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);
            $spareparts[] = ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 1, 'unit_price' => $amount];
        }

        $user = User::factory()->create();
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete', 'invoice.create', 'invoice.post'] as $code) {
            $this->grantBranchPermission($user, $branch, $code);
        }

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => $services,
            'spareparts' => $spareparts,
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);
        $invoice = Invoice::where('work_order_id', $workOrder->id)->firstOrFail();
        $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");
        $invoice->update(['invoice_date' => $invoiceDate]);

        return $invoice->fresh(['details', 'workOrder.mechanic', 'branch', 'customer']);
    }

    public function test_pdf_actions_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/workshop-performance/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/workshop-performance/pdf-download')->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance/pdf-preview');

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance/pdf-download');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_pdf_preview_mechanic_view_shows_aggregates(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [90000], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance/pdf-preview');

        $response->assertOk();
        $text = preg_replace('/\s+/', ' ', $this->extractPdfText($response->getContent()));
        $this->assertStringContainsString('MEK-001 - Agus Setiawan', $text);
        $this->assertStringContainsString('100.000', $text);
        $this->assertStringContainsString('90.000', $text);
        $this->assertStringContainsString('190.000', $text);
    }

    public function test_pdf_preview_invoice_detail_view_shows_paired_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [90000, 40000], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance/pdf-preview?view_type=invoice_detail');

        $response->assertOk();
        $text = preg_replace('/\s+/', ' ', $this->extractPdfText($response->getContent()));
        $this->assertStringContainsString('MEK-001 - Agus Setiawan', $text);
        $this->assertStringContainsString('Jasa 0', $text);
        $this->assertStringContainsString('Sparepart 0', $text);
        $this->assertStringContainsString('Sparepart 1', $text);
        $this->assertStringContainsString('Cabang Jakarta', $text);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=WorkshopPerformanceReportExportTest`
Expected: FAIL — view `reports.workshop-performance.pdf` belum ada (`InvalidArgumentException: View [reports.workshop-performance.pdf] not found`).

- [ ] **Step 3: Implementasi view PDF**

Buat file baru `resources/views/reports/workshop-performance/pdf.blade.php`:

```blade
@extends('layouts.print')
@section('report-title', 'Laporan Performance Bengkel')
@section('filter-summary', $filterSummary)
@section('note')
    @if ($truncated)
        <p class="print-note">Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap.</p>
    @endif
@endsection
@section('table')
    @if ($viewType === 'mechanic')
        <table class="print-table">
            <thead>
                <tr>
                    <th>Mekanik</th><th>Total Customer</th><th>Total Qty Jasa</th><th>Total Discount Jasa (Rp)</th><th>Subtotal Jasa</th>
                    <th>Total Qty Sparepart</th><th>Total Discount Sparepart (Rp)</th><th>Subtotal Sparepart</th><th>Grand Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($mechanicRows as $row)
                    @php
                        $mechanicLabel = $row->mechanic_nip ? "{$row->mechanic_nip} - {$row->mechanic_name}" : $row->mechanic_name;
                        $grandTotal = (float) $row->subtotal_jasa + (float) $row->subtotal_sparepart;
                    @endphp
                    <tr>
                        <td>{{ $mechanicLabel }}</td>
                        <td>{{ number_format($row->total_customer, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->total_qty_jasa, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->total_discount_jasa, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->subtotal_jasa, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->total_qty_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->total_discount_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->subtotal_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($grandTotal, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        @foreach ($invoices as $invoice)
            @php
                $mechanicLabel = optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-';
                $pairs = \App\Support\WorkshopPerformanceLinePairer::build($invoice);
                $totalJasa = collect($pairs)->sum('jasa_subtotal');
                $totalSparepart = collect($pairs)->sum('sparepart_subtotal');
                $totalLine = $totalJasa + $totalSparepart;
            @endphp
            <table class="print-table" style="margin-bottom: 4px;">
                <tbody>
                    <tr>
                        <td><strong>No. Invoice:</strong> {{ $invoice->number }}</td>
                        <td><strong>Tanggal:</strong> {{ $invoice->invoice_date->format('d/m/Y') }}</td>
                        <td><strong>Status:</strong> {{ $invoice->status }}</td>
                        <td><strong>Customer:</strong> {{ $invoice->customer->name }}</td>
                        <td><strong>Mekanik:</strong> {{ $mechanicLabel }}</td>
                        <td><strong>Cabang:</strong> {{ $invoice->branch->name }}</td>
                    </tr>
                </tbody>
            </table>
            <table class="print-table" style="margin-bottom: 12px;">
                <thead>
                    <tr>
                        <th>Jasa</th><th>Harga Satuan Jasa</th><th>Qty</th><th>Diskon (%)</th><th>Subtotal Jasa</th>
                        <th>Sparepart</th><th>Harga Satuan Sparepart</th><th>Qty</th><th>Diskon (%)</th><th>Subtotal Sparepart</th>
                        <th>Subtotal Line</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pairs as $pair)
                        <tr>
                            <td>{{ $pair['jasa_desc'] }}</td>
                            <td>{{ number_format($pair['jasa_price'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['jasa_qty'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['jasa_discount_percent'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['jasa_subtotal'], 0, ',', '.') }}</td>
                            <td>{{ $pair['sparepart_desc'] }}</td>
                            <td>{{ number_format($pair['sparepart_price'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['sparepart_qty'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['sparepart_discount_percent'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['sparepart_subtotal'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['subtotal_line'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="11">&mdash;</td></tr>
                    @endforelse
                    <tr>
                        <td colspan="4"><strong>Total</strong></td>
                        <td><strong>{{ number_format($totalJasa, 0, ',', '.') }}</strong></td>
                        <td colspan="4"></td>
                        <td><strong>{{ number_format($totalSparepart, 0, ',', '.') }}</strong></td>
                        <td><strong>{{ number_format($totalLine, 0, ',', '.') }}</strong></td>
                    </tr>
                </tbody>
            </table>
        @endforeach
    @endif
@endsection
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=WorkshopPerformanceReportExportTest`
Expected: PASS (5 test) — test `exportExcel` belum ditulis (masuk Task 4), jadi jumlah test di file ini masih 5 di titik ini.

- [ ] **Step 5: Commit**

```bash
git add resources/views/reports/workshop-performance/pdf.blade.php tests/Feature/WorkshopPerformanceReportExportTest.php
git commit -m "feat: add PDF preview/download view for workshop performance report"
```

---

### Task 4: Export Excel dengan Formula Asli

**Files:**
- Create: `app/Exports/WorkshopPerformanceMechanicExport.php`
- Create: `app/Exports/WorkshopPerformanceInvoiceDetailExport.php`
- Test: `tests/Feature/WorkshopPerformanceReportExportTest.php` (tambahan — Excel)

**Interfaces:**
- Consumes: `WorkshopPerformanceReportController::exportExcel()` (sudah ada sejak Task 2, sudah memanggil kedua class export ini — tinggal class-nya yang belum ada), `WorkshopPerformanceLinePairer::build()` (Task 1).
- Produces: `WorkshopPerformanceMechanicExport(Collection $rows, string $filterSummary)`, `WorkshopPerformanceInvoiceDetailExport(Collection $invoices, string $filterSummary)` — keduanya `implements FromArray, ShouldAutoSize, WithEvents`, seluruh isi sheet (termasuk formula) ditulis di `registerEvents()` → `AfterSheet`.

- [ ] **Step 1: Tulis failing test untuk Excel export (content-type + formula)**

Tambahkan di `tests/Feature/WorkshopPerformanceReportExportTest.php`, setelah import existing tambahkan:

```php
use PhpOffice\PhpSpreadsheet\IOFactory;
```

Tambahkan method helper dan test berikut, sebelum penutup `}` terakhir class:

```php
    protected function loadExportedSheet(string $xlsxContent): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'wpr') . '.xlsx';
        file_put_contents($tempPath, $xlsxContent);
        $spreadsheet = IOFactory::load($tempPath);
        unlink($tempPath);

        return $spreadsheet->getActiveSheet();
    }

    public function test_export_excel_is_forbidden_without_permission(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/reports/workshop-performance/export-excel');

        $response->assertForbidden();
    }

    public function test_export_excel_mechanic_view_returns_xlsx_with_grand_total_formula(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [90000], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance/export-excel');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sheet = $this->loadExportedSheet($response->getContent());
        $this->assertSame('MEK-001 - Agus Setiawan', $sheet->getCell('A3')->getValue());
        // assertEquals (bukan assertSame) untuk sel numerik: PhpSpreadsheet boleh membaca ulang
        // angka bulat sebagai int atau float tergantung reader — yang penting nilainya, bukan tipenya.
        $this->assertEquals(100000, $sheet->getCell('E3')->getValue());
        $this->assertEquals(90000, $sheet->getCell('H3')->getValue());
        $this->assertSame('=E3+H3', $sheet->getCell('I3')->getValue());
    }

    public function test_export_excel_invoice_detail_view_writes_subtotal_and_total_formulas(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [90000, 40000], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance/export-excel?view_type=invoice_detail');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sheet = $this->loadExportedSheet($response->getContent());
        // Baris 2: meta header; baris 3: nilai meta; baris 4: header kolom Jasa/Sparepart; baris 5-6: pairing; baris 7: Total.
        $this->assertSame('=B5*C5*(1-D5/100)', $sheet->getCell('E5')->getValue());
        $this->assertSame('=G5*H5*(1-I5/100)', $sheet->getCell('J5')->getValue());
        $this->assertSame('=J5+E5', $sheet->getCell('K5')->getValue());
        $this->assertSame('Total', $sheet->getCell('A7')->getValue());
        $this->assertSame('=SUM(E5:E6)', $sheet->getCell('E7')->getValue());
        $this->assertSame('=SUM(J5:J6)', $sheet->getCell('J7')->getValue());
        $this->assertSame('=J7+E7', $sheet->getCell('K7')->getValue());
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=WorkshopPerformanceReportExportTest`
Expected: FAIL — `App\Exports\WorkshopPerformanceMechanicExport`/`WorkshopPerformanceInvoiceDetailExport` belum ada (class not found saat `Excel::download()` dipanggil di controller Task 2).

- [ ] **Step 3: Implementasi `WorkshopPerformanceMechanicExport`**

Buat file baru `app/Exports/WorkshopPerformanceMechanicExport.php`:

```php
<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class WorkshopPerformanceMechanicExport implements FromArray, ShouldAutoSize, WithEvents
{
    protected Collection $rows;
    protected string $filterSummary;

    public function __construct(Collection $rows, string $filterSummary)
    {
        $this->rows = $rows;
        $this->filterSummary = $filterSummary;
    }

    public function array(): array
    {
        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->mergeCells('A1:I1');
                $sheet->setCellValue('A1', $this->filterSummary);
                $sheet->getStyle('A1')->getFont()->setBold(true);

                $headings = [
                    'Mekanik', 'Total Customer', 'Total Qty Jasa', 'Total Discount Jasa (Rp)', 'Subtotal Jasa',
                    'Total Qty Sparepart', 'Total Discount Sparepart (Rp)', 'Subtotal Sparepart', 'Grand Total',
                ];
                foreach ($headings as $index => $heading) {
                    $sheet->setCellValueByColumnAndRow($index + 1, 2, $heading);
                }
                $sheet->getStyle('A2:I2')->getFont()->setBold(true);

                $row = 3;
                foreach ($this->rows as $mechanicRow) {
                    $mechanicLabel = $mechanicRow->mechanic_nip
                        ? "{$mechanicRow->mechanic_nip} - {$mechanicRow->mechanic_name}"
                        : $mechanicRow->mechanic_name;

                    $sheet->setCellValue("A{$row}", $mechanicLabel);
                    $sheet->setCellValue("B{$row}", (float) $mechanicRow->total_customer);
                    $sheet->setCellValue("C{$row}", (float) $mechanicRow->total_qty_jasa);
                    $sheet->setCellValue("D{$row}", (float) $mechanicRow->total_discount_jasa);
                    $sheet->setCellValue("E{$row}", (float) $mechanicRow->subtotal_jasa);
                    $sheet->setCellValue("F{$row}", (float) $mechanicRow->total_qty_sparepart);
                    $sheet->setCellValue("G{$row}", (float) $mechanicRow->total_discount_sparepart);
                    $sheet->setCellValue("H{$row}", (float) $mechanicRow->subtotal_sparepart);
                    $sheet->setCellValue("I{$row}", "=E{$row}+H{$row}");

                    $row++;
                }
            },
        ];
    }
}
```

- [ ] **Step 4: Implementasi `WorkshopPerformanceInvoiceDetailExport`**

Buat file baru `app/Exports/WorkshopPerformanceInvoiceDetailExport.php`:

```php
<?php

namespace App\Exports;

use App\Support\WorkshopPerformanceLinePairer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class WorkshopPerformanceInvoiceDetailExport implements FromArray, ShouldAutoSize, WithEvents
{
    protected Collection $invoices;
    protected string $filterSummary;

    public function __construct(Collection $invoices, string $filterSummary)
    {
        $this->invoices = $invoices;
        $this->filterSummary = $filterSummary;
    }

    public function array(): array
    {
        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->mergeCells('A1:K1');
                $sheet->setCellValue('A1', $this->filterSummary);
                $sheet->getStyle('A1')->getFont()->setBold(true);

                $row = 2;

                foreach ($this->invoices as $invoice) {
                    $mechanicLabel = optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-';

                    $metaHeadings = ['No. Invoice', 'Tanggal', 'Status', 'Customer', 'Mekanik', 'Cabang'];
                    foreach ($metaHeadings as $index => $heading) {
                        $sheet->setCellValueByColumnAndRow($index + 1, $row, $heading);
                    }
                    $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true);
                    $row++;

                    $sheet->setCellValue("A{$row}", $invoice->number);
                    $sheet->setCellValue("B{$row}", $invoice->invoice_date->format('Y-m-d'));
                    $sheet->setCellValue("C{$row}", $invoice->status);
                    $sheet->setCellValue("D{$row}", $invoice->customer->name);
                    $sheet->setCellValue("E{$row}", $mechanicLabel);
                    $sheet->setCellValue("F{$row}", $invoice->branch->name);
                    $row++;

                    $lineHeadings = [
                        'Jasa', 'Harga Satuan Jasa', 'Qty', 'Diskon (%)', 'Subtotal Jasa',
                        'Sparepart', 'Harga Satuan Sparepart', 'Qty', 'Diskon (%)', 'Subtotal Sparepart',
                        'Subtotal Line',
                    ];
                    foreach ($lineHeadings as $index => $heading) {
                        $sheet->setCellValueByColumnAndRow($index + 1, $row, $heading);
                    }
                    $sheet->getStyle("A{$row}:K{$row}")->getFont()->setBold(true);
                    $row++;

                    $pairs = WorkshopPerformanceLinePairer::build($invoice);
                    $firstPairRow = $row;

                    if (empty($pairs)) {
                        $sheet->setCellValue("A{$row}", '-');
                        $sheet->setCellValue("B{$row}", 0);
                        $sheet->setCellValue("C{$row}", 0);
                        $sheet->setCellValue("D{$row}", 0);
                        $sheet->setCellValue("E{$row}", "=B{$row}*C{$row}*(1-D{$row}/100)");
                        $sheet->setCellValue("F{$row}", '-');
                        $sheet->setCellValue("G{$row}", 0);
                        $sheet->setCellValue("H{$row}", 0);
                        $sheet->setCellValue("I{$row}", 0);
                        $sheet->setCellValue("J{$row}", "=G{$row}*H{$row}*(1-I{$row}/100)");
                        $sheet->setCellValue("K{$row}", "=J{$row}+E{$row}");
                        $row++;
                    } else {
                        foreach ($pairs as $pair) {
                            $sheet->setCellValue("A{$row}", $pair['jasa_desc']);
                            $sheet->setCellValue("B{$row}", $pair['jasa_price']);
                            $sheet->setCellValue("C{$row}", $pair['jasa_qty']);
                            $sheet->setCellValue("D{$row}", $pair['jasa_discount_percent']);
                            $sheet->setCellValue("E{$row}", "=B{$row}*C{$row}*(1-D{$row}/100)");
                            $sheet->setCellValue("F{$row}", $pair['sparepart_desc']);
                            $sheet->setCellValue("G{$row}", $pair['sparepart_price']);
                            $sheet->setCellValue("H{$row}", $pair['sparepart_qty']);
                            $sheet->setCellValue("I{$row}", $pair['sparepart_discount_percent']);
                            $sheet->setCellValue("J{$row}", "=G{$row}*H{$row}*(1-I{$row}/100)");
                            $sheet->setCellValue("K{$row}", "=J{$row}+E{$row}");
                            $row++;
                        }
                    }

                    $lastPairRow = $row - 1;

                    $sheet->setCellValue("A{$row}", 'Total');
                    $sheet->setCellValue("E{$row}", "=SUM(E{$firstPairRow}:E{$lastPairRow})");
                    $sheet->setCellValue("J{$row}", "=SUM(J{$firstPairRow}:J{$lastPairRow})");
                    $sheet->setCellValue("K{$row}", "=J{$row}+E{$row}");
                    $sheet->getStyle("A{$row}:K{$row}")->getFont()->setBold(true);
                    $row++;
                }
            },
        ];
    }
}
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=WorkshopPerformanceReportExportTest`
Expected: PASS (8 test)

- [ ] **Step 6: Commit**

```bash
git add app/Exports/WorkshopPerformanceMechanicExport.php app/Exports/WorkshopPerformanceInvoiceDetailExport.php tests/Feature/WorkshopPerformanceReportExportTest.php
git commit -m "feat: add Excel exports with native formulas for workshop performance report"
```

---

### Task 5: End-to-End Integration Test Suite & Verifikasi Manual Browser

**Files:**
- Test: `tests/Feature/WorkshopPerformanceReportIntegrationTest.php`

**Interfaces:**
- Consumes: seluruh stack Task 1–4 (route, controller, views, exports) — tidak ada kode baru diproduksi, murni test integrasi lintas-layer + verifikasi manual.

- [ ] **Step 1: Tulis test integrasi end-to-end**

Buat file baru `tests/Feature/WorkshopPerformanceReportIntegrationTest.php` — skenario realistis lintas mode (index → PDF → Excel) dengan data yang sama, memverifikasi konsistensi angka di ketiga output:

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
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class WorkshopPerformanceReportIntegrationTest extends TestCase
{
    use RefreshDatabase, ExtractsPdfText;

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

    protected function makeInvoiceWithLines(Branch $branch, Customer $customer, Mechanic $mechanic, array $serviceAmounts, array $sparepartAmounts, string $invoiceDate): Invoice
    {
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        MechanicBranch::firstOrCreate(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::firstOrCreate(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id,
            'plate_number' => 'B ' . random_int(1000, 9999) . ' ' . $branch->code,
        ]);

        $services = [];
        foreach ($serviceAmounts as $index => $amount) {
            $catalog = ServiceCatalog::create(['code' => 'SVC-' . random_int(10000, 99999), 'name' => "Jasa {$index}", 'default_price' => $amount]);
            $services[] = ['service_catalog_id' => $catalog->id, 'description' => "Jasa {$index}", 'qty' => 1, 'unit_price' => $amount];
        }

        $spareparts = [];
        foreach ($sparepartAmounts as $index => $amount) {
            $sparepart = Sparepart::create(['code' => 'SPR-' . random_int(10000, 99999), 'name' => "Sparepart {$index}"]);
            $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => $amount]);
            DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);
            $spareparts[] = ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 1, 'unit_price' => $amount];
        }

        $user = User::factory()->create();
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete', 'invoice.create', 'invoice.post'] as $code) {
            $this->grantBranchPermission($user, $branch, $code);
        }

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => $services,
            'spareparts' => $spareparts,
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);
        $invoice = Invoice::where('work_order_id', $workOrder->id)->firstOrFail();
        $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");
        $invoice->update(['invoice_date' => $invoiceDate]);

        return $invoice->fresh(['details', 'workOrder.mechanic', 'branch', 'customer']);
    }

    public function test_mechanic_view_is_consistent_across_index_pdf_and_excel(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [120000], [80000], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $indexResponse = $this->actingAs($viewer)->get('/reports/workshop-performance');
        $pdfResponse = $this->actingAs($viewer)->get('/reports/workshop-performance/pdf-preview');
        $excelResponse = $this->actingAs($viewer)->get('/reports/workshop-performance/export-excel');

        $indexResponse->assertOk();
        $indexResponse->assertSee('MEK-001 - Agus Setiawan');
        $indexResponse->assertSee('200.000');

        $pdfResponse->assertOk();
        $pdfText = preg_replace('/\s+/', ' ', $this->extractPdfText($pdfResponse->getContent()));
        $this->assertStringContainsString('MEK-001 - Agus Setiawan', $pdfText);
        $this->assertStringContainsString('200.000', $pdfText);

        $excelResponse->assertOk();
        $excelResponse->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_invoice_detail_view_is_consistent_across_index_and_pdf(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [90000, 40000], now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $indexResponse = $this->actingAs($viewer)->get('/reports/workshop-performance?view_type=invoice_detail');
        $pdfResponse = $this->actingAs($viewer)->get('/reports/workshop-performance/pdf-preview?view_type=invoice_detail');

        $indexResponse->assertOk();
        $indexResponse->assertSee('Sparepart 1');

        $pdfResponse->assertOk();
        $pdfText = preg_replace('/\s+/', ' ', $this->extractPdfText($pdfResponse->getContent()));
        $this->assertStringContainsString('Sparepart 1', $pdfText);
        $this->assertStringContainsString('Cabang Jakarta', $pdfText);
    }

    public function test_full_permission_gate_denies_index_pdf_and_excel_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/workshop-performance')->assertOk();
        $this->actingAs($user)->get('/reports/workshop-performance')->assertSee('Anda belum memiliki akses laporan performance bengkel di cabang manapun.');
        $this->actingAs($user)->get('/reports/workshop-performance/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/workshop-performance/pdf-download')->assertForbidden();
        $this->actingAs($user)->get('/reports/workshop-performance/export-excel')->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan test integrasi, pastikan lulus**

Run: `php artisan test --filter=WorkshopPerformanceReportIntegrationTest`
Expected: PASS (3 test)

- [ ] **Step 3: Full regression**

Run: `php artisan test`
Expected: 100% hijau, tidak ada regresi pada test lain. Rincian test baru: `WorkshopPerformanceLinePairerTest` (4), `MenuPermissionSeederTest`/`AppShellTest` (+2 method masing-masing), `WorkshopPerformanceReportControllerTest` (6), `WorkshopPerformanceReportExportTest` (8, gabungan PDF di Task 3 + Excel di Task 4), `WorkshopPerformanceReportIntegrationTest` (3).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/WorkshopPerformanceReportIntegrationTest.php
git commit -m "test: add end-to-end integration coverage for workshop performance report"
```

- [ ] **Step 5: Verifikasi manual browser**

1. Jalankan `php artisan tinker` untuk memberi permission `report.workshop_performance.view` ke demo user pada branch tertentu (pola sama seperti milestone laporan sebelumnya: `Permission::firstOrCreate(...)` + `UserBranchPermission::firstOrCreate(...)`).
2. Buka `/reports/workshop-performance` di browser — cek Tampilan Mekanik: kolom sesuai spec, angka masuk akal, mekanik tanpa invoice tidak muncul.
3. Ganti `?view_type=invoice_detail` — cek card per invoice, pairing Jasa/Sparepart benar, Direct Sales (jika ada) tampil dengan Mekanik `-`.
4. Cek filter Cabang/Status/Tanggal berfungsi di kedua tampilan.
5. Klik "Preview PDF" di kedua tampilan — expect trigger file download di Browser pane (perilaku dikenal, dikonfirmasi via `extractPdfText()`-based test sebagai bukti utama, bukan visual browser check).
6. Download Excel di kedua tampilan, buka file, verifikasi manual: kolom `Grand Total` (Mekanik) dan `Subtotal Jasa`/`Subtotal Sparepart`/`Subtotal Line`/`Total` (Invoice Detail) berisi formula asli (klik sel, lihat formula bar), bukan angka statis.
7. Laporkan hasil verifikasi ke user.

---

## Self-Review Notes

- **Task 1 Step 10 (test sidebar):** sengaja ditulis sebelum route ada (Task 2) — ini pola pengecualian dari "tulis test lalu gagal lalu implementasi lalu lulus" yang biasa dalam satu task, karena test ini bergantung pada dua task berbeda (permission dari Task 1, route dari Task 2). Sudah dicatat eksplisit di plan supaya eksekutor tidak bingung kenapa 2 assertion gagal di akhir Task 1 tapi baru diverifikasi PASS di akhir Task 2.
- **`LengthAwarePaginator::resolveCurrentPage()`** — method static ini didefinisikan di `Illuminate\Pagination\AbstractPaginator` (induk `LengthAwarePaginator`), sudah diverifikasi ada di `vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php:505`.
- **`setCellValueByColumnAndRow($columnIndex, $row, $value)`** dipakai untuk menulis heading per-kolom via loop index — diverifikasi ada di `vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Worksheet/Worksheet.php:1196`. `setCellValue($coordinate, $value)` (string seperti `"A3"`) dipakai untuk sel individual lainnya.
- **`PhpOffice\PhpSpreadsheet\IOFactory`** dipakai di test Task 4 untuk membaca ulang file Excel yang di-generate dan memverifikasi formula tertulis di `getValue()` (bukan `getCalculatedValue()`, supaya assertion memverifikasi string formula persis, bukan hasil kalkulasinya) — package `phpoffice/phpspreadsheet` sudah tersedia sebagai dependency transitif `maatwebsite/excel` (dikonfirmasi di `vendor/phpoffice/phpspreadsheet`).
- **Direct Sales invoice test:** payload persis `POST /invoices/direct` (`branch_id`, `customer_id`, `invoice_date`, `services[]` dengan `description`/`qty`/`unit_price`/`discount_percent`, `spareparts[]`) diambil dari test existing `InvoiceReportControllerTest::test_index_direct_sale_invoice_shows_dash_for_mechanic_column()` — sudah diverifikasi route dan shape-nya ada di codebase.
- **Angka test Task 2** (190.000 / 90.000 / 280.000 / 80.000) dipilih bulat tanpa sisa desimal supaya tidak rapuh terhadap pembulatan `round()` — perhitungan manual didokumentasikan di komentar test tidak diperlukan karena angkanya sudah didesain genap: Invoice 1 (100.000 jasa, tanpa diskon) + Invoice 2 (100.000 jasa + 100.000 sparepart, diskon 10% flat → masing-masing jadi 90.000) = Subtotal Jasa 190.000, Subtotal Sparepart 90.000, Grand Total 280.000.
