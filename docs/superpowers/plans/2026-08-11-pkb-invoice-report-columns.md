# Penambahan Kolom Laporan PKB & Laporan Invoice Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambahkan kolom Cabang, Mekanik (`{kode mekanik} - {nama mekanik}`), Tahun Motor, Kilometer ke Laporan PKB (Rekap & Detail), dan kolom Cabang, Mekanik, Diskon (khusus Detail) ke Laporan Invoice (Rekap & Detail) — konsisten di index view, PDF preview/download, dan Excel export.

**Architecture:** Murni penambahan kolom tampilan pada laporan yang sudah ada. Tidak ada migration/perubahan skema. Data sumber (branch, mechanic.nip, vehicle.year, work_order.odometer_km, invoice_detail.discount_amount) semuanya sudah ada di database. Perubahan terbatas pada: satu accessor model baru (`Mechanic::display_label`), eager-load tambahan di `InvoiceReportController` (PKB tidak perlu perubahan controller), dan penambahan kolom di 4 view (2 index blade, 2 pdf blade) + 2 kelas Excel export.

**Tech Stack:** Laravel 8.75, PHP 7.4, Blade, Maatwebsite Excel, DomPDF (lewat `layouts.print`).

## Global Constraints

- PHP 7.4 syntax only (tidak ada named arguments, tidak ada `match`, tidak ada nullsafe chaining ganda tanpa `optional()` helper — codebase ini konsisten pakai `optional()->` bukan `?->`).
- Tidak ada perubahan migration/skema database di plan ini — spec eksplisit menyatakan semua kolom yang dibutuhkan sudah ada.
- Format angka mengikuti `number_format($value, 0, ',', '.')` (titik ribuan, tanpa desimal) kecuali untuk `odometer_km` yang ditampilkan apa adanya (`{{ $value ?? '-' }}`, tanpa `number_format`) — mengikuti konvensi yang sudah dipakai di `work-orders/show.blade.php` dan `work-orders/print-pdf.blade.php`.
- Nilai kosong/null ditampilkan `-` (bukan `&mdash;` — `&mdash;` khusus dipakai untuk baris item yang memang tidak punya baris jasa/sparepart sama sekali, pola existing yang tidak diubah).
- Setiap task diakhiri dengan `php artisan test` filter ke file yang diubah, lalu commit dengan pesan persis seperti tercantum di task.
- Jalankan full `php artisan test` di Task 6 sebelum commit terakhir — wajib 100% hijau tanpa regresi.

---

## File Structure

- **Modify:** `app/Models/Mechanic.php` — tambah accessor `getDisplayLabelAttribute()`.
- **Modify:** `resources/views/reports/pkb/index.blade.php` — tambah kolom Cabang/Mekanik/Tahun Motor/Kilometer di tabel Rekap & Detail, update `colspan` empty-state.
- **Modify:** `resources/views/reports/pkb/pdf.blade.php` — tambah kolom yang sama di kedua blok tabel PDF.
- **Modify:** `app/Exports/PkbReportExport.php` — tambah kolom yang sama di `headings()`/`map()`.
- **Modify:** `app/Http/Controllers/InvoiceReportController.php` — tambah eager-load `workOrder.mechanic` di `index()`, `exportExcel()`, `renderPdf()`.
- **Modify:** `resources/views/reports/invoices/index.blade.php` — tambah kolom Cabang/Mekanik (Rekap & Detail) dan Diskon (Detail saja), update `colspan` empty-state.
- **Modify:** `resources/views/reports/invoices/pdf.blade.php` — tambah kolom yang sama di kedua blok tabel PDF.
- **Modify:** `app/Exports/InvoiceReportExport.php` — tambah kolom yang sama di `headings()`/`map()`.
- **Modify (tests):** `tests/Feature/MechanicServiceModelTest.php`, `tests/Feature/PkbReportControllerTest.php`, `tests/Feature/PkbReportExportTest.php`, `tests/Feature/InvoiceReportControllerTest.php`, `tests/Feature/InvoiceReportExportTest.php`.

---

### Task 1: Accessor `Mechanic::display_label`

**Files:**
- Modify: `app/Models/Mechanic.php`
- Test: `tests/Feature/MechanicServiceModelTest.php`

**Interfaces:**
- Produces: `Mechanic::getDisplayLabelAttribute(): string`, diakses sebagai `$mechanic->display_label`. Format: `"{nip} - {name}"` jika `nip` terisi, jika tidak `"{name}"` saja. Dipakai oleh Task 2–5.

- [ ] **Step 1: Tulis failing test**

Tambahkan di `tests/Feature/MechanicServiceModelTest.php`, setelah method `test_mechanic_join_date_is_cast_to_a_date()`:

```php
    public function test_mechanic_display_label_combines_nip_and_name(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);

        $this->assertSame('MEK-001 - Agus Setiawan', $mechanic->display_label);
    }

    public function test_mechanic_display_label_falls_back_to_name_when_nip_is_null(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);

        $this->assertSame('Agus Setiawan', $mechanic->display_label);
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=test_mechanic_display_label`
Expected: FAIL — `display_label` undefined attribute (assertion gagal karena null !== string yang diharapkan).

- [ ] **Step 3: Implementasi accessor**

