# Migrasi 007 — Reservasi Stok PKB — Design

Status: approved by user, ready for implementation plan

## Purpose

Migrasi 007 dari roadmap (`Rencana_Migrasi_Database_Sistem_Bengkel.md` §10): menambahkan alur konfirmasi PKB yang sesungguhnya mereservasi stok, melanjutkan migrasi 006 (PKB CRUD, status `DRAFT`/`CANCELLED` saja). Migrasi ini menambah status `OPEN`/`SHORTAGE`, tabel `inventory_reservations`, dan tiga aksi baru pada `WorkOrderController`: `confirm()`, `overrideShortage()`, dan `cancel()` yang diperluas untuk melepas reservasi.

**Keputusan urutan (dikonfirmasi user):** migrasi 007 dikerjakan sesuai urutan dokumen meski migrasi 008 (penerimaan barang, satu-satunya penulis `on_hand_qty`) belum ada — artinya di aplikasi nyata setiap PKB yang dikonfirmasi akan `SHORTAGE` terus sampai 008 selesai, karena stok fisik semua sparepart masih 0. Ini bukan bug, melainkan konsekuensi yang disadari dan diterima dari mendahulukan 007. Test tetap memverifikasi logika reservasi secara lengkap dengan men-seed `on_hand_qty` manual (pola yang sudah dipakai test Sparepart yang ada).

## Scope

**In scope:**
- Tabel `inventory_reservations` (satu baris per `work_order_sparepart_line`, bukan per header PKB).
- Kolom baru di `work_orders`: `shortage_override_reason`, `shortage_overridden_by`, `shortage_overridden_at` (semua nullable).
- `WorkOrderStatus` menambah `OPEN`, `SHORTAGE` (di samping `DRAFT`/`CANCELLED` yang sudah ada).
- `WorkOrderController::confirm()` — reservasi stok dengan locking, partial reservation, penentuan `OPEN` vs `SHORTAGE`.
- `WorkOrderController::overrideShortage()` — pencatatan alasan override, tanpa mengubah reservasi/status.
- `WorkOrderController::cancel()` diperluas — bisa dari `DRAFT`/`OPEN`/`SHORTAGE`, melepas reservasi aktif kalau ada.
- `WorkOrderPolicy::cancel()` diperluas mengizinkan `OPEN`/`SHORTAGE` (sebelumnya cuma `DRAFT`).
- UI: tombol Konfirmasi, tombol Override (kalau `SHORTAGE`), kolom "Direservasi" di tabel baris sparepart, badge status baru, tombol Ubah hilang untuk status selain `DRAFT`.

**Explicitly out of scope:**
- `WorkOrderPolicy::update()` **tidak berubah** — tetap hanya `DRAFT` (PKB terkunci total setelah dikonfirmasi, dikonfirmasi user; kalau perlu ubah, PKB harus dibatalkan lalu dibuat PKB baru — tidak ada alur "batalkan konfirmasi kembali ke DRAFT").
- Approval terpisah untuk override — cukup permission `pkb.override_stock_shortage` saja, tanpa alur `PENDING_APPROVAL`/`approved_by` (dikonfirmasi user, konsisten dengan pola akses permission-langsung proyek ini).
- Status `COMPLETED`/`INVOICED` dan seluruh alur invoice — migrasi 009+.
- Tabel `inventory_reservations` untuk `INVOICE_DRAFT`/`TRANSFER` — kolom `reservation_type` disiapkan untuk nilai-nilai itu nanti, tapi migrasi ini hanya pernah menulis `'PKB'`.
- `expires_at` pada `inventory_reservations` — itu untuk kasus invoice draft yang ditinggalkan, tidak relevan untuk reservasi PKB, kolom ini **tidak dibuat** di migrasi ini (bisa ditambah nanti kalau invoice draft benar-benar butuh).
- Perubahan ke `sparepart_branch_stocks.on_hand_qty` — kolom ini tetap read-only sampai migrasi 008 (penerimaan barang) memberinya penulis.

## Data Model

`inventory_reservations`:
```
id                bigint PK
branch_id         FK -> branches
sparepart_branch_id FK -> sparepart_branches
reservation_type  varchar(20) -- selalu 'pkb' di migrasi ini
reference_type    varchar(50) -- selalu 'work_order_sparepart_line' di migrasi ini
reference_id      bigint -- FK ke work_order_sparepart_lines.id (tidak dibuat FK constraint DB literal ke tabel spesifik, karena reference_type bisa merujuk tabel lain nanti — pola polymorphic-ringan, mirip attachments)
qty               decimal(18,3), check > 0
status            varchar(20) default 'active' -- 'active' | 'released'
created_at        timestamp
created_by        FK -> users, nullable
```
Index wajib: `(sparepart_branch_id, status)` untuk query "reservasi aktif per sparepart" yang dipakai saat cancel.

