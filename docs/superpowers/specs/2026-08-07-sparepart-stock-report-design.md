# Laporan Sparepart / Stok (Sparepart Stock Report) — Design

## 1. Tujuan

Laporan read-only atas kondisi stok sparepart per cabang — status kritis/habis/tersedia, nilai inventaris, dan rincian on-hand/reserved/available. Ini adalah placeholder Laporan ke-5 (terakhir) yang diaktifkan di proyek ini, menutup seluruh track Laporan.

## 2. Arsitektur

- **Modul standalone, single-action**: `App\Http\Controllers\SparepartStockReportController@index`.
- **Route**: `GET /reports/sparepart-stock` → `reports.sparepart-stock.index`, didaftarkan di dalam grup `Route::prefix('reports')->name('reports.')` yang sudah ada di `routes/web.php`, tepat setelah baris route `invoice-pkb-gap.index`.
- **Tidak ada migration baru, tidak ada Policy baru.** Query murni Eloquent/query builder di atas tabel yang sudah ada: `sparepart_branches`, `sparepart_branch_stocks`, `spareparts`.
- **Basis entitas: `SparepartBranch`** (bukan `Sparepart`) — setiap baris laporan mewakili satu kombinasi sparepart+cabang, sesuai bentuk data stok yang memang per-cabang.

## 3. Koreksi Kode Permission (ditemukan saat eksplorasi, dikonfirmasi bersama pengguna)

Kode permission yang dipakai adalah **`report.sparepart.view`** — kode yang sudah nyata ter-seed (`MenuPermissionSeeder.php:259`) dan sudah menggerbang placeholder sidebar saat ini (`sidebar.blade.php:168`), **bukan** `report.sparepart_stock.view`. Memakai kode yang salah akan membuat link sidebar dan otorisasi laporan tidak singkron.

## 4. Otorisasi

Branch-scoped, pola persis 4 laporan sebelumnya:

```php
$permittedBranches = $user->branchesWithPermission('report.sparepart.view');
if ($permittedBranches->isEmpty()) {
    return view('reports.sparepart-stock.no-access');
}
```

Base query di-scope ke `whereIn('sparepart_branches.branch_id', $permittedBranches->pluck('id'))` lebih dulu; filter `branch_ids[]` dari form hanya mempersempit (di-intersect terhadap set yang diizinkan), tidak pernah dipercaya sendirian.

## 5. Mekanisme Inti — Join ke `sparepart_branch_stocks`

`sparepart_branch_stocks` adalah tabel 1:1 sungguhan (PK = `sparepart_branch_id`), bukan ledger append-only seperti `inventory_movements` — jadi cukup **`join()` SQL biasa**, bukan correlated subquery seperti di Laporan Gap:

```php
$query = SparepartBranch::query()
    ->join('sparepart_branch_stocks', 'sparepart_branch_stocks.sparepart_branch_id', '=', 'sparepart_branches.id')
    ->select('sparepart_branches.*')
    ->addSelect([
        'sparepart_branch_stocks.on_hand_qty',
        'sparepart_branch_stocks.reserved_qty',
    ]);
```

Kolom `sparepart_branches.*` dan `sparepart_branch_stocks.*` di-`select` eksplisit (bukan `->with('stock')` saja) supaya `on_hand_qty`/`reserved_qty` tersedia langsung sebagai atribut Eloquent di query yang sama dengan filter/paginasi — konsisten dengan pola `pkb_total as` di Laporan Gap, hanya di sini lewat join biasa karena relasinya benar-benar 1:1.

**Search kode/nama** tetap lewat `whereHas('sparepart', ...)` (bukan join ke `spareparts`, menghindari 3-way join yang tidak perlu):
```php
->when($search, function ($q, $term) {
    $escaped = addcslashes($term, '%_\\');
    $q->whereHas('sparepart', function ($inner) use ($escaped) {
        $inner->where('code', 'like', "%{$escaped}%")
            ->orWhere('name', 'like', "%{$escaped}%");
    });
})
```

## 6. Definisi Status Stok — Reuse Formula Dashboard yang Sudah Ada

`DashboardController::computeCriticalStockCount()` (baris 94-99) sudah mendefinisikan "kritis" sebagai `is_active=true AND minimum_stock > 0 AND (on_hand_qty - reserved_qty) < minimum_stock`. Laporan ini **mereuse formula available/minimum yang sama**, diperluas jadi 3 kategori mutually-exclusive untuk filter `stock_status`:

| Kategori | Kondisi SQL |
|---|---|
| **Habis** | `sparepart_branch_stocks.on_hand_qty = 0` |
| **Kritis/Minimum** | `sparepart_branch_stocks.on_hand_qty > 0 AND sparepart_branches.minimum_stock > 0 AND (sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) < sparepart_branches.minimum_stock` |
| **Tersedia** | sisanya (`on_hand_qty > 0` dan tidak memenuhi kondisi Kritis) |
| **Semua** (default) | tanpa filter tambahan |

Reject-to-safe-default: nilai `stock_status` selain `{habis, kritis, tersedia, semua}` jatuh ke `semua` (default sesuai instruksi pengguna — beda dari Laporan Gap yang defaultnya bukan "semua").

**Tidak ada filter `is_active`** — baik item aktif maupun nonaktif tetap muncul di laporan tanpa exclusion tersembunyi (nilai stok fisik pada item yang baru dinonaktifkan tetap relevan untuk diaudit), mengikuti preseden "no hidden status exclusion" dari Laporan Invoice/PKB.

## 7. Filter Form

| Field | Query param | Tipe | Default |
|---|---|---|---|
| Cabang | `branch_ids[]` | multiselect (partial `branch-multiselect-filter`) | kosong = semua cabang yang diizinkan |
| Status Stok | `stock_status` | select | `semua` |
| Search (kode/nama) | `search` | text | kosong |
| Mode Tampilan | `mode` | select | `rekap` |

Mode toggle pola reject-to-safe-default identik dengan laporan lain: `$mode = request('mode') === 'detail' ? 'detail' : 'rekap';`.

## 8. Summary Cards

Satu `selectRaw` agregat di atas query yang sudah difilter (clone sebelum paginasi):

```php
$summary = (clone $query)->selectRaw(
    'COUNT(*) as total_jenis_item, ' .
    'COALESCE(SUM(sparepart_branch_stocks.on_hand_qty), 0) as total_qty_on_hand, ' .
    'COALESCE(SUM(CASE WHEN sparepart_branches.minimum_stock > 0 AND (sparepart_branch_stocks.on_hand_qty - sparepart_branch_stocks.reserved_qty) < sparepart_branches.minimum_stock THEN 1 ELSE 0 END), 0) as total_item_kritis, ' .
    'COALESCE(SUM(sparepart_branch_stocks.on_hand_qty * sparepart_branches.selling_price), 0) as total_nilai_inventaris'
)->first();
```

4 stat card:
1. **Total Jenis Item** — `total_jenis_item` (COUNT baris `SparepartBranch` dalam set terfilter — satu baris = satu SKU per cabang)
2. **Total Qty On-Hand** — `total_qty_on_hand`
3. **Total Item Kritis** — `total_item_kritis`. **Catatan penting**: formula ini **independen dari filter `stock_status` yang sedang aktif** dan **mencakup item Habis juga** (selama `minimum_stock > 0`) — ini adalah KPI konsisten-lintas-halaman yang meniru persis `computeCriticalStockCount()` milik Dashboard, bukan sekadar hitungan hasil filter "Kritis/Minimum" yang sedang dipilih user (yang secara sengaja mengecualikan Habis untuk kebutuhan drill-down filter).
4. **Total Nilai Inventaris** — `total_nilai_inventaris` (nilai stok fisik `on_hand_qty * selling_price`, bukan `available_qty` — konvensi akuntansi inventaris standar: nilai barang secara fisik, bukan yang belum ter-reservasi)

## 9. Tampilan Dual-Mode

### 9.1 Divergensi disengaja dari Laporan PKB/Invoice/Gap

Ketiga laporan sebelumnya punya bentuk 1-ke-banyak sungguhan (satu Invoice/PKB punya banyak baris line item), sehingga mode Detail = pecah jadi satu baris per line. **`SparepartBranch` tidak punya turunan line item** — setiap baris laporan SUDAH mewakili satu unit granular (satu sparepart di satu cabang). Karena itu, mode Detail di laporan ini **bukan pemecahan baris**, melainkan **baris yang sama dengan Rekap, hanya kolom lebih lengkap** (rincian on_hand/reserved/available terpisah, bukan cuma nilai gabungan). Paginasi `simplePaginate(15)` di level `SparepartBranch` untuk KEDUA mode (bukan hanya Rekap) — tidak ada isu jumlah baris per halaman bervariasi seperti di Laporan Gap, karena baris memang 1:1 dengan halaman di kedua mode.

