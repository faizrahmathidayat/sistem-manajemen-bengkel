# Redesign UI Total (Modern/Elegan/Sleek) — Dokumen Spesifikasi Desain

## 1. Latar Belakang & Tujuan

Tampilan JMS MOTOR saat ini sudah punya fondasi design-token yang cukup rapi
(`resources/views/partials/design-tokens.blade.php`, satu file CSS terpusat yang
di-`@include` oleh `layouts/app.blade.php` dan `layouts/guest.blade.php`), tapi:

- Tidak ada mode gelap.
- Feedback loading hanya ada di halaman/aksi tertentu (dashboard, kirim email
  invoice) — tidak konsisten di seluruh aplikasi.
- Navbar cukup padat (badge permission bisa sampai 4 item + nama + logout
  berjejer langsung di topbar).
- Sidebar & card sudah cukup modern tapi bisa dinaikkan lagi levelnya (radius,
  active-indicator, badge ikon).
- Baris dinamis di PKB/Invoice (`_line_item_scripts.blade.php`) muncul instan
  tanpa transisi.

Tujuan milestone ini: redesign UI total agar tampil modern/elegan/sleek,
menambahkan theme switcher Light/Dark yang persisten, dan menambahkan global
loading feedback — **tanpa framework JS baru, tanpa build-step baru**, murni
memperluas pola yang sudah ada di codebase.

## 2. Hasil Eksplorasi Codebase

- **Layout:** `layouts/app.blade.php` (shell utama, sudah punya navbar +
  offcanvas sidebar), `layouts/guest.blade.php` (halaman login, minimal).
- **Design tokens:** `partials/design-tokens.blade.php` — satu blok
  `<style>` inline berisi CSS custom properties (`--color-bg`,
  `--color-surface`, `--color-ink`, dst) + semua styling komponen (topbar,
  sidebar, card, table, stat-card, status-dot, tabs, accordion). **Ini satu
  satunya sumber styling di seluruh aplikasi** — tidak ada Mix/Vite yang
  benar-benar di-`@include` di Blade manapun (`webpack.mix.js` ada tapi
  `public/js/app.js` / `public/css/app.css` tidak pernah dipakai). Artinya
  perubahan di file ini otomatis berlaku ke **semua** halaman yang extends
  `layouts.app`.
- **Sidebar:** `partials/sidebar.blade.php` — 5 grup menu
  (Dashboard, Operasional, Persediaan, Master Data, Administrasi, Reporting)
  dengan heading `.sidebar-heading` dan link `.nav-link` yang sudah punya
  state `.active` (gradient + glow, lihat baris 100-105 design-tokens).
  Selalu berlatar gelap (`--color-sidebar: #0F172A`) baik nanti mode
  terang/gelap dipilih atau tidak.
  Struktur `@can`/`branchesWithPermission` per grup **tidak diubah** — murni
  visual.
- **Navbar (`app.blade.php` baris 13-49):** brand logo (sudah JMS MOTOR +
  `images/logo.png`), lalu di kanan: sampai 3 badge kode permission +
  "+N lainnya", nama user, tombol logout — semua sejajar langsung di topbar,
  tidak ada dropdown.
- **Overlay loading yang sudah ada (2 pola berbeda, tidak konsisten):**
  1. `dashboard/index.blade.php` — `.dashboard-loading-parent` /
     `.dashboard-loading-overlay` (absolute, per-section, dipicu manual oleh
     `fetch()` AJAX di dashboard saja).
  2. `invoices/show.blade.php` — `.page-loading-overlay` (fixed, full-page,
     ditambahkan di commit `940a539` khusus untuk form Kirim Email, dipicu
     oleh listener `submit` manual per halaman).
- **Baris dinamis:** `work-orders/_line_item_scripts.blade.php` dan
  `invoices/_line_item_scripts.blade.php` — pola identik: `<template>` +
  `cloneNode` + `appendChild` ke container (`#invoiceServiceLines`,
  `#invoiceSparepartLines`, dan padanannya di work-orders). Baris baru
  langsung muncul tanpa transisi apa pun.
- **Stack JS:** vanilla JS (IIFE) + jQuery **hanya** untuk Select2
  (`public/js/select2-ajax-picker.js`). Tidak ada framework SPA.
- **Bootstrap:** sudah `5.3.3` via CDN di kedua layout — cukup baru untuk
  native dark mode (`data-bs-theme` attribute), tidak perlu upgrade.

## 3. Keputusan Arsitektur

### 3.1 Dynamic Light/Dark Theme Switcher

