# Cetak Invoice & Kirim Email Invoice Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambahkan aksi "Cetak Invoice" (preview PDF inline) dan "Kirim Email" (PDF terlampir, dikirim lewat queue) pada halaman detail invoice, hanya untuk invoice berstatus POSTED/PARTIALLY_PAID/PAID.

**Architecture:** `App\Support\InvoicePdfBuilder` membangun PDF sekali (dipakai oleh controller stream & Mailable attachment). `InvoicePolicy::print()`/`::sendEmail()` menyatukan validasi status+permission untuk controller (`authorize()`) dan Blade (`@can`). `App\Mail\InvoicePostedMail implements ShouldQueue` dikirim via `Mail::to($email)->queue(...)`, diproses oleh `QUEUE_CONNECTION=database`.

**Tech Stack:** Laravel 8.75, PHP 7.4.33, `barryvdh/laravel-dompdf`, MySQL, `smalot/pdfparser` (via `Tests\Concerns\ExtractsPdfText`, sudah ada).

## Global Constraints

- PHP 7.4.33 runtime — jangan pakai sintaks PHP8 (constructor property promotion, match expression, nullsafe operator, union types). Typed properties (PHP 7.4) boleh dipakai.
- MySQL, primary key bigint (`$table->id()` / `bigIncrements`), tanpa model roles — akses berbasis permission code per cabang (`hasPermissionToInBranch`).
- Konvensi UI Bootstrap 5 (`btn btn-outline-secondary btn-sm`, `bi-*` icon dari Bootstrap Icons) — ikuti tombol Ubah/Posting yang sudah ada di `show.blade.php`.
- Setiap assertion terhadap isi PDF di test WAJIB pakai `Tests\Concerns\ExtractsPdfText::extractPdfText()`, bukan `assertStringContainsString` langsung ke binary response.
- `phpunit.xml` sudah mem-force `MAIL_MAILER=array` dan `QUEUE_CONNECTION=sync` untuk test environment — perubahan `.env`/`.env.example` ke `database`/SMTP Mailtrap tidak memengaruhi test suite.
- Commit di akhir setiap task, pesan singkat & deskriptif (bahasa Indonesia, gaya commit history proyek: `feat: ...`).

---

### Task 1: Queue Infrastructure + InvoicePdfBuilder + Template PDF

**Files:**
- Create: `database/migrations/2026_08_07_000002_create_jobs_table.php`
- Modify: `.env`
- Modify: `.env.example`
- Create: `app/Support/InvoicePdfBuilder.php`
- Create: `resources/views/invoices/print-pdf.blade.php`
- Test: `tests/Feature/InvoicePdfBuilderTest.php`

**Interfaces:**
- Produces: `App\Support\InvoicePdfBuilder::build(Invoice $invoice): \Barryvdh\DomPDF\PDF` dan `InvoicePdfBuilder::filename(Invoice $invoice): string` — dipakai oleh `InvoiceController::printPdf()` (Task 3) dan `InvoicePostedMail::build()` (Task 2).
- Consumes: `Invoice` model relasi `branch`, `customer`, `workOrder.vehicle.brand`, `workOrder.vehicle.type`, `details`, `allocations.paymentReceipt` (semua sudah ada, lihat `app/Models/Invoice.php` dan `app/Models/WorkOrder.php`).

- [ ] **Step 1: Buat migration tabel `jobs`**

`database/migrations/2026_08_07_000002_create_jobs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateJobsTable extends Migration
{
    public function up()
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('jobs');
    }
}
```

- [ ] **Step 2: Jalankan migration di database lokal**

Run: `php artisan migrate`
Expected: output menunjukkan `2026_08_07_000002_create_jobs_table` berhasil di-migrate.

- [ ] **Step 3: Update `.env`**

Ganti baris `QUEUE_CONNECTION=sync` menjadi:
```
QUEUE_CONNECTION=database
```

