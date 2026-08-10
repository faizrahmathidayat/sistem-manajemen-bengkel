# Penyesuaian Modul Invoice (Diskon Itemized, Direct Sales, & Conditional PPN Print) — Design Spec

**Date:** 2026-08-11
**Status:** Approved

## 1. Background

Milestone ini menambah tiga kemampuan pada modul Invoice:

1. **Diskon per item** — saat ini diskon hanya ada di level header invoice (`invoices.discount_percent`/`discount_amount`), berlaku rata ke seluruh invoice. Dibutuhkan diskon per baris (`invoice_details`), baik untuk item jasa maupun sparepart.
2. **Direct Sales (DS)** — saat ini invoice HANYA bisa dibuat dari PKB yang sudah `completed` (`invoices.work_order_id` wajib diisi, unique). Dibutuhkan jalur baru untuk membuat invoice tanpa PKB sama sekali (penjualan sparepart/jasa langsung ke customer), dengan nomor dokumen berprefix berbeda.
3. **Conditional PPN print** — baris PPN di PDF cetak invoice saat ini selalu tampil meski 0%. Perlu disembunyikan saat tidak relevan.

## 2. Codebase Audit (ringkasan)

- **Konfirmasi struktur DB** (sesuai koreksi user): satu tabel `invoice_details` dengan kolom `item_type` (`'service'`/`'sparepart'`, konstanta di `App\Support\InvoiceDetailItemType`), **bukan** tabel terpisah per jenis item. `line_total` adalah kolom tersimpan (bukan dihitung saat render) — ini yang berperan sebagai "subtotal" per baris.
- **`invoice_details` sudah mendukung baris free-form** (tidak terikat PKB): migration `2026_08_06_000001` sudah mengganti CHECK constraint dari "wajib salah satu dari `work_order_service_line_id`/`work_order_sparepart_line_id`" menjadi "boleh keduanya NULL, asal tidak dua-duanya terisi sekaligus" (`ck_invoice_details_not_both_sources`). Baris sparepart tetap wajib punya `sparepart_branch_id` (`ck_invoice_details_sparepart_requires_branch`). **Artinya tabel `invoice_details` sudah siap dipakai untuk baris Direct Sales tanpa migration tambahan** — hanya `invoices.work_order_id` yang perlu diubah.
- **Koreksi penting dari asumsi awal brief** (dikonfirmasi bersama user): nomor invoice **bukan** format `INV-xxxxx`. Format aktual: `{TYPE}/{BRANCH}/{PERIOD}/{NOMOR:5}` (mis. `INV/JKT/202608/00001`), dihasilkan oleh `App\Services\DocumentNumberGenerator` generik yang juga dipakai PKB (`PKB/...`), Goods Receipt (`PB/...`), Stock Adjustment (`SA/...`), Stock Transfer (`ST/...`). Test `DocumentNumberGeneratorTest.php` sudah meng-assert format ini persis — **tidak boleh diubah**. Nomor Direct Sales akan memakai `documentType = 'DS'` pada generator yang sama, menghasilkan `DS/JKT/202608/00001` — konsisten dengan seluruh dokumen lain di sistem, tanpa perubahan pada infrastruktur penomoran.
- **`InvoiceService`** (`app/Services/InvoiceService.php`) adalah pusat logika bisnis: `createFromWorkOrder()`, `updateInvoice()`, `postInvoice()`, `cancelInvoice()`. Baik header (`subtotal_service`, `subtotal_sparepart`, `grand_total`) maupun per-baris (`line_total`) dihitung murni di PHP, disimpan ke kolom — tidak ada trigger/generated column DB.
- **`InvoicePolicy::create(User, WorkOrder)`** — signature terikat ke `WorkOrder`, dan mensyaratkan `$workOrder->status === COMPLETED`. Direct Sales butuh ability baru yang tidak terikat PKB.
- **Tidak ada `create.blade.php` maupun `StoreInvoiceRequest`** untuk invoice — pembuatan invoice hari ini murni tombol satu-klik dari halaman detail PKB (`InvoiceController::store()` hanya baca `work_order_id`). Direct Sales butuh form baru dari nol.
- **Referensi `$invoice->workOrder` tanpa null-guard** ditemukan di 2 tempat yang PASTI akan crash untuk invoice Direct Sales: `resources/views/invoices/show.blade.php:40` (`{{ $invoice->workOrder->number }}`) dan `resources/views/invoices/print-pdf.blade.php:64,65,69` (plat nomor, kendaraan, No. PKB). Kedua file ini wajib disesuaikan dengan null-guard.
- **Sudah aman tanpa perubahan** (diverifikasi, referensi `$invoice->workOrder` sudah null-safe atau sudah difilter):
  - `DashboardController::computePkbInvoiceRows()` — sudah pakai `optional(optional($invoice->workOrder)->vehicle)->plate_number ?? '-'`.
  - `InvoicePkbGapReportController::buildQuery()` — query dasarnya sudah `->whereNotNull('invoices.work_order_id')`, sehingga laporan gap PKB-vs-Invoice otomatis mengecualikan invoice Direct Sales begitu kolom dibuat nullable. Tidak ada perubahan diperlukan di `InvoicePkbGapComparator`/`InvoicePkbGapReportController`/view laporannya.
  - `resources/views/invoices/edit.blade.php` — hanya mereferensikan `work_order_service_line_id`/`work_order_sparepart_line_id` per baris (sudah nullable & sudah punya mode "locked"/"free"); form edit ini otomatis kompatibel dengan invoice Direct Sales (semua barisnya akan tampil sebagai baris "free"/bisa-diedit, karena tidak ada trace ke PKB).
