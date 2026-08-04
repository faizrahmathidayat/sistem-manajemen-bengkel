# Sub-Proyek 008c — Transfer Stock Design Spec

**Tanggal:** 2026-08-05
**Status:** Disetujui pengguna, siap masuk tahap implementation plan.

## Konteks

Bagian ketiga dan terakhir dari dekomposisi migrasi 008 (008a Goods Receipt → 008b Stock Adjustment → **008c Transfer Stock**). Modul ini adalah **modul pertama yang melibatkan dua cabang dalam satu dokumen** — semua modul persediaan sebelumnya (Goods Receipt, Stock Adjustment) hanya menyentuh satu cabang per dokumen.

Dokumen sumber migrasi (`Rencana_Migrasi_Database_Sistem_Bengkel.md` §11.3) mendefinisikan `stock_transfers`/`stock_transfer_lines` dengan `transfer_status` enum `DRAFT, APPROVED, DISPATCHED, RECEIVED, CANCELLED` — **modul ini mengikuti 5 status ini persis, tanpa deviasi** (berbeda dari 008b yang menambah status `APPROVED` terpisah dari dokumen sumber; di sini dokumen sumber sudah punya 5 status yang sesuai dengan 5 permission yang di-seed).

## 1. Arsitektur & Alur Status

```
DRAFT --approve()--> APPROVED --dispatch()--> DISPATCHED --receive()--> RECEIVED
  |                      |
  +-------cancel()-------+
```

- **DRAFT**: dibuat & diedit bebas, butuh `stock_transfer.create` di cabang **asal**.
- **APPROVED**: disetujui (`approved_by`/`approved_at`), stok **belum** tersentuh. Butuh `stock_transfer.approve` di cabang **asal**.
- **DISPATCHED**: barang dianggap sudah keluar — `on_hand_qty` cabang **asal** langsung dikurangi, ditulis ledger `TRANSFER_OUT`. Butuh `stock_transfer.dispatch` di cabang **asal**. Sebelum mutasi, divalidasi: tidak boleh membuat `on_hand_qty` turun di bawah `reserved_qty` (all-or-nothing untuk semua baris — pola yang sama persis dengan 008b, diterapkan proaktif kali ini karena sudah jadi pelajaran, bukan menunggu ditemukan reviewer).
- **RECEIVED**: barang dianggap sudah diterima — `on_hand_qty` cabang **tujuan** ditambah, ditulis ledger `TRANSFER_IN`. Butuh `stock_transfer.receive` di cabang **tujuan**. Final, tidak bisa dibatalkan lagi.
- **CANCELLED**: hanya dari DRAFT/APPROVED (sebelum stok bergerak), butuh `stock_transfer.cancel` di cabang **asal**. Setelah DISPATCHED, tidak ada jalan mundur — kasus fisik seperti barang hilang/rusak di jalan ditangani lewat Stock Adjustment terpisah di cabang tujuan (setelah RECEIVED) atau cabang asal, bukan reversal transfer.

**Permission** (6 kode, sudah ter-seed di `MenuPermissionSeeder`, tidak perlu kode baru), dicek di **cabang yang berbeda tergantung aksi** — pertama kalinya di proyek ini:

| Aksi | Kode | Cabang yang dicek | Syarat status |
|---|---|---|---|
| Lihat | `stock_transfer.view` | asal ATAU tujuan | semua status |
| Buat/edit | `stock_transfer.create` | asal | DRAFT |
| Setujui | `stock_transfer.approve` | asal | DRAFT |
| Kirim | `stock_transfer.dispatch` | asal | APPROVED |
| Terima | `stock_transfer.receive` | tujuan | DISPATCHED |
| Batalkan | `stock_transfer.cancel` | asal | DRAFT / APPROVED |

## 2. Skema Data

**Tabel baru:**

