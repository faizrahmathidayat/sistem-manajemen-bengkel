# Desain: Cetak Invoice (Preview PDF) & Kirim Invoice ke Email Customer

**Tanggal:** 2026-08-07
**Status:** Draft untuk direview

## 1. Latar Belakang & Tujuan

Halaman detail invoice (`/invoices/{id}`) saat ini (`resources/views/invoices/show.blade.php`,
`app/Http/Controllers/InvoiceController.php`) hanya menampilkan data dan aksi Ubah/Posting/Batalkan.
Milestone ini menambahkan dua aksi baru yang hanya berlaku untuk invoice yang **sudah diposting**
(status `posted`, `partially_paid`, atau `paid`):

1. **Cetak Invoice** — render PDF nota/faktur formal (`resources/views/invoices/print-pdf.blade.php`)
   dan buka di tab baru (preview, bukan attachment).
2. **Kirim Email** — kirim PDF invoice yang sama sebagai lampiran email ke `customer->email`,
   diproses lewat queue (asynchronous), bukan langsung saat request.

Dua permission code untuk fitur ini — `invoice.print` dan `invoice.email` — **sudah ada** di
`database/seeders/MenuPermissionSeeder.php` (baris 67-68) tapi belum pernah dipakai oleh kode manapun.
Milestone ini yang pertama kali memakainya.

## 2. Keputusan Arsitektur

### 2.1 Queue driver: `database` (bukan `sync`)

Requirement mewajibkan `InvoicePostedMail implements ShouldQueue` dan dikirim via
`Mail::to($email)->queue(...)`. Agar benar-benar asynchronous (bukan cuma implementasi interface tanpa efek
nyata), `QUEUE_CONNECTION` diubah dari `sync` menjadi `database`. Ini butuh:

- Migration baru `jobs` table (belum ada — baru `failed_jobs` yang sudah ada dari Laravel default).
  Dibuat via `php artisan queue:table` lalu `php artisan migrate`.
- **Operasional:** proses `php artisan queue:work` (atau `queue:listen` untuk dev) harus berjalan agar
  job benar-benar diproses. Ini adalah proses terpisah dari `php artisan serve` / dev server biasa —
  akan didokumentasikan di ringkasan akhir sebagai catatan operasional, bukan diotomatisasi
  (tidak ada supervisor/systemd setup dalam scope milestone ini).
- Di test suite: `Queue::fake()` / `Mail::fake()` dipakai sehingga job tidak benar-benar diproses saat
  `php artisan test` — jadi worker tidak perlu berjalan supaya test tetap hijau.

### 2.2 Orientasi PDF: Portrait A4

`print-pdf.blade.php` adalah dokumen nota/faktur mandiri (bukan `@extends('layouts.print')` milik
milestone laporan sebelumnya — itu didesain landscape untuk tabel data lebar, dan template ini butuh
tampilan formal ala nota toko). Tabel baris invoice hanya 6 kolom (Tipe, Kode, Deskripsi, Qty, Harga,
Total) sehingga muat nyaman di lebar Portrait A4.

### 2.3 PDF dibangun sekali, dipakai dua tempat

Preview PDF (controller) dan lampiran email (Mailable) butuh PDF binary yang identik. Untuk menghindari
duplikasi logic `Pdf::loadView(...)`, dibuat helper `App\Support\InvoicePdfBuilder`:

```php
namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Invoice;

class InvoicePdfBuilder
{
    public static function build(Invoice $invoice): \Barryvdh\DomPDF\PDF
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

Dipakai oleh:
- `InvoiceController::printPdf()` → `InvoicePdfBuilder::build($invoice)->stream(InvoicePdfBuilder::filename($invoice))`
- `InvoicePostedMail::build()` → `->attachData(InvoicePdfBuilder::build($this->invoice)->output(), InvoicePdfBuilder::filename($this->invoice))`

Ini stateless (static method, tidak butuh instance controller) — pola yang sama seperti
`InvoicePkbGapComparator::build()` di milestone sebelumnya, dipilih karena sudah terbukti bekerja baik
untuk kasus serupa (logic dipakai di dua tempat berbeda tanpa duplikasi).

### 2.4 Validasi status: policy, bukan cuma UI

`InvoicePolicy` dapat 2 method baru:

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

Controller memanggil `$this->authorize('print', $invoice)` / `$this->authorize('sendEmail', $invoice)` —
otomatis 403 untuk DRAFT/CANCELLED atau user tanpa permission, konsisten dengan pola `post`/`cancel`
yang sudah ada. UI (`@can('print', $invoice)` / `@can('sendEmail', $invoice)`) memakai policy yang sama
sehingga tidak ada logic status-check yang terduplikasi antara Blade dan Controller.

## 3. Routes

Ditambahkan ke grup `Route::prefix('invoices')->name('invoices.')` yang sudah ada di `routes/web.php`:

```php
Route::get('/{invoice}/print', [InvoiceController::class, 'printPdf'])->name('print');
Route::post('/{invoice}/send-email', [InvoiceController::class, 'sendEmail'])->name('send-email');
```

→ `invoices.print` (GET, stream inline, dibuka `target="_blank"` dari UI) dan `invoices.send-email`
(POST, form submit biasa + redirect back dengan flash message — bukan AJAX, konsisten dengan pola
posting/cancel invoice yang sudah ada di halaman ini).

## 4. Alur Kirim Email

```php
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