- **Doctrine DBAL terpasang** (`composer.json`: `doctrine/dbal: ^3.1`) — migration `->nullable()->change()` pada kolom existing didukung tanpa perlu paket tambahan.
- **`_line_item_scripts.blade.php`** (dipakai `edit.blade.php`) sudah punya scaffolding JS untuk baris jasa/sparepart dalam mode "locked" (dari PKB, readonly) dan "free" (manual/AJAX-picked) — scaffolding ini dipakai ulang untuk form Direct Sales (semua baris dalam mode "free").
- **Tidak ada kalkulasi total live di JS** pada `edit.blade.php` hari ini — total dihitung 100% server-side setelah submit. Ini menyederhanakan Fitur 1: cukup tambah input diskon per baris, tanpa perlu logika rekalkulasi grand-total di client.
- **Sistem piutang/pembayaran parsial sudah ada** (`PaymentReceipt` + `PaymentAllocation`, bergantung pada `Invoice::grand_total`/`outstanding_amount`) — perubahan Fitur 1 tidak mengubah cara `grand_total` header dihitung (tetap `sum(line_total) - discount + tax`), hanya mengubah formula `line_total` itu sendiri, sehingga alur payment allocation tidak terdampak.

## 3. Decisions

1. **Format nomor Direct Sales** (dikonfirmasi user): pakai ulang `DocumentNumberGenerator::next($branch, 'DS')` → `DS/{cabang}/{periode}/{nomor:5}`, konsisten dengan seluruh dokumen lain. Bukan format literal `DS-xxxxx`.
2. **Scope conditional PPN** (dikonfirmasi user): hanya `print-pdf.blade.php`. `show.blade.php` (halaman detail web) tetap menampilkan baris PPN 0% seperti sekarang, tidak disentuh.
3. **Diskon per baris — sumber input**: mengikuti pola diskon header yang sudah ada (`discount_percent` sebagai input utama, `discount_amount` dihitung & disimpan otomatis dari persentase). Formula: `discount_amount = round((qty*unit_price) * discount_percent / 100, 2)`, lalu `line_total = round((qty*unit_price) - discount_amount, 2)` — sesuai formula di brief.
4. **Direct Sales tetap masuk siklus DRAFT → edit → post yang sudah ada**: form "Tambah Invoice Langsung" hanya membuat invoice **draft** beserta baris-barisnya (Cabang, Customer, item jasa/sparepart bebas) — diskon header/PPN/jatuh-tempo tetap diisi lewat halaman **edit** yang sudah ada (`invoices.edit`/`update`), sama seperti alur invoice dari PKB hari ini. Ini menghindari duplikasi logika kalkulasi total (yang sudah ada di `InvoiceService::updateInvoice()`) ke tempat baru.
5. **Direct Sales — otorisasi**: menambah ability baru `InvoicePolicy::createDirect(User, Branch): bool` yang me-reuse kode permission **`invoice.create`** yang sudah ada (branch-scoped) — tanpa syarat status PKB (karena tidak ada PKB). Tidak menambah kode permission baru.
6. **Direct Sales — struktur controller**: mengikuti pola `SparepartBranchController::createExisting()`/`storeExisting()` yang sudah ada di codebase ini untuk varian alur create — ditambahkan sebagai method baru `InvoiceController::createDirect()`/`storeDirect()`, bukan controller terpisah.
7. **Kolom `invoice_details` baru** mengikuti ukuran persis yang diminta di brief: `discount_percent` decimal(5,2) default 0 (sama seperti header), `discount_amount` decimal(15,2) default 0 (header pakai decimal(18,2); brief secara eksplisit meminta decimal(15,2) untuk level baris — diikuti apa adanya, cukup besar untuk nilai rupiah wajar per baris).
8. **Tabel line-item cetak PDF menampilkan kolom Diskon baru** (perluasan wajar dari Fitur 1, di luar permintaan literal brief tapi diperlukan agar angka `line_total` yang tercetak bisa dipertanggungjawabkan — tanpa ini, `qty × harga` di PDF tidak akan cocok dengan `Total` yang tercetak bila ada diskon). Kolom ini murni tampilan, tidak mengubah kalkulasi.