```
stock_transfers
  id, number (unique, "ST/{BRANCH_ASAL_CODE}/{YYYYMM}/{00001}" via DocumentNumberGenerator::next($fromBranch, 'ST'))
  from_branch_id, to_branch_id (FK branches)
  transfer_date, status (string, default 'draft')
  approved_by (nullable FK users), approved_at (nullable timestamp)
  dispatched_by (nullable FK users), dispatched_at (nullable timestamp)
  received_by (nullable FK users), received_at (nullable timestamp)
  notes (nullable text)
  created_by/updated_by/timestamps (HasAudit)

  CHECK (from_branch_id <> to_branch_id)

stock_transfer_lines
  id, stock_transfer_id (FK, cascadeOnDelete)
  sparepart_id (FK spareparts — identitas global, BUKAN sparepart_branch_id, karena baris ini dipakai untuk dua SparepartBranch berbeda tergantung cabang asal/tujuan)
  qty (decimal 18,3, CHECK > 0)
  sort_order (integer, default 0)
  created_by/updated_by/timestamps (HasAudit)

  UNIQUE(stock_transfer_id, sparepart_id) -- larangan duplikat sparepart per dokumen
```

**Perluasan class yang sudah ada:**
- `app/Support/TransferStatus.php` (baru) — `DRAFT`, `APPROVED`, `DISPATCHED`, `RECEIVED`, `CANCELLED`.
- `app/Support/InventoryMovementType.php` (sudah ada, tambah 2 konstanta) — `TRANSFER_OUT`, `TRANSFER_IN`.

Tidak ada field "reason" per baris (beda dari 008b) — transfer bukan alat audit selisih stok, cukup `notes` bebas di header.

## 3. Logika Bisnis & Otorisasi

**`StockTransferPolicy`:**

```php
view(User, StockTransfer): stock_transfer.view di from_branch_id ATAU to_branch_id, semua status
update(User, StockTransfer): stock_transfer.create di from_branch_id && status === DRAFT
approve(User, StockTransfer): stock_transfer.approve di from_branch_id && status === DRAFT
dispatch(User, StockTransfer): stock_transfer.dispatch di from_branch_id && status === APPROVED
receive(User, StockTransfer): stock_transfer.receive di to_branch_id && status === DISPATCHED
cancel(User, StockTransfer): stock_transfer.cancel di from_branch_id && status IN [DRAFT, APPROVED]
```

**Disiplin locking**: setiap aksi pengubah status mengunci baris header (`StockTransfer::whereKey($id)->lockForUpdate()->first()`) lalu re-verifikasi status di dalam transaksi sebelum bertindak — diterapkan pada `approve()`/`dispatch()`/`receive()`/`cancel()` sejak draft pertama.

**`dispatch()`** — mutasi stok pertama, di cabang **asal**:
1. Kunci header, re-verifikasi `APPROVED`.
2. Untuk tiap baris: cari `SparepartBranch` (sparepart_id + from_branch_id), kunci baris stoknya (`sparepart_branch_id` ascending via `->reorder()`).
3. **Guard reserved_qty** (diterapkan proaktif — pelajaran langsung dari bug Critical yang ditemukan di 008b): validasi SEMUA baris dulu sebelum memutasi APA PUN — jika `on_hand_qty - qty < reserved_qty` untuk sparepart manapun, tolak SELURUH dispatch (all-or-nothing) dengan pesan jelas menyebutkan sparepart dan reserved_qty-nya. Pola dua-pass (validasi lalu mutasi) yang sama persis dengan `StockAdjustmentController::post()`.
4. Kurangi `on_hand_qty`, tulis `InventoryMovement` (`TRANSFER_OUT`, `balance_after` = `on_hand_qty` setelah dikurangi) untuk tiap baris.
5. Set `DISPATCHED`, catat `dispatched_by`/`dispatched_at`.

**`receive()`** — mutasi kedua, di cabang **tujuan**:
1. Kunci header, re-verifikasi `DISPATCHED`.
2. Untuk tiap baris: cari `SparepartBranch` (sparepart_id + to_branch_id) — HARUS ada (divalidasi sejak create/update, dicek ulang di sini; jika ternyata sudah dinonaktifkan sejak dispatch, tolak dengan pesan jelas, bukan 500), kunci baris stoknya.
3. Tidak perlu guard reserved_qty di sisi tujuan (menambah stok tidak pernah melanggar `reserved_qty <= on_hand_qty`).
4. Tambah `on_hand_qty`, tulis `InventoryMovement` (`TRANSFER_IN`) untuk tiap baris.
5. Set `RECEIVED`, catat `received_by`/`received_at`. Final.

**`approve()`** — stempel saja (`approved_by`/`approved_at`), tidak menyentuh stok.

