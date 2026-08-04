# Sub-Proyek 008b — Stock Adjustment (Penyesuaian Stok) Design Spec

**Tanggal:** 2026-08-04
**Status:** Disetujui pengguna, siap masuk tahap implementation plan.

## Konteks

Bagian kedua dari dekomposisi migrasi 008 (008a Goods Receipt → **008b Stock Adjustment** → 008c Transfer Stock, sesuai keputusan dekomposisi yang sama dengan pola migrasi 006→007). Modul ini adalah **konsumen kedua** dari ledger `inventory_movements` dan penulis kedua ke `sparepart_branch_stocks.on_hand_qty`, keduanya dibangun di 008a.

Dokumen sumber migrasi (`Rencana_Migrasi_Database_Sistem_Bengkel.md` §11.2) mendefinisikan `stock_adjustments`/`stock_adjustment_lines` dengan status `DRAFT, PENDING_APPROVAL, POSTED, CANCELLED` (4 status). **Modul ini sengaja menyimpang dari dokumen sumber** dengan menambah status `APPROVED` sebagai tahap terpisah antara persetujuan dan posting (5 status total) — keputusan eksplisit pengguna, karena permission `stock_adjustment.approve` dan `stock_adjustment.post` sudah di-seed sebagai 2 kode berbeda di `MenuPermissionSeeder`, mengindikasikan keduanya memang dimaksudkan sebagai 2 aksi/tahap terpisah, bukan satu aksi gabungan.

## 1. Arsitektur & Alur Status

```
DRAFT --submit()--> PENDING_APPROVAL --approve()--> APPROVED --post()--> POSTED
  |                        |                            |
  +----------cancel()------+------------cancel()---------+
                            |
                            v
                        CANCELLED (final)
```

- **DRAFT**: dapat diedit bebas (tambah/hapus/ubah baris). Dibuat & diedit via `stock_adjustment.create` (tidak ada kode `.edit` terpisah, mengikuti presenden Goods Receipt).
- **PENDING_APPROVAL**: menunggu persetujuan, tidak dapat diedit lagi.
- **APPROVED**: sudah disetujui (`approved_by`/`approved_at` tercatat), menunggu posting. Stok **belum** tersentuh sama sekali.
- **POSTED**: final. Stok sudah dimutasi + ledger `inventory_movements` ditulis. Tidak dapat diedit/dibatalkan lagi setelahnya (sama seperti Goods Receipt — tidak ada alur reversal-setelah-posting).
- **CANCELLED**: final, dapat dicapai dari DRAFT/PENDING_APPROVAL/APPROVED, tidak pernah menyentuh stok. Tidak ada alur "tolak" terpisah — approver yang tidak setuju cukup meng-cancel (permission `stock_adjustment.cancel`, independen dari siapa yang membuat/menyetujui).

**Permission** (5 kode, sudah ter-seed di `MenuPermissionSeeder`, tidak perlu kode baru):

| Aksi | Kode | Syarat status |
|---|---|---|
| Lihat | `stock_adjustment.view` | semua status |
| Buat/edit/ajukan | `stock_adjustment.create` | create/update/submit: DRAFT |
| Setujui | `stock_adjustment.approve` | PENDING_APPROVAL |
| Posting | `stock_adjustment.post` | APPROVED |
| Batalkan | `stock_adjustment.cancel` | DRAFT / PENDING_APPROVAL / APPROVED |

Tidak ada pembatasan segregation-of-duties — user yang sama boleh create lalu approve sendiri, selama punya kedua permission di cabang tersebut. Konsisten dengan filosofi permission-langsung-ke-user proyek ini (tidak ada workflow approval berlapis di modul manapun sebelumnya).

## 2. Skema Data

**Tabel baru:**

```
stock_adjustments
  id, number (unique, "SA/{BRANCH_CODE}/{YYYYMM}/{00001}" via DocumentNumberGenerator::next($branch, 'SA'))
  branch_id (FK branches), adjustment_date, reason (wajib, level header)
  status (string, default 'draft')
  approved_by (nullable FK users), approved_at (nullable timestamp)
  notes (nullable text)
  created_by/updated_by/timestamps (HasAudit)

stock_adjustment_lines
  id, stock_adjustment_id (FK, cascadeOnDelete)
  sparepart_branch_id (FK sparepart_branches)
  system_qty (decimal, snapshot on_hand_qty saat baris dibuat — historis, tidak diubah lagi setelah dibuat)
  physical_qty (decimal, input user — hasil hitung fisik)
  adjustment_qty (decimal, dihitung server-side saat baris dibuat/diedit = physical_qty - system_qty — nilai historis untuk direview approver, BUKAN nilai yang dipakai saat posting)
  reason (string, wajib, level baris)
  sort_order (integer, default 0)
  created_by/updated_by/timestamps (HasAudit)

  UNIQUE(stock_adjustment_id, sparepart_branch_id) -- larangan duplikat sparepart per dokumen
```