### 9.2 Mode Rekap (default) — 7 kolom

| Kolom | Sumber |
|---|---|
| Kode | `sparepartBranch.sparepart.code` |
| Nama Sparepart | `sparepartBranch.sparepart.name` |
| Cabang | `sparepartBranch.branch.name` |
| Stok Min | `sparepartBranch.minimum_stock` |
| Stok On-Hand | `sparepartBranch.on_hand_qty` |
| Nilai Inventaris | `on_hand_qty * selling_price` (dihitung per-baris di PHP, hanya 15 baris/halaman) |
| Status | badge, lihat 9.4 |

### 9.3 Mode Detail — 10 kolom

| Kolom | Sumber |
|---|---|
| Kode | `sparepartBranch.sparepart.code` |
| Nama Sparepart | `sparepartBranch.sparepart.name` |
| Cabang | `sparepartBranch.branch.name` |
| Stok Min | `sparepartBranch.minimum_stock` |
| On-Hand | `sparepartBranch.on_hand_qty` |
| Reserved | `sparepartBranch.reserved_qty` |
| Available | `on_hand_qty - reserved_qty` (dihitung per-baris) |
| Harga Satuan | `sparepartBranch.selling_price` |
| Nilai Total | `on_hand_qty * selling_price` |
| Status | badge, lihat 9.4 |

Tidak ada baris/kolom yang bisa kosong ("—") pada kedua mode — setiap `SparepartBranch` yang sudah tercipta otomatis punya baris `sparepart_branch_stocks` (dijamin oleh `SparepartBranch::booted()`), jadi tidak ada kasus tepi "item tanpa data stok" seperti kasus "invoice tanpa detail" di laporan lain.

### 9.4 Badge Status

| Kondisi | Badge |
|---|---|
| Habis (`on_hand_qty = 0`) | `<span class="status-dot status-danger">Habis</span>` |
| Kritis/Minimum | `<span class="status-dot status-warning">Kritis</span>` |
| Tersedia | `<span class="status-dot status-active">Tersedia</span>` |

## 10. Paginasi

`simplePaginate(15)` di level `SparepartBranch`, berlaku sama di kedua mode (lihat 9.1). `withQueryString()` dipakai agar link paginasi membawa filter aktif.

## 11. Sidebar Wiring

`resources/views/partials/sidebar.blade.php:168-174` — placeholder saat ini:
```blade
@if ($user->branchesWithPermission('report.sparepart.view')->isNotEmpty())
<li class="nav-item">
    <span class="nav-link nav-link-disabled">
        <i class="bi bi-file-earmark-spreadsheet me-2"></i> Laporan Sparepart
        <span class="badge-soon">Segera Hadir</span>
    </span>
</li>
@endif
```
Diganti dengan link nyata ke `reports.sparepart-stock.index`, mempertahankan ikon `bi-file-earmark-spreadsheet` dan label teks "Laporan Sparepart" apa adanya.

## 12. Halaman No-Access

`resources/views/reports/sparepart-stock/no-access.blade.php` — pola identik dengan 4 laporan sebelumnya: judul + ikon `bi-file-earmark-spreadsheet`, pesan "Anda belum memiliki akses laporan sparepart di cabang manapun."

## 13. Ruang Lingkup yang Sengaja Dikecualikan

- **Tidak ada link drill-down** dari Kode/Nama Sparepart. Tidak seperti Invoice/PKB yang punya halaman detail baca-saja (`invoices.show`/`work-orders.show`), `SparepartBranch` hanya punya halaman `edit` (ber-Policy tulis `sparepart.edit`, permission berbeda dari `report.sparepart.view`) — memaksakan link ke sana akan mencampur otorisasi baca-laporan dengan otorisasi tulis-data-master. Baris laporan ini murni teks, tanpa tautan, konsisten dengan prinsip "tidak ada aksi dari laporan" yang sudah ditetapkan di Laporan Gap.
- **Tidak ada filter `is_active`** — lihat bagian 6.
- **Tidak ada export** (CSV/PDF) — sama seperti 4 laporan sebelumnya.
- **Tidak ada breakdown per-transaksi/riwayat mutasi** — itu sudah menjadi domain Kartu Stok (`stock-card.index`), laporan ini murni snapshot kondisi stok saat ini, bukan riwayat pergerakannya.