Ganti seluruh blok `MAIL_*` (baris `MAIL_MAILER` s.d. `MAIL_FROM_NAME`) menjadi:
```
MAIL_MAILER=smtp
MAIL_HOST="sandbox.smtp.mailtrap.io"
MAIL_PORT=465
MAIL_USERNAME="ce7b7321166adf"
MAIL_PASSWORD="4f849ef5303417"
MAIL_ENCRYPTION="tls"
MAIL_FROM_ADDRESS="no-reply@bengkel.com"
MAIL_FROM_NAME="${APP_NAME}"
```

- [ ] **Step 4: Update `.env.example`**

Sama seperti Step 3, tapi `MAIL_USERNAME`/`MAIL_PASSWORD` diisi placeholder (bukan kredensial asli, karena file ini di-commit ke git):
```
MAIL_MAILER=smtp
MAIL_HOST="sandbox.smtp.mailtrap.io"
MAIL_PORT=465
MAIL_USERNAME="your-mailtrap-username"
MAIL_PASSWORD="your-mailtrap-password"
MAIL_ENCRYPTION="tls"
MAIL_FROM_ADDRESS="no-reply@bengkel.com"
MAIL_FROM_NAME="${APP_NAME}"
```
Dan `QUEUE_CONNECTION=sync` → `QUEUE_CONNECTION=database`.

- [ ] **Step 5: Buat `InvoicePdfBuilder`**

`app/Support/InvoicePdfBuilder.php`:

```php
<?php

namespace App\Support;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

class InvoicePdfBuilder
{
    public static function build(Invoice $invoice): DomPdf
    {
        $invoice->loadMissing([
            'branch', 'customer', 'workOrder.vehicle.brand', 'workOrder.vehicle.type',
            'details', 'allocations.paymentReceipt',
        ]);

        return Pdf::loadView('invoices.print-pdf', ['invoice' => $invoice])->setPaper('a4', 'portrait');
    }

    public static function filename(Invoice $invoice): string
    {
        return 'invoice-' . $invoice->number . '.pdf';
    }
}
```

- [ ] **Step 6: Buat template PDF nota invoice**

`resources/views/invoices/print-pdf.blade.php`:

```blade
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 24px; }
        .invoice-header { width: 100%; margin-bottom: 14px; border-bottom: 2px solid #1a1a1a; padding-bottom: 10px; }
        .invoice-header table { width: 100%; }
        .invoice-header .branch-name { font-size: 15px; font-weight: bold; margin: 0 0 2px; }
        .invoice-header .branch-detail { font-size: 9px; color: #555; }
        .invoice-header .doc-title { font-size: 18px; font-weight: bold; text-align: right; }
        .invoice-header .doc-number { font-size: 10px; text-align: right; color: #444; }

        .info-table { width: 100%; margin-bottom: 14px; }
        .info-table td { vertical-align: top; padding: 0; font-size: 10px; width: 50%; }
        .info-table .label { color: #666; }
        .info-table h3 { font-size: 10px; margin: 0 0 4px; text-transform: uppercase; color: #444; }

        table.line-table { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 10px; }
        table.line-table th, table.line-table td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
        table.line-table th { background: #eee; font-weight: bold; }
        table.line-table td.num { text-align: right; }

        table.summary-table { width: 45%; margin-left: 55%; border-collapse: collapse; font-size: 10px; margin-bottom: 14px; }
        table.summary-table td { padding: 3px 6px; }
        table.summary-table td.num { text-align: right; }
        table.summary-table tr.grand-total td { font-weight: bold; font-size: 12px; border-top: 1px solid #1a1a1a; }

        table.payment-table { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 14px; }
        table.payment-table th, table.payment-table td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
        table.payment-table th { background: #eee; font-weight: bold; }

        .signature-table { width: 100%; margin-top: 40px; font-size: 10px; }
        .signature-table td { width: 50%; text-align: center; }
        .signature-space { height: 50px; }
    </style>
</head>
<body>
    <div class="invoice-header">
        <table>
            <tr>
                <td>
                    <p class="branch-name">{{ $invoice->branch->name }}</p>
                    <p class="branch-detail">
                        {{ $invoice->branch->address ?? '-' }}<br>
                        {{ $invoice->branch->phone ?? '-' }}
                    </p>
                </td>
                <td>
                    <div class="doc-title">INVOICE</div>
                    <div class="doc-number">No. {{ $invoice->number }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="info-table">
        <tr>
            <td>
                <h3>Data Customer &amp; Kendaraan</h3>
                <div><span class="label">Nama:</span> {{ $invoice->customer->name }}</div>
                <div><span class="label">Alamat:</span> {{ $invoice->customer->address ?? '-' }}</div>
                <div><span class="label">No. Polisi:</span> {{ $invoice->workOrder->vehicle->plate_number }}</div>
                <div><span class="label">Kendaraan:</span> {{ optional($invoice->workOrder->vehicle->brand)->name }} {{ optional($invoice->workOrder->vehicle->type)->name }}</div>
            </td>
            <td>
                <h3>Info Invoice</h3>
                <div><span class="label">No. PKB:</span> {{ $invoice->workOrder->number }}</div>
                <div><span class="label">Tanggal Invoice:</span> {{ $invoice->invoice_date->format('d/m/Y') }}</div>
                <div><span class="label">Jatuh Tempo:</span> {{ optional($invoice->due_date)->format('d/m/Y') ?? '-' }}</div>
            </td>
        </tr>
    </table>

    <table class="line-table">
        <thead>
            <tr><th>Tipe</th><th>Kode</th><th>Deskripsi</th><th>Qty</th><th>Harga Satuan</th><th>Total</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->details as $detail)
                <tr>
                    <td>{{ $detail->item_type === \App\Support\InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart' }}</td>
                    <td>{{ $detail->item_code_snapshot ?? '-' }}</td>
                    <td>{{ $detail->description }}</td>
                    <td class="num">{{ number_format($detail->qty, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($detail->line_total, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="summary-table">
        <tr><td>Subtotal Jasa</td><td class="num">{{ number_format($invoice->subtotal_service, 0, ',', '.') }}</td></tr>
        <tr><td>Subtotal Sparepart</td><td class="num">{{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}</td></tr>
        <tr><td>Diskon ({{ number_format($invoice->discount_percent, 2, ',', '.') }}%)</td><td class="num">{{ number_format($invoice->discount_amount, 0, ',', '.') }}</td></tr>
        <tr><td>PPN ({{ number_format($invoice->tax_percent, 2, ',', '.') }}%)</td><td class="num">{{ number_format($invoice->tax_amount, 0, ',', '.') }}</td></tr>
        <tr class="grand-total"><td>Grand Total</td><td class="num">{{ number_format($invoice->grand_total, 0, ',', '.') }}</td></tr>
        <tr><td>Sudah Dibayar</td><td class="num">{{ number_format($invoice->paid_amount, 0, ',', '.') }}</td></tr>
        <tr><td>Sisa Piutang</td><td class="num">{{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</td></tr>
    </table>

    @if ($invoice->allocations->isNotEmpty())
        <table class="payment-table">
            <thead>
                <tr><th>No. Pembayaran</th><th>Tanggal</th><th>Nominal Dialokasikan</th></tr>
            </thead>
            <tbody>
                @foreach ($invoice->allocations as $allocation)
                    <tr>
                        <td>{{ $allocation->paymentReceipt->number }}</td>
                        <td>{{ $allocation->paymentReceipt->payment_date->format('d/m/Y') }}</td>
                        <td>{{ number_format($allocation->allocated_amount, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="signature-table">
        <tr>
            <td>
                Hormat kami,
                <div class="signature-space"></div>
                ( ................................. )
            </td>
            <td>
                Penerima,
                <div class="signature-space"></div>
                ( ................................. )
            </td>
        </tr>
    </table>
</body>
</html>
```

- [ ] **Step 7: Tulis test `InvoicePdfBuilderTest`**

