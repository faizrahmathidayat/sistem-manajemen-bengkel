# Permintaan Barang, Pembelian Barang & Perubahan Penerimaan Barang — Design

Status: **diimplementasikan** (branch `feature/permintaan-pembelian-barang`); dokumen ini sudah diperbarui sesuai perilaku final (14 Nov 2026). Rancangan untuk client + mockup UI ada di PDF `2026-11-13-permintaan-pembelian-barang-rancangan.pdf`.

## Tujuan

Hari ini stok bertambah lewat Penerimaan Barang yang dibuat bebas (tanpa supplier, tanpa persetujuan). Perubahan ini memasang alur pengadaan yang terkontrol:

```
Permintaan Barang → Penentuan Supplier → Approval Berjenjang → Pembelian Barang → Penerimaan Barang → Stok bertambah
 (Kepala Bengkel)    (Kepala Gudang)      (Manager/Dir/CEO)    (per supplier)      (per pembelian)
```

## Goals

- Modul **Permintaan Barang**, **Pembelian Barang**, dan master **Supplier** baru.
- Approval berjenjang berdasarkan total permintaan, dengan **level, approver, dan aturan nominal yang dapat dikonfigurasi** di layar Pengaturan Approval (tanpa mengubah kode).
- **Penerimaan Barang** hanya dibuat berdasarkan Pembelian; stok tetap bertambah saat Penerimaan diposting.
- Laporan Permintaan Barang, Laporan Pembelian Barang, dan penambahan kolom Supplier/No. Pembelian pada Laporan Penerimaan Barang.

## Non-goals

- Pembayaran ke supplier (hutang dagang), retur pembelian, pembatalan Penerimaan setelah diposting.
- Satu barang dipecah ke lebih dari satu supplier dalam satu permintaan.
- Approval pada Pembelian (approval hanya pada Permintaan).

## Anti-goal

Stok bertambah tanpa melalui permintaan yang disetujui; approver menyetujui di luar urutan; total yang disetujui berbeda dengan total yang dibeli.

## Keputusan user (dikonfirmasi 2026-11-13)

1. **Akses:** Permintaan dibuat per cabang oleh Kepala Bengkel (permission per cabang). Kepala Gudang, para approver, dan petugas Pembelian berlaku **lintas cabang** (permission global).
2. **Approval berjenjang, sepenuhnya dapat dikonfigurasi** (dikonfirmasi 2026-11-13: awalnya saya salah mengira konfigurasi tidak perlu, lalu level juga diminta dinamis). Di layar *Pengaturan Approval* (Administrasi) admin mengatur:
   - **Level**: tambah, ubah nama, urutkan, nonaktifkan (contoh awal: Manager, Direktur, CEO; bisa ditambah mis. "Kepala Cabang"). Urutan level = urutan persetujuan.
   - **Approver per level**: boleh lebih dari satu user, salah satu cukup.
   - **Aturan nominal**: tabel baris "total sampai Rp X (inklusif)" + level yang wajib menyetujui. Baris terakhir tanpa batas ("di atas batas sebelumnya"). Ada "Uji Aturan" untuk mencoba nominal.

   Data awal (seed migrasi, bebas diubah):

   | Total permintaan | Rantai (berurutan) |
   |---|---|
   | ≤ Rp 10.000.000 | Manager |
   | > 10 jt s.d. ≤ 50 jt | Manager → Direktur |
   | > 50 jt | Manager → Direktur → CEO |

   - Saat *Ajukan Approval*, rantai (nama level) dan daftar approver **disalin** ke permintaan (`purchase_request_approvals` + `purchase_request_approval_users`); mengubah level/aturan/approver tidak memengaruhi permintaan berjalan.
   - Validasi simpan pengaturan: batas atas naik ketat, aturan terakhir tanpa batas, tiap aturan ≥ 1 level aktif, nama level unik; level yang dipakai aturan tidak boleh dihapus/dinonaktifkan.
   - *Ajukan Approval* ditolak bila ada level dalam rantai yang belum punya approver aktif.
   - Satu orang hanya memproses satu level per permintaan; pemohon/pengaju tidak boleh menyetujui permintaannya sendiri (keputusan saya).
   - Bila semua approver level yang sedang giliran nonaktif, admin (`approval_setting.manage`) memakai *Muat Ulang Approver* untuk menyalin ulang dari pengaturan saat ini (keputusan saya).