CHECK constraints (pola sama dengan `goods_receipt_lines`): `physical_qty >= 0`, `system_qty >= 0`. `adjustment_qty` boleh negatif, tidak di-CHECK.

**Perluasan class yang sudah ada (bukan tabel baru):**
- `app/Support/StockAdjustmentStatus.php` (baru) — `DRAFT`, `PENDING_APPROVAL`, `APPROVED`, `POSTED`, `CANCELLED`.
- `app/Support/InventoryMovementType.php` (sudah ada sejak 008a, tambah 2 konstanta) — `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`.

Tidak ada perubahan skema pada `inventory_movements`/`sparepart_branch_stocks` — keduanya sudah memiliki seluruh kolom yang dibutuhkan sejak 008a.

## 3. Logika Bisnis & Otorisasi

**`StockAdjustmentPolicy`** (mirip `GoodsReceiptPolicy`, dengan 4 aksi status-aware bukan 2):

```php
view(User, StockAdjustment): stock_adjustment.view, semua status
update(User, StockAdjustment): stock_adjustment.create && status === DRAFT
submit(User, StockAdjustment): stock_adjustment.create && status === DRAFT
approve(User, StockAdjustment): stock_adjustment.approve && status === PENDING_APPROVAL
post(User, StockAdjustment): stock_adjustment.post && status === APPROVED
cancel(User, StockAdjustment): stock_adjustment.cancel && status IN [DRAFT, PENDING_APPROVAL, APPROVED]
```

**Disiplin locking** (diterapkan sejak awal — pelajaran dari migrasi 007 & 008a, bukan retrofit setelah bug ditemukan): setiap aksi pengubah status (`submit`/`approve`/`post`/`cancel`) mengunci baris header (`StockAdjustment::whereKey($id)->lockForUpdate()->first()`) lalu **me-re-verifikasi status di dalam transaksi** sebelum bertindak — mencegah race dari 2 request bersamaan yang berlomba mengubah status yang sama. `post()` selain itu juga mengunci baris `sparepart_branch_stocks` secara berurutan (`sparepart_branch_id` ascending, wajib pakai `->reorder()` sebelum `->orderBy(...)` karena relasi `lines()` membawa `orderBy('sort_order')` bawaan — persis pola yang sudah terbukti benar di `goods_receipt_lines`).

**Matematika `post()`** (menjamin hasil akhir selalu persis sama dengan `physical_qty`, terlepas dari mutasi stok lain yang terjadi selama proses approval):

1. Kunci baris stok (`sparepart_branch_stocks`), ambil `on_hand_qty` **terkini** saat itu juga — bukan `system_qty` yang tersimpan sejak baris dibuat.
2. `delta = physical_qty - on_hand_qty_terkini`.
3. Jika `delta == 0`: **tidak menulis baris `InventoryMovement`** untuk baris ini (CHECK constraint `qty_in > 0 OR qty_out > 0` melarang baris dengan delta nol) — baris ini dianggap "tidak ada perubahan riil dibutuhkan", dokumen tetap lanjut ke POSTED.
4. Jika `delta != 0`: `on_hand_qty` di-update jadi `physical_qty` persis. Tulis `InventoryMovement` dengan `qty_in`/`qty_out` = `abs(delta)` (arah sesuai tanda `delta`), `movement_type` = `ADJUSTMENT_IN` (delta positif) / `ADJUSTMENT_OUT` (delta negatif), `balance_after` = `physical_qty`.
5. Jika `delta` yang dihitung ulang ini **berbeda** dari `adjustment_qty` yang tersimpan di baris sejak dibuat/disetujui (artinya stok bergeser di tengah alur approval karena mutasi lain, misalnya Goods Receipt lain di-posting duluan), catat pergeseran itu di kolom `notes` milik `InventoryMovement` (contoh: `"Tercatat saat diajukan: +5, diterapkan saat posting: +3 (stok bergeser sejak diajukan)"`) — transparan, bukan diam-diam berbeda dari angka yang direview approver.

**Validasi:**
- `StoreStockAdjustmentRequest`/`UpdateStockAdjustmentRequest`: `lines.*.sparepart_branch_id` harus unik dalam satu submission (custom validator, mirip pola cross-branch-check yang sudah ada di GR). Sparepart harus aktif & milik cabang yang sama dengan dokumen (pola sama dengan GR).
- `reason` wajib di header DAN di setiap baris.
- `adjustment_qty` pada baris selalu dihitung server-side (`physical_qty - system_qty`) saat baris dibuat/diedit, tidak pernah dipercaya dari input klien — walau `physical_qty` sendiri adalah input klien murni.
- `branch_id` immutable setelah dibuat (pola sama dengan PKB/GR — absen dari `UpdateStockAdjustmentRequest`).
- Filter blank lines via `prepareForValidation()` (pola sama dengan GR).