`tests/Feature/InvoicePdfBuilderTest.php`:

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
use App\Support\InvoicePdfBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class InvoicePdfBuilderTest extends TestCase
{
    use RefreshDatabase;
    use ExtractsPdfText;

    protected function makeInvoice(Branch $branch)
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => 'SVC-01', 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);

        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete'] as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(['code' => $code], ['resource' => $resource, 'action' => $action, 'description' => $code]);
            UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
        }

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
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        return (new InvoiceService())->createFromWorkOrder($workOrder->fresh());
    }

    public function test_build_returns_streamable_pdf_binary(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);

        $output = InvoicePdfBuilder::build($invoice)->output();

        $this->assertStringStartsWith('%PDF', $output);
    }

    public function test_filename_uses_invoice_number(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);

        $this->assertSame('invoice-' . $invoice->number . '.pdf', InvoicePdfBuilder::filename($invoice));
    }

    public function test_pdf_content_includes_invoice_number_customer_and_plate_number(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);

        $output = InvoicePdfBuilder::build($invoice)->output();
        $content = $this->extractPdfText($output);

        $this->assertStringContainsString($invoice->number, $content);
        $this->assertStringContainsString('Budi Santoso', $content);
        $this->assertStringContainsString('B 1234 XYZ', $content);
    }
}
```

- [ ] **Step 8: Jalankan test**

Run: `php artisan test --filter=InvoicePdfBuilderTest`
Expected: 3 test PASS.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_07_000002_create_jobs_table.php app/Support/InvoicePdfBuilder.php resources/views/invoices/print-pdf.blade.php tests/Feature/InvoicePdfBuilderTest.php .env .env.example
git commit -m "feat: add jobs table, InvoicePdfBuilder, and invoice print PDF template"
```

---

### Task 2: Mailable `InvoicePostedMail` + Email Blade

**Files:**
- Create: `app/Mail/InvoicePostedMail.php`
- Create: `resources/views/emails/invoice-posted.blade.php`
- Test: `tests/Feature/InvoicePostedMailTest.php`

**Interfaces:**
- Consumes: `App\Support\InvoicePdfBuilder::build()`/`::filename()` (Task 1).
- Produces: `App\Mail\InvoicePostedMail` (constructor `__construct(Invoice $invoice)`, public property `$invoice`) — dipakai oleh `InvoiceController::sendEmail()` (Task 3) lewat `Mail::to($email)->queue(new InvoicePostedMail($invoice))`.

- [ ] **Step 1: Buat `InvoicePostedMail`**

`app/Mail/InvoicePostedMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Support\InvoicePdfBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoicePostedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Invoice $invoice;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function build()
    {
        $pdf = InvoicePdfBuilder::build($this->invoice);

        return $this->subject("Invoice {$this->invoice->number} — {$this->invoice->branch->name}")
            ->view('emails.invoice-posted')
            ->with(['invoice' => $this->invoice])
            ->attachData($pdf->output(), InvoicePdfBuilder::filename($this->invoice), [
                'mime' => 'application/pdf',
            ]);
    }
}
```

- [ ] **Step 2: Buat body email**

`resources/views/emails/invoice-posted.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #212529;">
    <p>Salam {{ $invoice->customer->name }},</p>
    <p>
        Terlampir invoice <strong>{{ $invoice->number }}</strong> dari {{ $invoice->branch->name }}
        sebesar <strong>Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</strong>
        tertanggal {{ $invoice->invoice_date->format('d/m/Y') }}.
    </p>
    @if ($invoice->outstanding_amount > 0)
        <p>Sisa tagihan: <strong>Rp {{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</strong>.</p>
    @endif
    <p>Terima kasih.</p>
</body>
</html>
```

- [ ] **Step 3: Tulis test `InvoicePostedMailTest`**

`tests/Feature/InvoicePostedMailTest.php` (helper `makeInvoice()` sama seperti `InvoicePdfBuilderTest`, disalin agar file test tetap berdiri sendiri):