3. **Harga:** Kepala Gudang mengisi supplier **dan harga beli** per barang saat assign. Satu barang = satu supplier.
4. **Pembelian:** dibuat otomatis (draft, satu per supplier) saat approval terakhir disetujui. Petugas Pembelian menerbitkannya. Status: Draft → Diterbitkan → Diterima Sebagian → Selesai (+ Dibatalkan). Tanpa approval lagi.
5. **Penerimaan:** wajib berdasarkan Pembelian yang diterbitkan; boleh bertahap; qty tidak boleh melebihi sisa; harga dari Pembelian (average cost tetap dihitung dari harga itu). Penerimaan lama tetap terlihat dan masuk laporan.
6. **Supplier:** master global (kode, nama, kontak, telepon, alamat, aktif).

## Keputusan saya (default, mohon dikoreksi jika salah)

- Penolakan wajib alasan; Permintaan yang ditolak **final** (buat ulang dari awal, tanpa tombol salin).
- **Stok minimal (blokir ketat, dikonfirmasi user):** hanya barang dengan **stok tersedia (on_hand − reserved) < stok minimal** cabang yang boleh diminta. Dicek saat simpan, dan dicek ulang di dalam transaksi penyimpanan dengan lock baris stok (urut `sparepart_branch_id`) agar perubahan stok bersamaan tidak meloloskan permintaan. Barang tanpa stok minimal (0) tidak dapat diminta. Pencarian sparepart di form hanya menampilkan barang yang memenuhi.
- **Pembatalan (dikonfirmasi user):** `waiting_supplier` → pemohon (walau supplier sudah terisi) atau pemegang `assign_supplier` (Kepala Gudang), alasan opsional; `waiting_approval` → hanya Kepala Gudang, alasan wajib, langkah pending di-`void`, keputusan yang sudah masuk tetap; `approved`/`rejected`/`cancelled` final (permintaan yang sudah disetujui dibatalkan lewat pembatalan Pembelian). Kolom `cancel_reason`, `cancelled_at`, `cancelled_by`. Mengubah isi permintaan tetap hanya oleh pemohon dan hanya sebelum ada supplier terisi.
- **Pilih item saat approval (dikonfirmasi user 16 Nov 2026):** tiap level punya pengaturan *Boleh pilih item* (`approval_levels.can_select_items`; nilainya juga disalin ke `purchase_request_approvals.can_select_items` saat diajukan, tetapi langkah yang masih menunggu membaca pengaturan level saat ini, jadi perubahan pengaturan langsung berlaku; salinan hanya cadangan bila level sudah dihapus). Approver level itu boleh membatalkan centang baris pada layar detail; perubahan terkirim bersama tombol *Setuju* (bukan simpan terpisah). Item yang sudah tidak dicentang oleh level sebelumnya **terkunci** (tidak bisa dicentang lagi), minimal satu item tetap dicentang (menolak semuanya = *Tolak*), level tanpa hak hanya melihat. Baris menyimpan `is_selected` + `deselected_step_no`. Rantai approval **tetap** seperti saat diajukan; `total_amount` mengikuti item yang dicentang dan `submitted_total` menyimpan total awal. Pembelian hanya dibuat dari item yang dicentang. Laporan Permintaan: nilai total hanya menghitung item dicentang; mode Detail punya kolom *Dipilih*.
- **Urgent (16 Nov 2026):** `purchase_requests.is_urgent` (default false), diisi lewat saklar di form Permintaan Baru / Ubah; lencana URGENT tampil di daftar, detail, inbox approval, dan laporan (kolom Urgent di Excel/PDF).
- **No. Rekening supplier (16 Nov 2026):** `suppliers.account_number` di master. Saat menentukan supplier, kolom No. Rekening per baris terisi otomatis dari master dan bisa diubah (disimpan di `purchase_request_lines.supplier_account_number`; master tidak ikut berubah) selama permintaan belum diajukan approval. Bila dikosongkan, dipakai nomor dari master; bila supplier dilepas, nomor dikosongkan. Nomor pertama yang terisi per supplier disalin ke `purchase_orders.supplier_account_number`, tampil di detail dan cetak PO.
- **Kelayakan rantai:** *Ajukan Approval* ditolak (dengan pesan) bila rantai mustahil diselesaikan — pemohon/pengaju dikecualikan dan tiap langkah butuh orang berbeda; permintaan yang tertahan (semua approver langkah aktif nonaktif) diberi peringatan, dapat dimuat ulang approvernya oleh admin atau dibatalkan Kepala Gudang. Form keputusan membawa `step_no` sehingga halaman lama tidak dapat menyetujui langkah yang salah.
- Satu orang hanya boleh menyetujui **satu level** per permintaan (meski terdaftar di beberapa level).
- Nomor dokumen: Permintaan `RQ`, Pembelian `PO` (format `DocumentNumberGenerator` yang ada); Penerimaan tetap `PB`.
- Pembelian yang dibatalkan bersifat final dan tidak mengembalikan baris ke Permintaan (permintaan baru harus dibuat). Pembelian hanya bisa dibatalkan jika belum ada penerimaan terposting.
- Draft Penerimaan lama (tanpa Pembelian) masih boleh diedit/diposting/dibatalkan; pembuatan Penerimaan baru tanpa Pembelian ditutup. Fitur import Excel baris Penerimaan dihapus dari form baru (baris datang dari Pembelian).
- Permission laporan baru bersifat global (semua cabang, dengan filter cabang).