## 4. Design — Fitur 1: Diskon Per Item

### 4.1 Migration

`database/migrations/2026_08_11_000004_add_discount_to_invoice_details_table.php`:
```php
Schema::table('invoice_details', function (Blueprint $table) {
    $table->decimal('discount_percent', 5, 2)->default(0)->after('unit_price');
    $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_percent');
});
DB::statement("ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_discount_percent_range CHECK (discount_percent >= 0 AND discount_percent <= 100)");
DB::statement("ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_discount_amount_nonnegative CHECK (discount_amount >= 0)");
```

### 4.2 Model — `InvoiceDetail`

`$fillable` tambah `'discount_percent', 'discount_amount'`; `$casts` tambah `'discount_percent' => 'decimal:2', 'discount_amount' => 'decimal:2'`.

### 4.3 `InvoiceService`

- `createFromWorkOrder()`: baris dari PKB tidak membawa konsep diskon (PKB tidak punya kolom diskon) — `discount_percent`/`discount_amount` diisi `0` saat insert (`line_total` tetap disalin apa adanya dari PKB, tidak berubah perilaku).
- `updateInvoice()`: untuk tiap baris jasa & sparepart, baca `discount_percent` dari input (default 0 bila kosong), hitung:
  ```php
  $gross = round($qty * $unitPrice, 2);
  $discountPercent = (float) ($line['discount_percent'] ?? 0);
  $discountAmount = round($gross * $discountPercent / 100, 2);
  $lineTotal = round($gross - $discountAmount, 2);
  ```
  simpan `discount_percent`, `discount_amount`, `line_total` (menggantikan `line_total = round($qty * $unitPrice, 2)` yang sekarang). Agregasi header (`subtotal_service`/`subtotal_sparepart` = `sum(line_total)`) **tidak berubah logikanya** — otomatis sudah bersih dari diskon karena `line_total` sudah net.

### 4.4 `UpdateInvoiceRequest`

Tambah rules:
```php
'services.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
'spareparts.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
```