`App\Mail\InvoicePostedMail`:

```php
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

`SerializesModels` men-serialize `$invoice` sebagai reference (id) saat job disimpan ke tabel `jobs`,
lalu di-reload utuh dari DB saat job diproses worker — aman dipakai lintas request/proses.

Body email (`resources/views/emails/invoice-posted.blade.php`) — HTML sederhana (bukan Markdown
mailable, konsisten dengan gaya PDF template yang sudah full-HTML/CSS inline):

```
Salam {customer.name},

Terlampir invoice {number} dari {branch.name} sebesar Rp {grand_total} tertanggal {invoice_date}.
Sisa tagihan (jika ada): Rp {outstanding_amount}.

Terima kasih.
```

## 5. Perubahan `.env` dan `.env.example`

```
MAIL_MAILER=smtp
MAIL_HOST="sandbox.smtp.mailtrap.io"
MAIL_PORT=465
MAIL_USERNAME="ce7b7321166adf"
MAIL_PASSWORD="4f849ef5303417"
MAIL_ENCRYPTION="tls"
MAIL_FROM_ADDRESS="no-reply@bengkel.com"
MAIL_FROM_NAME="${APP_NAME}"

QUEUE_CONNECTION=database
```

Kredensial Mailtrap ditulis apa adanya ke `.env` (lingkungan lokal, bukan production, dan `.env` sudah
ter-`.gitignore`). `.env.example` mendapat baris yang sama **kecuali** `MAIL_USERNAME`/`MAIL_PASSWORD`
diisi placeholder (`your-mailtrap-username` / `your-mailtrap-password`) — kredensial asli tidak masuk
`.env.example` karena file itu di-commit ke git, agar tidak membocorkan kredensial testing ke riwayat repo.

**Catatan:** port 465 dengan `MAIL_ENCRYPTION=tls` sedikit tidak umum (465 biasanya dipasangkan dengan
`ssl`, `tls` biasanya port 587) — tapi ini nilai yang diberikan Mailtrap sandbox untuk kredensial ini,
dipakai apa adanya sesuai instruksi.

## 6. Perubahan UI (`resources/views/invoices/show.blade.php`)

Di header aksi (baris 6-19 saat ini, sejajar dengan tombol Ubah/Posting):

```php
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

`@can` otomatis menyembunyikan tombol untuk status DRAFT/CANCELLED atau user tanpa permission — tidak
ada logic status tambahan di Blade (memakai `InvoicePolicy` yang sama dengan controller, lihat §2.4).

Tidak ada JS konfirmasi tambahan untuk tombol "Kirim Email" (klik langsung submit) — konsisten dengan
tombol "Posting" yang sudah ada di halaman yang sama, yang juga submit langsung tanpa modal konfirmasi.

## 7. Template PDF (`resources/views/invoices/print-pdf.blade.php`)

Dokumen HTML mandiri (bukan `@extends` layout laporan), gaya nota/faktur formal:

- **Header:** nama & alamat/telepon cabang (`$invoice->branch`), judul "INVOICE" / "FAKTUR".
- **Info invoice:** No. Invoice, Tanggal, Jatuh Tempo, No. PKB (`$invoice->workOrder->number`).
- **Info customer & kendaraan:** Nama customer, alamat, No. Polisi, Merk/Tipe kendaraan
  (`$invoice->workOrder->vehicle`).
- **Tabel baris invoice:** Tipe (Jasa/Sparepart), Kode, Deskripsi, Qty, Harga Satuan, Total — sama
  dengan kolom yang sudah ditampilkan di `show.blade.php`.
- **Ringkasan:** Subtotal Jasa, Subtotal Sparepart, Diskon, PPN, Grand Total, Sudah Dibayar, Sisa
  Piutang — sama dengan §Ringkasan di `show.blade.php`.
- **Riwayat pembayaran:** tabel kecil No. Pembayaran / Tanggal / Nominal (kosong jika belum ada
  pembayaran — tanpa baris "belum ada pembayaran" di PDF, cukup skip section jika collection kosong).
