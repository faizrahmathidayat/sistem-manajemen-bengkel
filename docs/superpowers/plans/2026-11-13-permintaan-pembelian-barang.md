# Permintaan Barang, Pembelian Barang & Penerimaan Berbasis Pembelian — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pasang alur pengadaan Permintaan → Penentuan Supplier → Approval berjenjang dinamis → Pembelian per supplier → Penerimaan berbasis Pembelian, lengkap dengan 3 laporan.

**Architecture:** Dokumen baru (`purchase_requests`, `purchase_orders`) mengikuti pola Penerimaan Barang yang ada: header + lines, status string di kelas `Support`, Policy untuk akses record, transisi status di `DB::transaction` + `lockForUpdate()` + guard status. Rantai approval dibentuk dari konfigurasi dinamis (level, user per level, aturan nominal) oleh `ApprovalChainResolver` lalu **disalin (snapshot)** ke permintaan saat diajukan. Penerimaan diubah agar berasal dari baris Pembelian; stok tetap bertambah hanya saat posting.

**Tech Stack:** Laravel 8.75 (**PHP 7.4.33**), MySQL 8, Blade + Bootstrap 5 CDN + Select2 (`public/js/select2-ajax-picker.js`), maatwebsite/excel, barryvdh/dompdf, PHPUnit (`RefreshDatabase`).

**Spec:** `docs/superpowers/specs/2026-11-13-permintaan-pembelian-barang-design.md` (mockup UI: `…-rancangan.pdf` di folder yang sama). Eksekutor membaca keduanya.

## Global Constraints

- **PHP 7.4**: dilarang nullsafe `?.`, named arguments, `match`, enum, constructor promotion, union types — termasuk di Blade `@php()`. Typed properties & `fn` boleh.
- **Laravel 8.75**: jangan pakai `Request::integer()` dst. (Laravel 9+). Untuk tiap route GET baru tulis test yang benar-benar me-GET route itu.
- MySQL, PK `bigint` (`$table->id()`), tanpa tabel roles. Status = string biasa (bukan native ENUM), konstanta di `app/Support/*Status.php`.
- Permission langsung ke user; cek global tanpa argumen lewat `Gate::before` (`@can('code')`, `$user->hasPermissionTo()`), cek record lewat **Policy** (argumen model → `Gate::before` melepas ke Policy). Permission per cabang: `hasPermissionToInBranch($code, $branchId)` / `branchesWithPermission($code)`.
- Setiap transisi status: `DB::transaction`, **lock header dulu** (`whereKey()->lockForUpdate()->first()`), cek ulang status, baru efek. **Urutan lock baku**: `goods_receipts` → `purchase_orders` → `purchase_order_lines` (id naik) → `sparepart_branch_stocks` (`sparepart_branch_id` naik, lewat `->reorder()->orderBy(...)` karena relasi `lines()` sudah membawa `orderBy('sort_order')`). Tidak boleh ada kode yang mengunci dengan urutan terbalik.
- `line_total`/`total_amount` selalu dihitung ulang di server (`round(qty * price, 2)`), tidak percaya input klien. Qty di UI bilangan bulat (blokir desimal, seperti perbaikan terakhir); kolom DB tetap `decimal(18,3)`.
- Nomor dokumen lewat `DocumentNumberGenerator::next($branch, 'RQ'|'PO')`; Penerimaan tetap `'PB'`. Tanggal bisnis memakai `config('app.business_timezone')`.
- `HasAudit` (created_by/updated_by) pada semua tabel dokumen/master baru; aksi penting dicatat lewat `(new AuditLogger())->log($event, $branchId, $model, $old, $new)` dengan konstanta di `AuditEvent` (+ `LABELS` + `SEVERITIES`; perbarui `tests/Unit/AuditEventTest.php`).
- UI: reuse `layouts.app`, `partials.list-filter-bar`, `partials.empty-state`, `status-dot`, `panel`, `page-heading`; paginasi `->paginate(10)->withQueryString()`; teks Bahasa Indonesia. Editor baris dinamis **wajib punya baris header kolom**; `<select>` yang diisi AJAX = **Select2** (jembatan `select2:select select2:clear` → native `change`); fetch yang ditunggu user = spinner. Di Blade jangan `@json(old(...))`/`@json` dengan fungsi bermulti-koma: pakai `@php($x = ...)` lalu `@json($x)`.
- Route (nama saya, bebas selama konsisten): `suppliers.*`, `purchase-requests.*`, `approvals.*` (inbox), `approval-settings.*`, `purchase-orders.*`; `goods-receipts.*` dan `reports.*` yang ada dipertahankan.
- Test baru memakai trait `Tests\Concerns\GrantsPermissions` (dibuat di Task 1) — jangan menyalin helper `grantBranchPermission` per file.
- Commit per task (pesan gaya `feat: …`/`test: …`, akhiri dengan baris Co-Authored-By dari konteks sesi). Suite penuh harus hijau di akhir tiap task (`php artisan test`).

## Review Focus