### 4.5 `InvoiceController::edit()`

`$existingServiceLines`/`$existingSparepartLines` tambah field `'discount_percent' => (float) $detail->discount_percent` agar form edit bisa pre-fill nilai diskon baris yang sudah tersimpan.

### 4.6 Views — `_line_item_scripts.blade.php` & `edit.blade.php`

Tambah 1 kolom baru **"Diskon (%)"** ke kedua template baris (`invoiceServiceLineTemplate`, `invoiceSparepartLineTemplate`), input `type="number" step="0.01" min="0" max="100"`, name pattern `services[{index}][discount_percent]` / `spareparts[{index}][discount_percent]`. Layout kolom disesuaikan agar tetap muat 12 grid-kolom Bootstrap (mis. item 6, qty 2, harga 2, diskon 1, hapus 1). Header label row di `edit.blade.php` (`Jasa | Qty | Harga Satuan | ...`) tambah label "Diskon %". JS `addServiceLine()`/`addSparepartLine()` di `_line_item_scripts.blade.php` tambah wiring `name` untuk field diskon baru; blok pre-fill `existingServiceLines.forEach(...)`/`existingSparepartLines.forEach(...)` di `edit.blade.php` set `.service-discount-percent`/`.sparepart-discount-percent` dari data yang sudah dikirim controller (§4.5).

### 4.7 View — `print-pdf.blade.php`

Tambah kolom **"Diskon"** ke `table.line-table` (antara "Harga Satuan" dan "Total"):
```blade
<tr><th>Tipe</th><th>Kode</th><th>Deskripsi</th><th>Qty</th><th>Harga Satuan</th><th>Diskon</th><th>Total</th></tr>
...
<td class="num">{{ $detail->discount_amount > 0 ? number_format($detail->discount_amount, 0, ',', '.') : '-' }}</td>
```

## 5. Design — Fitur 2: Direct Sales (Invoice Tanpa PKB)

### 5.1 Migration

`database/migrations/2026_08_11_000005_make_work_order_id_nullable_on_invoices_table.php`:
```php
Schema::table('invoices', function (Blueprint $table) {
    $table->foreignId('work_order_id')->nullable()->change();
});
```
Unique index & foreign key pada `work_order_id` **tetap ada apa adanya** (MySQL unique index mengizinkan banyak baris NULL, sehingga semantik "maksimal satu invoice per PKB" tetap terjaga, dan sembarang jumlah invoice Direct Sales dengan `work_order_id = NULL` tetap diperbolehkan). `down()` mengembalikan ke `->nullable(false)->change()`.

### 5.2 `Invoice` Model

Tambah accessor kenyamanan:
```php
public function getIsDirectSaleAttribute(): bool
{
    return is_null($this->work_order_id);
}
```

### 5.3 `App\Services\DocumentNumberGenerator` — tidak berubah

Dipakai apa adanya dengan `documentType` baru `'DS'` — tidak ada perubahan kode di service ini (infrastruktur counter per-cabang/per-periode sudah generik).

### 5.4 `InvoiceService::createDirectSale()`