- **Footer/tanda tangan:** dua kolom tanda tangan ("Hormat kami," / "Penerima") dengan area kosong untuk
  tanda tangan fisik, khas nota kertas.

## 8. Testing Strategy

**File baru:** `tests/Feature/InvoicePrintEmailTest.php`, memakai helper `makeWorkOrder()` +
`grantBranchPermission()` yang sudah ada polanya di `InvoiceControllerTest.php`, lalu invoice diposting
via `InvoiceService::postInvoice()` (langsung service call, bukan HTTP, agar setup test lebih ringkas —
sudah ada di kelas ini via `InvoiceService`).

Kasus yang dicakup:
1. `print` mengembalikan 200 + `content-type: application/pdf` untuk invoice POSTED.
2. `print` mengembalikan 403 untuk invoice DRAFT.
3. `print` mengembalikan 403 untuk invoice CANCELLED.
4. `print` mengembalikan 403 tanpa permission `invoice.print`.
5. Isi PDF memuat No. Invoice, nama customer, No. Polisi (pakai `ExtractsPdfText` yang sudah ada dari
   milestone laporan — `tests/Concerns/ExtractsPdfText.php`).
6. `send-email` dengan customer yang punya email → `Mail::fake()` + assert
   `Mail::assertQueued(InvoicePostedMail::class, fn ($mail) => $mail->hasTo($email))`, dan flash
   `status` berisi email tersebut.
7. `send-email` dengan customer tanpa email → flash `error`, `Mail::assertNothingQueued()`.
8. `send-email` untuk invoice DRAFT/CANCELLED → 403.
9. `send-email` tanpa permission `invoice.email` → 403.
10. Tombol "Cetak Invoice"/"Kirim Email" muncul di `show.blade.php` untuk invoice POSTED dengan
    permission, dan tidak muncul untuk invoice DRAFT (assert `assertDontSee`).
11. `InvoicePostedMail::build()` — `Mail::fake()` tidak merender Mailable sungguhan sehingga tidak bisa
    dipakai untuk cek attachment, dan Mailable Laravel 8.75 di proyek ini **tidak** punya method
    `assertHasAttachment()` (sudah dicek langsung ke `vendor/laravel/framework/.../Mailable.php` — hanya
    tersedia `assertSeeInHtml`/`assertDontSeeInHtml`/`assertSeeInText`/`assertDontSeeInText`). Sebagai
    gantinya, test memanggil `(new InvoicePostedMail($invoice))->build()` secara langsung lalu memeriksa
    property public `$rawAttachments` (diisi oleh `attachData()`):
    ```php
    $mailable = (new InvoicePostedMail($invoice))->build();
    $this->assertCount(1, $mailable->rawAttachments);
    $this->assertSame('invoice-' . $invoice->number . '.pdf', $mailable->rawAttachments[0]['name']);
    $this->assertSame('application/pdf', $mailable->rawAttachments[0]['options']['mime']);
    $mailable->assertSeeInHtml($invoice->number);
    ```

Semua test memakai `Mail::fake()` — job Mailable tidak benar-benar dieksekusi/diproses queue saat
`php artisan test`, jadi tidak butuh worker berjalan maupun koneksi SMTP asli.

## 9. Manifest File

**Baru:**
- `app/Support/InvoicePdfBuilder.php`
- `app/Mail/InvoicePostedMail.php`
- `resources/views/invoices/print-pdf.blade.php`
- `resources/views/emails/invoice-posted.blade.php`
- `database/migrations/xxxx_xx_xx_create_jobs_table.php` (via `php artisan queue:table`)
- `tests/Feature/InvoicePrintEmailTest.php`

**Diubah:**
- `app/Http/Controllers/InvoiceController.php` (+`printPdf()`, +`sendEmail()`)
- `app/Policies/InvoicePolicy.php` (+`print()`, +`sendEmail()`)
- `routes/web.php` (+2 route di grup `invoices`)
- `resources/views/invoices/show.blade.php` (+2 tombol aksi)
- `.env`, `.env.example` (SMTP Mailtrap + `QUEUE_CONNECTION=database`)

## 10. Di Luar Scope

- Tidak ada halaman riwayat "email terkirim" / log pengiriman — cukup flash message instan bahwa email
  masuk antrean (sesuai requirement).
- Tidak ada retry-UI manual jika job gagal — mengandalkan mekanisme retry default Laravel queue
  (`failed_jobs` table yang sudah ada menampung job yang gagal permanen).
- Tidak ada supervisor/systemd config untuk menjalankan `queue:work` otomatis di production — di luar
  scope milestone ini (hanya kode aplikasi + dokumentasi catatan operasional).
- Tidak ada tombol "Download PDF" terpisah untuk invoice (beda dari milestone laporan) — hanya Preview
  (stream inline) sesuai requirement eksplisit di §2 permintaan Anda.
