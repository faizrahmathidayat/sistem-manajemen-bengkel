# Desain: Kirim Invoice via WhatsApp (Direct Link) & Halaman Invoice Publik Berproteksi PIN

**Tanggal:** 2026-08-12
**Status:** Draft untuk direview

## 1. Latar Belakang & Tujuan

Saat ini invoice hanya bisa dilihat customer lewat PDF terlampir di email (`InvoiceController::sendEmail`,
lihat `docs/superpowers/specs/2026-08-07-invoice-print-email-design.md`). Banyak customer bengkel lebih
mudah dihubungi lewat WhatsApp daripada email. Milestone ini menambahkan:

1. **Tombol "Kirim via WhatsApp"** di halaman detail invoice (`invoices/show.blade.php`) — membuka
   `wa.me` dengan pesan siap-kirim berisi link invoice publik + PIN.
2. **Halaman invoice publik** (`/i/{hash_id}`) — tanpa login, dilindungi PIN 6 digit, menampilkan PDF
   invoice yang identik dengan yang staff lihat (`InvoicePdfBuilder`, reuse penuh).

Kedua fitur bersifat **hanya untuk invoice yang sudah final** (status `posted`, `partially_paid`, `paid`)
— pola yang sama seperti gate `print`/`sendEmail` yang sudah ada di `InvoicePolicy`, karena invoice DRAFT
bisa berubah datanya lewat `InvoiceController::update()`, dan tidak seharusnya dibagikan ke customer dulu.

## 2. Keputusan Arsitektur

### 2.1 Data & Schema: `hash_id` + `pin` nullable, digenerate saat invoice dibuat

Migration baru `database/migrations/2026_08_12_000001_add_pin_and_hash_id_to_invoices_table.php`:

```php
Schema::table('invoices', function (Blueprint $table) {
    $table->string('hash_id', 32)->nullable()->unique()->after('number');
    $table->string('pin', 6)->nullable()->after('hash_id');
});
```

Nullable karena invoice lama (sebelum migration ini) tidak otomatis punya nilai — lihat §9 "Keputusan
yang Perlu Dikonfirmasi" poin B soal backfill.

`InvoiceService` mendapat helper baru, dipanggil dari **kedua** titik pembuatan invoice
(`createFromWorkOrder()` dan `createDirectSale()`), tepat sebelum `Invoice::create([...])`:

```php
protected function generatePublicAccessCredentials(): array
{
    do {
        $hashId = Str::random(32);
    } while (Invoice::where('hash_id', $hashId)->exists());

    return [
        'hash_id' => $hashId,
        'pin' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
    ];
}
```

dipakai sebagai `...$this->generatePublicAccessCredentials()` di dalam array `Invoice::create([...])` pada
kedua method. Loop uniqueness pada `hash_id` murni jaga-jaga — peluang collision untuk 32 karakter random
(`Str::random` = alfanumerik campuran, ~62^32 kombinasi) secara praktis nol, tapi constraint `unique()` di
DB akan melempar exception kalau tidak dicek, jadi loop ini murah untuk dipasang.

**Kenapa saat CREATE, bukan saat POST:** Baik PKB→invoice maupun Direct Sales, kedua alur pembuatan
invoice sama-sama lewat `InvoiceService::createFromWorkOrder()`/`createDirectSale()` — satu titik
pemanggilan per alur, versus dua titik (create + post) kalau digenerate ulang saat posting. Karena halaman
publik dan tombol WA sama-sama sudah di-gate ke status POSTED+ (§2.4), invoice DRAFT yang sudah punya
`hash_id`/`pin` di database tidak berisiko — tidak ada jalan untuk mengaksesnya secara publik sebelum
status invoice berubah jadi POSTED. Ini didaftar sebagai keputusan yang perlu dikonfirmasi eksplisit di
§9 karena requirement asli menyebut "saat invoice dibuat/diposting" — dua kemungkinan yang tidak 100% sama.

### 2.2 Rute publik: `{invoice:hash_id}`, bukan lookup manual

Laravel mendukung custom route-key-binding langsung di definisi route:

```php
Route::get('/i/{invoice:hash_id}', [PublicInvoiceController::class, 'showPinForm'])->name('public-invoices.show');
```

`{invoice:hash_id}` memberi tahu Laravel untuk resolve `Invoice` lewat kolom `hash_id`, bukan primary key
`id` — berlaku hanya untuk route ini (route lain seperti `/invoices/{invoice}` tetap resolve lewat `id`
seperti biasa, tidak perlu override `getRouteKeyName()` di model `Invoice`). Kalau `hash_id` tidak
ditemukan, Laravel otomatis melempar 404 generik — tidak membocorkan apakah suatu hash pernah ada.