## Status & alur

**Permintaan** (`purchase_requests.status`): `waiting_supplier` → `waiting_approval` → `approved` | `rejected`; `cancelled` (lihat aturan pembatalan di atas; dari `waiting_supplier` atau `waiting_approval`). Posisi approval = level pending terkecil.

**Approval** (`purchase_request_approvals.status`): `pending` | `approved` | `rejected` | `void` (level yang tidak sempat diproses karena ditolak).

**Pembelian** (`purchase_orders.status`): `draft` → `issued` → `partially_received` → `completed`; `cancelled`.

**Penerimaan** (`goods_receipts.status`): tetap `draft` → `posted` | `cancelled`.

## Data model

Tabel baru (semua `bigint` PK, `HasAudit` created_by/updated_by + timestamps, engine MySQL, mengikuti konvensi existing):

- `suppliers`: code (unique), name, contact_person, phone, address, is_active.
- `purchase_requests`: number (unique), branch_id, request_date, status, total_amount (snapshot saat diajukan), notes, submitted_at, submitted_by, rejected_reason, cancel_reason, cancelled_at, cancelled_by.
- `purchase_request_lines`: purchase_request_id, sparepart_branch_id, qty (>0), supplier_id (null sampai di-assign), unit_price, line_total, sort_order.
- Konfigurasi approval (diatur admin): `approval_levels` (name unik, sort_order, is_active); `approval_level_users` (approval_level_id, user_id, unique); `approval_rules` (max_amount inklusif, NULL = tanpa batas hanya pada aturan terakhir; sort_order); `approval_rule_levels` (approval_rule_id, approval_level_id, unique).
- Snapshot per permintaan: `purchase_request_approvals` (purchase_request_id, step_no 1..n, approval_level_id nullable, level_name, status, acted_by, acted_at, notes) dan `purchase_request_approval_users` (purchase_request_approval_id, user_id). Dibuat saat "Ajukan Approval" oleh `ApprovalChainResolver`: pilih aturan dengan batas terkecil ≥ total, urutkan level menurut `sort_order`, pastikan tiap level punya ≥ 1 user aktif, lalu salin.
- `purchase_orders`: number (unique), purchase_request_id, supplier_id, branch_id, order_date, status, total_amount, notes, issued_at, issued_by.
- `purchase_order_lines`: purchase_order_id, purchase_request_line_id, sparepart_branch_id, qty_ordered, unit_price, line_total, qty_received (diperbarui saat posting Penerimaan dengan lock).

Perubahan tabel existing:

- `goods_receipts`: + `purchase_order_id` (FK, nullable — null hanya untuk data lama).
- `goods_receipt_lines`: + `purchase_order_line_id` (FK, nullable).

Check constraint mengikuti pola `goods_receipt_lines` (qty > 0, harga ≥ 0).

## Aturan bisnis & konkurensi

