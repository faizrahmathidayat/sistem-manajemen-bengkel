# Migrasi 006 — PKB / Work Orders (CRUD, DRAFT & CANCELLED only) — Design

Status: approved by user, ready for implementation plan

## Purpose

Migrasi 006 dari roadmap (`Rencana_Migrasi_Database_Sistem_Bengkel.md` §9): header dan detail PKB (Perintah Kerja Bengkel). Ini modul operasional pertama yang dibangun setelah seluruh Master Data selesai (Cabang, Customer, Kendaraan, Mekanik, Jasa Service, Sparepart) — PKB merangkai semuanya menjadi satu dokumen transaksi.

**Scope eksplisit dibatasi ke CRUD saja** (status `draft`/`cancelled`), sesuai keputusan yang dikonfirmasi user: dokumen sumber sendiri memisahkan PKB (migrasi 006) dari reservasi stok (migrasi 007, tabel `inventory_reservations`). Status "OPEN/Confirmed" yang memicu reservasi stok nyata (locking, `SHORTAGE`, dsb — §5.3–5.4 dokumen bisnis) **sengaja tidak dibangun di sini** — itu jadi migrasi 007 tersendiri. Kolom `status` tetap kolom string biasa (bukan native MySQL ENUM, konsisten dengan konvensi proyek) sehingga migrasi 007 nanti tinggal menambah nilai yang diizinkan tanpa perlu mengubah skema.

## Scope

**In scope:**
- Tabel baru: `work_orders`, `work_order_service_lines`, `work_order_sparepart_lines`.
- CRUD PKB: create (DRAFT), list (search + filter cabang + empty-state, pola standar), detail, edit (ubah header + baris selama masih DRAFT), cancel (DRAFT → CANCELLED, final).
- Validasi cascading cabang→customer→kendaraan, cabang→mekanik, cabang→sparepart aktif.
- Swap sidebar placeholder "Perintah Kerja Bengkel" (sudah ada, gated `pkb.view`, branch-scoped) jadi link asli.
- Permission yang dipakai: `pkb.view`, `pkb.create`, `pkb.edit`, `pkb.cancel` — keempatnya **sudah ter-seed** di `MenuPermissionSeeder` (beseras `pkb.confirm`/`pkb.override_stock_shortage`/`pkb.print` yang belum dipakai sampai migrasi 007+). Tidak ada permission baru yang perlu ditambahkan.

**Explicitly out of scope:**
- Reservasi stok, status `OPEN`/`SHORTAGE`/`COMPLETED`/`INVOICED`, tombol "Konfirmasi PKB", `pkb.confirm`, `pkb.override_stock_shortage` — migrasi 007.
- Cetak PKB (`pkb.print`) — belum ada infrastruktur PDF di proyek ini sama sekali; ditunda ke saat cetak invoice/PKB benar-benar dibangun.
- Invoice, pembayaran, laporan PKB — migrasi 009/010/011.
- Perubahan pada `Sparepart`, `SparepartBranch`, `SparepartBranchStock`, `Mechanic`, `Customer`, `Vehicle`, atau relasi/pola yang sudah ada — hanya dikonsumsi (dibaca), tidak dimodifikasi.

## Data Model

`work_orders`:
```
id              bigint PK
number          varchar(50) unique   -- via DocumentNumberGenerator::next($branch, 'PKB')
branch_id       FK -> branches
customer_id     FK -> customers
vehicle_id      FK -> vehicles       -- NOT NULL (wajib, dikonfirmasi user)
mechanic_id     FK -> mechanics      -- NOT NULL (wajib, dikonfirmasi user)
work_order_date date, not null, default today
odometer_km     decimal(12,1) nullable, check >= 0 bila diisi
status          varchar(20) not null default 'draft'  -- 'draft' | 'cancelled' (nilai lain ditambah migrasi 007+)
notes           text nullable
+ HasAudit (created_by, updated_by)
+ timestamps
```

`work_order_service_lines`:
```
id                  bigint PK
work_order_id       FK -> work_orders, cascade on delete (baris ikut terhapus jika header dihapus — header sendiri tidak pernah di-hard-delete lewat aplikasi, ini murni jaring pengaman DB)
service_catalog_id  FK -> service_catalogs, nullable (boleh jasa free-text)
description         varchar(255) not null   -- snapshot nama jasa, bisa diedit bebas
qty                 decimal(18,3) not null, check > 0
unit_price          decimal(18,2) not null, check >= 0   -- snapshot harga, default dari service_catalog.default_price saat dipilih, bisa diubah manual
line_total          decimal(18,2) not null, check >= 0   -- qty * unit_price, dihitung server-side saat simpan (bukan trust dari input)
sort_order          int not null default 0
+ HasAudit + timestamps
```