Method baru, dipanggil dari controller, menerima array data (branch_id, customer_id, invoice_date, services[], spareparts[] — format sama seperti `updateInvoice()`, termasuk `discount_percent` per baris dari Fitur 1):
```php
public function createDirectSale(Branch $branch, Customer $customer, array $data): Invoice
{
    return DB::transaction(function () use ($branch, $customer, $data) {
        $invoice = Invoice::create([
            'number' => (new DocumentNumberGenerator())->next($branch, 'DS'),
            'work_order_id' => null,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
            'status' => InvoiceStatus::DRAFT,
            'subtotal_service' => 0,
            'subtotal_sparepart' => 0,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_percent' => 0,
            'tax_amount' => 0,
            'grand_total' => 0,
        ]);

        $sortOrder = 0;
        foreach ($data['services'] ?? [] as $line) {
            $gross = round((float) $line['qty'] * (float) $line['unit_price'], 2);
            $discountAmount = round($gross * ((float) ($line['discount_percent'] ?? 0)) / 100, 2);
            InvoiceDetail::create([
                'invoice_id' => $invoice->id,
                'item_type' => InvoiceDetailItemType::SERVICE,
                'description' => $line['description'],
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'discount_percent' => $line['discount_percent'] ?? 0,
                'discount_amount' => $discountAmount,
                'line_total' => round($gross - $discountAmount, 2),
                'sort_order' => $sortOrder++,
            ]);
        }
        foreach ($data['spareparts'] ?? [] as $line) {
            $sparepartBranch = SparepartBranch::with('sparepart')->findOrFail($line['sparepart_branch_id']);
            $gross = round((float) $line['qty'] * (float) $line['unit_price'], 2);
            $discountAmount = round($gross * ((float) ($line['discount_percent'] ?? 0)) / 100, 2);
            InvoiceDetail::create([
                'invoice_id' => $invoice->id,
                'item_type' => InvoiceDetailItemType::SPAREPART,
                'sparepart_branch_id' => $sparepartBranch->id,
                'item_code_snapshot' => $sparepartBranch->sparepart->code,
                'description' => $sparepartBranch->sparepart->name,
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'discount_percent' => $line['discount_percent'] ?? 0,
                'discount_amount' => $discountAmount,
                'line_total' => round($gross - $discountAmount, 2),
                'sort_order' => $sortOrder++,
            ]);
        }

        $subtotalService = round((float) $invoice->details()->where('item_type', InvoiceDetailItemType::SERVICE)->sum('line_total'), 2);
        $subtotalSparepart = round((float) $invoice->details()->where('item_type', InvoiceDetailItemType::SPAREPART)->sum('line_total'), 2);
        $invoice->update([
            'subtotal_service' => $subtotalService,
            'subtotal_sparepart' => $subtotalSparepart,
            'grand_total' => round($subtotalService + $subtotalSparepart, 2),
        ]);

        return $invoice->fresh('details');
    });
}
```
(Catatan: baris `work_order_service_line_id`/`work_order_sparepart_line_id` sengaja tidak diisi — default `null`, sesuai dukungan CHECK constraint yang sudah ada.)

### 5.5 `InvoicePolicy`

Tambah:
```php
public function createDirect(User $user, Branch $branch): bool
{
    return $user->hasPermissionToInBranch('invoice.create', $branch->id);
}
```

### 5.6 `StoreDirectSaleInvoiceRequest` (baru)

```php
public function authorize()
{
    $branchId = (int) $this->input('branch_id');
    return $branchId && $this->user()->hasPermissionToInBranch('invoice.create', $branchId);
}

public function rules()
{
    return [
        'branch_id' => ['required', 'integer', 'exists:branches,id'],
        'customer_id' => ['required', 'integer', 'exists:customers,id'],
        'invoice_date' => ['required', 'date'],
        'services' => ['nullable', 'array'],
        'services.*.description' => ['required_with:services.*.qty', 'string', 'max:255'],
        'services.*.qty' => ['required_with:services.*.description', 'numeric', 'min:0.001'],
        'services.*.unit_price' => ['required_with:services.*.description', 'numeric', 'min:0'],
        'services.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        'spareparts' => ['nullable', 'array'],
        'spareparts.*.sparepart_branch_id' => ['required_with:spareparts.*.qty', 'integer', 'exists:sparepart_branches,id'],
        'spareparts.*.qty' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0.001'],
        'spareparts.*.unit_price' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0'],
        'spareparts.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
    ];
}
```
`prepareForValidation()` memfilter baris kosong (pola sama seperti `UpdateInvoiceRequest`). `withValidator()`:
- menambah error umum bila `services` dan `spareparts` (setelah difilter) sama-sama kosong ("Invoice harus punya minimal satu baris jasa atau sparepart.");
- untuk tiap baris sparepart, validasi `sparepart_branch_id` merujuk ke `SparepartBranch` yang `is_active` dan `branch_id`-nya sama dengan `branch_id` yang dipilih di form — pola persis sama dengan pengecekan yang sudah ada di `UpdateInvoiceRequest::withValidator()`, mencegah user memilih sparepart dari cabang lain.

