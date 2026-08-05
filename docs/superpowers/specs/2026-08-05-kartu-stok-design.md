# Kartu Stok (Stock Card) — Design Spec

**Tanggal:** 2026-08-05
**Status:** Disetujui pengguna, siap masuk tahap implementation plan.

## Konteks

Sisa pekerjaan migrasi 008 yang belum terselesaikan oleh 008a/008b/008c: dokumen sumber (`Rencana_Migrasi_Database_Sistem_Bengkel.md` §2, migrasi 008) menyebut "Penerimaan, adjustment, transfer, **dan kartu stok**" sebagai satu paket, tapi dekomposisi 008a-c hanya mencakup 3 dokumen transaksinya. Tabel ledger `inventory_movements` sudah ditulis oleh ketiganya sejak 2026-08-04/05, tapi belum ada UI untuk melihatnya secara penuh.

Dashboard sudah punya tab "Kartu Stok" (dari redesign 2026-08-02, sebelum ledger ada) dengan kartu stat (Stok Fisik/Reservasi/Tersedia) yang **sudah nyata** (query ke `sparepart_branch_stocks`), tapi tabel riwayat mutasinya masih `dummyMutationRows()` — belum pernah disambungkan ke `inventory_movements`. Sidebar masih punya placeholder "Kartu Stok" terpisah dengan badge "Segera Hadir", mengindikasikan rencana halaman tersendiri.

## Keputusan Desain (dari sesi brainstorming)

1. **Halaman tersendiri** (`/stock-card`, sidebar link asli) dengan filter+paginasi penuh — bukan cuma menyambungkan tab Dashboard. Tab Dashboard tetap ada sebagai preview ringkas, ikut disambungkan ke data asli sebagai bagian pekerjaan ini.
2. **Tabel riwayat HANYA menampilkan `inventory_movements`** (mutasi kuantitas riil) — TIDAK menggabungkan `inventory_reservations` (yang tidak memindahkan kuantitas, cuma mengunci) ke linimasa yang sama. Reservasi tetap ditampilkan sebagai kartu stat "Stok Reservasi" (angka live saat ini), bukan baris riwayat.
3. **Urutan kronologis** (lama ke baru), saldo berjalan turun ke bawah — sesuai konvensi kartu stok tradisional. `->orderBy('movement_at')->orderBy('id')`, bukan `orderByDesc`.
4. **Tidak ada permission code baru** — tetap piggyback ke `sparepart.view` (sudah begitu di sidebar sekarang untuk placeholder-nya).
5. **Tidak ada tabel/migrasi baru** — murni query read-only.

## Arsitektur

- Route baru: `GET /stock-card` → `StockCardController::index()`. Nama route Inggris (`stock-card.index`), label tampilan Indonesia ("Kartu Stok") — konsisten dengan pola `stock-adjustments`/`goods-receipts`.
- **Cabang**: dipilih via branch-switcher session-persisted yang SUDAH ADA (`resources/views/sparepart-branches/_branch_switcher_select.blade.php`, session key `current_sparepart_branch_id`) — direuse apa adanya, TIDAK dibuat session key baru. Ini juga berarti berpindah cabang di halaman Kartu Stok akan ikut mengubah cabang "diingat" di Master Sparepart, dan sebaliknya — sengaja, karena keduanya secara konsep adalah "konteks cabang sparepart yang sama".
- **Sparepart**: dropdown yang di-populate dari sparepart yang punya `SparepartBranch` aktif di cabang terpilih (query server-side langsung, submit via GET — bukan AJAX, karena ini halaman baca bukan form transaksi). Query param `sparepart_id`.
- **Kartu stat**: reuse persis `DashboardController::computeKartuStok()`'s logic (extract ke method/service yang bisa dipakai kedua tempat, atau duplikasi kecil — keputusan implementasi, lihat plan).
- **Tabel riwayat**: `InventoryMovement::where('sparepart_branch_id', $sparepartBranchId)->orderBy('movement_at')->orderBy('id')->simplePaginate(20)`.
- **Resolusi referensi**: `reference_type`/`reference_id` diselesaikan ke nomor dokumen asal + link, lewat switch kecil di controller:
  - `goods_receipt_line` → `GoodsReceiptLine::find($id)->goodsReceipt` → nomor + `route('goods-receipts.show', ...)`
  - `stock_adjustment_line` → `StockAdjustmentLine::find($id)->stockAdjustment` → nomor + `route('stock-adjustments.show', ...)`
  - `stock_transfer_line` → `StockTransferLine::find($id)->stockTransfer` → nomor + `route('stock-transfers.show', ...)`
  - Kalau baris tidak ditemukan (dokumen sudah dihapus — seharusnya tidak mungkin karena tidak ada hard delete di proyek ini, tapi jaga-jaga): tampilkan `reference_type`/`reference_id` mentah tanpa link, bukan error.
  - Migrasi berikutnya (009 Invoice) yang menambah `movement_type`/`reference_type` baru cukup menambah 1 case ke switch ini.

## UI

- Header halaman: branch-switcher + dropdown sparepart (form GET, submit on-change).
- 3 kartu stat (Stok Fisik/Reservasi/Tersedia).
- Tabel: Tanggal, Tipe Mutasi (label Indonesia per `movement_type`), Referensi (nomor dokumen + link), Masuk, Keluar, Saldo Akhir. Paginasi 20 baris/halaman.
- `empty-state` kalau sparepart terpilih belum punya riwayat mutasi sama sekali.
- `no-access` kalau user tidak punya `sparepart.view` di cabang manapun (pola yang sama seperti Master Sparepart).
- Sidebar: swap placeholder jadi link asli ke `stock-card.index`, kondisi gating (`sparepart.view`) TIDAK berubah.
- Dashboard: `DashboardController::dummyMutationRows()` diganti query nyata (beberapa baris terakhir untuk sparepart terpilih, TANPA link referensi — cukup ringkas untuk preview, link penuh ada di halaman `/stock-card`).

## Testing & Rencana Eksekusi

Cakupan test: render halaman dengan cabang+sparepart terpilih, kartu stat benar, tabel riwayat terurut kronologis dengan resolusi referensi benar untuk ketiga jenis dokumen, `empty-state`, `no-access`, paginasi (>20 baris), Dashboard tab menampilkan data asli, sidebar link asli.

**1 task tunggal** — scope kecil dan terpadu (controller, views, routes, sidebar, dashboard wiring).

**Eksekusi: inline** (`executing-plans`, bukan `subagent-driven-development`) — read-only, tanpa concurrency/locking/lifecycle baru, sesuai keputusan pengguna untuk hemat token.

## Self-Review

- **Placeholder scan**: tidak ada.
- **Konsistensi**: nama field/route konsisten dengan modul-modul sebelumnya.
- **Cakupan**: semua keputusan brainstorming (halaman tersendiri, hanya inventory_movements, kronologis, piggyback sparepart.view) tercermin di spec ini.
