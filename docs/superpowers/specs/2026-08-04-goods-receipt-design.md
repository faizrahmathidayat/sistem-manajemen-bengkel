# Sub-Proyek 008a — Penerimaan Barang & Fondasi Kartu Stok — Design

Status: approved by user, ready for implementation plan

## Purpose

Sub-proyek pertama dari migrasi 008 (per `Rencana_Migrasi_Database_Sistem_Bengkel.md` §11, dokumen sumber lengkap di luar repo di `D:\FAIZ\file-faiz\projects\`). Migrasi 008 mencakup 3 jenis dokumen (Penerimaan Barang, Stock Adjustment, Transfer Stock) + satu tabel ledger bersama (`inventory_movements`/kartu stok) — dipecah menjadi 3 sub-proyek berurutan (keputusan dikonfirmasi user), dimulai dari Penerimaan Barang karena ini **satu-satunya cara `sparepart_branch_stocks.on_hand_qty` akhirnya punya penulis** — sampai saat ini stok fisik semua sparepart selalu 0, sehingga setiap PKB yang dikonfirmasi (migrasi 007) selalu `SHORTAGE`.

## Scope

**In scope:**
- `goods_receipts` (header) + `goods_receipt_lines` (detail) — CRUD dokumen penerimaan barang per cabang, status `DRAFT`/`POSTED`/`CANCELLED`.
- `inventory_movements` — ledger append-only (kartu stok) dipakai bersama semua modul stok ke depannya; sub-proyek ini hanya menulis `movement_type = 'receipt'`.
- Aksi **posting**: menambah `on_hand_qty` + mencatat baris `inventory_movements` per baris, dalam transaksi dengan locking sejak awal (bukan ditambal belakangan, mengikuti pelajaran migrasi 007).
- List, create (DRAFT, edit bebas selama DRAFT), detail, posting, cancel (DRAFT saja).
- Permission `receipt.view/create/post/cancel` — **sudah ter-seed** di `MenuPermissionSeeder` (menu `persediaan.receipt`, branch-scoped), tidak ada permission baru.
- Swap sidebar placeholder "Penerimaan Barang" (sudah ada, gated `receipt.view`) jadi link asli.

**Explicitly out of scope:**
- Stock Adjustment, Transfer Stock — sub-proyek 008b/008c terpisah.
- **Cancel/pembalikan setelah `POSTED`** — dikonfirmasi user, tidak ada preseden di dokumen sumber untuk membatalkan penerimaan barang yang sudah di-posting (beda dengan invoice/transfer yang punya alur void eksplisit). `POSTED` bersifat final di scope ini; koreksi kesalahan setelah posting nanti lewat Stock Adjustment (008b).
- `movement_type` selain `'receipt'` (`invoice`, `invoice_void`, `adjustment_in`, `adjustment_out`, `transfer_out`, `transfer_in`) — `InventoryMovementType` hanya berisi `RECEIPT` di sub-proyek ini, nilai lain ditambah bertahap saat modul terkait dibangun (mengikuti pola `WorkOrderStatus` yang ditambah bertahap per migrasi, bukan sekaligus).
- Tombol "Buat Penerimaan Barang" di Dashboard — tidak ada placeholder untuk ini di Dashboard saat ini (beda dengan PKB), jadi tidak ada yang perlu diaktifkan di sana.
- Referensi supplier/purchase order — `reference_number` hanya field teks bebas (nota beli, dsb), bukan tabel supplier/PO terelasi (dokumen sumber §11.1 menyebut ini sebagai open question, belum diputuskan, di luar scope sub-proyek ini).

## Data Model

`goods_receipts`:
```
id                bigint PK
number            varchar(50) unique   -- via DocumentNumberGenerator::next($branch, 'PB')
branch_id         FK -> branches
receipt_date      date, not null, default today
reference_number  varchar(100) nullable
status            varchar(20) not null default 'draft'   -- 'draft' | 'posted' | 'cancelled'
notes             text nullable
+ HasAudit (created_by, updated_by)
+ timestamps
```

`goods_receipt_lines`:
```
id                    bigint PK
goods_receipt_id      FK -> goods_receipts, cascade on delete
sparepart_branch_id   FK -> sparepart_branches
qty                   decimal(18,3), check > 0
purchase_price        decimal(18,2), check >= 0
line_total            decimal(18,2), check >= 0   -- qty * purchase_price, dihitung ulang server-side, tidak pernah dipercaya dari input klien
+ HasAudit + timestamps
```

`inventory_movements`:
```
id                    bigint PK
movement_at           timestamp
branch_id             FK -> branches
sparepart_branch_id   FK -> sparepart_branches
movement_type         varchar(20)   -- hanya 'receipt' ditulis di sub-proyek ini; kolom string biasa, bukan native DB enum
qty_in                decimal(18,3) default 0
qty_out               decimal(18,3) default 0
balance_after         decimal(18,3)   -- snapshot on_hand_qty SETELAH mutasi ini diterapkan
reference_type        varchar(50)   -- 'goods_receipt_line' di sub-proyek ini
reference_id          bigint   -- tanpa FK constraint literal (pola polymorphic-ringan, sama seperti inventory_reservations di migrasi 007), karena reference_type akan merujuk tabel berbeda-beda nanti
notes                 text nullable
created_by            FK -> users, nullable
created_at            timestamp
```
Check constraints (persis dokumen sumber §11.4):
```sql
CHECK (qty_in >= 0 AND qty_out >= 0);
CHECK (NOT (qty_in > 0 AND qty_out > 0));
CHECK (qty_in > 0 OR qty_out > 0);
```

`GoodsReceiptStatus` (support class, pola sama `WorkOrderStatus`):
```php
class GoodsReceiptStatus
{
    const DRAFT = 'draft';
    const POSTED = 'posted';
    const CANCELLED = 'cancelled';
}
```

`InventoryMovementType` (support class baru, hanya `RECEIPT` untuk sub-proyek ini):
```php
class InventoryMovementType
{
    const RECEIPT = 'receipt';
}
```

## Business Logic

**`GoodsReceiptController::store()`** (`receipt.create`): header + minimal 1 baris (sparepart_branch aktif & dari cabang yang sama, qty > 0, purchase_price >= 0). `line_total` dihitung server-side (`qty * purchase_price`), tidak pernah dipercaya dari input klien.

**`GoodsReceiptController::update()`**: hanya kalau status `DRAFT` — kunci row (`lockForUpdate` + re-check status) di awal transaksi **sejak awal**, bukan ditambal belakangan (pelajaran langsung dari bug `WorkOrderController::update()` di migrasi 007 — modul baru ini menerapkan pola locking sejak desain awal).

**`GoodsReceiptController::post()`** (route baru `PATCH /goods-receipts/{goodsReceipt}/post`, permission `receipt.post`, hanya kalau `status === DRAFT`), dalam satu `DB::transaction()`:
1. Kunci row `GoodsReceipt` (`lockForUpdate`), re-check status masih `DRAFT`.
2. Muat baris, urutkan `sparepart_branch_id` ascending (pola locking konsisten dari migrasi 007 — pakai `reorder()` sebelum `orderBy()` kalau relasinya sudah punya `orderBy` bawaan, supaya urutan benar-benar berlaku).
3. Untuk tiap baris: kunci `sparepart_branch_stocks` row (`lockForUpdate`), tambahkan `qty` ke `on_hand_qty`, simpan.
4. Buat baris `InventoryMovement` (`movement_type: RECEIPT`, `qty_in: $line->qty`, `qty_out: 0`, `balance_after: $stock->on_hand_qty` **setelah** ditambah, `reference_type: 'goods_receipt_line'`, `reference_id: $line->id`, `created_by: auth()->id()`).
5. Set `status = POSTED`.

**`GoodsReceiptController::cancel()`** (route baru `PATCH /goods-receipts/{goodsReceipt}/cancel`, permission `receipt.cancel`, hanya kalau `status === DRAFT`): set `status = CANCELLED`. Tidak ada dampak stok/ledger (belum pernah di-posting).

## Authorization

`GoodsReceiptPolicy` (pola sama `WorkOrderPolicy`):
```php
class GoodsReceiptPolicy
{
    public function view(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $user->hasPermissionToInBranch('receipt.view', $goodsReceipt->branch_id);
    }

    public function update(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $goodsReceipt->status === GoodsReceiptStatus::DRAFT
            && $user->hasPermissionToInBranch('receipt.create', $goodsReceipt->branch_id);
    }

    public function post(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $goodsReceipt->status === GoodsReceiptStatus::DRAFT
            && $user->hasPermissionToInBranch('receipt.post', $goodsReceipt->branch_id);
    }

    public function cancel(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $goodsReceipt->status === GoodsReceiptStatus::DRAFT
            && $user->hasPermissionToInBranch('receipt.cancel', $goodsReceipt->branch_id);
    }
}
```
Catatan: dokumen sumber tidak punya kode `receipt.edit` terpisah — `update()` sengaja memakai `receipt.create` (siapa yang bisa membuat, juga bisa mengubah selama masih `DRAFT`), bukan menambah kode permission baru.

## UI

**List** (`/goods-receipts`): pola standar `list-filter-bar` (search nomor) + `branch-multiselect-filter` + `empty-state`, identik strukturnya dengan PKB/Sparepart.

**Create/Edit**: satu halaman, cabang (create: dropdown dibatasi `branchesWithPermission('receipt.create')`; edit: tetap, tidak bisa diubah — sama seperti PKB) + baris sparepart dinamis (tambah/hapus, dropdown sparepart aktif di cabang + qty + harga beli), total baris dihitung server-side. Lebih sederhana dari form PKB — cuma 1 jenis baris, dan hanya 1 level cascading (cabang→sparepart), bukan 4 level.

**Detail**: header + tabel baris (kode/nama/qty/harga beli/subtotal) + tombol **Posting** (`@can('post', ...)`) dan **Batalkan** (`@can('cancel', ...)`). Badge status 3 varian: Draft/Diposting/Dibatalkan. Setelah `POSTED`, tidak ada tombol ubah sama sekali.

**Sidebar**: ganti placeholder "Penerimaan Barang" (`resources/views/partials/sidebar.blade.php`) jadi `<a href="{{ route('goods-receipts.index') }}">` asli, mempertahankan gating `receipt.view` yang sudah ada — pola persis PKB.

## Testing

Mengikuti pola project (`RefreshDatabase`, HTTP request nyata, `on_hand_qty` mulai dari 0 sesuai kondisi nyata sekarang):
- Create dengan header+baris lengkap → `DRAFT`, `on_hand_qty` belum berubah.
- Posting → status `POSTED`, `on_hand_qty` bertambah tepat sesuai qty tiap baris, baris `inventory_movements` (`qty_in` benar, `balance_after` benar) dibuat per baris sparepart.
- Posting dengan 2+ baris sparepart berbeda (`sparepart_branch_id` berbeda) → locking + urutan lock benar, mirror test multi-baris dari migrasi 007's fix round.
- Posting ditolak (403) tanpa `receipt.post`; ditolak kalau status bukan `DRAFT`.
- Cancel dari `DRAFT` → `CANCELLED`, tidak ada dampak stok/ledger.
- Cancel ditolak (403) kalau status `POSTED`.
- Update/edit baris ditolak (403) untuk status selain `DRAFT`.
- Validasi: baris qty <= 0 ditolak, sparepart tidak aktif atau bukan dari cabang penerimaan ditolak, minimal 1 baris wajib.
- List/empty-state/filter-bar — pola standar.
- Sidebar: placeholder lama tidak lagi muncul, link asli muncul sesuai gating `receipt.view` — grep text-collision standar terhadap `AppShellTest.php`/`DashboardTest.php` sebelum menyatakan bersih.

## Execution

Ukurannya mirip migrasi 006 tapi sedikit lebih sederhana (1 jenis baris bukan 2, cuma 1 level cascading dropdown bukan 4). Perkiraan 4 task: skema+model+support classes, Policy, controller+FormRequest+view, sidebar+verifikasi akhir. Tetap `subagent-driven-development` penuh — modul ini penulis pertama `on_hand_qty`, locking harus benar sejak desain awal mengingat 3 bug konkurensi yang ditemukan di migrasi 007.