### 5.7 `InvoiceController`

Tambah 2 method:
```php
public function createDirect()
{
    $branches = auth()->user()->branchesWithPermission('invoice.create');
    if ($branches->isEmpty()) {
        return view('invoices.no-access');
    }
    $serviceCatalogs = ServiceCatalog::where('is_active', true)->orderBy('name')->get();
    return view('invoices.create-direct', compact('branches', 'serviceCatalogs'));
}

public function storeDirect(StoreDirectSaleInvoiceRequest $request)
{
    $branch = Branch::findOrFail($request->input('branch_id'));
    $customer = Customer::findOrFail($request->input('customer_id'));
    $invoice = (new InvoiceService())->createDirectSale($branch, $customer, $request->validated());
    return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice Direct Sales berhasil dibuat.');
}
```

### 5.8 Routes

Ditambahkan **sebelum** route `/{invoice}` (agar tidak tertangkap sebagai parameter wildcard):
```php
Route::get('/direct/create', [InvoiceController::class, 'createDirect'])->name('createDirect');
Route::post('/direct', [InvoiceController::class, 'storeDirect'])->name('storeDirect');
```

### 5.9 View baru — `resources/views/invoices/create-direct.blade.php`

Form: dropdown Cabang (dari `$branches`), picker Customer AJAX (reuse `route('lookup.customers')`, sudah dipakai form PKB, permission `customer.view`, filter `branch_id` opsional), `invoice_date` (default hari ini), baris jasa/sparepart (reuse `@include('invoices._line_item_scripts')` — semua baris mode "free": `InvoiceLineItems.addServiceLine(false)` / `addSparepartLine(branchId, false)`, dengan diskon per baris dari Fitur 1). JS: saat dropdown Cabang berubah, `window.currentInvoiceBranchId` diperbarui dan picker Customer/Sparepart AJAX di-reinit dengan `branch_id` baru (pola sama dengan form PKB saat ganti cabang).

### 5.10 Entry point UI

Tombol baru **"+ Invoice Langsung (DS)"** di `resources/views/invoices/index.blade.php`, tampil bila `auth()->user()->branchesWithPermission('invoice.create')->isNotEmpty()`, link ke `route('invoices.createDirect')`.

### 5.11 Null-guard untuk `$invoice->workOrder`

**`resources/views/invoices/show.blade.php`** baris 40, ganti:
```blade
<div class="col-md-3"><strong>PKB</strong><div>{{ $invoice->workOrder->number }}</div></div>
```
menjadi:
```blade
<div class="col-md-3"><strong>PKB</strong><div>{{ $invoice->workOrder->number ?? 'Direct Sales' }}</div></div>
```

**`resources/views/invoices/print-pdf.blade.php`**, blok "Data Customer & Kendaraan" (baris 60-66) dan field "No. PKB" (baris 69) dibungkus kondisional:
```blade
<div><span class="label">Nama:</span> {{ $invoice->customer->name }}</div>
<div><span class="label">Alamat:</span> {{ $invoice->customer->address ?? '-' }}</div>
@if ($invoice->workOrder)
    <div><span class="label">No. Polisi:</span> {{ $invoice->workOrder->vehicle->plate_number }}</div>
    <div><span class="label">Kendaraan:</span> {{ optional($invoice->workOrder->vehicle->brand)->name }} {{ optional($invoice->workOrder->vehicle->type)->name }}</div>
@endif
```
```blade
<div><span class="label">No. PKB:</span> {{ optional($invoice->workOrder)->number ?? 'Direct Sales' }}</div>
```

### 5.12 Sudah aman tanpa perubahan (lihat §2)

