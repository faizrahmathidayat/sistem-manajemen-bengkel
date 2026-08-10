# Penyesuaian Spesifikasi Menu PKB & Master Kendaraan — Dokumen Spesifikasi Desain

## 1. Ringkasan

Milestone ini mencakup 4 perubahan independen pada modul PKB (Perintah Kerja Bengkel) dan Master
Kendaraan:

1. Tambah field **Tahun Kendaraan** di Master Kendaraan, ditampilkan di form pembuatan PKB, detail PKB,
   dan cetakan PDF PKB.
2. Ganti label **"Catatan" → "Keluhan"** pada form dan tampilan PKB.
3. **Kunci harga jasa** (`unit_price`) pada baris PKB agar selalu mengikuti `ServiceCatalog::default_price`.
4. **Kunci harga sparepart** (`unit_price`) pada baris PKB agar selalu mengikuti `SparepartBranch::selling_price`.

Keempatnya menyentuh area yang sama (`app/Models/Vehicle.php`, `WorkOrderController`, form Blade PKB),
tapi tidak saling bergantung secara teknis — bisa diurutkan bebas di Implementation Plan.

## 2. Temuan Eksplorasi Penting

- **Tidak ada kolom `year` di tabel `vehicles`** — perlu migration baru.
- **Halaman detail PKB (`show.blade.php`) saat ini hanya menampilkan `plate_number`** untuk field
  Kendaraan (tidak ada Merk/Tipe sama sekali), sedangkan **cetakan PDF sudah menampilkan Merk+Tipe**
  (tanpa plat, di baris terpisah). Kedua template ini sudah tidak konsisten satu sama lain — di luar
  scope milestone ini untuk diseragamkan; saya hanya menambahkan field Tahun ke masing-masing tanpa
  mengubah struktur yang sudah ada.
- **`work-orders/index.blade.php` tidak punya kolom "Catatan" sama sekali** — jadi poin #2 ("ubah label
  ... tabel index ... dari Catatan menjadi Keluhan") tidak memerlukan perubahan kode di sana; tidak ada
  yang perlu diubah karena labelnya memang tidak pernah ditampilkan di tabel index.