Di `app/Models/Mechanic.php`, tambahkan method berikut setelah `hasAccessToBranch()`:

```php
    public function getDisplayLabelAttribute(): string
    {
        return $this->nip ? "{$this->nip} - {$this->name}" : $this->name;
    }
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=test_mechanic_display_label`
Expected: PASS (2 test)

- [ ] **Step 5: Commit**

```bash
git add app/Models/Mechanic.php tests/Feature/MechanicServiceModelTest.php
git commit -m "feat: add Mechanic::display_label accessor for report columns"
```

---

### Task 2: Laporan PKB — Index View (Rekap & Detail)

**Files:**
- Modify: `resources/views/reports/pkb/index.blade.php`
- Test: `tests/Feature/PkbReportControllerTest.php`

**Interfaces:**
- Consumes: `$workOrder->mechanic->display_label` (Task 1), `$workOrder->branch->name`, `$workOrder->vehicle->year`, `$workOrder->odometer_km` — semua sudah eager-loaded oleh `PkbReportController::index()` yang sudah ada, tidak ada perubahan controller di task ini.

- [ ] **Step 1: Tulis failing test**

Tambahkan di `tests/Feature/PkbReportControllerTest.php`, setelah method `test_index_shows_customer_vehicle_and_mechanic_columns()`:

```php
    public function test_index_rekap_mode_shows_branch_mechanic_code_year_and_odometer(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch, 'Agus Setiawan');
        $scenario['mechanic']->update(['nip' => 'MEK-001']);
        $scenario['vehicle']->update(['year' => 2022]);
        $workOrder = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED);
        $workOrder->update(['odometer_km' => 15000.5]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
        $response->assertSee('MEK-001 - Agus Setiawan');
        $response->assertSee('2022');
        $response->assertSee('15000.5');
    }

    public function test_index_detail_mode_shows_branch_mechanic_code_year_and_odometer(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch, 'Agus Setiawan');
        $scenario['mechanic']->update(['nip' => 'MEK-001']);
        $scenario['vehicle']->update(['year' => 2022]);
        $workOrder = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $workOrder->update(['odometer_km' => 15000.5]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?mode=detail');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
        $response->assertSee('MEK-001 - Agus Setiawan');
        $response->assertSee('2022');
        $response->assertSee('15000.5');
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=PkbReportControllerTest`
Expected: 2 test baru FAIL (kolom belum ada di view), test lama tetap PASS.

- [ ] **Step 3: Update tabel Rekap di `resources/views/reports/pkb/index.blade.php`**

Ganti header tabel Rekap (baris `<thead class="table-light">` kedua, di dalam blok `@else`):

```blade
                    <tr>
                        <th>No. PKB</th>
                        <th>Tanggal</th>
                        <th>Customer &amp; Kendaraan</th>
                        <th>Mekanik</th>
                        <th>Subtotal Jasa</th>
                        <th>Subtotal Sparepart</th>
                        <th>Grand Total</th>
                        <th>Status</th>
                    </tr>
```

menjadi:

```blade
                    <tr>
                        <th>No. PKB</th>
                        <th>Cabang</th>
                        <th>Tanggal</th>
                        <th>Customer &amp; Kendaraan</th>
                        <th>Mekanik</th>
                        <th>Tahun Motor</th>
                        <th>Kilometer</th>
                        <th>Subtotal Jasa</th>
                        <th>Subtotal Sparepart</th>
                        <th>Grand Total</th>
                        <th>Status</th>
                    </tr>
```

Ganti baris body Rekap:

```blade
                        <tr>
                            <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                            <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                            <td>
                                {{ $workOrder->customer->name }}<br>
                                <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                            </td>
                            <td>{{ $workOrder->mechanic->name }}</td>
                            <td>{{ number_format($subtotalService, 0, ',', '.') }}</td>
                            <td>{{ number_format($subtotalSparepart, 0, ',', '.') }}</td>
                            <td>{{ number_format($subtotalService + $subtotalSparepart, 0, ',', '.') }}</td>
```

menjadi:

```blade
                        <tr>
                            <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                            <td>{{ $workOrder->branch->name }}</td>
                            <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                            <td>
                                {{ $workOrder->customer->name }}<br>
                                <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                            </td>
                            <td>{{ $workOrder->mechanic->display_label }}</td>
                            <td>{{ $workOrder->vehicle->year ?? '-' }}</td>
                            <td>{{ $workOrder->odometer_km ?? '-' }}</td>
                            <td>{{ number_format($subtotalService, 0, ',', '.') }}</td>
                            <td>{{ number_format($subtotalSparepart, 0, ',', '.') }}</td>
                            <td>{{ number_format($subtotalService + $subtotalSparepart, 0, ',', '.') }}</td>
```

Ganti `colspan="8"` pada baris empty-state Rekap menjadi `colspan="11"`.

- [ ] **Step 4: Update tabel Detail di file yang sama**

Ganti header tabel Detail:

```blade
                    <tr>
                        <th>No. PKB</th>
                        <th>Tanggal</th>
                        <th>Customer &amp; Kendaraan</th>
                        <th>Tipe Item</th>
                        <th>Nama Item/Jasa</th>
                        <th>Qty</th>
                        <th>Harga Satuan</th>
                        <th>Subtotal Line</th>
                        <th>Status</th>
                    </tr>
```