- **Mekanisme:** native Bootstrap 5.3 `data-bs-theme="light|dark"` di elemen
  `<html>`. Semua override warna dark-mode ditulis sebagai CSS custom
  property baru di dalam selector `html[data-bs-theme="dark"]` pada
  `design-tokens.blade.php` — token yang sama (`--color-bg`,
  `--color-surface`, dst) di-redefine, komponen di bawahnya (card, table,
  topbar, dst) **tidak perlu diubah** karena sudah memakai `var(--color-*)`.
- **Anti-FOUC:** skrip inline kecil diletakkan di `<head>`, **sebelum**
  `design-tokens.blade.php` di-include, yang langsung membaca
  `localStorage.getItem('theme')` dan set `data-bs-theme` di `<html>` secara
  synchronous — supaya tidak ada kedipan tema salah saat halaman pertama kali
  render.
- **Toggle:** tombol ikon bulan/matahari (`bi-moon-stars` / `bi-brightness-high`)
  di navbar kanan, dengan `title`/`data-bs-toggle="tooltip"` "Mode Gelap" /
  "Mode Terang" (mengikuti tema aktif saat ini). Klik → toggle
  `data-bs-theme` di `<html>`, simpan ke `localStorage.theme`, ganti ikon
  & tooltip.
- **Default saat localStorage kosong (kunjungan pertama):** **Light**
  (bukan mengikuti `prefers-color-scheme` OS). Alasan: ini aplikasi kerja
  shift/shared-computer di bengkel, defaultnya harus dapat diprediksi staf
  dan tidak berubah-ubah tergantung setting OS komputer yang dipakai
  bergantian. → lihat §7 untuk konfirmasi.
- **Palet warna:**

  | Token | Light | Dark |
  |---|---|---|
  | `--color-bg` | `#F8FAFC` | `#0B0F17` |
  | `--color-surface` (card) | `#FFFFFF` | `#1E293B` |
  | `--color-border` | `#E2E8F0` | `#334155` (transparan via `rgba`) |
  | `--color-ink` | `#0F172A` | `#F1F5F9` |
  | `--color-ink-muted` | `#64748B` | `#94A3B8` |
  | `--color-accent` | `#2563EB` (unchanged) | `#3B82F6` (sedikit lebih terang agar kontras di background gelap) |
  | `--color-success` / `--color-danger` / `--color-warning` | unchanged | unchanged (sudah cukup kontras di kedua tema) |
  | shadow card | `0 4px 20px rgba(0,0,0,.03)` | `0 4px 20px rgba(0,0,0,.35)` |

- **Sidebar:** **tetap gelap permanen di kedua tema** (tidak ikut toggle) —
  pola umum admin dashboard ("dark sidebar, adaptive content"), dan sudah
  jadi identitas visual sidebar saat ini. Di mode gelap, warna sidebar
  disamakan dengan `--color-surface` dark (`#1E293B`) supaya menyatu dengan
  card, bukan malah kontras. → lihat §7 untuk konfirmasi.

### 3.2 Global Page Loading Overlay

- **Konsolidasi:** overlay `.page-loading-overlay` yang sudah dibuat khusus
  untuk form Kirim Email (`invoices/show.blade.php`, commit `940a539`)
  **dipindahkan menjadi mekanisme global** di `layouts/app.blade.php`, lalu
  kode khusus per-halaman di `invoices/show.blade.php` (overlay div + script
  inline) **dihapus** — digantikan otomatis oleh mekanisme global yang sama
  persis secara visual (spinner + teks), tinggal teksnya digeneralisasi jadi
  "Memuat...". Ini menghindari dua sumber kebenaran untuk hal yang sama.
  → lihat §7 untuk konfirmasi.
- **Style:** class `.page-loading-overlay` yang sudah ada dipertahankan
  strukturnya (`position: fixed; inset: 0; z-index: 2000;`), ditambah efek
  `backdrop-filter: blur(5px)` sesuai permintaan, dan spinner diganti jadi
  varian "neon modern" (border-based spinner dengan warna accent + glow
  `box-shadow`, bukan `spinner-border` Bootstrap polos) supaya terasa sebagai
  micro-interaction baru, bukan sekadar spinner default.