`route('public-invoices.show', $invoice)` (dipakai di §2.6 untuk membangun link WA) otomatis
menghasilkan URL dengan `$invoice->hash_id`, bukan `$invoice->id`, karena binding field di atas juga
dipakai Laravel untuk arah sebaliknya (URL generation), bukan cuma resolving.

### 2.3 Tiga route publik: form PIN, verifikasi, tampil PDF

```php
Route::prefix('i')->name('public-invoices.')->group(function () {
    Route::get('/{invoice:hash_id}', [PublicInvoiceController::class, 'showPinForm'])->name('show');
    Route::post('/{invoice:hash_id}/verify', [PublicInvoiceController::class, 'verifyPin'])
        ->name('verify')->middleware('throttle:10,1');
    Route::get('/{invoice:hash_id}/pdf', [PublicInvoiceController::class, 'showPdf'])->name('pdf');
});
```

Diletakkan **di luar** grup `Route::middleware(['auth'])` di `routes/web.php` — sejajar dengan
`Route::middleware('guest')->group(...)` yang sudah membungkus `/login`, ditaruh tepat setelah blok
tersebut (baris ~45). Middleware global `web` group (`StartSession`, `VerifyCsrfToken`,
`EnsureUserIsActive`) tetap berlaku — sudah dicek `EnsureUserIsActive` no-op untuk guest
(`Auth::user()` null), jadi aman dipasang di luar grup `auth`.

`throttle:10,1` pada `verify` (10 percobaan/menit per IP) — pola yang sama persis dengan
`POST /login` yang sudah pakai `throttle:5,1`. PIN 6 digit numerik (1 juta kombinasi) rentan brute-force
kalau tidak dibatasi; `hash_id` 32 karakter sendiri sudah jadi faktor rahasia pertama, tapi throttle tetap
lini pertahanan kedua yang murah untuk dipasang, konsisten dengan pola yang sudah ada di codebase ini.

**Alur:**
1. `showPinForm` — kalau invoice belum POSTED+ atau `hash_id`/`pin` kosong (invoice lama belum backfill,
   lihat §9.B) → 404. Kalau session sudah tandai invoice ini terverifikasi
   (`session('public_invoice_verified.' . $invoice->id)`) → redirect langsung ke route `pdf`. Selain itu →
   render `public.invoice-pin-form`.
2. `verifyPin` — validasi input `pin` (`required|digits:6`), bandingkan dengan `hash_equals($invoice->pin,
   $request->input('pin'))` (timing-safe, bukan `===`). Cocok → set session, redirect ke route `pdf`.
   Tidak cocok → redirect balik ke `show` dengan flash error "PIN salah.".
3. `showPdf` — invoice harus POSTED+ **dan** session harus tandai terverifikasi (kalau tidak, redirect ke
   `show`, bukan 403 — supaya customer yang link session-nya sudah kedaluwarsa cukup diarahkan ke form
   PIN lagi, bukan lihat halaman error). Stream `InvoicePdfBuilder::build($invoice)->stream(...)` — PDF
   yang **identik** dengan yang staff lihat (§2.4).

Session "sementara" memakai session lifetime default Laravel (`SESSION_LIFETIME=120` menit,
`config/session.php`) — tidak ada TTL custom per-invoice. Cukup untuk requirement "customer tidak perlu
mengetik ulang PIN setiap refresh" tanpa menambah state baru di luar session bawaan.

### 2.4 Reuse penuh `InvoicePdfBuilder` — tidak ada template PDF baru

```php
InvoicePdfBuilder::build($invoice)->stream(InvoicePdfBuilder::filename($invoice));
```

Sama persis dengan yang dipakai `InvoiceController::printPdf()` — `invoices/print-pdf.blade.php` tidak
disentuh sama sekali. Ini memenuhi requirement "reuse view invoices/print-pdf.blade.php atau
InvoicePdfBuilder" secara harfiah.

### 2.5 Policy & permission baru: `invoice.share_whatsapp`

Mengikuti pola `print`/`sendEmail` yang sudah ada persis (§2.4 di spec sebelumnya
`2026-08-07-invoice-print-email-design.md`):

```php
// InvoicePolicy
public function shareWhatsapp(User $user, Invoice $invoice): bool
{
    return in_array($invoice->status, [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID], true)
        && $user->hasPermissionToInBranch('invoice.share_whatsapp', $invoice->branch_id);
}
```