- **Baris Sparepart PKB sudah 100% wajib pilih dari `SparepartBranch`** (tidak ada opsi input bebas) —
  mengunci harganya (poin #4) adalah perubahan yang sederhana dan tanpa ambiguitas.
- **Baris Jasa PKB punya opsi "-- Manual --"** (deskripsi bebas, tanpa `service_catalog_id`) di samping
  memilih dari Master Katalog Jasa. Opsi ini membuat "kunci harga SELALU dari katalog" (poin #3) menjadi
  ambigu untuk baris yang tidak terhubung katalog. **Sudah dikonfirmasi ke user: opsi Manual dihapus,
  setiap baris jasa wajib terhubung ke Master Katalog Jasa** — sama seperti pola yang sudah dipakai baris
  Sparepart.
- **Kolom `default_unit_price` pada `work_order_sparepart_lines` sudah ada** dan sudah diisi dari
  `SparepartBranch::selling_price` saat ini juga (lihat `WorkOrderController::syncSparepartLines()`) —
  kolom ini sudah persis berfungsi sebagai snapshot harga master. Tidak perlu kolom baru untuk baris
  Jasa; setelah dikunci, `unit_price` pada `work_order_service_lines` akan otomatis selalu identik
  dengan `ServiceCatalog::default_price` pada saat baris dibuat, cukup berfungsi sebagai snapshot-nya
  sendiri.
- **Audit dampak ke test suite**: digrep seluruh `tests/Feature/*.php` yang mem-POST/PUT ke
  `/work-orders` (46 pemanggilan, 18 file). **Hampir semua payload `services[]` yang ada sudah
  menyertakan `service_catalog_id`**, dan pola yang konsisten dipakai adalah membuat `ServiceCatalog`
  dengan `default_price` yang SAMA dengan `unit_price` yang dikirim di payload test (mis.
  `InvoicePkbGapReportControllerTest::makeGapPair()`). Karena itu, mengunci harga di server (selalu
  timpa dengan harga master) **tidak akan mengubah hasil test manapun** yang sudah ada — perubahan
  perilakunya hanya terasa kalau nilai `unit_price` yang dikirim client BEDA dari harga master, yang
  sejauh ini tidak disimulasikan test manapun.
  Dua pemakaian `service_catalog_id => null` yang ditemukan di `WorkOrderManagementTest.php` (baris 633,
  1085) ada di dalam test yang mengharapkan response **403 Forbidden** (karena PKB sudah bukan status
  draft) — `FormRequest::authorize()` gagal duluan sebelum `rules()` dievaluasi, jadi isi payload tidak
  relevan dan test ini tidak akan rusak.
  **Koreksi (ditemukan saat penyusunan Implementation Plan):** audit ini sempat melewatkan 2 kejadian
  nyata di file yang sama — `test_update_does_not_silently_reassign_a_now_inactive_customer` (±baris 304)
  dan `test_update_replaces_lines_and_recomputes_totals` (±baris 603) — keduanya mengirim baris jasa
  `service_catalog_id => null` dengan `description` terisi (bukan baris kosong yang ke-filter oleh
  `prepareForValidation()`) dan mengharapkan **sukses**. Kedua test ini akan rusak begitu
  `service_catalog_id` diwajibkan. Implementation Plan (Task 3, Step 1) memperbaiki kedua test tersebut
  agar memakai `service_catalog_id` katalog yang valid, sebagai bagian dari task itu — bukan perubahan
  desain, hanya koreksi akurasi audit di atas.
  (Catatan: ada banyak literal `services => [[...]]` tanpa `service_catalog_id` di
  `InvoicePkbGapReportControllerTest.php` — setelah ditelusuri, itu adalah payload untuk `PUT
  /invoices/{id}` (edit baris Invoice, fitur terpisah), BUKAN payload PKB. Tidak relevan dengan
  milestone ini.)

## 3. Rincian Desain per Perubahan

### 3.1 Field Tahun Kendaraan

**Migration baru** `2026_08_10_000001_add_year_to_vehicles_table.php`:
```php
Schema::table('vehicles', function (Blueprint $table) {
    $table->unsignedSmallInteger('year')->nullable()->after('engine_number');
});
```
Nullable karena data kendaraan lama tidak punya tahun.

**Model `Vehicle`**: tambah `'year'` ke `$fillable`.

**Form Master Kendaraan** (`resources/views/vehicles/_form.blade.php`, dipakai `create.blade.php` &
`edit.blade.php`): tambah input Tahun di baris yang sama dengan No. Polisi/No. Rangka/No. Mesin. Baris
itu diubah dari 3 kolom `col-md-4` menjadi 4 kolom `col-md-3` (plate/frame/engine/year), tetap pas dalam
grid 12 kolom.
```blade
<input type="number" name="year" id="year" value="{{ old('year', $vehicle->year) }}"
       class="form-control @error('year') is-invalid @enderror" min="1900" max="{{ now()->year + 1 }}">
```

**`StoreVehicleRequest` & `UpdateVehicleRequest`**: tambah rule
`'year' => ['nullable', 'integer', 'digits:4', 'between:1900,' . (now()->year + 1)]`.

**Tampil di pembuatan PKB** — satu-satunya tempat kendaraan "ditampilkan" saat membuat PKB adalah label
di dropdown `<select id="vehicleSelect">` (tidak ada modal/panel info terpisah di UI saat ini). Format
label saat ini (dari sesi sebelumnya): `"{Brand} {Type} - {Plate}"` (mis. "Honda Beat - B 1234 ABC").
Diperluas jadi: **`"{Brand} {Type} {Year} - {Plate}"`** → *"Honda Beat 2020 - B 1234 ABC"*. Perubahan di:
- `WorkOrderLookupController::vehiclesByCustomer()` — tambah `year` ke kolom yang di-select & response
  JSON.
- `resources/views/work-orders/_line_item_scripts.blade.php` fungsi `vehicleLabel()` — sisipkan year
  setelah brand+type.
- `resources/views/work-orders/edit.blade.php` — opsi awal yang di-render server-side (bukan lewat AJAX)
  juga dibangun ulang dengan year, dan `WorkOrderController::edit()`'s query `$vehicles` (sudah eager
  load `brand`/`type`) otomatis ikut membawa kolom `year` tanpa perlu eager load tambahan.

**Tampil di detail PKB** (`work-orders/show.blade.php`): tambah field baru **"Tahun Kendaraan"**
(`col-md-3`, sejajar dengan field Kendaraan/Mekanik yang sudah ada) menampilkan
`{{ $workOrder->vehicle->year ?? '-' }}`. `WorkOrderController::show()` sudah eager load relasi
`vehicle` — kolom `year` datang gratis, tidak perlu ubah query.

**Tampil di cetakan PDF** (`work-orders/print-pdf.blade.php`): baris `Kendaraan:` yang sudah ada
(`{{ brand }} {{ type }}`) diperluas jadi `{{ brand }} {{ type }} ({{ year }})` — tahun ditambahkan dalam
kurung, hanya kalau ada (`$workOrder->vehicle->year ? " ({$workOrder->vehicle->year})" : ''`).
`WorkOrderController::printPdf()` sudah eager load `vehicle.brand`/`vehicle.type`, kolom `year` gratis.

### 3.2 Label "Catatan" → "Keluhan"

Perubahan label teks saja — **nama field/kolom `notes` di database TIDAK berubah**, hanya teks yang
dilihat user.

| File | Baris | Perubahan |
|---|---|---|
| `work-orders/create.blade.php` | label `<label class="form-label">Catatan</label>` | → "Keluhan" |
| `work-orders/edit.blade.php` | label yang sama | → "Keluhan" |
| `work-orders/show.blade.php` | `<strong>Catatan</strong>` | → "Keluhan" |
| `work-orders/print-pdf.blade.php` | `<span class="label">Catatan:</span>` | → "Keluhan:" |
| `work-orders/index.blade.php` | — | **Tidak ada perubahan** — tabel index tidak punya kolom Catatan |

### 3.3 Penguncian Harga Jasa

**Keputusan yang sudah dikonfirmasi**: opsi `-- Manual --` dihapus dari dropdown Katalog Jasa. Setiap
baris jasa **wajib** memilih entri dari Master Katalog Jasa.

**UI** (`resources/views/work-orders/_line_item_scripts.blade.php`, template `serviceLineTemplate`):
- `<option value="">-- Manual --</option>` → `<option value="">-- Pilih Jasa --</option>` (placeholder
  kosong tetap ada supaya baris lama yang belum dipilih tidak otomatis ke-assign ke katalog pertama;
  bedanya sekarang wajib diisi, bukan valid dibiarkan kosong).
- `.service-catalog-select` ditambah atribut `required`.
- `.service-unit-price` ditambah atribut `readonly`. Nilainya tetap otomatis terisi lewat listener
  `change` yang sudah ada (baca `data-price` dari opsi terpilih) — hanya tidak lagi bisa diketik manual.
- `.service-description` **tetap bisa diedit** (spesifikasi hanya minta kunci harga, bukan deskripsi) —
  berguna untuk menambah detail spesifik per baris meski jasanya dari katalog yang sama.

**Backend — `StoreWorkOrderRequest` & `UpdateWorkOrderRequest`**: ubah rule
```php
'services.*.service_catalog_id' => ['required_with:services.*.qty', 'integer', 'exists:service_catalogs,id'],
```
(sebelumnya `nullable`) — meniru pola `spareparts.*.sparepart_branch_id` yang sudah ada.

**Backend — `WorkOrderController::syncServiceLines()`**: sebelumnya memakai `$line['unit_price']` apa
adanya dari client. Diubah agar **selalu** mengambil dari `ServiceCatalog::default_price`, mengabaikan
apa pun yang dikirim client di `unit_price` (pertahanan berlapis — validasi UI `readonly` bisa dilewati
lewat devtools/request manual, jadi server tidak boleh percaya nilai itu):
```php
$catalog = ServiceCatalog::findOrFail($line['service_catalog_id']);
$unitPrice = (float) $catalog->default_price;
```

### 3.4 Penguncian Harga Sparepart

Lebih sederhana karena baris Sparepart sudah wajib pilih dari master sejak awal (tidak ada opsi
manual).

**UI** (`sparepartLineTemplate`): `.sparepart-unit-price` ditambah atribut `readonly`. Nilainya sudah
otomatis terisi lewat `onSelect` callback yang ada di `initAjaxSelect()` — tidak ada perubahan alur JS,
hanya field jadi tidak bisa diketik manual.

**Backend — `WorkOrderController::syncSparepartLines()`**: satu baris berubah, dari
`$unitPrice = (float) $line['unit_price'];` menjadi `$unitPrice = (float) $sparepartBranch->selling_price;`
(variabel `$sparepartBranch` sudah di-fetch di method yang sama). Kolom `default_unit_price` tetap diisi
sama seperti sekarang (`$sparepartBranch->selling_price`) — sekarang kedua kolom (`unit_price` dan
`default_unit_price`) akan selalu bernilai sama saat baris dibuat, karena keduanya memang sumbernya satu.

**Validasi**: rule `spareparts.*.unit_price` tetap ada (numeric, min:0) sebagai validasi bentuk data
saja — nilainya tidak lagi dipakai controller, tapi tidak mengganggu untuk dibiarkan.

## 4. Kompatibilitas dengan Data Lama

- PKB berstatus selain **Draft** tidak bisa diubah sama sekali (aturan existing, tidak berubah) — jadi
  perubahan ini hanya berlaku efektif saat PKB *baru dibuat* atau *draft lama diedit ulang*.
- Draft PKB lama yang punya baris jasa "Manual" (`service_catalog_id = null`): saat draft itu dibuka
  lagi di form Edit, dropdown baris tersebut akan tampil kosong (placeholder "-- Pilih Jasa --", bukan
  otomatis terpilih ke katalog manapun). User **wajib memilih katalog jasa yang sesuai** sebelum bisa
  menyimpan ulang draft tersebut — deskripsi & harga baris itu akan mengikuti katalog yang baru dipilih.
  Tidak perlu migrasi data karena baris lama di database tidak diubah kecuali user benar-benar
  menyimpan ulang draft tsb.

## 5. Testing Strategy

- **Vehicle/Master Kendaraan**: test model (`year` fillable & tersimpan), test form create/edit
  (render input year, validasi rentang tahun), test list tidak wajib diubah (kolom Tahun tidak diminta
  ditambah ke tabel index Kendaraan — spec hanya minta ditampilkan di PKB).
- **Label Keluhan**: test `assertSee('Keluhan')` + `assertDontSee` label lama pada create/edit/show/PDF
  PKB (nama field `name="notes"` tidak diubah, jadi test lama yang submit `notes` tidak perlu diubah).
- **Kunci harga jasa**:
  - Test baru: submit `service_catalog_id` valid dengan `unit_price` yang **beda** dari
    `default_price` katalog → assert baris tersimpan pakai `default_price` katalog, BUKAN nilai yang
    dikirim.
  - Test baru: submit baris jasa **tanpa** `service_catalog_id` (deskripsi & harga saja, meniru
    perilaku "Manual" lama) → assert **validation error** (bukan lagi diterima).
  - Regresi: jalankan ulang seluruh test yang menyentuh `POST/PUT /work-orders` (18 file yang teridentifikasi
    di eksplorasi) — diprediksi **tidak ada yang gagal** berdasarkan audit di §2, tapi tetap wajib
    dijalankan untuk memastikan.
- **Kunci harga sparepart**: pola sama — test submit `unit_price` beda dari `SparepartBranch::selling_price`
  → assert tersimpan pakai harga master.
- Full test suite dijalankan di akhir setiap task sesuai konvensi proyek ini.

## 6. Batasan Global (berlaku ke semua task di Implementation Plan)

- PHP 7.4.33 — jangan pakai sintaks PHP8.
- Nama field `notes` di database & form (`name="notes"`) **tidak berubah** — hanya label tampilan.
- Tidak ada migrasi data untuk baris jasa "Manual" lama; penyesuaian hanya berlaku saat draft lama
  diedit ulang secara aktif.
- Perubahan harga di form PKB harus tervalidasi di **backend**, bukan cuma `readonly` di UI (readonly
  HTML bisa dilewati lewat request manual).
- Pola konvensi test proyek ini tetap dipakai: helper duplikat per file test (bukan trait bersama), TDD
  (test dulu → merah → implementasi → hijau), commit per task setelah full suite hijau.