`work_orders` — kolom tambahan:
```
shortage_override_reason  text nullable
shortage_overridden_by    FK -> users, nullable
shortage_overridden_at    timestamp nullable
```

`WorkOrderStatus` (`app/Support/WorkOrderStatus.php`, sudah ada) — tambah 2 konstanta:
```php
const OPEN = 'open';
const SHORTAGE = 'shortage';
```

## Business Logic

**`WorkOrderController::confirm(WorkOrder $workOrder)`** (route baru `PATCH /work-orders/{workOrder}/confirm`, permission `pkb.confirm`, hanya kalau `status === DRAFT`):

Dalam satu `DB::transaction()`:
1. Muat `$workOrder->sparepartLines`, urutkan berdasarkan `sparepart_branch_id` (urutan konsisten untuk hindari deadlock antar transaksi PKB berbeda yang berebut sparepart sama).
2. Untuk setiap baris sparepart: `SparepartBranchStock::where('sparepart_branch_id', $line->sparepart_branch_id)->lockForUpdate()->first()`.
3. `$available = $stock->on_hand_qty - $stock->reserved_qty`; `$reserveQty = min($available, $line->qty)`.
4. Kalau `$reserveQty > 0`: buat `InventoryReservation` (`status: 'active'`, `qty: $reserveQty`, `reference_type: 'work_order_sparepart_line'`, `reference_id: $line->id`), tambahkan `$reserveQty` ke `$stock->reserved_qty`, simpan.
5. Kalau `$reserveQty < $line->qty` untuk baris manapun → set flag `$hasShortage = true`.
6. Setelah semua baris diproses: `$workOrder->status = $hasShortage ? WorkOrderStatus::SHORTAGE : WorkOrderStatus::OPEN`, simpan.
7. Kalau `$workOrder->sparepartLines` kosong (PKB cuma jasa) → langsung `OPEN`, tidak ada iterasi reservasi.

**`WorkOrderController::overrideShortage(WorkOrder $workOrder)`** (route baru `PATCH /work-orders/{workOrder}/override-shortage`, permission `pkb.override_stock_shortage`, hanya kalau `status === SHORTAGE` dan `shortage_overridden_at` masih null):
- Validasi `reason` wajib diisi (string, non-kosong).
- Set `shortage_override_reason`, `shortage_overridden_by = auth()->id()`, `shortage_overridden_at = now()`. **Status PKB tidak berubah** (tetap `SHORTAGE`) dan **tidak ada perubahan reservasi**.

**`WorkOrderController::cancel(WorkOrder $workOrder)`** (route sudah ada dari migrasi 006, logikanya diperluas):
- Policy `cancel` sekarang mengizinkan `DRAFT`, `OPEN`, `SHORTAGE` (lihat bagian Authorization).
- Dalam satu `DB::transaction()`: kalau status sebelumnya `OPEN`/`SHORTAGE`, cari semua `InventoryReservation` dengan `reference_type = 'work_order_sparepart_line'` dan `reference_id` masuk daftar id baris sparepart PKB ini, `status = 'active'` → set `status = 'released'`, dan kurangi `reserved_qty` pada `sparepart_branch_stocks` terkait sebesar `qty` reservasi tersebut (lock baris stock yang sama seperti di `confirm()`).
- Set `$workOrder->status = WorkOrderStatus::CANCELLED`.

## Authorization

`WorkOrderPolicy` (perluasan dari yang sudah ada di migrasi 006):
```php
public function cancel(User $user, WorkOrder $workOrder): bool
{
    return in_array($workOrder->status, [WorkOrderStatus::DRAFT, WorkOrderStatus::OPEN, WorkOrderStatus::SHORTAGE], true)
        && $user->hasPermissionToInBranch('pkb.cancel', $workOrder->branch_id);
}

public function confirm(User $user, WorkOrder $workOrder): bool
{
    return $workOrder->status === WorkOrderStatus::DRAFT
        && $user->hasPermissionToInBranch('pkb.confirm', $workOrder->branch_id);
}

public function overrideShortage(User $user, WorkOrder $workOrder): bool
{
    return $workOrder->status === WorkOrderStatus::SHORTAGE
        && is_null($workOrder->shortage_overridden_at)
        && $user->hasPermissionToInBranch('pkb.override_stock_shortage', $workOrder->branch_id);
}
```
`update()` **tidak berubah** — tetap `$workOrder->status === WorkOrderStatus::DRAFT`.

## UI