Permission code baru `invoice.share_whatsapp` ditambahkan ke `database/seeders/MenuPermissionSeeder.php`,
sejajar dengan `invoice.print`/`invoice.email` (baris 75-76 saat ini). Alasan permission **baru** (bukan
reuse `invoice.email`): filosofi permission granular di aplikasi ini — satu tombol/aksi = satu permission
code (lihat `invoice.print` vs `invoice.email` yang juga terpisah walau sama-sama "kirim/tampilkan
invoice ke customer"). Dikonfirmasi eksplisit di §9.C karena ini nambah 1 permission code baru yang perlu
di-assign manual ke user/branch setelah deploy (termasuk ke superadmin — otomatis lewat
`DemoUsersSeeder`'s "grant semua permission" tapi user lain di production perlu di-assign manual).

Halaman `invoices/show.blade.php` — tombol baru sejajar tombol Cetak/Kirim Email yang sudah ada:

```blade
@can('shareWhatsapp', $invoice)
    @if ($waLink = \App\Support\WhatsAppInvoiceLinkBuilder::build($invoice))
        <a href="{{ $waLink }}" target="_blank" class="btn btn-success btn-sm">
            <i class="bi bi-whatsapp"></i> Kirim via WhatsApp
        </a>
    @endif
@endcan
```

Tombol **disembunyikan sepenuhnya** (bukan disabled dengan tooltip) kalau `customer->phone` kosong —
konsisten dengan pola `@if` kondisional lain di halaman yang sama (mis. blok "Invoice dibatalkan"),
lebih sederhana daripada menambah state disabled+tooltip untuk kasus yang jarang terjadi (customer tanpa
nomor telepon, sementara mayoritas data customer punya `phone` terisi — lihat
`ProductionMasterDataSeeder`).

### 2.6 `WhatsAppInvoiceLinkBuilder` — format nomor & pesan, testable terpisah

Class baru `app/Support/WhatsAppInvoiceLinkBuilder.php`, pola stateless-static yang sama seperti
`InvoicePdfBuilder`:

```php
namespace App\Support;

use App\Models\Invoice;

class WhatsAppInvoiceLinkBuilder
{
    public static function build(Invoice $invoice): ?string
    {
        // Invoice lama dari sebelum migration ini punya hash_id/pin NULL (tidak dibackfill,
        // lihat §7.B) — tombol WhatsApp otomatis tersembunyi untuknya lewat null-check ini.
        if (! $invoice->hash_id || ! $invoice->pin) {
            return null;
        }

        $phone = static::formatPhone($invoice->customer->phone);
        if (! $phone) {
            return null;
        }

        return 'https://wa.me/' . $phone . '?text=' . urlencode(static::message($invoice));
    }

    public static function formatPhone(?string $rawPhone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $rawPhone);
        if ($digits === '') {
            return null;
        }
        if (substr($digits, 0, 1) === '0') {
            return '62' . substr($digits, 1);
        }
        if (substr($digits, 0, 2) === '62') {
            return $digits;
        }

        return '62' . $digits;
    }

    public static function message(Invoice $invoice): string
    {
        $customerName = $invoice->customer->name;
        $branchName = $invoice->branch->name;
        $total = number_format($invoice->grand_total, 0, ',', '.');
        $publicUrl = route('public-invoices.show', $invoice);

        return <<<TEXT
        Halo {$customerName},
        Berikut invoice dari {$branchName}.
        No. Invoice : {$invoice->number}
        Total : Rp {$total}
        Invoice dapat dilihat di:
        {$publicUrl}
        pin : {$invoice->pin}

        notes : pin hanya berlaku di invoice ini.
        Terima kasih.
        TEXT;
    }
}
```

(variabel lokal dipakai untuk interpolasi heredoc di atas, bukan pemanggilan method langsung di dalam
string — supaya tidak butuh `$this` di method statis.)

**Format nomor telepon:** data customer riil (`ProductionMasterDataSeeder`) berformat lokal Indonesia
dengan spasi, mis. `"0851 9955 8442"`. `wa.me` butuh format E.164 tanpa `+` dan tanpa spasi
(`6285199558442`). Aturan: buang semua karakter non-digit → kalau diawali `0`, ganti jadi `62`; kalau
sudah diawali `62`, biarkan; selain itu (nomor tanpa `0` di depan, jarang tapi mungkin), tambahkan prefix
`62`.

Class ini murni logic (format nomor, susun pesan, build URL) — tidak menyentuh HTTP/session, jadi bisa
di-unit-test langsung tanpa `RefreshDatabase`/HTTP request, cukup pasang `Invoice` + relasi
`customer`/`branch` in-memory atau lewat factory.

### 2.7 Halaman form PIN — layout minimal, reuse `layouts.guest`

View baru `resources/views/public/invoice-pin-form.blade.php`, `@extends('layouts.guest')` — layout yang
sama dipakai halaman login (sudah branded JMS MOTOR + logo, lihat commit `ac0988a`). Form sederhana:

```blade
@extends('layouts.guest')
@section('content')
    <div class="container">
        <div class="row justify-content-center align-items-center" style="min-height: 100vh;">
            <div class="col-12 col-sm-8 col-md-6 col-lg-4">
                <div class="card">
                    <div class="card-body p-4">
                        <h1 class="h5 mb-1 text-center">Invoice {{ $invoice->number }}</h1>
                        <p class="text-center mb-4" style="color: var(--color-ink-muted);">
                            Masukkan 6 digit PIN yang dikirimkan ke Anda.
                        </p>

                        @if ($errors->any())
                            <div class="alert alert-danger">{{ $errors->first() }}</div>
                        @endif

                        <form method="POST" action="{{ route('public-invoices.verify', $invoice) }}">
                            @csrf
                            <div class="mb-3">
                                <input type="text" name="pin" inputmode="numeric" pattern="\d{6}" maxlength="6"
                                    class="form-control form-control-lg text-center" autofocus required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Lihat Invoice</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
```

Tidak ada JS tambahan (no Select2/AJAX — form submit biasa, konsisten dengan form login yang jadi
acuannya).

## 3. Alur Data Ringkas

```
Staff buat/posting invoice → InvoiceService generate hash_id + pin (sekali, saat create)
Staff klik "Kirim via WhatsApp" di /invoices/{id} → wa.me terbuka, pesan sudah terisi link + PIN
Customer klik link → GET /i/{hash_id} → form PIN (atau langsung PDF kalau session masih ingat)
Customer input PIN → POST /i/{hash_id}/verify → cocok → session tersimpan → redirect ke PDF
                                                → salah → balik ke form, flash error
Customer lihat PDF → GET /i/{hash_id}/pdf → cek session + status invoice → stream InvoicePdfBuilder
```

## 4. Testing Strategy

**File baru:** `tests/Feature/PublicInvoiceTest.php`, `tests/Unit/WhatsAppInvoiceLinkBuilderTest.php`
(atau `tests/Feature/` kalau perlu `route()` helper yang butuh app context penuh — kemungkinan besar
`tests/Feature/` seperti kelas test lain di proyek ini, karena `route()` butuh named route terdaftar).

**`PublicInvoiceTest`:**
1. `showPinForm` — 200 + render form untuk invoice POSTED dengan `hash_id` valid.
2. `showPinForm` — 404 untuk `hash_id` yang tidak ada di DB.
3. `showPinForm` — 404 untuk invoice DRAFT/CANCELLED (walau `hash_id` ada — hanya relevan kalau §9.A
   dijawab "generate saat create", karena draft/cancelled tetap punya hash_id tapi tidak boleh diakses).
4. `showPinForm` — kalau session sudah terverifikasi, redirect langsung ke route `pdf` (bukan render form
   lagi).
5. `verifyPin` — PIN benar → redirect ke `pdf`, session ter-set.
6. `verifyPin` — PIN salah → redirect balik ke `show` dengan flash error, session TIDAK ter-set.
7. `verifyPin` — percobaan ke-11 dalam 1 menit dari IP yang sama → 429 (throttle).
8. `showPdf` — tanpa verifikasi session → redirect ke `show`, bukan stream PDF.
9. `showPdf` — dengan session terverifikasi → 200 + `content-type: application/pdf`, isi PDF sama dengan
   yang dihasilkan `InvoicePdfBuilder::build($invoice)` langsung (pakai `ExtractsPdfText` seperti test
   print/email yang sudah ada).
10. Seluruh route ini bisa diakses **tanpa** `actingAs()` — test eksplisit tanpa login untuk membuktikan
    memang publik.
11. `InvoiceService::createFromWorkOrder()`/`createDirectSale()` — assert `hash_id` (32 char, unique
    antar-invoice) dan `pin` (6 digit) terisi setelah invoice dibuat.
12. Tombol "Kirim via WhatsApp" muncul di `invoices/show.blade.php` untuk invoice POSTED dengan permission
    `invoice.share_whatsapp` DAN customer punya `phone`; tidak muncul untuk DRAFT, tanpa permission, atau
    customer tanpa `phone` (3 kasus `assertDontSee` terpisah).

**`WhatsAppInvoiceLinkBuilderTest`:**
1. `formatPhone` — `"0851 9955 8442"` → `"6285199558442"`.
2. `formatPhone` — sudah `"62..."` → tidak berubah (selain hapus spasi/simbol).
3. `formatPhone` — `null`/string kosong → `null`.
4. `message()` — assert setiap placeholder ({nama_customer}, {nama_bengkel}, {no_invoice}, {total},
   {public_url}, {pin}) muncul dengan nilai yang benar, format sesuai template di §2.6.
5. `build()` — `null` kalau `customer->phone` kosong; string diawali `https://wa.me/62...` kalau ada.

## 5. Manifest File

**Baru:**
- `database/migrations/2026_08_12_000001_add_pin_and_hash_id_to_invoices_table.php`
- `app/Http/Controllers/PublicInvoiceController.php`
- `app/Support/WhatsAppInvoiceLinkBuilder.php`
- `resources/views/public/invoice-pin-form.blade.php`
- `tests/Feature/PublicInvoiceTest.php`
- `tests/Feature/WhatsAppInvoiceLinkBuilderTest.php` (atau `tests/Unit/`, lihat §4)

**Diubah:**
- `app/Services/InvoiceService.php` (+`generatePublicAccessCredentials()`, dipanggil dari 2 method create)
- `app/Policies/InvoicePolicy.php` (+`shareWhatsapp()`)
- `database/seeders/MenuPermissionSeeder.php` (+`invoice.share_whatsapp`)
- `routes/web.php` (+3 route publik di luar grup `auth`)
- `resources/views/invoices/show.blade.php` (+1 tombol aksi)

## 6. Di Luar Scope

- **Tidak ada UI untuk regenerate/rotate PIN** — kalau PIN "bocor" (customer forward pesan ke orang lain),
  tidak ada cara staff memaksa PIN lama tidak berlaku lagi lewat UI. Bisa ditambah di milestone terpisah
  kalau dibutuhkan.
- **Tidak ada audit log** untuk aksi kirim WhatsApp / lihat invoice publik / percobaan PIN gagal —
  konsisten dengan `print`/`sendEmail` di milestone sebelumnya yang juga tidak di-audit-log.
- **Tidak ada rate-limit per-invoice** (hanya per-IP lewat `throttle:10,1`) — cukup untuk mitigasi dasar,
  bukan proteksi tingkat lanjut (captcha, dsb).
- **Tidak ada UI kirim WhatsApp otomatis via API resmi** (WhatsApp Business API) — `wa.me` deep link
  murni membuka aplikasi WhatsApp staff sendiri, staff yang menekan tombol "Kirim" di WhatsApp secara
  manual, sesuai requirement ("Direct Link").

## 7. Keputusan Terkonfirmasi

Tiga keputusan berikut sempat diajukan dengan rekomendasi default, dan sudah dikonfirmasi user — semua
tiga rekomendasi diterima apa adanya:

**A. Kapan `hash_id`/`pin` digenerate? → Saat invoice dibuat** (baik dari PKB maupun Direct Sales), satu
titik pemanggilan per alur di `InvoiceService`. Invoice DRAFT jadi sudah punya kredensial publik di DB,
tapi tetap tidak bisa diakses publik sampai statusnya POSTED+ (double-gate di §2.1, §2.3).

**B. Invoice lama dibackfill? → Tidak.** Invoice yang sudah ada sebelum migration ini tetap
`hash_id`/`pin` NULL selamanya (tidak ada data migration/seeder tambahan). `WhatsAppInvoiceLinkBuilder::build()`
(§2.6) eksplisit mengembalikan `null` kalau `hash_id`/`pin` kosong, sehingga tombol "Kirim via WhatsApp"
otomatis tersembunyi untuk invoice lama tersebut.

**C. Permission tombol WhatsApp? → Permission baru `invoice.share_whatsapp`**, ditambahkan ke
`MenuPermissionSeeder` sejajar `invoice.print`/`invoice.email` (§2.5). **Catatan operasional:** setelah
deploy, permission ini perlu di-assign manual ke user non-superadmin yang butuh akses tombol ini di
production (superadmin otomatis dapat semua permission lewat `DemoUsersSeeder`).