menjadi:

```blade
                    <tr>
                        <th>No. PKB</th>
                        <th>Cabang</th>
                        <th>Tanggal</th>
                        <th>Customer &amp; Kendaraan</th>
                        <th>Mekanik</th>
                        <th>Tahun Motor</th>
                        <th>Kilometer</th>
                        <th>Tipe Item</th>
                        <th>Nama Item/Jasa</th>
                        <th>Qty</th>
                        <th>Harga Satuan</th>
                        <th>Subtotal Line</th>
                        <th>Status</th>
                    </tr>
```

Ganti baris "no lines" (`@if ($lines->isEmpty())`):

```blade
                            <tr>
                                <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                                <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                                <td>
                                    {{ $workOrder->customer->name }}<br>
                                    <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                                </td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>{!! $statusBadge !!}</td>
                            </tr>
```

menjadi:

```blade
                            <tr>
                                <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                                <td>{{ $workOrder->branch->name }}</td>
                                <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                                <td>
                                    {{ $workOrder->customer->name }}<br>
                                    <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                                </td>
                                <td>{{ $workOrder->mechanic->display_label }}</td>
                                <td>{{ $workOrder->vehicle->year ?? '-' }}</td>
                                <td>{{ $workOrder->odometer_km ?? '-' }}</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>{!! $statusBadge !!}</td>
                            </tr>
```

Ganti baris `@foreach ($lines as $line)`:

```blade
                            @foreach ($lines as $line)
                                <tr>
                                    <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                                    <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                                    <td>
                                        {{ $workOrder->customer->name }}<br>
                                        <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                                    </td>
                                    <td>{{ $line['type'] }}</td>
                                    <td>{{ $line['name'] }}</td>
                                    <td>{{ number_format($line['qty'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($line['price'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($line['total'], 0, ',', '.') }}</td>
                                    <td>{!! $statusBadge !!}</td>
                                </tr>
                            @endforeach
```

menjadi:

```blade
                            @foreach ($lines as $line)
                                <tr>
                                    <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                                    <td>{{ $workOrder->branch->name }}</td>
                                    <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                                    <td>
                                        {{ $workOrder->customer->name }}<br>
                                        <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                                    </td>
                                    <td>{{ $workOrder->mechanic->display_label }}</td>
                                    <td>{{ $workOrder->vehicle->year ?? '-' }}</td>
                                    <td>{{ $workOrder->odometer_km ?? '-' }}</td>
                                    <td>{{ $line['type'] }}</td>
                                    <td>{{ $line['name'] }}</td>
                                    <td>{{ number_format($line['qty'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($line['price'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($line['total'], 0, ',', '.') }}</td>
                                    <td>{!! $statusBadge !!}</td>
                                </tr>
                            @endforeach
```