`InvoicePkbGapReportController`, `InvoicePkbGapComparator`, `DashboardController`, `edit.blade.php` — tidak perlu disentuh.

## 6. Design — Fitur 3: Conditional PPN Print

`resources/views/invoices/print-pdf.blade.php` baris 98, bungkus:
```blade
@if ($invoice->tax_percent > 0 && $invoice->tax_amount > 0)
    <tr><td>PPN ({{ number_format($invoice->tax_percent, 2, ',', '.') }}%)</td><td class="num">{{ number_format($invoice->tax_amount, 0, ',', '.') }}</td></tr>
@endif
```
(Baris disembunyikan bila **salah satu** dari `tax_percent`/`tax_amount` ≤ 0 — kedua kondisi digabung `&&` sehingga baris hanya tampil bila keduanya positif, sesuai kalimat brief "sembunyikan jika tax_amount ≤ 0 atau tax_percent ≤ 0".) `show.blade.php` **tidak diubah** (§3 poin 2).

## 7. Testing Strategy

- **Migration/model**: test baru untuk `discount_percent`/`discount_amount` di `InvoiceDetail` (fillable, CHECK constraint range), dan untuk `work_order_id` nullable (`Invoice::create()` tanpa `work_order_id` tidak error).
- **`InvoiceControllerTest.php`**: test baru untuk diskon per baris via `PUT /invoices/{invoice}` (assert `invoice_details.discount_amount`/`line_total` sesuai formula, dan `subtotal_service`/`subtotal_sparepart`/`grand_total` header tetap konsisten).
- **Test baru khusus Direct Sales** (kemungkinan file baru `InvoiceDirectSaleTest.php`): `createDirect()` menampilkan form untuk user dengan `invoice.create` di cabang manapun, ditolak tanpa permission; `storeDirect()` membuat invoice draft dengan `work_order_id = null`, nomor berformat `DS/{cabang}/{periode}/00001`; minimal satu baris jasa/sparepart wajib; invoice yang dihasilkan bisa lanjut ke `edit`/`update`/`post` lewat alur existing tanpa error; `show`/`print` untuk invoice Direct Sales tidak crash (null-guard §5.11) dan menampilkan "Direct Sales" alih-alih nomor PKB.
- **Conditional PPN**: test baru di `InvoicePdfBuilderTest.php` atau `InvoicePrintEmailTest.php` — PDF invoice dengan `tax_percent = 0` tidak mengandung teks "PPN", PDF dengan `tax_percent > 0` tetap mengandung teks "PPN".
- **Regresi yang wajib diverifikasi tidak berubah**: `DocumentNumberGeneratorTest.php` (format `INV/...` tidak boleh berubah), `InvoicePkbGapReportControllerTest.php`/`InvoicePkbGapReportExportTest.php` (invoice Direct Sales harus otomatis absen dari laporan gap PKB), seluruh test payment/receivables (`PaymentReceipt`/`PaymentAllocation`) tidak boleh terpengaruh oleh perubahan formula `line_total`.

## 8. Out of Scope

- Tidak ada perubahan pada `show.blade.php` untuk baris PPN (§3 poin 2).
- Tidak ada migrasi data lama — invoice existing (semua dari PKB) otomatis punya `discount_percent`/`discount_amount` = 0 di setiap baris (default kolom), tidak mengubah `line_total`/`grand_total` yang sudah tersimpan.
- Tidak ada perubahan pada `PaymentService`/`PaymentReceipt`/`PaymentAllocation` — hanya diverifikasi lewat test bahwa tidak ada regresi.
- Direct Sales tidak mendukung linking balik ke PKB di kemudian hari (invoice Direct Sales permanen tanpa `work_order_id`; tidak ada fitur "convert to PKB-linked").
- Tidak ada perubahan pada `InvoicePkbGapReportController`/`InvoicePkbGapComparator`/laporannya — sudah otomatis kompatibel (§2).