`work_order_sparepart_lines`:
```
id                      bigint PK
work_order_id           FK -> work_orders, cascade on delete
sparepart_branch_id     FK -> sparepart_branches
item_code_snapshot      varchar(30) not null   -- snapshot sparepart->code saat baris ditambahkan
item_name_snapshot      varchar(150) not null  -- snapshot sparepart->name saat baris ditambahkan
qty                     decimal(18,3) not null, check > 0
default_unit_price      decimal(18,2) not null, check >= 0  -- snapshot sparepart_branch->selling_price, murni referensi/display
unit_price              decimal(18,2) not null, check >= 0  -- harga aktual dipakai di baris, bisa diubah manual dari default
line_total              decimal(18,2) not null, check >= 0
sort_order              int not null default 0
+ HasAudit + timestamps
```

**Tidak ada perubahan ke `sparepart_branch_stocks`** — baris sparepart PKB murni mencatat rencana pemakaian, `on_hand_qty`/`reserved_qty` tidak disentuh. UI boleh menampilkan `SparepartBranchStock::available_qty` sebagai info referensi di samping dropdown pemilihan sparepart, tapi ini display-only, tidak divalidasi/diblokir.

**`WorkOrderStatus`** — class konstanta string biasa (bukan PHP enum — runtime PHP 7.4.33 tidak punya native enum), mis. `app/Support/WorkOrderStatus.php`:
```php
class WorkOrderStatus
{
    const DRAFT = 'draft';
    const CANCELLED = 'cancelled';
}
```
Divalidasi lewat `Rule::in([WorkOrderStatus::DRAFT, WorkOrderStatus::CANCELLED])` di tempat yang relevan — bukan constraint DB level, supaya migrasi 007 bisa menambah nilai tanpa migrasi skema baru.

## Business Validation

Di `StoreWorkOrderRequest`/`UpdateWorkOrderRequest` (`withValidator()`, mengikuti pola `StoreVehicleRequest`):
- `branch_id` dibatasi ke cabang tempat user punya `pkb.create` (mengikuti pola `branchesWithPermission('pkb.create')` dari perbaikan Sparepart).
- `customer_id` harus bisa dilayani di `branch_id` terpilih (`Customer::hasAccessToBranch($branchId)`, method sudah ada).
- `vehicle_id` wajib, harus milik `customer_id` terpilih (`Vehicle::customer_id === customer_id`).
- `mechanic_id` wajib, harus aktif dan ditugaskan ke `branch_id` (`Mechanic::hasAccessToBranch($branchId)`, method sudah ada).
- Setiap baris sparepart: `sparepart_branch_id` harus `is_active=true` dan `branch_id`-nya sama dengan PKB.
- **PKB harus punya minimal 1 baris** (jasa dan/atau sparepart) — keputusan desain sesi ini, tidak eksplisit di dokumen sumber.
- `qty > 0`, `unit_price >= 0` untuk setiap baris (divalidasi ulang server-side, `line_total` selalu dihitung ulang server-side dari `qty * unit_price`, tidak pernah dipercaya dari input klien).

**Permission harga baris:** dokumen sumber menyebut "harga dapat diubah hanya oleh user dengan permission yang ditetapkan", tapi tidak ada kode permission terpisah untuk itu di katalog yang sudah ter-seed. Keputusan sesi ini: siapa saja yang punya `pkb.create`/`pkb.edit` boleh mengubah harga baris — tidak ada gate permission tambahan.

## Authorization