- **Pemicu (event delegation di `app.blade.php`, satu listener global):**
  - **Klik link `<a>` di dalam `.app-body`** (konten halaman + sidebar) yang:
    punya `href` (bukan `#` / kosong), **tanpa** `target="_blank"`, **tanpa**
    atribut `download`, dan bukan `href="javascript:..."`. Link tab-baru,
    anchor in-page, dan tombol offcanvas (`data-bs-toggle`) otomatis
    terkecuali karena tidak match kriteria di atas / bukan navigasi halaman
    penuh.
  - **Submit form apa pun** di dalam `.app-body` (method GET atau POST),
    kecuali form yang secara eksplisit diberi atribut penanda
    `data-no-loading` (escape hatch untuk kasus form yang sudah punya
    penanganan sendiri, atau form AJAX seperti filter dashboard — dashboard
    sendiri sudah tidak submit form biasa jadi tidak kena, tapi flag ini
    disediakan untuk jaga-jaga).
  - Overlay **tidak** dipasang untuk request `fetch()` AJAX (dashboard KPI
    filter) — itu tetap pakai overlay section-nya sendiri
    (`.dashboard-loading-overlay`), tidak diubah, karena sifatnya partial
    update bukan navigasi halaman penuh.
  - Overlay otomatis hilang dengan sendirinya karena halaman berikutnya
    (hasil redirect/render baru) tidak membawa elemen overlay dalam kondisi
    tampil — tidak perlu logic "hide" eksplisit, sama seperti pola yang
    sudah dipakai di `940a539`.

### 3.3 Navbar Redesign

- Badge kode permission (`topbar-permission-badge`) dipindahkan **dari**
  langsung terlihat di topbar **ke dalam dropdown profil** (supaya topbar
  tidak padat) — trigger dropdown adalah avatar-inisial + nama user.
  Dropdown berisi: daftar penuh kode permission user (bukan cuma 3 pertama +
  "+N lainnya" — karena sekarang di dalam dropdown, tidak perlu dipotong),
  lalu divider, lalu tombol Logout (form yang sama, dipindah ke dalam
  dropdown-item).
- Elemen baru di navbar kanan (urutan kiri→kanan sebelum dropdown profil):
  teks Hari/Tanggal berjalan (mis. "Sabtu, 15 Agustus 2026", dihitung dari
  server via Carbon, bukan JS `Date()`, supaya konsisten dengan timezone
  aplikasi), lalu tombol Theme Switcher.
- Tombol toggle sidebar (offcanvas, mobile) tetap di posisi kiri seperti
  sekarang, tidak berubah.

### 3.4 Component Cards & Tables Redesign

- `.card`: border-radius dinaikkan dari `1rem` → `1rem` tetap tapi ditegaskan
  16px sesuai spesifikasi (`1rem` Bootstrap = 16px, sudah sesuai — tidak perlu
  diubah nilainya, cukup dipastikan konsisten di dark mode via shadow token
  di atas).
- `.stat-icon` (icon di stat-card dashboard) dibungkus badge lingkaran
  berwarna pastel-transparan per konteks (accent/success/warning), bukan
  cuma teks ikon polos berwarna `--color-accent` seperti sekarang — pakai
  `background: color-mix(in srgb, var(--color-accent) 12%, transparent)`
  (teknik `color-mix` yang sama persis dengan yang sudah dipakai di
  `.accordion-button:not(.collapsed)`, jadi konsisten dengan pola existing).
- Tabel (`.table thead th`) tidak berubah signifikan — sudah cukup modern
  (uppercase, letter-spacing, background soft) — hanya dipastikan versi
  dark-mode-nya kontras (background header pakai `--color-bg` bukan hardcode
  `#F8FAFC`).

### 3.5 Dynamic Table Row Animation

- CSS keyframe baru `@keyframes rowFadeSlideIn` (opacity 0→1 + translateY
  -8px→0, durasi ~200ms) di `design-tokens.blade.php`, di-attach lewat class
  `.line-row-enter` yang ditambahkan ke elemen baris.
- **File yang disentuh:** `work-orders/_line_item_scripts.blade.php` dan
  `invoices/_line_item_scripts.blade.php` — di titik `appendChild(wrapper)`
  untuk setiap fungsi `addServiceLine`/`addSparepartLine` (kedua file),
  tambahkan `wrapper.classList.add('line-row-enter')` sebelum
  `appendChild`. Tidak menyentuh logic bisnis line item sama sekali (qty,
  price, select2, dsb) — murni tambahan 1 baris per fungsi.

## 4. Detail File yang Dibuat/Diubah