Ganti `colspan="9"` pada baris empty-state Detail menjadi `colspan="13"`.

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=PkbReportControllerTest`
Expected: PASS semua (test lama + 2 test baru), termasuk `test_index_detail_mode_shows_placeholder_row_for_work_order_with_no_lines` yang masih harus lulus dengan kolom baru.

- [ ] **Step 6: Commit**

```bash
git add resources/views/reports/pkb/index.blade.php tests/Feature/PkbReportControllerTest.php
git commit -m "feat: add Cabang, Mekanik, Tahun Motor, Kilometer columns to PKB report index"
```

---

### Task 3: Laporan PKB — PDF & Excel Export

**Files:**
- Modify: `resources/views/reports/pkb/pdf.blade.php`
- Modify: `app/Exports/PkbReportExport.php`
- Test: `tests/Feature/PkbReportExportTest.php`

**Interfaces:**
- Consumes: sama seperti Task 2 (`display_label`, `branch->name`, `vehicle->year`, `odometer_km`), plus kedua method sudah eager-load data ini via `PkbReportController::exportExcel()`/`renderPdf()` yang sudah ada.

- [ ] **Step 1: Tulis failing test**

Tambahkan di `tests/Feature/PkbReportExportTest.php`, setelah method `test_pdf_preview_detail_mode_shows_line_items()`:

```php
    public function test_pdf_preview_rekap_mode_shows_branch_mechanic_year_and_odometer(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $workOrder = $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $workOrder->mechanic->update(['nip' => 'MEK-001']);
        $workOrder->vehicle->update(['year' => 2022]);
        $workOrder->update(['odometer_km' => 15000.5]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/pdf-preview');

        $response->assertOk();
        $text = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('Cabang Jakarta', $text);
        $this->assertStringContainsString('MEK-001 - Mekanik JKT', $text);
        $this->assertStringContainsString('2022', $text);
        $this->assertStringContainsString('15000.5', $text);
    }

    public function test_pdf_preview_detail_mode_shows_branch_mechanic_year_and_odometer(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $workOrder = $this->makeCompletedWorkOrder($branch, $customer, 100000, now()->toDateString());
        $workOrder->mechanic->update(['nip' => 'MEK-001']);
        $workOrder->vehicle->update(['year' => 2022]);
        $workOrder->update(['odometer_km' => 15000.5]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb/pdf-preview?mode=detail');

        $response->assertOk();
        $text = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('Cabang Jakarta', $text);
        $this->assertStringContainsString('MEK-001 - Mekanik JKT', $text);
        $this->assertStringContainsString('2022', $text);
        $this->assertStringContainsString('15000.5', $text);
    }
```

(Nama mekanik `"Mekanik JKT"` mengikuti pola `makeCompletedWorkOrder()` yang sudah ada: `Mechanic::firstOrCreate(['name' => "Mekanik {$branch->code}"])`.)

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=PkbReportExportTest`
Expected: 2 test baru FAIL, test lama tetap PASS.

- [ ] **Step 3: Update `resources/views/reports/pkb/pdf.blade.php`**

Ganti header tabel Detail:

```blade
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
```

menjadi:

```blade
            <thead>
                <tr>
                    <th>No. PKB</th><th>Cabang</th><th>Tanggal</th><th>Customer & Kendaraan</th>
                    <th>Mekanik</th><th>Tahun Motor</th><th>Kilometer</th>
                    <th>Tipe Item</th><th>Nama Item/Jasa</th><th>Qty</th><th>Harga Satuan</th><th>Subtotal Line</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($workOrders as $workOrder)
                    @php
                        $customerVehicle = $workOrder->customer->name . ($workOrder->vehicle ? ' / ' . $workOrder->vehicle->plate_number : '');
                        $mechanicLabel = $workOrder->mechanic->display_label;
                        $vehicleYear = $workOrder->vehicle->year ?? '-';
                        $odometerKm = $workOrder->odometer_km ?? '-';
                    @endphp
                    @foreach ($workOrder->serviceLines as $line)
                        <tr>
                            <td>{{ $workOrder->number }}</td><td>{{ $workOrder->branch->name }}</td><td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td><td>{{ $customerVehicle }}</td>
                            <td>{{ $mechanicLabel }}</td><td>{{ $vehicleYear }}</td><td>{{ $odometerKm }}</td>
                            <td>Jasa</td><td>{{ $line->description }}</td><td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->unit_price, 0, ',', '.') }}</td><td>{{ number_format($line->line_total, 0, ',', '.') }}</td><td>{{ $workOrder->status }}</td>
                        </tr>
                    @endforeach
                    @foreach ($workOrder->sparepartLines as $line)
                        <tr>
                            <td>{{ $workOrder->number }}</td><td>{{ $workOrder->branch->name }}</td><td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td><td>{{ $customerVehicle }}</td>
                            <td>{{ $mechanicLabel }}</td><td>{{ $vehicleYear }}</td><td>{{ $odometerKm }}</td>
                            <td>Sparepart</td><td>{{ $line->item_name_snapshot }}</td><td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->unit_price, 0, ',', '.') }}</td><td>{{ number_format($line->line_total, 0, ',', '.') }}</td><td>{{ $workOrder->status }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
```

Ganti header + body tabel Rekap:

```blade
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
```

menjadi:

```blade
            <thead>
                <tr><th>No. PKB</th><th>Cabang</th><th>Tanggal</th><th>Customer & Kendaraan</th><th>Mekanik</th><th>Tahun Motor</th><th>Kilometer</th><th>Subtotal Jasa</th><th>Subtotal Sparepart</th><th>Grand Total</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($workOrders as $workOrder)
                    @php
                        $subtotalService = (float) $workOrder->serviceLines->sum('line_total');
                        $subtotalSparepart = (float) $workOrder->sparepartLines->sum('line_total');
                    @endphp
                    <tr>
                        <td>{{ $workOrder->number }}</td>
                        <td>{{ $workOrder->branch->name }}</td>
                        <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                        <td>{{ $workOrder->customer->name }}{{ $workOrder->vehicle ? ' / ' . $workOrder->vehicle->plate_number : '' }}</td>
                        <td>{{ $workOrder->mechanic->display_label }}</td>
                        <td>{{ $workOrder->vehicle->year ?? '-' }}</td>
                        <td>{{ $workOrder->odometer_km ?? '-' }}</td>
                        <td>{{ number_format($subtotalService, 0, ',', '.') }}</td>
                        <td>{{ number_format($subtotalSparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($subtotalService + $subtotalSparepart, 0, ',', '.') }}</td>
                        <td>{{ $workOrder->status }}</td>
                    </tr>
                @endforeach
            </tbody>
```

- [ ] **Step 4: Update `app/Exports/PkbReportExport.php`**

Ganti method `headings()`:

```php
    public function headings(): array
    {
        return $this->mode === 'detail'
            ? ['No. PKB', 'Tanggal', 'Customer & Kendaraan', 'Tipe Item', 'Nama Item/Jasa', 'Qty', 'Harga Satuan', 'Subtotal Line', 'Status']
            : ['No. PKB', 'Tanggal', 'Customer & Kendaraan', 'Mekanik', 'Subtotal Jasa', 'Subtotal Sparepart', 'Grand Total', 'Status'];
    }
```

menjadi:

```php
    public function headings(): array
    {
        return $this->mode === 'detail'
            ? ['No. PKB', 'Cabang', 'Tanggal', 'Customer & Kendaraan', 'Mekanik', 'Tahun Motor', 'Kilometer', 'Tipe Item', 'Nama Item/Jasa', 'Qty', 'Harga Satuan', 'Subtotal Line', 'Status']
            : ['No. PKB', 'Cabang', 'Tanggal', 'Customer & Kendaraan', 'Mekanik', 'Tahun Motor', 'Kilometer', 'Subtotal Jasa', 'Subtotal Sparepart', 'Grand Total', 'Status'];
    }
```

Ganti method `map()`:

```php
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

        $rows = [];
        foreach ($workOrder->serviceLines as $line) {
            $rows[] = [$workOrder->number, $workOrder->work_order_date->format('Y-m-d'), $customerVehicle, 'Jasa', $line->description, (float) $line->qty, (float) $line->unit_price, (float) $line->line_total, $workOrder->status];
        }
        foreach ($workOrder->sparepartLines as $line) {
            $rows[] = [$workOrder->number, $workOrder->work_order_date->format('Y-m-d'), $customerVehicle, 'Sparepart', $line->item_name_snapshot, (float) $line->qty, (float) $line->unit_price, (float) $line->line_total, $workOrder->status];
        }

        return $rows;
    }
```

menjadi:

```php
    public function map($workOrder): array
    {
        $customerVehicle = $workOrder->customer->name . ($workOrder->vehicle ? ' / ' . $workOrder->vehicle->plate_number : '');
        $branchName = $workOrder->branch->name;
        $mechanicLabel = $workOrder->mechanic->display_label;
        $vehicleYear = $workOrder->vehicle->year ?? '-';
        $odometerKm = $workOrder->odometer_km ?? '-';

        if ($this->mode !== 'detail') {
            $subtotalService = (float) $workOrder->serviceLines->sum('line_total');
            $subtotalSparepart = (float) $workOrder->sparepartLines->sum('line_total');

            return [
                $workOrder->number,
                $branchName,
                $workOrder->work_order_date->format('Y-m-d'),
                $customerVehicle,
                $mechanicLabel,
                $vehicleYear,
                $odometerKm,
                $subtotalService,
                $subtotalSparepart,
                $subtotalService + $subtotalSparepart,
                $workOrder->status,
            ];
        }

        $rows = [];
        foreach ($workOrder->serviceLines as $line) {
            $rows[] = [$workOrder->number, $branchName, $workOrder->work_order_date->format('Y-m-d'), $customerVehicle, $mechanicLabel, $vehicleYear, $odometerKm, 'Jasa', $line->description, (float) $line->qty, (float) $line->unit_price, (float) $line->line_total, $workOrder->status];
        }
        foreach ($workOrder->sparepartLines as $line) {
            $rows[] = [$workOrder->number, $branchName, $workOrder->work_order_date->format('Y-m-d'), $customerVehicle, $mechanicLabel, $vehicleYear, $odometerKm, 'Sparepart', $line->item_name_snapshot, (float) $line->qty, (float) $line->unit_price, (float) $line->line_total, $workOrder->status];
        }

        return $rows;
    }
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=PkbReportExportTest`
Expected: PASS semua.

- [ ] **Step 6: Commit**

```bash
git add resources/views/reports/pkb/pdf.blade.php app/Exports/PkbReportExport.php tests/Feature/PkbReportExportTest.php
git commit -m "feat: add Cabang, Mekanik, Tahun Motor, Kilometer columns to PKB report PDF and Excel export"
```

---

### Task 4: Laporan Invoice — Controller Eager-Load + Index View (Rekap & Detail)

**Files:**
- Modify: `app/Http/Controllers/InvoiceReportController.php`
- Modify: `resources/views/reports/invoices/index.blade.php`
- Test: `tests/Feature/InvoiceReportControllerTest.php`

**Interfaces:**
- Consumes: `$invoice->branch->name`, `optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-'` (Direct Sales invoice → `-`), `$detail->discount_amount` (mode Detail).
- Produces: `InvoiceReportController::index()`/`exportExcel()`/`renderPdf()` sekarang eager-load `workOrder.mechanic` — dipakai juga oleh Task 5.

- [ ] **Step 1: Tulis failing test**

Tambahkan di `tests/Feature/InvoiceReportControllerTest.php`, setelah method `test_index_rekap_mode_shows_money_columns_and_status_badge()`:

```php
    public function test_index_rekap_mode_shows_branch_and_mechanic_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $invoice->workOrder->mechanic->update(['nip' => 'MEK-001']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
        $response->assertSee('MEK-001 - Mekanik JKT');
    }

    public function test_index_detail_mode_shows_branch_mechanic_and_discount_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $invoice->workOrder->mechanic->update(['nip' => 'MEK-001']);
        $invoice->details()->first()->update(['discount_percent' => 10, 'discount_amount' => 10000, 'line_total' => 90000]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?mode=detail');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
        $response->assertSee('MEK-001 - Mekanik JKT');
        $response->assertSee('10.000');
    }

    public function test_index_direct_sale_invoice_shows_dash_for_mechanic_column(): void
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
        $directSale = \App\Models\Invoice::latest('id')->first();
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee($directSale->number);
    }
```

`use App\Models\CustomerBranch;` sudah ter-import di file ini (dipakai `makeInvoice()`), jadi tidak perlu tambahan `use`. Test ini sengaja hanya memverifikasi `assertOk()` + nomor invoice tampil — cukup untuk membuktikan eager-load `workOrder.mechanic` dan chain `optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-'` tidak crash saat `workOrder` bernilai `null` (invoice Direct Sales); halaman laporan tidak pernah menampilkan literal teks "Direct Sales" di tempat lain (itu hanya ada di `invoices/show.blade.php`), jadi tidak ada string spesifik lain yang bisa diasersikan dengan andal untuk kolom Mekanik yang kosong.

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=InvoiceReportControllerTest`
Expected: 3 test baru FAIL (kolom belum ada / N+1 belum di-load), test lama tetap PASS.

- [ ] **Step 3: Update eager-load di `app/Http/Controllers/InvoiceReportController.php`**

Di method `index()`, ganti:

```php
        $invoices = $query->with(['branch', 'customer']);
```

menjadi:

```php
        $invoices = $query->with(['branch', 'customer', 'workOrder.mechanic']);
```

Di method `exportExcel()`, ganti:

```php
        $query = $this->buildQuery($filters, $permittedBranches)->with(['branch', 'customer', 'details']);
```

menjadi:

```php
        $query = $this->buildQuery($filters, $permittedBranches)->with(['branch', 'customer', 'details', 'workOrder.mechanic']);
```

Di method `renderPdf()`, ganti baris yang sama persis seperti di `exportExcel()`.

- [ ] **Step 4: Update tabel Rekap di `resources/views/reports/invoices/index.blade.php`**

Ganti header:

```blade
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
```

menjadi:

```blade
                    <tr>
                        <th>No. Invoice</th>
                        <th>Cabang</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Mekanik</th>
                        <th>Subtotal Jasa</th>
                        <th>Subtotal Sparepart</th>
                        <th>Discount</th>
                        <th>Grand Total</th>
                        <th>Terbayar</th>
                        <th>Sisa Piutang</th>
                        <th>Status</th>
                    </tr>
```

Ganti body:

```blade
                    @forelse ($invoices as $invoice)
                        <tr>
                            <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                            <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ number_format($invoice->subtotal_service, 0, ',', '.') }}</td>