## 4. UI

Mengikuti pola visual & komponen yang sudah ada — tidak ada komponen baru:

- **Index**: `list-filter-bar` (cari nomor/reason) + `branch-multiselect-filter`, badge status 5-warna, `empty-state` bila kosong, tombol "Buat Baru" gated `stock_adjustment.create` di cabang manapun (`branchesWithPermission`).
- **Create/Edit**: form header (cabang, tanggal, reason) + baris dinamis (`<template>` cloning, pola `_line_item_scripts.blade.php` yang sama dengan GR — cascading branch→sparepart 1 level). Tiap baris: pilih sparepart → `system_qty` terisi otomatis (readonly, dari AJAX lookup) → input `physical_qty` → `adjustment_qty` dihitung live di JS untuk preview (dihitung ulang server-side saat submit, tidak dipercaya dari klien) → `reason` per baris (wajib). **Pola replay `old()` + re-enable tombol "tambah baris" setelah validation error diterapkan SEJAK AWAL** (pelajaran langsung dari temuan Important di final review 008a — jangan ditambal belakangan lagi).
- **Show/Detail**: seluruh baris (system_qty/physical_qty/adjustment_qty/reason), info approval (`approved_by`/`approved_at` bila ada), tombol aksi kondisional sesuai status+permission user saat itu (Ajukan/Setujui/Post/Batalkan).
- **Sidebar**: swap placeholder "Stock Adjustment" jadi link asli ke `stock-adjustments.index`, gated `stock_adjustment.view`.

## 5. Testing & Rencana Eksekusi

**Cakupan test yang direncanakan** (`StockAdjustmentModelTest`, `StockAdjustmentAuthorizationTest`, `StockAdjustmentManagementTest`):
- Model: fillable/default status, unique number, cascade delete lines, unique constraint sparepart per dokumen.
- Policy: kombinasi status × permission untuk keenam aksi (view/update/submit/approve/post/cancel), termasuk cabang berbeda dan status salah per aksi.
- Management, **sejak awal, bukan ditambal di fix round**: render `GET create`/`GET edit` sukses, `PUT update` sukses, submit/approve/post/cancel happy-path + forbidden-path, duplicate-sparepart ditolak validasi, post() dengan stok yang sengaja "bergeser" di tengah alur (simulasi mutasi lain sebelum posting) membuktikan hasil akhir persis `physical_qty` dan `notes` ledger mencatat pergeseran itu, delta-nol tidak menulis baris ledger tapi dokumen tetap POSTED, cancel dari 3 status berbeda (DRAFT/PENDING_APPROVAL/APPROVED), replay `old()` pada create form setelah validation error.
- Sidebar wiring test (assertSee text + route URL).

**Rencana task** (5 task, mengikuti pola PKB migrasi 006 karena kompleksitas lifecycle mirip):
1. Data model — migrasi, model, `StockAdjustmentStatus`, ekstensi `InventoryMovementType`.
2. `StockAdjustmentPolicy`.
3. Lookup endpoint (sparepart by branch) + CRUD controller (index/create/store/show/edit/update) + FormRequests + views dasar.
4. Aksi lifecycle (`submit`/`approve`/`post`/`cancel`) + locking/matematika posting + tombol aksi di UI.
5. Sidebar wiring + verifikasi penuh.

**Eksekusi**: `subagent-driven-development` di worktree terisolasi, rigor penuh (task review + fix loop + final whole-branch review) — sama seperti semua modul sebelumnya, terutama karena `post()` di sini menyentuh stok+ledger persis seperti 008a.

## Self-Review

- **Placeholder scan**: tidak ada "TBD"/"TODO" — setiap keputusan sudah eksplisit.
- **Konsistensi internal**: alur status, permission, dan matematika posting saling konsisten; nama field (`system_qty`/`physical_qty`/`adjustment_qty`) dipakai sama di seluruh bagian.
- **Cakupan**: setiap keputusan yang disepakati dalam sesi brainstorming (5 status, cancel scope, tanpa segregation-of-duties, tanpa duplikat sparepart, matematika absolut saat posting, prefix nomor `SA`) tercermin di spec ini.
- **Deviasi dari dokumen sumber didokumentasikan eksplisit**: status `APPROVED` tambahan (5 vs 4 status di source doc) — dicatat di bagian Konteks dengan alasannya.