| File | Perubahan |
|---|---|
| `resources/views/partials/design-tokens.blade.php` | + dark-mode token block, + palet warna baru, + global overlay style (blur, neon spinner), + `.line-row-enter` keyframe, + style dropdown profil/badge/theme-toggle/date |
| `resources/views/layouts/app.blade.php` | + inline anti-FOUC script di `<head>`, navbar direstruktur (date + theme toggle + dropdown profil menggantikan badge-inline lama), + global overlay markup, + 1 script global (theme toggle handler + link/submit listener) |
| `resources/views/partials/sidebar.blade.php` | Tidak ada perubahan struktural (permission gating utuh) — hanya kemungkinan penyesuaian kecil markup jika diperlukan untuk active-indicator (dicek ulang saat implementasi, style-only) |
| `resources/views/invoices/show.blade.php` | **Hapus** blok `.page-loading-overlay` + script `sendEmailForm` khusus (baris 165-179) — digantikan mekanisme global |
| `resources/views/work-orders/_line_item_scripts.blade.php` | + `classList.add('line-row-enter')` di titik append (2 fungsi) |
| `resources/views/invoices/_line_item_scripts.blade.php` | + `classList.add('line-row-enter')` di titik append (2 fungsi) |
| `tests/Feature/InvoicePrintEmailTest.php` | 2 test overlay lama (`test_show_page_includes_send_email_loading_overlay_and_script_when_permitted`, `test_show_page_hides_send_email_loading_overlay_without_email_permission`) diupdate — assersi dipindah ke level layout global (overlay markup tidak lagi spesifik ke `invoices/show.blade.php`) |
| Test baru | Feature test ringan untuk halaman yang di-`extends('layouts.app')` (mis. dashboard) memastikan markup theme-toggle, overlay global, dan anti-FOUC script selalu ada di response |

## 5. Alur Data Ringkas

Tidak ada perubahan pada database, model, controller, atau route. Ini murni
perubahan presentation-layer (Blade + CSS + vanilla JS). Tidak ada migrasi
baru, tidak ada permission baru.

## 6. Testing Strategy

Karena ini murni perubahan UI (tidak ada logic bisnis/PHP baru selain markup
Blade), strategi testing:

- **Feature test (assert-response-body):** memastikan markup krusial selalu
  ada di halaman yang pakai `layouts.app` — anti-FOUC script tag, tombol
  theme-toggle, elemen overlay global, dropdown profil (bukan lagi badge
  inline) — dengan pola `assertSee(..., false)` seperti test yang sudah ada.
- **Regression test:** test yang sudah ada dan menyentuh markup lama
  (`InvoicePrintEmailTest` 2 test overlay) diupdate supaya tetap hijau
  dengan struktur baru — bukan dihapus, karena masih memvalidasi perilaku
  yang sama (overlay muncul saat permission ada, tidak muncul saat tidak
  ada) hanya sumbernya kini global bukan per-halaman.
- **Verifikasi manual browser (wajib sebelum tuntas, sesuai konvensi
  proyek):** toggle dark/light + reload (persistensi), klik link → overlay
  muncul lalu hilang di halaman baru, submit form (mis. cancel invoice) →
  overlay muncul, tambah baris service/sparepart di PKB & Invoice → animasi
  fade-in terlihat, cek responsif di mobile width (sidebar offcanvas +
  dropdown profil tidak pecah layout).

## 7. Keputusan Perlu Konfirmasi

1. **Default tema saat kunjungan pertama (belum ada di localStorage):**
   selalu **Light** (predictable untuk shared-computer bengkel) — bukan
   mengikuti preferensi OS (`prefers-color-scheme`).
2. **Sidebar tetap gelap permanen** di kedua tema (tidak ikut toggle
   Light/Dark) — hanya warnanya disesuaikan sedikit agar menyatu dengan
   card di mode gelap.
3. **Overlay Kirim Email lama dikonsolidasi ke overlay global** — kode
   khusus per-halaman di `invoices/show.blade.php` (dari commit `940a539`)
   dihapus, digantikan mekanisme global yang otomatis berlaku ke seluruh
   aplikasi.
4. **Badge permission dipindah total ke dalam dropdown profil** (tidak lagi
   tampil sebagian langsung di topbar) — daftar lengkap, tidak dipotong ke
   3 item pertama lagi.

## 8. Di Luar Scope

- Tidak ada perubahan pada `layouts/print.blade.php` (halaman cetak
  PDF/invoice) — halaman cetak sengaja polos untuk output PDF, tidak
  relevan dengan theme switcher/overlay.
- Tidak ada perubahan pada `layouts/guest.blade.php` **struktural** selain
  memastikan anti-FOUC script & dark-mode token juga aktif di sana (supaya
  halaman login konsisten jika user sempat set preferensi dark sebelum
  logout) — tapi **tanpa** toggle button di halaman login (belum ada navbar
  di sana, di luar scope menambah satu).
- Tidak menambah dependency/library JS baru (tidak ada Alpine.js, tidak ada
  animasi library seperti AOS) — animasi baris & spinner cukup pakai CSS
  keyframe murni.
- Tidak mengubah Mix/Vite build pipeline — tetap 100% inline
  `<style>`/`<script>` mengikuti konvensi yang sudah ada di codebase ini.
- Tidak mengubah struktur permission/menu sidebar (grup & item yang tampil
  per-permission tetap identik, murni visual).