```php
<?php

namespace Tests\Feature;

use App\Mail\InvoicePostedMail;
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
use Tests\TestCase;

class InvoicePostedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function makeInvoice(Branch $branch)
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso', 'email' => 'budi@example.test']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => 'SVC-01', 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);

        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete'] as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(['code' => $code], ['resource' => $resource, 'action' => $action, 'description' => $code]);
            UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
        }

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
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        return (new InvoiceService())->createFromWorkOrder($workOrder->fresh());
    }

    public function test_build_sets_subject_view_and_pdf_attachment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);

        $mailable = new InvoicePostedMail($invoice);
        // assertSeeInHtml triggers Mailable::build() internally (via renderForAssertions);
        // calling ->build() manually beforehand would invoke it twice and double the attachment.
        $mailable->assertSeeInHtml($invoice->number);
        $mailable->assertSeeInHtml($invoice->customer->name);

        $this->assertSame("Invoice {$invoice->number} — {$invoice->branch->name}", $mailable->subject);
        $this->assertCount(1, $mailable->rawAttachments);
        $this->assertSame('invoice-' . $invoice->number . '.pdf', $mailable->rawAttachments[0]['name']);
        $this->assertSame('application/pdf', $mailable->rawAttachments[0]['options']['mime']);
    }
}
```

- [ ] **Step 4: Jalankan test**

Run: `php artisan test --filter=InvoicePostedMailTest`
Expected: 1 test PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Mail/InvoicePostedMail.php resources/views/emails/invoice-posted.blade.php tests/Feature/InvoicePostedMailTest.php
git commit -m "feat: add InvoicePostedMail queued mailable with PDF attachment"
```

---

### Task 3: Policy + Controller Actions + Routes + UI

**Files:**
- Modify: `app/Policies/InvoicePolicy.php`
- Modify: `app/Http/Controllers/InvoiceController.php`
- Modify: `routes/web.php:194` (setelah route `cancel`)
- Modify: `resources/views/invoices/show.blade.php:11-19` (blok aksi header)

**Interfaces:**
- Consumes: `InvoicePdfBuilder` (Task 1), `InvoicePostedMail` (Task 2).
- Produces: route `invoices.print` (GET), route `invoices.send-email` (POST); policy abilities `print`, `sendEmail` dipakai baik di controller maupun `@can` pada Blade.

- [ ] **Step 1: Tambahkan `print()` dan `sendEmail()` ke `InvoicePolicy`**

Edit `app/Policies/InvoicePolicy.php`, tambahkan setelah method `cancel()`:

```php
    public function print(User $user, Invoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID], true)
            && $user->hasPermissionToInBranch('invoice.print', $invoice->branch_id);
    }

    public function sendEmail(User $user, Invoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID], true)
            && $user->hasPermissionToInBranch('invoice.email', $invoice->branch_id);
    }
```

- [ ] **Step 2: Tambahkan `printPdf()` dan `sendEmail()` ke `InvoiceController`**

Edit `app/Http/Controllers/InvoiceController.php`. Tambahkan import setelah baris `use Illuminate\Http\Request;`:

```php
use App\Mail\InvoicePostedMail;
use App\Support\InvoicePdfBuilder;
use Illuminate\Support\Facades\Mail;
```

Tambahkan method berikut setelah `cancel()`:

```php
    public function printPdf(Invoice $invoice)
    {
        $this->authorize('print', $invoice);

        return InvoicePdfBuilder::build($invoice)->stream(InvoicePdfBuilder::filename($invoice));
    }

    public function sendEmail(Invoice $invoice)
    {
        $this->authorize('sendEmail', $invoice);

        $email = $invoice->customer->email;
        if (! $email) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Customer belum memiliki alamat email. Tidak dapat mengirim invoice.');
        }

        Mail::to($email)->queue(new InvoicePostedMail($invoice));

        return redirect()->route('invoices.show', $invoice)
            ->with('status', "Invoice sedang dikirim ke {$email} (diproses di antrean).");
    }
```

- [ ] **Step 3: Tambahkan 2 route baru**

Edit `routes/web.php`, di dalam grup `Route::prefix('invoices')->name('invoices.')` (baris 187-195), tambahkan setelah baris route `cancel`:

```php
        Route::get('/{invoice}/print', [InvoiceController::class, 'printPdf'])->name('print');
        Route::post('/{invoice}/send-email', [InvoiceController::class, 'sendEmail'])->name('send-email');