```

menjadi:

```blade
                    @forelse ($invoices as $invoice)
                        <tr>
                            <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                            <td>{{ $invoice->branch->name }}</td>
                            <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-' }}</td>
                            <td>{{ number_format($invoice->subtotal_service, 0, ',', '.') }}</td>
```

Ganti `colspan="10"` pada baris empty-state Rekap menjadi `colspan="12"`.

- [ ] **Step 5: Update tabel Detail di file yang sama**

Ganti header:

```blade
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
```

menjadi:

```blade
                    <tr>
                        <th>No. Invoice</th>
                        <th>Cabang</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Mekanik</th>
                        <th>Tipe Item</th>
                        <th>Nama Item</th>
                        <th>Qty</th>
                        <th>Harga Satuan</th>
                        <th>Diskon</th>
                        <th>Subtotal Line</th>
                        <th>Status</th>
                    </tr>
```

Ganti seluruh blok `@forelse ($invoices as $invoice) ... @endforelse` (yang berisi `@php switch...@endphp` diikuti nested `@forelse ($invoice->details as $detail)`):

```blade
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
```

menjadi (tambahkan `@php $mechanicLabel = ...; @endphp` tepat setelah blok `@php switch ($invoice->status) ... @endphp` yang sudah ada, sebelum `@forelse ($invoice->details as $detail)`):

```blade
                        @php
                            $mechanicLabel = optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-';
                        @endphp
                        @forelse ($invoice->details as $detail)
                            <tr>
                                <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                                <td>{{ $invoice->branch->name }}</td>
                                <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td>{{ $invoice->customer->name }}</td>
                                <td>{{ $mechanicLabel }}</td>
                                <td>{{ $detail->item_type === \App\Support\InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart' }}</td>
                                <td>{{ $detail->description }}</td>
                                <td>{{ number_format($detail->qty, 0, ',', '.') }}</td>
                                <td>{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                                <td>{{ $detail->discount_amount > 0 ? number_format($detail->discount_amount, 0, ',', '.') : '-' }}</td>
                                <td>{{ number_format($detail->line_total, 0, ',', '.') }}</td>
                                <td>{!! $statusBadge !!}</td>
                            </tr>
                        @empty
                            <tr>
                                <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                                <td>{{ $invoice->branch->name }}</td>
                                <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td>{{ $invoice->customer->name }}</td>
                                <td>{{ $mechanicLabel }}</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>{!! $statusBadge !!}</td>
                            </tr>
                        @endforelse
```

Ganti `colspan="9"` pada baris empty-state Detail menjadi `colspan="12"`.

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=InvoiceReportControllerTest`
Expected: PASS semua (test lama + 3 test baru).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/InvoiceReportController.php resources/views/reports/invoices/index.blade.php tests/Feature/InvoiceReportControllerTest.php
git commit -m "feat: add Cabang, Mekanik, and per-line Diskon columns to Invoice report index"
```

---

### Task 5: Laporan Invoice — PDF & Excel Export

**Files:**
- Modify: `resources/views/reports/invoices/pdf.blade.php`
- Modify: `app/Exports/InvoiceReportExport.php`
- Test: `tests/Feature/InvoiceReportExportTest.php`

**Interfaces:**
- Consumes: `InvoiceReportController::renderPdf()`/`exportExcel()` sudah eager-load `workOrder.mechanic` sejak Task 4.

- [ ] **Step 1: Tulis failing test**

Tambahkan di `tests/Feature/InvoiceReportExportTest.php`, setelah method `test_pdf_preview_detail_mode_shows_line_items()`:

```php
    public function test_pdf_preview_rekap_mode_shows_branch_and_mechanic(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $invoice->workOrder->mechanic->update(['nip' => 'MEK-001']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/pdf-preview');

        $response->assertOk();
        $text = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('Cabang Jakarta', $text);
        $this->assertStringContainsString('MEK-001 - Mekanik JKT', $text);
    }

    public function test_pdf_preview_detail_mode_shows_branch_mechanic_and_discount(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $invoice->workOrder->mechanic->update(['nip' => 'MEK-001']);
        $invoice->details()->first()->update(['discount_percent' => 10, 'discount_amount' => 10000, 'line_total' => 90000]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices/pdf-preview?mode=detail');

        $response->assertOk();
        $text = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('Cabang Jakarta', $text);
        $this->assertStringContainsString('MEK-001 - Mekanik JKT', $text);
        $this->assertStringContainsString('10.000', $text);
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=InvoiceReportExportTest`
Expected: 2 test baru FAIL, test lama tetap PASS.

- [ ] **Step 3: Update `resources/views/reports/invoices/pdf.blade.php`**

Ganti seluruh blok Detail:

```blade
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
```

menjadi:

```blade
        @if ($mode === 'detail')
            <thead>
                <tr><th>No. Invoice</th><th>Cabang</th><th>Tanggal</th><th>Customer</th><th>Mekanik</th><th>Status</th><th>Tipe Item</th><th>Nama Item</th><th>Qty</th><th>Harga Satuan</th><th>Diskon</th><th>Subtotal Line</th></tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    @php $mechanicLabel = optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-'; @endphp
                    @forelse ($invoice->details as $detail)
                        <tr>
                            <td>{{ $invoice->number }}</td><td>{{ $invoice->branch->name }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td><td>{{ $mechanicLabel }}</td><td>{{ $invoice->status }}</td>
                            <td>{{ $detail->item_type === \App\Support\InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart' }}</td>
                            <td>{{ $detail->description }}</td><td>{{ number_format($detail->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                            <td>{{ $detail->discount_amount > 0 ? number_format($detail->discount_amount, 0, ',', '.') : '-' }}</td>
                            <td>{{ number_format($detail->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td>{{ $invoice->number }}</td><td>{{ $invoice->branch->name }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td><td>{{ $mechanicLabel }}</td><td>{{ $invoice->status }}</td>
                            <td colspan="6">&mdash;</td>
                        </tr>
                    @endforelse
                @endforeach
            </tbody>
```

Ganti blok Rekap:

```blade
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
```

menjadi:

```blade
            <thead>
                <tr><th>No. Invoice</th><th>Cabang</th><th>Tanggal</th><th>Customer</th><th>Mekanik</th><th>Subtotal Jasa</th><th>Subtotal Sparepart</th><th>Discount</th><th>Grand Total</th><th>Terbayar</th><th>Sisa Piutang</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td>{{ $invoice->number }}</td><td>{{ $invoice->branch->name }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td>
                        <td>{{ optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-' }}</td>
                        <td>{{ number_format($invoice->subtotal_service, 0, ',', '.') }}</td><td>{{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($invoice->discount_amount, 0, ',', '.') }}</td><td>{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                        <td>{{ number_format($invoice->paid_amount, 0, ',', '.') }}</td><td>{{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</td><td>{{ $invoice->status }}</td>
                    </tr>
                @endforeach
            </tbody>
```

- [ ] **Step 4: Update `app/Exports/InvoiceReportExport.php`**

Ganti method `headings()`:

```php
    public function headings(): array
    {
        return $this->mode === 'detail'
            ? ['No. Invoice', 'Tanggal', 'Customer', 'Status', 'Tipe Item', 'Nama Item', 'Qty', 'Harga Satuan', 'Subtotal Line']
            : ['No. Invoice', 'Tanggal', 'Customer', 'Subtotal Jasa', 'Subtotal Sparepart', 'Discount', 'Grand Total', 'Terbayar', 'Sisa Piutang', 'Status'];
    }
```

menjadi:

```php
    public function headings(): array
    {
        return $this->mode === 'detail'
            ? ['No. Invoice', 'Cabang', 'Tanggal', 'Customer', 'Mekanik', 'Status', 'Tipe Item', 'Nama Item', 'Qty', 'Harga Satuan', 'Diskon', 'Subtotal Line']
            : ['No. Invoice', 'Cabang', 'Tanggal', 'Customer', 'Mekanik', 'Subtotal Jasa', 'Subtotal Sparepart', 'Discount', 'Grand Total', 'Terbayar', 'Sisa Piutang', 'Status'];
    }
```

Ganti method `map()`:

```php
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
```

menjadi:

```php
    public function map($invoice): array
    {
        $branchName = $invoice->branch->name;
        $mechanicLabel = optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-';

        if ($this->mode !== 'detail') {
            return [
                $invoice->number,
                $branchName,
                $invoice->invoice_date->format('Y-m-d'),
                $invoice->customer->name,
                $mechanicLabel,
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
            return [[$invoice->number, $branchName, $invoice->invoice_date->format('Y-m-d'), $invoice->customer->name, $mechanicLabel, $invoice->status, '-', '-', null, null, null, null]];
        }

        return $invoice->details->map(function ($detail) use ($invoice, $branchName, $mechanicLabel) {
            return [
                $invoice->number,
                $branchName,
                $invoice->invoice_date->format('Y-m-d'),
                $invoice->customer->name,
                $mechanicLabel,
                $invoice->status,
                $detail->item_type === InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart',
                $detail->description,
                (float) $detail->qty,
                (float) $detail->unit_price,
                (float) $detail->discount_amount,
                (float) $detail->line_total,
            ];
        })->all();
    }
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=InvoiceReportExportTest`
Expected: PASS semua.

- [ ] **Step 6: Commit**

```bash
git add resources/views/reports/invoices/pdf.blade.php app/Exports/InvoiceReportExport.php tests/Feature/InvoiceReportExportTest.php
git commit -m "feat: add Cabang, Mekanik, and per-line Diskon columns to Invoice report PDF and Excel export"
```

---

### Task 6: Regresi Penuh & Verifikasi Manual

**Files:** Tidak ada file baru — task ini murni verifikasi.

- [ ] **Step 1: Jalankan full test suite**

Run: `php artisan test`
Expected: 100% PASS, tidak ada regresi dari 5 file test yang diubah maupun test lain yang menyentuh Laporan PKB/Invoice/Mechanic (mis. `MechanicManagementTest.php`, `MechanicBranchTabTest.php`, `InvoicePkbGapReportControllerTest.php`, `InvoicePkbGapReportExportTest.php` — tidak diubah, tapi memakai model/relasi yang sama).

- [ ] **Step 2: Verifikasi manual di browser**

Buka `/reports/pkb` (mode Rekap & Detail) dan `/reports/invoices` (mode Rekap & Detail) dengan user yang punya `report.pkb.view`/`report.invoice.view`, pastikan kolom Cabang, Mekanik, Tahun Motor, Kilometer (PKB) dan Cabang, Mekanik, Diskon (Invoice, mode Detail) tampil dengan benar dan tidak merusak layout tabel. Cek juga PDF preview kedua laporan (`/reports/pkb/pdf-preview`, `/reports/invoices/pdf-preview`) untuk memastikan tabel tidak overflow/rusak dengan kolom tambahan.

- [ ] **Step 3: Commit (jika ada perubahan sisa)**

Jika Step 1–2 tidak menghasilkan perubahan kode (murni verifikasi), tidak perlu commit baru — riwayat commit Task 1–5 sudah mencakup seluruh perubahan.

---

## Self-Review Notes

- **Spec coverage:** Semua item di §3 spec (`2026-08-11-pkb-invoice-report-columns-design.md`) — accessor mekanik, kolom PKB Rekap/Detail, kolom Invoice Rekap/Detail, colspan empty-state, eager-load Invoice — masing-masing punya task/step eksplisit di atas.
- **Placeholder scan:** Tidak ada "TBD"/"adjust as needed" — seluruh step berisi kode lengkap siap tempel, termasuk nilai numerik test (`2022`, `15000.5`, `MEK-001`) yang konkret.
- **Type/nama konsistensi:** `display_label` (accessor Task 1) dipakai identik di Task 2/3/4/5. `optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-'` dipakai identik di Task 4 (view) dan Task 5 (PDF+Excel) — pola nullsafe ganda ini sengaja diulang character-for-character karena `optional()` bukan properti yang bisa diekstrak ke variabel PHP biasa di Blade tanpa `@php`.
- **Kolom empty-state placeholder row (Invoice Detail):** dikoreksi dari draft awal — `colspan="5"` pada baris `@empty` di PDF Invoice Detail (§Task 5) dihitung ulang jadi `colspan="6"` karena baris itu sekarang punya 6 kolom eksplisit (No. Invoice, Cabang, Tanggal, Customer, Mekanik, Status) dari total 12 kolom header, bukan 4 eksplisit dari 9 seperti sebelumnya.
- **Test isolasi:** Task 4 & 5 sengaja mengeset `discount_percent`/`discount_amount`/`line_total` langsung lewat `$invoice->details()->first()->update(...)` alih-alih lewat HTTP `PUT /invoices/{id}` — menghindari kebutuhan permission `invoice.edit` tambahan pada user test yang hanya perlu `report.invoice.view`, dan tetap konsisten dengan pola manipulasi data langsung yang sudah dipakai di seluruh test suite laporan (mis. `DB::table('sparepart_branch_stocks')->update(...)`).
- **Koreksi draft awal:** `test_index_direct_sale_invoice_shows_dash_for_mechanic_column` (Task 4) awalnya mengasersikan `assertSee('Direct Sales')`, tapi teks itu tidak pernah dirender di `reports/invoices/index.blade.php` (hanya ada di `invoices/show.blade.php`). Diperbaiki jadi hanya `assertOk()` + nomor invoice tampil, yang tetap membuktikan chain `optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-'` tidak crash saat `workOrder` null.