**Halaman detail PKB** (`work-orders/show.blade.php`, sudah ada):
- Badge status: tambah varian `OPEN` ("Dikonfirmasi", hijau) dan `SHORTAGE` ("Kurang Stok", kuning/oranye).
- Tombol "Konfirmasi" — `@can('confirm', $workOrder)`, form `PATCH` ke route `work-orders.confirm`.
- Tombol "Ubah" — kondisinya sudah `@can('update', $workOrder)`, otomatis hilang untuk status non-`DRAFT` tanpa perlu ubah apa pun di view (Policy sudah menangani).
- Tombol "Batalkan" — kondisinya sudah `@can('cancel', $workOrder)`, otomatis ikut mengizinkan `OPEN`/`SHORTAGE` begitu Policy diperluas.
- Tabel baris sparepart: tambah kolom "Direservasi" — jumlah dari `InventoryReservation` aktif milik baris itu (`$line->qty` diminta vs jumlah direservasi; kalau tidak sama, highlight sebagai kekurangan).
- Kalau `status === SHORTAGE` dan `shortage_overridden_at` masih null: tampilkan form kecil (textarea alasan + tombol "Override Kekurangan Stok"), `@can('overrideShortage', $workOrder)`, submit `PATCH` ke route `work-orders.overrideShortage`.
- Kalau `shortage_overridden_at` sudah terisi: tampilkan info read-only "Kekurangan disetujui oleh {nama} pada {waktu}: {alasan}" — form override tidak muncul lagi.

## Testing

Mengikuti pola project (`RefreshDatabase`, HTTP request nyata; stok di-seed via `DB::table('sparepart_branch_stocks')->update([...])` seperti test Sparepart yang ada):
- Confirm dengan stok cukup semua baris → `OPEN`, reservasi dibuat sesuai qty diminta, `reserved_qty` bertambah tepat.
- Confirm dengan satu baris stok kurang (sebagian tersedia) → `SHORTAGE`, reservasi dibuat sebesar yang tersedia (bukan 0, bukan qty diminta penuh).
- Confirm dengan stok 0 → `SHORTAGE`, tidak ada baris `InventoryReservation` dibuat sama sekali untuk baris sparepart itu (keputusan desain: `$reserveQty === 0` tidak menghasilkan reservasi, menghindari data reservasi yang tidak berarti — lihat langkah 4 di bagian Business Logic, `confirm()` hanya membuat reservasi kalau `$reserveQty > 0`).
- Confirm PKB tanpa baris sparepart (cuma jasa) → langsung `OPEN`.
- Confirm ditolak (403) tanpa `pkb.confirm`; ditolak kalau status bukan `DRAFT`.
- Locking: dua confirm berurutan pada sparepart yang sama tidak boleh membuat total `reserved_qty` melebihi `on_hand_qty` (test sekuensial memverifikasi urutan pemrosesan, bukan test konkurensi thread sungguhan — PHPUnit tidak mendukung eksekusi paralel nyata dalam satu proses, cukup verifikasi logika lock-dan-hitung-ulang benar).
- Override: berhasil dengan permission + alasan terisi, status tetap `SHORTAGE`; ditolak tanpa alasan (validasi); ditolak kalau status bukan `SHORTAGE`; ditolak kalau sudah pernah di-override (`shortage_overridden_at` sudah terisi).
- Cancel dari `OPEN`: reservasi aktif jadi `released`, `reserved_qty` sparepart terkait berkurang kembali sesuai total yang dilepas.
- Cancel dari `SHORTAGE`: sama seperti di atas, termasuk kasus sudah di-override (override tidak menghalangi cancel).
- Cancel dari `DRAFT` (perilaku migrasi 006 yang sudah ada) tetap berfungsi — regresi test.
- `update()`/`edit()` ditolak (403) untuk status `OPEN`/`SHORTAGE`/`CANCELLED` — regresi eksplisit untuk memastikan Policy yang sudah ada tidak sengaja ikut berubah.

## Execution

Modul dengan locking/konkurensi (`SELECT ... FOR UPDATE`) dan matematika stok yang harus tepat — proses penuh `subagent-driven-development`, bukan dipangkas. Perkiraan scope: migrasi+model (tabel `inventory_reservations` + kolom baru `work_orders` + `InventoryReservation` model + perluasan `WorkOrderStatus`), lalu confirm+override+cancel di controller/Policy yang sudah ada (bukan modul baru dari nol — memperluas `WorkOrderController`/`WorkOrderPolicy` yang sudah ada dari migrasi 006), lalu wiring UI di `show.blade.php`. Kemungkinan 3-4 task, lebih kecil dari migrasi 006 karena tidak membangun form/cascading-dropdown baru.