`WorkOrderPolicy` (bare-`can()`/`authorize()` args-based, dikonsultasi lewat `Gate::before`'s existing fallback — mengikuti bentuk `SparepartBranchPolicy` persis):
```php
class WorkOrderPolicy
{
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasPermissionToInBranch('pkb.view', $workOrder->branch_id);
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->status === WorkOrderStatus::DRAFT
            && $user->hasPermissionToInBranch('pkb.edit', $workOrder->branch_id);
    }

    public function cancel(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->status === WorkOrderStatus::DRAFT
            && $user->hasPermissionToInBranch('pkb.cancel', $workOrder->branch_id);
    }
}
```
`update`/`cancel` keduanya menolak PKB yang sudah `CANCELLED` (final, tidak bisa diedit/dibatalkan ulang) — ditegakkan di Policy, bukan cuma disembunyikan di UI.

## UI / UX

**List** (`/work-orders`): pola standar `list-filter-bar` (search by nomor PKB) + `branch-multiselect-filter` + `empty-state`, identik strukturnya dengan Mekanik/Customer/dll yang sudah ada.

**Create/Edit** (satu halaman, pendekatan A yang disetujui — memperbesar pola cascading-dropdown Kendaraan yang sudah ada):
- Dropdown Cabang: hanya cabang tempat user punya `pkb.create` (create) / cabang PKB itu sendiri, read-only (edit).
- Dropdown Customer: AJAX di-reload saat Cabang berubah — `GET /work-orders/lookup/customers/{branch}`.
- Dropdown Kendaraan: AJAX di-reload saat Customer berubah — `GET /work-orders/lookup/vehicles/{customer}`.
- Dropdown Mekanik: AJAX di-reload saat Cabang berubah — `GET /work-orders/lookup/mechanics/{branch}`.
- Baris Jasa & Sparepart: tambah/hapus baris dinamis via JS vanilla (tanpa reload), dikirim sebagai array bertingkat (`services[i][...]`, `spareparts[i][...]`). Dropdown sparepart per baris di-refresh dari `GET /work-orders/lookup/spareparts/{branch}` saat Cabang berubah, menampilkan kode/nama/harga jual/stok tersedia (`available_qty`, display-only) per opsi.
- Baris service_catalog: memilih dari master (nama+harga default terisi otomatis, bisa diedit) atau kosongkan untuk isi manual (free-text description).

**Detail** (`/work-orders/{id}`): header + tabel baris jasa + tabel baris sparepart. Read-only total kalau `CANCELLED`; tombol "Ubah" (ke halaman edit) dan "Batalkan" muncul hanya kalau `DRAFT` dan user punya permission terkait (`@can('update', $workOrder)` / `@can('cancel', $workOrder)`).

**Sidebar:** ganti `resources/views/partials/sidebar.blade.php` placeholder "Perintah Kerja Bengkel" (`<span class="nav-link nav-link-disabled">` + badge "Segera Hadir") jadi `<a href="{{ route('work-orders.index') }}">` asli, mempertahankan gating `pkb.view`/branch-scoped yang sudah ada — 3 baris, tidak menyentuh struktur `@if` heading di sekitarnya (sesuai catatan lama di memory proyek).

## Testing

Mengikuti pola project (`RefreshDatabase`, HTTP request nyata, bukan mock; helper `grantBranchPermission()` sudah ada polanya di beberapa test file lain):
- CRUD dasar: create dengan header+baris lengkap (jasa+sparepart), index (list/search/filter cabang/empty-state), show, update (tambah/hapus/ubah baris), cancel.
- Validasi cascading: customer tidak dilayani cabang → ditolak; kendaraan bukan milik customer → ditolak; mekanik tidak aktif/tidak ditugaskan ke cabang → ditolak; sparepart dari cabang lain → ditolak.
- Validasi minimal 1 baris — PKB tanpa baris ditolak.
- `line_total` dihitung ulang server-side (kirim `line_total` yang salah dari klien, verifikasi hasil tersimpan tetap `qty * unit_price` yang benar).
- Permission: masing-masing `pkb.view`/`pkb.create`/`pkb.edit`/`pkb.cancel` diuji forbidden tanpa izin (401/403 sesuai pola project).
- Branch-scoping: user tidak bisa lihat/edit/batalkan PKB cabang yang bukan miliknya (mirror `SparepartBranchPolicy`'s test pattern).
- PKB `CANCELLED` tidak bisa diedit atau dibatalkan ulang (Policy menolak, bukan cuma UI yang sembunyikan tombol).
- Nomor PKB otomatis dan unik per cabang/periode (reuse `DocumentNumberGenerator`, sudah punya test sendiri — cukup verifikasi format/keunikan dari sisi `WorkOrderController`, tidak perlu re-test generator itu sendiri).
- Sidebar: `tests/Feature/AppShellTest.php` sudah punya 3 test yang menegaskan perilaku PLACEHOLDER lama secara eksplisit — semuanya perlu ditinjau ulang saat placeholder diganti link asli, karena assertion `assertSee`/`assertDontSee('Perintah Kerja Bengkel', false)` akan tetap lolos (teksnya sama), tapi maknanya berubah dari "placeholder disabled muncul" jadi "link asli muncul":
  - test yang menegaskan placeholder muncul untuk user dengan `pkb.view` — tetap valid sebagai "link PKB muncul", tidak perlu diubah assertion-nya, tapi baca komentar di sekitarnya (baris ~215-222) karena menyebutkan alasan spesifik terkait bentuk disabled-span yang sudah tidak relevan lagi setelah jadi link — perbarui komentarnya.
  - `test_sidebar_hides_pkb_placeholder_without_permission` (baris ~226) dan test lain di baris ~289 yang meng-assertDontSee — tetap valid secara perilaku (user tanpa `pkb.view` tetap tidak melihat link), tapi nama method-nya menyebut "placeholder" — pertimbangkan rename agar tidak menyesatkan (mis. `test_sidebar_hides_pkb_link_without_permission`), TIDAK wajib tapi disarankan untuk kejelasan.
  - Jalankan grep text-collision standar terhadap `AppShellTest.php`/`DashboardTest.php` untuk string baru apa pun yang muncul dari halaman PKB (mis. "Belum ada PKB", label tombol) sebelum menyatakan task ini bersih.

## Execution

Modul terbesar sejak migrasi Sparepart — skema baru (3 tabel) + Policy baru + 4 endpoint lookup AJAX + form dengan baris dinamis. Rekomendasi: `subagent-driven-development` di worktree, rencana dipecah beberapa task terpisah (migrasi+model+Policy, controller+FormRequest+lookup endpoints, view create/edit+JS, view list/detail, task final untuk swap sidebar placeholder + test collision check) — mirip struktur migrasi Sparepart (5 task) sebelumnya.