**`cancel()`** — hanya dari DRAFT/APPROVED, tidak pernah menyentuh stok.

**Validasi form:**
- `lines.*.sparepart_id` unik per dokumen (`distinct`).
- Sparepart harus aktif & punya `SparepartBranch` di KEDUA cabang (asal dan tujuan) — 2 pesan error berbeda tergantung cabang mana yang belum dikonfigurasi.
- `from_branch_id !== to_branch_id` divalidasi di FormRequest (selain CHECK constraint DB sebagai jaring pengaman kedua).
- `qty` dipercaya dari input (tidak ada harga untuk dihitung ulang, beda dari modul lain).
- `branch_id` (baik asal maupun tujuan) immutable setelah dibuat.

## 4. UI

Mengikuti pola visual & komponen yang sudah ada:

- **Index**: `list-filter-bar` (cari nomor) + filter cabang berbasis `from_branch_id` ATAU `to_branch_id`, badge status via partial `_status_badge` (dipakai index DAN show, tidak pernah diduplikasi inline — pelajaran dari migrasi 007), `empty-state`, tombol "Buat Baru" gated `stock_transfer.create` di cabang manapun.
- **Create/Edit**: pilih cabang asal → cascading AJAX sparepart aktif di cabang asal → pilih cabang tujuan (semua cabang aktif kecuali asal yang sudah dipilih) → baris dinamis (`<template>` cloning): sparepart + qty. Validasi 2-arah (aktif di kedua cabang) server-side saat submit. Pola replay `old()` + re-enable tombol dibangun sejak awal.
- **Show/Detail**: info kedua cabang, semua baris, info approve/dispatch/receive (siapa & kapan per tahap), tombol aksi kondisional sesuai status+permission.
- **Sidebar**: swap placeholder "Transfer Stock" jadi link asli ke `stock-transfers.index`, gated `stock_transfer.view`.

## 5. Testing & Rencana Eksekusi

**Cakupan test**: model (fillable/default, unique number, cascade, unique sparepart per dokumen, CHECK from<>to, CHECK qty>0), policy (status × permission × 6 aksi, termasuk permission benar di cabang salah, view dari cabang manapun), management sejak awal (render create/edit, update sukses, tolak sparepart belum dikonfigurasi di asal/tujuan dengan pesan berbeda, tolak duplikat, tolak from===to, replay old()), lifecycle (approve/dispatch/receive/cancel happy+forbidden, guard reserved_qty all-or-nothing multi-baris, ledger TRANSFER_OUT/IN dengan balance_after benar di kedua cabang, sparepart tujuan dinonaktifkan setelah dispatch sebelum receive, cancel hanya dari DRAFT/APPROVED), sidebar wiring.

**Rencana task** (5 task, sama seperti 008b):
1. Data model — migrasi, model, `TransferStatus`, ekstensi `InventoryMovementType`.
2. `StockTransferPolicy`.
3. Lookup endpoint (sparepart by cabang asal) + CRUD controller (index/create/store/show/edit/update) + FormRequests + views (termasuk partial `_status_badge` sejak awal).
4. Aksi lifecycle (`approve`/`dispatch`/`receive`/`cancel`) + locking/guard reserved_qty + tombol aksi UI.
5. Sidebar wiring + verifikasi penuh.

**Eksekusi**: `subagent-driven-development` di worktree terisolasi, rigor penuh — modul ini menyentuh stok di dua cabang sekaligus dan berinteraksi dengan reservasi PKB, setara dengan 008a/008b.

## Self-Review

- **Placeholder scan**: tidak ada.
- **Konsistensi internal**: alur status, permission-per-cabang, dan matematika dispatch/receive saling konsisten; nama field (`from_branch_id`/`to_branch_id`/`sparepart_id`) dipakai sama di seluruh bagian.
- **Cakupan**: setiap keputusan brainstorming (stock timing dispatch/receive, guard reserved_qty proaktif, sparepart harus sudah dikonfigurasi di tujuan, cancel hanya DRAFT/APPROVED, permission per-cabang-per-aksi) tercermin di spec ini.
- **Deviasi dari dokumen sumber**: TIDAK ADA kali ini — 5 status transfer_status dipakai persis seperti dokumen sumber (berbeda dari 008b yang punya 1 status tambahan).
