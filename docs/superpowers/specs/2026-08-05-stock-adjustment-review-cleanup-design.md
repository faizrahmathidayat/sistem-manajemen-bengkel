# Stock Adjustment — Deferred Minor Findings Cleanup — Design Spec

**Tanggal:** 2026-08-05
**Status:** Disetujui pengguna, siap masuk tahap implementation plan.

## Konteks

4 catatan Minor dari final whole-branch review migrasi 008b (Stock Adjustment, merged `ef9e6e5`) yang sebelumnya sengaja ditunda (tidak memblokir merge) — pengguna meminta keempatnya diperbaiki sebelum melanjutkan ke 008c (Transfer Stock).

## 1. Konvensi error-flash baru (project-wide)

Saat ini `resources/views/layouts/app.blade.php` hanya punya satu blok flash message:
```blade
@if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
```
Tidak ada padanan untuk pesan error — sehingga pesan penolakan `post()` (misal "Tidak bisa memposting: ... sedang direservasi ...") ikut tampil hijau seolah sukses.

**Perbaikan:** tambah blok kedua di layout yang sama, merender `session('error')` sebagai `alert-danger`:
```blade
@if (session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif
```
`StockAdjustmentController::post()` — HANYA baris yang memflash pesan penolakan reservasi (baris rejection message saat `$reservationViolations` tidak kosong) diubah dari `->with('status', $message)` menjadi `->with('error', $message)`. Pesan sukses (`'Stock adjustment berhasil diposting.'`) dan pesan "sudah tidak dalam status X" (lost-race no-op) TETAP pakai `status` — ini bukan error pengguna, hanya info bahwa aksi tidak dilakukan.

**Scope eksplisit:** konvensi baru ini HANYA dipakai oleh pesan penolakan reservasi di Stock Adjustment. Modul lain (PKB, Goods Receipt) TIDAK di-retrofit untuk memakainya — di luar permintaan yang disetujui.

## 2. `update()` — hoisted-flag untuk pesan akurat

`StockAdjustmentController::update()` saat ini (baris 136-156) tidak punya flag seperti `submit()`/`approve()`/`post()`/`cancel()` — kalau recheck status di dalam transaksi menemukan dokumen sudah bukan `DRAFT` lagi (lost race), method tetap flash "Stock adjustment berhasil diperbarui." padahal tidak ada yang benar-benar diupdate.

**Perbaikan:** terapkan pola yang sama persis dengan 4 method lain — hoist `$noLongerDraft = false;` sebelum transaksi, set `true` di dalam recheck-gagal, lalu branch pesan setelah transaksi:
```php
if ($noLongerDraft) {
    return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment ini sudah tidak dalam status draft.');
}
```
(Pesan ini identik dengan yang sudah dipakai `submit()` untuk kasus yang sama — reuse teks yang sudah ada, bukan bikin baru.)

## 3. Test badge diperketat

`test_show_renders_status_badge_and_approval_info_when_approved` (baris 336-353) saat ini `assertSee('Disetujui')` — teks ini juga muncul sebagai label statis lain di halaman (bukan cuma dari badge), jadi test ini tidak benar-benar membuktikan partial `_status_badge` ter-render.

**Perbaikan:** ganti assertion itu jadi mengecek fragment HTML badge secara utuh:
```php
$response->assertSee('<span class="status-dot status-active">Disetujui</span>', false);
```
Assertion `assertSee('Budi Approver')` tetap dipertahankan apa adanya.

## 4. Partial badge — `@else` jadi eksplisit

`resources/views/stock-adjustments/_status_badge.blade.php` saat ini punya `@else` sebagai catch-all untuk `CANCELLED` — kalau suatu saat status ke-6 ditambahkan tanpa partial ini ikut diupdate, dia akan diam-diam dilabeli "Dibatalkan".

**Perbaikan:** ganti jadi eksplisit + tambah fallback "tidak dikenal" yang jelas:
```blade
@if ($status === \App\Support\StockAdjustmentStatus::DRAFT)
    <span class="status-dot status-active">Draft</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::PENDING_APPROVAL)
    <span class="status-dot status-active">Diajukan</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::APPROVED)
    <span class="status-dot status-active">Disetujui</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::POSTED)
    <span class="status-dot status-active">Diposting</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::CANCELLED)
    <span class="status-dot status-inactive">Dibatalkan</span>
@else
    <span class="status-dot status-inactive">Status tidak dikenal</span>
@endif
```
Bukan exception — 1 baris yang salah tidak boleh merusak seluruh halaman list. Tapi juga tidak diam-diam mengklaim "Dibatalkan" untuk status yang sebenarnya tidak dikenal.

## Testing & Eksekusi

Test baru/diubah:
- `test_layout_renders_error_flash_as_a_danger_alert` (baru) — assert `session('error')` di-render sebagai `alert-danger`, dan `session('status')` tetap `alert-success` (regression guard supaya blok baru tidak menggantikan blok lama).
- `test_post_rejection_message_uses_the_error_flash_key` (baru, atau extend test reservasi yang sudah ada) — assert respons dari `post()` yang ditolak karena reservasi punya `session('error')` terisi, BUKAN `session('status')`.
- `test_update_second_call_with_a_stale_in_memory_status_flashes_an_accurate_message` (baru) — mirror pola test yang sudah ada untuk `submit()`'s stale-status case, tapi untuk `update()`.
- `test_show_renders_status_badge_and_approval_info_when_approved` (diperbaiki, bukan baru) — assertion diperketat.
- Tidak perlu test baru untuk item 4 (partial badge) — perilaku 5 status yang valid tidak berubah, sudah dicakup `test_index_renders_all_five_status_badges_correctly`. Fallback "tidak dikenal" tidak reachable dari kode manapun saat ini (tidak ada status ke-6), jadi tidak diberi test — akan jadi jaring pengaman kalau modul masa depan menambah status baru tanpa update partial ini.

**Rencana task**: 1 task tunggal (semua 4 item cukup kecil dan saling terkait — sama-sama di area "polish pasca-review" — untuk digabung, tidak perlu dipecah).

**Eksekusi**: mengingat ukurannya kecil dan tidak menyentuh logika locking/concurrency, `subagent-driven-development` tetap dipakai untuk konsistensi proses, tapi hanya 1 task + 1 review (tanpa fix-loop yang diantisipasi).

## Self-Review

- **Placeholder scan**: tidak ada.
- **Konsistensi**: pola hoisted-flag di item 2 identik dengan yang sudah dipakai di `submit()`/`approve()`/`post()`/`cancel()`. Pola error-flash di item 1 paralel dengan pola `status`/`alert-success` yang sudah ada.
- **Scope**: 4 item sesuai persis dengan yang dipilih pengguna, tidak lebih.