1. Total tepat di batas aturan (Rp 10.000.000 → hanya aturan #1; Rp 10.000.001 → #2), total di atas batas terakhir, dan total Rp 0/tanpa harga — rantai harus benar atau pengajuan ditolak dengan pesan jelas.
2. Satu user terdaftar di beberapa level, atau pemohon/pengaju juga approver: hanya boleh memproses satu level dan tidak boleh menyetujui permintaannya sendiri.
3. Dua approver satu level menekan Setuju bersamaan / klik ganda: hanya satu yang berefek, tidak ada PO ganda.
4. Dua draft Penerimaan untuk sisa PO yang sama: yang diposting kedua harus ditolak (qty > sisa) tanpa mengubah stok; PO dibatalkan setelah penerimaan sebagian harus ditolak.
5. Data lama & konfigurasi berubah: Penerimaan tanpa PO tetap tampil/bisa diposting/masuk laporan dengan "—"; mengubah level/aturan/approver tidak memengaruhi permintaan yang sudah diajukan; supplier/approver dinonaktifkan setelah dipakai tidak merusak dokumen lama.

---

## Tahap 1 — Fondasi: permission, audit, Supplier

### Task 1: Permission & audit event baru, trait test

**Files:**
- Modify: `database/seeders/MenuPermissionSeeder.php`, `app/Support/AuditEvent.php`, `tests/Unit/AuditEventTest.php`, `tests/Feature/MenuPermissionSeederTest.php`
- Create: `tests/Concerns/GrantsPermissions.php`

**Interfaces:**
- Produces: trait `GrantsPermissions` dengan `grantGlobal(User $u, string $code): void` (membuat `Permission` bila belum ada + `UserPermission`) dan `grantBranch(User $u, Branch $b, string $code): void` (assign cabang via `UserBranchService` + `UserBranchPermission`).
- Produces: `AuditEvent::PURCHASE_REQUEST_SUBMITTED|PURCHASE_REQUEST_APPROVED|PURCHASE_REQUEST_REJECTED|PURCHASE_REQUEST_CANCELLED|PURCHASE_ORDER_ISSUED|PURCHASE_ORDER_CANCELLED|APPROVAL_SETTING_CHANGED` lengkap dengan `LABELS` & `SEVERITIES` (MEDIUM; reject/cancel/setting HIGH-MEDIUM sesuai kewajaran, setting = HIGH).

- [ ] **Step 1: Tulis test seeder** — `test_seeder_creates_procurement_menus_and_permissions` menegaskan menu (kode → `is_branch_scoped`): `pembelian.permintaan` true, `pembelian.penentuan_supplier` false, `pembelian.pembelian` false, `master.supplier` false, `administrasi.approval_setting` false, `reporting.purchase_request` false, `reporting.purchase_order` false; dan permission: `purchase_request.view|create` (menu permintaan), `purchase_request.view_all|assign_supplier`, `purchase_order.view|issue|cancel`, `supplier.view|create|edit`, `approval_setting.view|manage`, `report.purchase_request.view`, `report.purchase_order.view`. Tegaskan **tidak ada** `purchase_request.approve_*`. Test idempotensi (seed dua kali, `purchase_order.issue` count 1).
- [ ] **Step 2: Jalankan** `php artisan test --filter=MenuPermissionSeederTest` → FAIL.
- [ ] **Step 3: Tambah menu & permission di seeder** (urutan/penamaan mengikuti entri existing; deskripsi Bahasa Indonesia) dan konstanta `AuditEvent`; sesuaikan `AuditEventTest` (jumlah/label/severity).
- [ ] **Step 4: Buat trait `GrantsPermissions`** (dipakai semua test berikutnya).
- [ ] **Step 5: Jalankan** `php artisan test` → PASS. **Commit** `feat: seed procurement permissions and audit events`.

### Task 2: Master Supplier (CRUD) + sidebar

**Files:**
- Create: `database/migrations/2026_11_13_000001_create_suppliers_table.php`, `app/Models/Supplier.php`, `app/Http/Controllers/SupplierController.php`, `app/Http/Requests/StoreSupplierRequest.php`, `UpdateSupplierRequest.php`, `resources/views/suppliers/{index,create,edit,_form}.blade.php`, `tests/Feature/SupplierManagementTest.php`
- Modify: `routes/web.php`, `resources/views/partials/sidebar.blade.php` (grup Master Data, gate `supplier.view`), `app/Http/Controllers/LookupController.php` (+`suppliers`), `routes/web.php` (`lookup.suppliers`)

**Interfaces:**
- Produces: tabel `suppliers` (code unique ≤30, name ≤150, contact_person, phone, address, is_active default true, + HasAudit); `Supplier::scopeActive($q)`; route `lookup.suppliers` (`?q=` pencarian 20 hasil aktif; `?ids[]=` resolve; permission `supplier.view` ATAU `purchase_request.assign_supplier`), format `[['id'=>..,'text'=>"CODE — Nama"]]` seperti lookup lain.

- [ ] **Step 1: Test** — `SupplierManagementTest`: user tanpa `supplier.view` → 403 di index; index memfilter search code/name & status; create valid → tersimpan & redirect; kode duplikat → error validasi; edit mengubah data & toggle `is_active` (tanpa route delete); `lookup.suppliers` mengembalikan hanya supplier aktif untuk `q`, dan mengembalikan supplier nonaktif bila di-resolve via `ids[]`; sidebar menampilkan "Supplier" hanya untuk pemegang `supplier.view` (assert `route('suppliers.index')` ada).
- [ ] **Step 2: Jalankan** → FAIL.
- [ ] **Step 3: Implementasi** migrasi/model/controller/requests/views/route/sidebar/lookup mengikuti pola `RackController`/`ServiceCatalogController` (flat master) + `list-filter-bar` (search code/name + filter status).
- [ ] **Step 4: Jalankan** `php artisan test` → PASS. **Commit** `feat: add supplier master`.

---

## Tahap 2 — Permintaan Barang & penentuan supplier

### Task 3: Skema & model Permintaan Barang

**Files:**
- Create: migrasi `2026_11_13_000002_create_purchase_requests_table.php`, `…000003_create_purchase_request_lines_table.php`; `app/Support/PurchaseRequestStatus.php`, `app/Models/PurchaseRequest.php`, `app/Models/PurchaseRequestLine.php`; `tests/Feature/PurchaseRequestModelTest.php`

**Interfaces:**
- Produces: `PurchaseRequestStatus::WAITING_SUPPLIER|WAITING_APPROVAL|APPROVED|REJECTED|CANCELLED` (`'waiting_supplier'`, …). `purchase_requests`: number unique, branch_id FK, request_date, status default `waiting_supplier`, notes, total_amount decimal(18,2) null, submitted_at/submitted_by null, rejected_reason text null, HasAudit; index (branch_id, request_date, status). `purchase_request_lines`: purchase_request_id cascade, sparepart_branch_id FK, qty decimal(18,3), supplier_id FK null, unit_price decimal(18,2) null, line_total decimal(18,2) null, sort_order; CHECK `qty > 0`, `unit_price >= 0` (raw `DB::statement`, pola `goods_receipt_lines`); unique `(purchase_request_id, sparepart_branch_id)`.
- Produces: relasi `PurchaseRequest::branch()|lines()` (`orderBy('sort_order')`)`|approvals()|purchaseOrders()`; `PurchaseRequestLine::sparepartBranch()|supplier()`; `PurchaseRequest::isAssignmentComplete(): bool` (semua baris punya supplier_id & unit_price > 0); `PurchaseRequest::computeTotal(): float`.

- [ ] **Step 1: Test** — default status; CHECK qty>0 menolak 0; unique sparepart per permintaan; `isAssignmentComplete()` false saat ada baris tanpa harga/supplier, true saat lengkap; `computeTotal()` = jumlah `round(qty*unit_price,2)`.
- [ ] **Step 2–4:** FAIL → implementasi → PASS. **Commit** `feat: purchase request schema and models`. (Relasi `approvals()`/`purchaseOrders()` baru dipasang di Task 6/9 — di sini cukup lines.)

### Task 4: Buat / ubah / batalkan / daftar / detail Permintaan (Kepala Bengkel)

**Files:**
- Create: `app/Policies/PurchaseRequestPolicy.php`, `app/Http/Controllers/PurchaseRequestController.php`, `app/Http/Requests/StorePurchaseRequestRequest.php`, `UpdatePurchaseRequestRequest.php`, `resources/views/purchase-requests/{index,create,edit,show,no-access,_status_badge,_status_filter_select,_line_scripts}.blade.php`, `tests/Feature/PurchaseRequestManagementTest.php`, `tests/Feature/PurchaseRequestAuthorizationTest.php`
- Modify: `app/Providers/AuthServiceProvider.php` (daftarkan policy), `routes/web.php`, sidebar (grup baru **Pembelian**: heading + item "Permintaan Barang" gate `purchase_request.view` per cabang ATAU `view_all`/`assign_supplier`), `LookupController`/pola yang dipakai form Penerimaan untuk Select2 sparepart per cabang

**Interfaces:**
- Consumes: Task 1 permission; Task 3 model.
- Produces: `PurchaseRequestPolicy` dengan metode `view`, `update`, `cancel`, `assignSupplier` (di Task 5 dipakai), plus stub `approve/reject/reloadApprovers` ditambah Task 8. Aturan: `view` = `hasPermissionToInBranch('purchase_request.view', branch)` ATAU `hasPermissionTo('purchase_request.view_all')` ATAU `hasPermissionTo('purchase_request.assign_supplier')` ATAU `hasPermissionTo('approval_setting.manage')` ATAU user ada di `approvals.users` snapshot (Task 6 menambah). `update`/`cancel` = status `waiting_supplier` AND tidak ada baris yang `supplier_id` terisi AND `created_by == $user->id` AND `hasPermissionToInBranch('purchase_request.create', branch)`.
- Produces: route `purchase-requests.index|create|store|show|edit|update|cancel`. `PurchaseRequestController::store`: nomor via `DocumentNumberGenerator::next($branch,'RQ')`, status `waiting_supplier`, `syncLines()` (hapus & buat ulang, `line_total` null). Form create: pilih cabang dari `branchesWithPermission('purchase_request.create')`, baris sparepart (Select2 AJAX per cabang, kolom "Stok Saat Ini" via data lookup yang ada atau endpoint kecil `purchase-requests.stock/{sparepartBranch}`), header kolom baris, qty bulat >0, sparepart tidak boleh ganda.

- [ ] **Step 1: Test manajemen** — Kepala Bengkel (grant branch `view`+`create`) membuat permintaan → nomor berawalan `RQ/`, status `waiting_supplier`, baris tersimpan tanpa supplier/harga; cabang tanpa `create` → 403/validasi; baris ganda ditolak; qty 0/desimal ditolak; index hanya menampilkan cabangnya (user global `view_all` melihat semua); GET create/edit/show benar-benar dirender (200) — hindari kasus `@json`; edit meng-update baris; cancel mengubah status `cancelled` dan menulis `AuditEvent::PURCHASE_REQUEST_CANCELLED`.
- [ ] **Step 2: Test otorisasi (Policy)** — `update`/`cancel` ditolak untuk non-pembuat, saat status bukan `waiting_supplier`, dan saat ada baris yang sudah punya supplier; `view` diizinkan untuk `view_all`; ditolak untuk cabang lain.
- [ ] **Step 3: Jalankan** → FAIL.
- [ ] **Step 4: Implementasi** (controller/requests/policy/views/route/sidebar). `update` mengunci header + cek ulang status/baris-tanpa-supplier di transaksi.
- [ ] **Step 5: Jalankan** `php artisan test` → PASS. **Commit** `feat: purchase request create/edit/cancel`.

### Task 5: Penentuan supplier & harga (Kepala Gudang)

**Files:**
- Modify: `PurchaseRequestController` (+`assignSuppliers`), `resources/views/purchase-requests/show.blade.php` (mode assign), `routes/web.php`, policy (`assignSupplier`)
- Create: `app/Http/Requests/AssignPurchaseRequestSuppliersRequest.php`, `tests/Feature/PurchaseRequestAssignSupplierTest.php`

**Interfaces:**
- Produces: route `purchase-requests.assign-suppliers` (PATCH). Body: `lines[<line_id>][supplier_id]`, `lines[<line_id>][unit_price]` (harga bulat ≥0; `PATCH` menyimpan sebagian = "Simpan Draft"). `PurchaseRequestPolicy::assignSupplier` = status `waiting_supplier` AND `hasPermissionTo('purchase_request.assign_supplier')`. Validasi: supplier harus aktif (kecuali yang sudah terpasang di baris itu), line_id milik permintaan itu.
- Server menghitung `line_total = round(qty*unit_price,2)` bila harga terisi; `total_amount` TIDAK diisi di sini (diisi saat submit, Task 8).
- Detail view mode assign: tabel header kolom (Kode, Sparepart, Qty, Supplier (Select2 via `lookup.suppliers`), Harga Beli, Subtotal), subtotal & "Total saat ini" dihitung JS sebagai bantuan, banner "N dari M baris belum lengkap", tombol **Simpan Draft** (+ tombol Ajukan Approval ditambah Task 8 dan nonaktif sampai lengkap).

- [ ] **Step 1: Test** — user dengan `assign_supplier` menyimpan sebagian (satu baris terisi) → tersimpan, status tetap `waiting_supplier`; supplier nonaktif ditolak; harga negatif ditolak; baris milik permintaan lain ditolak; user tanpa `assign_supplier` (Kepala Bengkel) → 403; setelah ada supplier terisi, Kepala Bengkel tidak bisa `update`/`cancel` (regresi Task 4); halaman show merender mode assign untuk Kepala Gudang dan mode baca untuk Kepala Bengkel.
- [ ] **Step 2–4:** FAIL → implementasi (spinner/Select2 mengikuti Global Constraints; lock header + cek ulang status di transaksi) → PASS. **Commit** `feat: assign supplier and price on purchase requests`.

---

## Tahap 3 — Approval dinamis

### Task 6: Skema konfigurasi/snapshot approval + `ApprovalChainResolver`

**Files:**
- Create: migrasi `…000004_create_approval_levels_table.php` (+`approval_level_users`), `…000005_create_approval_rules_table.php` (+`approval_rule_levels`), `…000006_create_purchase_request_approvals_table.php` (+`purchase_request_approval_users`), `…000007_seed_default_approval_levels_and_rules.php`; `app/Support/PurchaseRequestApprovalStatus.php`; models `ApprovalLevel`, `ApprovalRule`, `PurchaseRequestApproval`; `app/Services/ApprovalChainResolver.php`, `app/Exceptions/ApprovalChainException.php`; `tests/Feature/ApprovalChainResolverTest.php`

**Interfaces:**
- Produces tabel & model sesuai spec §Data model: `approval_levels(name unique, sort_order, is_active)`, `approval_level_users(approval_level_id, user_id, unique)`, `approval_rules(max_amount decimal(18,2) null, sort_order)`, `approval_rule_levels(approval_rule_id, approval_level_id, unique)`, `purchase_request_approvals(purchase_request_id, step_no, approval_level_id null FK nullOnDelete, level_name, status default 'pending', acted_by null, acted_at null, notes null)`, `purchase_request_approval_users(purchase_request_approval_id, user_id, unique)`. Seed migrasi: level Manager(1)/Direktur(2)/CEO(3) tanpa user; aturan 10.000.000→[Manager], 50.000.000→[Manager,Direktur], NULL→[Manager,Direktur,CEO] (idempotent bila dijalankan di DB berisi).
- Produces: `PurchaseRequestApprovalStatus::PENDING|APPROVED|REJECTED|VOID`. Relasi: `ApprovalLevel::users()` (BelongsToMany User via `approval_level_users`), `ApprovalRule::levels()` (via `approval_rule_levels`), `PurchaseRequest::approvals()` (`orderBy('step_no')`), `PurchaseRequestApproval::users()` (via `purchase_request_approval_users`).
- Produces: `ApprovalChainResolver::resolve(float $total): array` → daftar langkah berurutan `[['level_id'=>int,'level_name'=>string,'user_ids'=>int[]], …]` (level diurut `sort_order`, hanya level aktif, user hanya yang `is_active`). Aturan terpilih = baris `max_amount` terkecil yang `>= $total` (inklusif); bila tidak ada, baris `max_amount IS NULL`. Melempar `ApprovalChainException` (pesan siap tampil, mis. "Approver level Direktur belum diatur") bila total ≤ 0, tidak ada aturan, aturan tanpa level aktif, atau ada level tanpa user aktif.

- [ ] **Step 1: Test resolver** (nama test = perilaku): `test_total_at_exactly_first_boundary_uses_first_rule` (10.000.000 → [Manager]); `test_total_one_rupiah_above_boundary_uses_second_rule`; `test_total_above_last_bound_uses_unbounded_rule`; `test_steps_follow_level_sort_order_not_rule_insertion_order`; `test_inactive_levels_and_inactive_users_are_excluded`; `test_level_without_active_users_throws_with_level_name_in_message`; `test_zero_total_throws`; `test_seed_migration_defaults_exist` (3 level, 3 aturan, aturan terakhir NULL).
- [ ] **Step 2: Jalankan** → FAIL.
- [ ] **Step 3: Implementasi** migrasi/model/resolver.
- [ ] **Step 4: Jalankan** `php artisan test` → PASS. **Commit** `feat: dynamic approval schema and chain resolver`.

### Task 7: Layar Pengaturan Approval (level, approver, aturan)

**Files:**
- Create: `app/Services/ApprovalConfigService.php`, `app/Http/Controllers/ApprovalSettingController.php`, `app/Http/Requests/SaveApprovalLevelsRequest.php`, `SaveApprovalRulesRequest.php`, `resources/views/approval-settings/{index,_tab_levels,_tab_rules,no-access}.blade.php`, `tests/Feature/ApprovalSettingTest.php`
- Modify: `routes/web.php`, sidebar (grup **Administrasi**, item "Pengaturan Approval" gate `approval_setting.view`)

**Interfaces:**
- Consumes: Task 6 model; `AuditEvent::APPROVAL_SETTING_CHANGED`.
- Produces: route `approval-settings.index` (`?tab=levels|rules`), `approval-settings.save-levels` (PUT), `approval-settings.save-rules` (PUT). `ApprovalConfigService::saveLevels(array $levels): void` — tiap item `{id?, name, sort_order, is_active, user_ids[], delete?}`; `saveRules(array $rules): void` — tiap item `{id?, max_amount|null, level_ids[]}` (urutan array = `sort_order`). Keduanya dalam transaksi, melempar `ValidationException` untuk pelanggaran, mencatat audit (old/new ringkas).
- Aturan validasi (dengan pesan Indonesia): nama level unik & wajib; user_ids hanya user aktif; **level yang dipakai aturan tidak boleh dihapus/dinonaktifkan**; `max_amount` aturan naik ketat; **aturan terakhir wajib `max_amount` null** dan hanya aturan terakhir yang boleh null; tiap aturan ≥1 level aktif tanpa duplikat; minimal 1 aturan.
- Izin: lihat = `approval_setting.view`; simpan = `approval_setting.manage`. UI sesuai mockup hal. 4–5: tab Level & Approver (Select2 multi user aktif, grip urutan, peringatan "masih approver pada N permintaan berjalan" dihitung dari `purchase_request_approval_users` pending), tab Aturan Nominal (input batas format ribuan, chip level, label "Cakupan" dihitung JS, kotak **Uji Aturan** memanggil `approval-settings.preview?total=` yang memakai `ApprovalChainResolver` TANPA melempar ke UI — kembalikan pesan error sebagai teks).

- [ ] **Step 1: Test** — tanpa `approval_setting.view` → 403; user `view` tanpa `manage` tidak bisa PUT (403); `saveRules` menolak batas tidak naik, aturan terakhir berbatas, dua aturan null, aturan tanpa level, level nonaktif; `saveLevels` menolak hapus/nonaktifkan level yang dipakai aturan, user nonaktif, nama ganda; penyimpanan valid mengubah data dan menulis audit `APPROVAL_SETTING_CHANGED`; `preview?total=38320000` pada data seed menghasilkan rantai Manager→Direktur (dengan user terpasang) dan pesan jelas bila level kosong; GET index kedua tab dirender 200.
- [ ] **Step 2–4:** FAIL → implementasi → PASS. **Commit** `feat: approval settings screens`.

### Task 8: Ajukan Approval, Setuju/Tolak, Inbox, Muat Ulang Approver

**Files:**
- Create: `app/Services/PurchaseRequestService.php`, `app/Http/Controllers/ApprovalController.php`, `resources/views/approvals/{index,no-access}.blade.php`, partial `purchase-requests/_approval_progress.blade.php`, `tests/Feature/PurchaseRequestApprovalFlowTest.php`
- Modify: `PurchaseRequestPolicy` (`submit`, `approve`, `reject`, `reloadApprovers`), `PurchaseRequestController` (aksi submit/approve/reject/reload-approvers) & `show.blade.php` (stepper progres, tombol Ajukan/Setuju/Tolak + modal Tolak dengan alasan, tombol Muat Ulang Approver), `routes/web.php`, sidebar (item "Approval Permintaan" + lencana jumlah), `LookupController` bila perlu

**Interfaces:**
- Consumes: `ApprovalChainResolver::resolve()`, `PurchaseOrderGenerator` (Task 9, dipanggil dari `approve`; di task ini cukup memanggil jika kelas ada — Task 9 melengkapi dan test PO ada di Task 9).
- Produces (`PurchaseRequestService`, semua di `DB::transaction`, lock header `purchase_requests` lebih dulu, cek ulang status): `submit(PurchaseRequest $pr, User $actor): void` — wajib status `waiting_supplier` & `isAssignmentComplete()`; hitung `total_amount`; `resolve($total)`; tulis `purchase_request_approvals` (+`…_users`), set status `waiting_approval`, `submitted_at/by`; catat `PURCHASE_REQUEST_SUBMITTED`; melempar `ApprovalChainException` → controller mengubahnya menjadi error validasi di halaman. `approve(PurchaseRequest $pr, User $actor, ?string $note = null): void` — langkah pending `step_no` terkecil; actor harus ada di snapshot langkah itu, aktif, bukan `submitted_by`/`created_by`, dan belum memproses langkah lain pada permintaan ini; tandai `approved`; bila langkah terakhir → status `approved` + `PurchaseOrderGenerator::generateFor($pr)` (transaksi yang sama); audit `PURCHASE_REQUEST_APPROVED`. `reject(PurchaseRequest $pr, User $actor, string $reason): void` — validasi sama; langkah → `rejected` + `notes`, langkah pending lain → `void`, status `rejected`, `rejected_reason`; audit `PURCHASE_REQUEST_REJECTED`. `reloadApprovers(PurchaseRequest $pr, User $actor): void` — hanya `approval_setting.manage`, status `waiting_approval`, hanya langkah pending terkecil: salin ulang `user_ids` dari konfigurasi level itu (jika level masih ada); error bila hasilnya kosong.
- Policy: `approve`/`reject` = `PurchaseRequestService::canAct($pr, $user): bool` (aturan di atas, dipakai juga untuk menampilkan tombol); `submit` = `assign_supplier` & status; `reloadApprovers` = `approval_setting.manage`.
- Route: `purchase-requests.submit|approve|reject|reload-approvers` (PATCH) dan `approvals.index` (`?tab=pending|history`). Inbox "Menunggu Saya" = permintaan `waiting_approval` yang langkah pending terkecilnya memuat user itu; "Riwayat Saya" = langkah yang di-`acted_by` user itu (+keputusan). Lencana sidebar = jumlah "Menunggu Saya". Item sidebar tampil bila user ada di `approval_level_users` ATAU `purchase_request_approval_users`.

- [ ] **Step 1: Test alur** — `test_submit_blocked_until_all_lines_have_supplier_and_price`; `test_submit_builds_chain_and_snapshots_approvers_for_38_jt` (2 langkah, user tersalin, `total_amount` benar); `test_submit_rejected_with_message_when_a_level_has_no_active_user`; `test_only_current_step_approver_can_approve` (Direktur belum bisa saat Manager pending; Direktur bisa setelahnya); `test_approving_last_step_marks_request_approved`; `test_reject_requires_reason_voids_remaining_steps_and_is_final` (setelah ditolak tak ada aksi lagi, 403/redirect); `test_requester_cannot_approve_own_request`; `test_same_user_cannot_process_two_steps_of_one_request`; `test_changing_settings_after_submit_does_not_affect_snapshot` (ganti user level/aturan setelah submit → approver tetap dari snapshot); `test_double_approve_is_effective_once` (panggil `approve` dua kali berturut-turut oleh dua user satu level → langkah hanya `approved` sekali, langkah berikutnya tidak ikut ter-approve); `test_reload_approvers_requires_manage_and_copies_current_config`.
- [ ] **Step 2: Test inbox** — approver level 1 melihat permintaan di "Menunggu Saya"; Direktur tidak melihat permintaan yang rantainya hanya Manager; setelah Manager setuju (2 level) permintaan muncul untuk Direktur dan hilang dari Manager; "Riwayat Saya" menampilkan keputusan; lencana sidebar benar; user bukan approver tidak melihat menu; halaman `approvals.index` & show dalam tiap status dirender 200.
- [ ] **Step 3: Jalankan** → FAIL.
- [ ] **Step 4: Implementasi** service/policy/controller/views/route/sidebar (stepper sesuai mockup hal. 11–14; modal Tolak: alasan wajib, tombol terkunci saat submit).
- [ ] **Step 5: Jalankan** `php artisan test` → PASS (test PO yang bergantung `PurchaseOrderGenerator` ditandai di Task 9). **Commit** `feat: purchase request approval flow and inbox`.

---

## Tahap 4 — Pembelian Barang

### Task 9: Skema Pembelian + `PurchaseOrderGenerator`

**Files:**
- Create: migrasi `…000008_create_purchase_orders_table.php`, `…000009_create_purchase_order_lines_table.php`; `app/Support/PurchaseOrderStatus.php`, `app/Models/PurchaseOrder.php`, `PurchaseOrderLine.php`, `app/Services/PurchaseOrderGenerator.php`; `tests/Feature/PurchaseOrderGeneratorTest.php`
- Modify: `PurchaseRequest` (relasi `purchaseOrders()`), `PurchaseRequestService::approve` (panggil generator — sudah dirancang Task 8)

**Interfaces:**
- Produces: `PurchaseOrderStatus::DRAFT|ISSUED|PARTIALLY_RECEIVED|COMPLETED|CANCELLED`. `purchase_orders(number unique, purchase_request_id FK, supplier_id FK, branch_id FK, order_date, status default draft, total_amount, notes, issued_at/issued_by null, HasAudit)`; `purchase_order_lines(purchase_order_id cascade, purchase_request_line_id FK, sparepart_branch_id FK, qty_ordered decimal(18,3) CHECK>0, unit_price, line_total, qty_received decimal(18,3) default 0 CHECK 0<=qty_received<=qty_ordered, sort_order)`. Relasi `PurchaseOrder::lines()` (`orderBy('sort_order')`)`|supplier()|branch()|purchaseRequest()|goodsReceipts()`; `PurchaseOrderLine::sparepartBranch()|purchaseOrder()`; `PurchaseOrderLine::remainingQty(): float`.
- Produces: `PurchaseOrderGenerator::generateFor(PurchaseRequest $pr): \Illuminate\Support\Collection` — satu PO `draft` per `supplier_id` pada baris permintaan (nomor `PO` via generator dokumen cabang permintaan, `order_date` hari bisnis, total per PO dihitung dari baris); dipanggil di dalam transaksi approve terakhir.

- [ ] **Step 1: Test** — permintaan 5 baris/3 supplier (data contoh spec: 12.900.000 / 10.920.000 / 14.500.000) → 3 PO draft dengan total benar & jumlah baris benar; seluruh baris PO menyalin qty/harga dari baris permintaan; nomor unik berawalan `PO/`; CHECK `qty_received <= qty_ordered` menolak; `test_final_approval_generates_pos_and_non_final_does_not` (lengkapi alur Task 8: setelah langkah terakhir `purchaseOrders()->count() == 3`; setelah langkah pertama dari dua, 0).
- [ ] **Step 2–4:** FAIL → implementasi → PASS. **Commit** `feat: purchase order schema and generation on final approval`.

### Task 10: UI Pembelian (daftar, detail, terbitkan, batalkan, cetak)

**Files:**
- Create: `app/Policies/PurchaseOrderPolicy.php`, `app/Http/Controllers/PurchaseOrderController.php`, `resources/views/purchase-orders/{index,show,no-access,_status_badge,_status_filter_select,print-pdf}.blade.php`, `tests/Feature/PurchaseOrderManagementTest.php`
- Modify: `AuthServiceProvider`, `routes/web.php`, sidebar (item "Pembelian Barang" gate `purchase_order.view`), `PurchaseRequest` show (daftar PO terbentuk dengan tautan)

**Interfaces:**
- Produces: route `purchase-orders.index|show|issue|cancel|print`. `PurchaseOrderPolicy`: `view` = `purchase_order.view` global; `issue` = status `draft` & `purchase_order.issue`; `cancel` = status ∈ {draft, issued} & `purchase_order.cancel` & **tidak ada Penerimaan terposting** (`goodsReceipts()->where('status','posted')->doesntExist()`). `issue`: lock PO, cek `draft`, set `issued` + `issued_at/by`, audit `PURCHASE_ORDER_ISSUED`. `cancel`: lock PO, cek ulang kelayakan, set `cancelled`, audit `PURCHASE_ORDER_CANCELLED`; Penerimaan draft milik PO itu ikut `cancelled`. `print`: PDF via `layouts.print` / DomPDF mengikuti `work-orders/print-pdf`.
- Daftar: filter cabang/supplier/status + search nomor, kolom progres diterima (% qty), tombol sesuai status (mockup hal. 15–16).

- [ ] **Step 1: Test** — tanpa `purchase_order.view` 403; user `issue` menerbitkan draft → `issued`, audit tercatat; terbitkan dua kali = efek sekali; batalkan PO issued tanpa penerimaan OK; batalkan setelah ada Penerimaan terposting → ditolak; pembatalan men-cancel draft Penerimaan terkait; baris PO tidak punya endpoint edit; GET index/show/print dirender (200, PDF `%PDF`); filter supplier/status bekerja.
- [ ] **Step 2–4:** FAIL → implementasi → PASS. **Commit** `feat: purchase order screens`.

---

## Tahap 5 — Penerimaan berbasis Pembelian

### Task 11: Skema & pembuatan Penerimaan dari PO

**Files:**
- Create: migrasi `…000010_add_purchase_order_columns_to_goods_receipts.php`; `tests/Feature/GoodsReceiptFromPurchaseOrderTest.php`
- Modify: `app/Models/GoodsReceipt.php` (+`purchaseOrder()`), `GoodsReceiptLine.php` (+`purchaseOrderLine()`; fillable), `GoodsReceiptController` (`create`, `store`, `edit`, `update`, `show`, `index`), `StoreGoodsReceiptRequest`, `UpdateGoodsReceiptRequest`, `GoodsReceiptPolicy` (tetap), views `goods-receipts/{create,edit,show,index}.blade.php`, route baru `goods-receipts.lookup.purchase-orders` + `goods-receipts.po-lines/{purchaseOrder}` (JSON), `tests/Feature/GoodsReceiptManagementTest.php` & `GoodsReceiptImportTest.php` (sesuaikan)

**Interfaces:**
- Produces: kolom `goods_receipts.purchase_order_id` (FK nullable, index) dan `goods_receipt_lines.purchase_order_line_id` (FK nullable). Route JSON `goods-receipts.lookup.purchase-orders` (`?q=`, hanya PO `issued|partially_received` di cabang yang dimiliki user dengan `receipt.create`) dan `goods-receipts.po-lines` (`[{purchase_order_line_id, code, name, ordered, received, remaining, unit_price}]`; otorisasi cabang PO).
- `store` (wajib `purchase_order_id`): validasi PO status layak & cabang ∈ `branchesWithPermission('receipt.create')`; `lines[]` = `{purchase_order_line_id, qty}`; baris qty 0 dilewati, minimal satu baris qty>0; tiap qty ≤ `remainingQty()` (cek lunak; keras di posting); `branch_id` diambil dari PO (bukan input), `purchase_price` disalin dari baris PO (input harga diabaikan). `reference_number` = No. Surat Jalan Supplier. `update` untuk Penerimaan ber-PO hanya mengubah qty/tanggal/referensi/catatan; Penerimaan lama **tanpa PO** memakai alur edit lama (cabang kode di request berdasar `$goodsReceipt->purchase_order_id`).
- Form create baru: pilih PO (Select2, spinner saat memuat baris), info supplier/cabang readonly, tabel baris dengan header (Kode, Sparepart, Dipesan, Sudah Diterima, Sisa, Qty Diterima Sekarang, Harga (dari PO), Subtotal), baris sisa 0 dinonaktifkan; **tombol import/download template dihapus dari form create** (endpoint import dibiarkan).
- Index/show: kolom Supplier & No. Pembelian (— untuk data lama), tautan ke PO.

- [ ] **Step 1: Test** — `test_cannot_create_receipt_without_purchase_order`; `test_create_from_issued_po_copies_prices_and_takes_branch_from_po`; `test_qty_above_remaining_rejected`; `test_draft_po_or_cancelled_po_not_receivable`; `test_user_without_receipt_create_in_po_branch_forbidden`; `test_po_lookup_lists_only_receivable_pos_in_users_branches`; `test_legacy_receipt_without_po_still_viewable_editable_and_listed_with_dash`; `test_create_form_has_no_import_button_but_import_endpoint_still_works`; GET create/edit/show/index 200.
- [ ] **Step 2: Jalankan** → FAIL. **Step 3: Implementasi.** **Step 4:** perbarui test lama yang membuat Penerimaan via `store` tanpa PO (ubah ke pembuatan model langsung untuk jalur legacy) → `php artisan test` PASS. **Commit** `feat: create goods receipts from purchase orders`.

### Task 12: Posting Penerimaan memperbarui progres Pembelian

**Files:**
- Modify: `GoodsReceiptController::post` (+ helper pribadi), `app/Http/Controllers/GoodsReceiptController.php` cancel (tetap), `tests/Feature/GoodsReceiptPostingProcurementTest.php` (baru)

**Interfaces:**
- Consumes: urutan lock baku (Global Constraints): `goods_receipts` → `purchase_orders` (bila `purchase_order_id`) → `purchase_order_lines` (id naik) → stok (sparepart_branch_id naik).
- Behavior `post()` untuk Penerimaan ber-PO: setelah lock header, kunci PO, kunci baris PO yang dirujuk, **cek `qty ≤ qty_ordered − qty_received` tiap baris di bawah lock**, bila melanggar → `ValidationException` (rollback total, tidak ada perubahan stok; pesan "Qty melebihi sisa pembelian untuk <kode>"); bila lolos: logika stok/average cost/`inventory_movements` yang ada tidak berubah (memakai `purchase_price` baris, yang berasal dari PO); tambah `qty_received`; status PO → `partially_received` atau `completed` (semua baris `qty_received == qty_ordered`). Penerimaan legacy (tanpa PO) tidak menyentuh PO sama sekali.

- [ ] **Step 1: Test** — `test_posting_adds_stock_average_cost_and_updates_po_progress` (PO 120 pesan; terima 80 → `partially_received`, stok +80, `qty_received` 80); `test_posting_remaining_marks_po_completed`; `test_second_draft_exceeding_remaining_is_rejected_after_first_posted_and_stock_unchanged` (Review Focus #4); `test_legacy_receipt_posting_unchanged_and_does_not_touch_po`; `test_posting_twice_is_noop_second_time`; `test_po_cancel_blocked_after_partial_receipt` (menyambung Task 10).
- [ ] **Step 2–4:** FAIL → implementasi → `php artisan test` PASS. **Commit** `feat: goods receipt posting updates purchase order progress`.

---

## Tahap 6 — Laporan & verifikasi

### Task 13: Laporan Permintaan Barang

**Files:**
- Create: `app/Http/Controllers/PurchaseRequestReportController.php`, `app/Exports/PurchaseRequestReportExport.php`, `resources/views/reports/purchase-request/{index,pdf,no-access}.blade.php`, `tests/Feature/PurchaseRequestReportControllerTest.php`, `PurchaseRequestReportExportTest.php`
- Modify: `routes/web.php` (`reports.purchase-requests.index|export-excel|pdf-preview|pdf-download`), sidebar (Laporan, gate `report.purchase_request.view`)

**Interfaces:**
- Pola persis `GoodsReceiptReportController` (`HandlesReportExport`, `resolveFilters`, `buildRowsQuery`, `buildSummary`), tetapi izin **global** `report.purchase_request.view` dan cakupan **semua cabang aktif** (kirim koleksi semua cabang ke `authorizeExport`). Filter: cabang[], status, supplier, tanggal dari/sampai, mode `rekap|detail`, sparepart (detail). Rekap kolom: Nomor, Tanggal, Cabang, Pemohon, Item, Total, Status, Posisi/Keputusan (level pending/penolak + alasan), Lama Proses (hari). Detail: per baris (Kode, Sparepart, Qty, Supplier, Harga, Subtotal). Ringkasan: jumlah, nilai total (tanpa `cancelled`), menunggu approval, ditolak.

- [ ] **Step 1: Test** — akses 403 tanpa permission; filter status/cabang/supplier/tanggal; mode detail memuat baris & filter sparepart; ringkasan tidak menghitung `cancelled`; Excel & PDF unduh dengan isi/headings benar (pola `GoodsReceiptReportExportTest`); `?q`-style input array tidak crash (`status[]=x`).
- [ ] **Step 2–4:** FAIL → implementasi → PASS. **Commit** `feat: purchase request report`.

### Task 14: Laporan Pembelian Barang

**Files:** analog Task 13 — `PurchaseOrderReportController`, `PurchaseOrderReportExport`, `resources/views/reports/purchase-order/*`, tests; route `reports.purchase-orders.index|export-excel|pdf-preview|pdf-download`; permission global `report.purchase_order.view`; item sidebar Laporan "Laporan Pembelian Barang" (gate permission itu) di `partials/sidebar.blade.php`.

**Interfaces:**
- Mode `rekap` (per PO: No, Tanggal, Supplier, Cabang, Nilai PO, Diterima (Rp, dari `qty_received*unit_price`), Outstanding, % Terima, Status), `detail` (per baris: Qty Pesan, Qty Terima, **Sisa**, Harga, Nilai), `supplier` (Supplier, Jumlah PO, Nilai Dipesan, Diterima, Outstanding). Filter cabang/supplier/status/tanggal/sparepart(detail) + `outstanding=1` (hanya PO `issued|partially_received`). Ringkasan: jumlah PO, nilai dipesan, nilai diterima, outstanding — **tidak menghitung `cancelled`**; "diterima" dihitung dari `qty_received` (bukan status).

- [ ] **Step 1: Test** — angka rekap/detail/per-supplier dengan data PO sebagian-diterima; `outstanding=1` menyembunyikan `completed|cancelled|draft`; PO `cancelled` tidak masuk ringkasan; izin & export seperti Task 13.
- [ ] **Step 2–4:** FAIL → implementasi → PASS. **Commit** `feat: purchase order report`.

### Task 15: Perubahan Laporan Penerimaan Barang

**Files:**
- Modify: `GoodsReceiptReportController`, `app/Exports/GoodsReceiptReportExport.php`, `resources/views/reports/goods-receipt/{index,pdf}.blade.php`, `tests/Feature/GoodsReceiptReportControllerTest.php`, `GoodsReceiptReportExportTest.php`

**Interfaces:**
- Tambah filter `supplier_id` (lewat `purchase_orders.supplier_id`; data lama tidak lolos filter bila supplier dipilih) pada `resolveFilters`/`applyReceiptFilters`; kolom **Supplier** & **No. Pembelian** pada rekap & detail (— bila tanpa PO), kolom **Qty Dipesan** pada detail; export Excel/PDF ikut (heading & `map()` diperbarui); `filterSummaryText` memuat supplier.

- [ ] **Step 1: Test** — penerimaan ber-PO menampilkan supplier & nomor PO; legacy menampilkan "-"; filter supplier hanya mengembalikan penerimaan ber-PO supplier itu; ringkasan total tidak berubah untuk data lama; export memuat heading baru.
- [ ] **Step 2–4:** FAIL → implementasi → PASS. **Commit** `feat: supplier and purchase order columns in goods receipt report`.

### Task 16: Verifikasi akhir

- [ ] **Step 1:** `php artisan test` — seluruh suite hijau (catat jumlah test baru).
- [ ] **Step 2:** `php artisan migrate:fresh --seed` pada DB lokal bersih berhasil; jalankan seeder demo bila ada untuk memastikan tidak patah.
- [ ] **Step 3: Alur uji manual di browser** (server dev dari direktori checkout ini; verifikasi fingerprint halaman baru sebelum percaya hasil; ukur geometri layout dengan `getBoundingClientRect` bila menyentuh CSS): buat user Kepala Bengkel, Kepala Gudang, Manager, Direktur, Petugas Pembelian, Petugas Gudang; atur level/approver/aturan di Pengaturan Approval; jalankan alur Rp 38,3 jt (2 level) → 3 PO → terbitkan → terima sebagian → posting → cek kartu stok, progres PO, 3 laporan; lakukan satu skenario Tolak.
- [ ] **Step 4:** Audit `@json(` di view baru (pola aman), cek `git status` bersih dari file sementara, ringkas hasil untuk user. Tidak ada merge/push tanpa persetujuan user.