- Semua transisi status di dalam `DB::transaction` + `lockForUpdate()` pada header, dengan guard status (pola `GoodsReceiptController::post`), sehingga klik ganda / dua approver bersamaan tidak menggandakan efek.
- **Ajukan Approval** hanya aktif jika semua baris punya supplier dan harga > 0; total dihitung ulang di server.
- **Approve/Tolak** hanya oleh pemegang permission level pending terkecil, dan bukan orang yang sudah menyetujui level lain pada permintaan yang sama. Approve level terakhir → status `approved` + buat Pembelian draft per supplier dalam transaksi yang sama.
- **Tolak** → permintaan `rejected`, level pending lain `void`, alasan wajib.
- **Terbitkan Pembelian** hanya dari `draft`. **Posting Penerimaan** memeriksa `qty ≤ qty_ordered − qty_received` di bawah lock, menambah stok + average cost (harga dari PO), menulis `inventory_movements`, memperbarui `qty_received` dan status Pembelian.
- Seluruh aksi penting (ajukan, setuju, tolak, terbitkan, batalkan) dicatat di audit log mengikuti pola `AuditEvent` yang ada.

## Permission baru (seed di `MenuPermissionSeeder`)

| Menu | Scope | Permission |
|---|---|---|
| Permintaan Barang | per cabang | `purchase_request.view`, `purchase_request.create` (buat/ubah; batalkan miliknya selama menunggu supplier) |
| Penentuan Supplier | global | `purchase_request.view_all`, `purchase_request.assign_supplier` (assign + ajukan approval + batalkan permintaan, termasuk yang sedang menunggu approval) |
| Pengaturan Approval (Administrasi) | global | `approval_setting.view`, `approval_setting.manage` |
| Approval Permintaan | — | tanpa kode permission: akses = user ada di snapshot approver level yang sedang giliran |
| Pembelian Barang | global | `purchase_order.view`, `purchase_order.issue`, `purchase_order.cancel` |
| Supplier (master) | global | `supplier.view`, `supplier.create`, `supplier.edit` |
| Laporan Permintaan Barang | global | `report.purchase_request.view` |
| Laporan Pembelian Barang | global | `report.purchase_order.view` |

Penerimaan memakai `receipt.*` yang sudah ada (per cabang). Policy baru: `PurchaseRequestPolicy`, `PurchaseOrderPolicy`; `GoodsReceiptPolicy` disesuaikan.

## Layar

Detail setiap layar (mockup) ada di PDF. Ringkas: Supplier (list+form); Permintaan (list, buat dengan kolom Stok Tersedia / Minimal, detail: tentukan supplier, menunggu approval dengan panel Progres Approval, modal Tolak dan Batalkan, hasil Ditolak/Dibatalkan); Pengaturan Approval (tab Level & Approver, tab Aturan Nominal); Inbox Approval; Pembelian (list, detail); Penerimaan (buat dari Pembelian, list dengan kolom baru); tiga laporan; sidebar grup "Pembelian".

## Laporan

1. **Laporan Permintaan Barang** — filter cabang/status/tanggal/supplier; mode Rekap (per dokumen: total, status, posisi approval) dan Detail (per baris). Export Excel/PDF.
2. **Laporan Pembelian Barang** — filter cabang/supplier/status/tanggal + "hanya belum selesai diterima"; mode Rekap, Detail (qty pesan/terima/sisa), Per Supplier. Export Excel/PDF.
3. **Laporan Penerimaan Barang** (existing) — tambah kolom Supplier dan No. Pembelian, filter supplier; data lama tampil "-".

## Urutan implementasi (usulan untuk rencana)

1. Supplier + permission seed. 2. Permintaan + assign supplier. 3. Pengaturan Approval + approval berjenjang. 4. Pembelian. 5. Refactor Penerimaan. 6. Laporan. Setiap tahap punya feature test sendiri (otorisasi, transisi status, resolusi aturan dinamis termasuk batas inklusif & aturan terakhir tanpa batas, validasi pengaturan, snapshot tidak terpengaruh perubahan aturan, konkurensi posting).

## Diserahkan ke pembangun

Struktur controller/request/policy, nama route, tampilan error validasi, dan detail Select2 lookup (supplier, Pembelian aktif) mengikuti pola modul Penerimaan/Stock Transfer yang ada.