```

- [ ] **Step 4: Tambahkan tombol "Cetak Invoice" dan "Kirim Email" di `show.blade.php`**

Edit `resources/views/invoices/show.blade.php`, di dalam `<div class="d-flex gap-2">` (baris 6-19), tambahkan setelah blok `@can('post', $invoice)...@endcan`:

```blade
            @can('print', $invoice)
                <a href="{{ route('invoices.print', $invoice) }}" target="_blank" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-printer"></i> Cetak Invoice
                </a>
            @endcan
            @can('sendEmail', $invoice)
                <form method="POST" action="{{ route('invoices.send-email', $invoice) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-envelope"></i> Kirim Email
                    </button>
                </form>
            @endcan
```

- [ ] **Step 5: Sanity check routes terdaftar**

Run: `php artisan route:list --name=invoices.print`
Expected: menampilkan `GET|HEAD  invoices/{invoice}/print` mengarah ke `InvoiceController@printPdf`.

Run: `php artisan route:list --name=invoices.send-email`
Expected: menampilkan `POST  invoices/{invoice}/send-email` mengarah ke `InvoiceController@sendEmail`.

- [ ] **Step 6: Commit**

```bash
git add app/Policies/InvoicePolicy.php app/Http/Controllers/InvoiceController.php routes/web.php resources/views/invoices/show.blade.php
git commit -m "feat: wire invoice print and send-email actions to policy, routes, and UI"
```

---

### Task 4: Test Suite `InvoicePrintEmailTest` + Verifikasi Manual

**Files:**
- Test: `tests/Feature/InvoicePrintEmailTest.php`

**Interfaces:**
- Consumes: seluruh hasil Task 1-3 (`InvoicePdfBuilder`, `InvoicePostedMail`, routes `invoices.print`/`invoices.send-email`, policy `print`/`sendEmail`, tombol UI).

- [ ] **Step 1: Tulis `InvoicePrintEmailTest`**

`tests/Feature/InvoicePrintEmailTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Mail\InvoicePostedMail;
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
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class InvoicePrintEmailTest extends TestCase
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

    protected function makeWorkOrder(Branch $branch): WorkOrder
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso', 'email' => 'budi@example.test']);
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
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        return $workOrder->fresh();
    }

    protected function makePostedInvoice(Branch $branch)
    {
        $invoice = (new InvoiceService())->createFromWorkOrder($this->makeWorkOrder($branch));
        (new InvoiceService())->postInvoice($invoice);

        return $invoice->fresh();
    }

    protected function makeDraftInvoice(Branch $branch)
    {
        return (new InvoiceService())->createFromWorkOrder($this->makeWorkOrder($branch));
    }

    public function test_print_returns_pdf_for_posted_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.print');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_print_content_includes_invoice_number_customer_and_plate_number(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.print');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $content = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString($invoice->number, $content);
        $this->assertStringContainsString('Budi Santoso', $content);
        $this->assertStringContainsString("B 1234 {$branch->code}", $content);
    }

    public function test_print_is_forbidden_for_draft_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeDraftInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.print');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $response->assertForbidden();
    }

    public function test_print_is_forbidden_for_cancelled_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeDraftInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.print');
        $this->grantBranchPermission($user, $branch, 'invoice.void');
        $this->actingAs($user)->patch("/invoices/{$invoice->id}/cancel", ['reason' => 'Batal.']);

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $response->assertForbidden();
    }

    public function test_print_is_forbidden_without_invoice_print_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $response->assertForbidden();
    }

    public function test_send_email_queues_mail_and_flashes_success_when_customer_has_email(): void
    {
        Mail::fake();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.email');

        $response = $this->actingAs($user)->post("/invoices/{$invoice->id}/send-email");

        $response->assertRedirect("/invoices/{$invoice->id}");
        $response->assertSessionHas('status');
        Mail::assertQueued(InvoicePostedMail::class, function ($mail) use ($invoice) {
            return $mail->hasTo($invoice->customer->email) && $mail->invoice->is($invoice);
        });
    }

    public function test_send_email_rejects_when_customer_has_no_email(): void
    {
        Mail::fake();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $invoice->customer->update(['email' => null]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.email');

        $response = $this->actingAs($user)->post("/invoices/{$invoice->id}/send-email");

        $response->assertRedirect("/invoices/{$invoice->id}");
        $response->assertSessionHas('error');
        Mail::assertNothingQueued();
    }

    public function test_send_email_is_forbidden_for_draft_invoice(): void
    {
        Mail::fake();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeDraftInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.email');

        $response = $this->actingAs($user)->post("/invoices/{$invoice->id}/send-email");

        $response->assertForbidden();
        Mail::assertNothingQueued();
    }

    public function test_send_email_is_forbidden_without_invoice_email_permission(): void
    {
        Mail::fake();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post("/invoices/{$invoice->id}/send-email");

        $response->assertForbidden();
        Mail::assertNothingQueued();
    }

    public function test_show_page_displays_print_and_email_buttons_for_posted_invoice_with_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'invoice.print');
        $this->grantBranchPermission($user, $branch, 'invoice.email');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee(route('invoices.print', $invoice), false);
        $response->assertSee(route('invoices.send-email', $invoice), false);
        $response->assertSee('Cetak Invoice');
        $response->assertSee('Kirim Email');
    }

    public function test_show_page_hides_print_and_email_buttons_for_draft_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeDraftInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'invoice.print');
        $this->grantBranchPermission($user, $branch, 'invoice.email');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertDontSee('Cetak Invoice');
        $response->assertDontSee('Kirim Email');
    }
}
```

- [ ] **Step 2: Jalankan test file baru**

Run: `php artisan test --filter=InvoicePrintEmailTest`
Expected: 9 test PASS.

- [ ] **Step 3: Jalankan full test suite**

Run: `php artisan test`
Expected: seluruh test (test suite lama + `InvoicePdfBuilderTest` + `InvoicePostedMailTest` + `InvoicePrintEmailTest`) PASS tanpa regresi.

- [ ] **Step 4: Verifikasi manual di browser**

Login sebagai `faiz_rahmat` / `faiz_rahmat` (akses penuh semua cabang & permission). Buka invoice yang sudah POSTED (atau posting salah satu invoice draft terlebih dulu):
1. Klik "Cetak Invoice" → pastikan tab baru terbuka menampilkan PDF nota (bukan attachment/download).
2. Klik "Kirim Email" → pastikan redirect kembali ke halaman invoice dengan flash message sukses berisi email tujuan.
3. Buka invoice berstatus DRAFT → pastikan tombol "Cetak Invoice"/"Kirim Email" tidak muncul.
4. Catat sebagai catatan operasional: karena `QUEUE_CONNECTION=database`, job "Kirim Email" baru benar-benar terkirim setelah proses `php artisan queue:work` dijalankan secara terpisah — verifikasi manual di atas hanya memastikan job masuk antrean (tabel `jobs`), bukan pengiriman SMTP aktual (di luar scope kecuali user secara eksplisit ingin menjalankan `queue:work` dan mengecek inbox Mailtrap).

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/InvoicePrintEmailTest.php
git commit -m "test: add end-to-end coverage for invoice print and send-email"
```

---

## Self-Review Notes

- **Spec coverage:** Requirement 1 (validasi status) → Task 3 Step 1 (`InvoicePolicy`) + Task 4 tests forbidden-DRAFT/CANCELLED. Requirement 2 (preview PDF) → Task 1 (`InvoicePdfBuilder` + template) + Task 3 Step 2-3 (`printPdf()` + route) + Task 4 tests. Requirement 3 (queue email) → Task 2 (`InvoicePostedMail`) + Task 3 Step 2-3 (`sendEmail()` + route) + Task 4 tests. Requirement 4 (UI) → Task 3 Step 4 + Task 4 UI tests. `.env`/`.env.example` → Task 1 Step 3-4.
- **Placeholder scan:** tidak ada `TBD`/`TODO`; seluruh step berisi kode nyata.
- **Type consistency:** `InvoicePdfBuilder::build()`/`::filename()` dipakai dengan signature yang sama persis di Task 1 (definisi), Task 2 (`InvoicePostedMail::build()`), dan Task 3 (`InvoiceController::printPdf()`). Nama policy ability `print`/`sendEmail` konsisten dipakai di `InvoicePolicy` (Task 3 Step 1), `authorize()` (Task 3 Step 2), dan `@can` (Task 3 Step 4).
