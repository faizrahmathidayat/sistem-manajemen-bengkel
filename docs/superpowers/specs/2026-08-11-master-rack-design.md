# Master Rak (Global) & Relasi ke Master Sparepart Cabang — Design Spec

**Date:** 2026-08-11
**Status:** Approved

## 1. Background

Sparepart per cabang (`sparepart_branches`) saat ini menyimpan lokasi rak sebagai teks bebas di kolom `rack_number` — diisi manual tanpa validasi, sehingga rawan typo/duplikasi penamaan ("Rak A-1" vs "rak a1" vs "A1"). Milestone ini menambahkan modul Master Rak global (`racks`) yang terstruktur dan unik per kode, lalu menghubungkannya ke `sparepart_branches` lewat `rack_id` sebagai pengganti input bebas tersebut.

## 2. Codebase Audit (ringkasan)

- **Template modul global**: `ServiceCatalog` (`app/Models/ServiceCatalog.php`, `ServiceCatalogController`, `StoreServiceCatalogRequest`/`UpdateServiceCatalogRequest`, `resources/views/service-catalogs/*`) adalah contoh persis pola yang dibutuhkan Rack — model global sederhana (`code`, `is_active`, audit fields via trait `HasAudit`), controller CRUD tanpa Policy (otorisasi langsung `$this->authorize('service.view')` dst. karena permission-nya non-branch-scoped), views `_form`/`create`/`edit`/`index` dengan `partials.list-filter-bar` & `partials.empty-state`. Rack akan meniru pola ini 1:1, hanya tanpa field `name`/`default_price` (Rack cuma butuh `code`).
- **`sparepart_branches`** (`app/Models/SparepartBranch.php`, migration `2026_08_02_000014_create_sparepart_branches_table.php`): sudah punya kolom `rack_number` (string(30), nullable, free-text) yang ditulis di 3 tempat (`SparepartBranchController::store()`, `storeExisting()`, form edit) dan dibaca di `index.blade.php`. FK pattern untuk relasi opsional di tabel ini: `foreignId(...)->nullable()->constrained(...)->nullOnDelete()` (belum ada contoh persis di tabel ini, tapi konsisten dengan pattern audit FK `nullOnDelete()` yang sudah dipakai untuk `created_by`/`updated_by`).
- **Otorisasi**: `sparepart.*` permission BRANCH-SCOPED (dicek via `SparepartBranchPolicy` + `hasPermissionToInBranch()`), sedangkan `service.*` GLOBAL (dicek langsung via `$user->can()`, tanpa Policy). Rack sebagai modul global murni harus dapat permission code baru sendiri (`rack.view`/`rack.create`/`rack.edit`) — bukan reuse `sparepart.*` (branch-scoped, secara semantik terikat manajemen sparepart per cabang) maupun `service.*` (secara semantik terikat jasa).
- **`MenuPermissionSeeder.php`**: setiap modul global punya blok menu sendiri, pola persis:
  ```php
  ['code' => 'master.service', 'name' => 'Jasa Service', 'is_branch_scoped' => false, 'permissions' => [...]],
  ```
  Rack akan menambah blok `master.rack` baru dengan pola sama, diletakkan setelah blok `master.service` (baris ~195).
- **Sidebar** (`resources/views/partials/sidebar.blade.php`): grup "Master Data" (baris 79-124) menampilkan link tiap modul global lewat `@can('xxx.view')`, kondisi gate di baris 79 juga perlu ditambah `|| $user->can('rack.view')` agar grup section tetap muncul untuk user yang hanya punya akses Rack.
- **Test existing yang akan terdampak**: `SparepartBranchIndexAndCreateTest.php` & `SparepartBranchEditAndDeactivateTest.php` — keduanya set/assert `rack_number` di beberapa test (termasuk `test_update_saves_rack_price_minimum_stock_without_touching_is_active`), akan disesuaikan ke `rack_id`. Seeder `DemoMasterDataSeeder.php` juga mengisi `rack_number` untuk data demo — **di luar cakupan**, dibiarkan apa adanya (lihat §6).

## 3. Decisions

1. **`rack_id` menggantikan `rack_number` di UI** (dikonfirmasi bersama user): dropdown pilih Rak (`rack_id`) menggantikan input teks bebas `rack_number` di ketiga form (`create`, `create-existing`, `edit`). Kolom `rack_number` **tidak dihapus** dari DB (kompatibilitas data lama, dan menghindari migration destruktif yang di luar cakupan milestone ini) — hanya tidak lagi ditampilkan/diisi dari form manapun. Index & detail menampilkan `rack->code`, bukan `rack_number`.
2. **Permission baru**: `rack.view`, `rack.create`, `rack.edit` — global (`is_branch_scoped => false`), mengikuti pola `service.*` persis. Tidak ada `rack.delete` (Rack hanya bisa dinonaktifkan via toggle `is_active`, sama seperti ServiceCatalog yang juga tidak punya aksi delete).
3. **Tidak ada Policy class untuk Rack** — otorisasi langsung `$this->authorize('rack.xxx')` di controller, sama seperti `ServiceCatalogController` (karena permission-nya global, bukan per-record/branch).
4. **Dropdown Rak di form sparepart-branch** menampilkan hanya rak dengan `is_active = true`, diurutkan berdasarkan `code`. Memakai `<select>` HTML biasa (bukan Select2 AJAX) — jumlah rak diperkirakan kecil, konsisten dengan pola dropdown branch-select di `sparepart-branches/create.blade.php` yang juga plain `<select>`.

## 4. Design

### 4.1 Migration — `racks`

File baru: `database/migrations/2026_08_11_000002_create_racks_table.php`.

```php
Schema::create('racks', function (Blueprint $table) {
    $table->id();
    $table->string('code', 30)->unique();
    $table->boolean('is_active')->default(true);
    $table->unsignedBigInteger('created_by')->nullable();
    $table->unsignedBigInteger('updated_by')->nullable();
    $table->timestamps();

    $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
    $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
});
```

### 4.2 Model — `app/Models/Rack.php`

```php
class Rack extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['code', 'is_active', 'created_by', 'updated_by'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function sparepartBranches()
    {
        return $this->hasMany(SparepartBranch::class);
    }
}
```

### 4.3 FormRequests

`StoreRackRequest::rules()`: `'code' => ['required', 'string', 'max:30', 'unique:racks,code']`.
`UpdateRackRequest::rules()`: `'code' => ['required', 'string', 'max:30', Rule::unique('racks', 'code')->ignore($this->route('rack'))]`.

### 4.4 Controller — `app/Http/Controllers/RackController.php`

Struktur identik `ServiceCatalogController` (index dengan search by `code`, create, store, edit, update) — `$this->authorize('rack.view'|'rack.create'|'rack.edit')`, search hanya kolom `code` (Rack tidak punya `name`).

### 4.5 Views — `resources/views/racks/`

`_form.blade.php`, `create.blade.php`, `edit.blade.php`, `index.blade.php` — struktur identik `service-catalogs/*` tapi tanpa field `name`/`default_price`. Index table: kolom Kode, Status, Aksi. Empty-state icon `bi-grid-3x3-gap` (ikon rak/grid), judul "Belum ada rak".

### 4.6 Routes

```php
Route::prefix('racks')->name('racks.')->group(function () {
    Route::get('/', [RackController::class, 'index'])->name('index');
    Route::get('/create', [RackController::class, 'create'])->name('create');
    Route::post('/', [RackController::class, 'store'])->name('store');
    Route::get('/{rack}/edit', [RackController::class, 'edit'])->name('edit');
    Route::put('/{rack}', [RackController::class, 'update'])->name('update');
});
```
Diletakkan di dalam grup `Route::middleware(['auth'])`, setelah grup `service-catalogs`.

### 4.7 Permission seeder

Tambah blok baru di `MenuPermissionSeeder.php` setelah blok `master.service`:
```php
[
    'code' => 'master.rack',
    'name' => 'Rack',
    'is_branch_scoped' => false,
    'permissions' => [
        ['code' => 'rack.view', 'resource' => 'rack', 'action' => 'view', 'description' => 'Melihat rack'],
        ['code' => 'rack.create', 'resource' => 'rack', 'action' => 'create', 'description' => 'Membuat rack'],
        ['code' => 'rack.edit', 'resource' => 'rack', 'action' => 'edit', 'description' => 'Mengubah rack'],
    ],
],
```

### 4.8 Sidebar

Di `resources/views/partials/sidebar.blade.php`:
- Baris 79: tambah `|| $user->can('rack.view')` ke kondisi gate grup "Master Data".
- Setelah blok `@can('service.view') ... @endcan` (baris 117-123): tambah blok baru
  ```blade
  @can('rack.view')
  <li class="nav-item">
      <a href="{{ route('racks.index') }}" class="nav-link {{ request()->routeIs('racks.*') ? 'active' : '' }}">
          <i class="bi bi-grid-3x3-gap me-2"></i> Rack
      </a>
  </li>
  @endcan
  ```

### 4.9 Migration — `add_rack_id_to_sparepart_branches_table`

File baru: `database/migrations/2026_08_11_000003_add_rack_id_to_sparepart_branches_table.php`.

```php
Schema::table('sparepart_branches', function (Blueprint $table) {
    $table->foreignId('rack_id')->nullable()->after('rack_number')->constrained('racks')->nullOnDelete();
});
```
`down()`: drop foreign key lalu drop kolom `rack_id`.

### 4.10 Model — `app/Models/SparepartBranch.php`

- `$fillable` tambah `'rack_id'`.
- Relasi baru:
  ```php
  public function rack()
  {
      return $this->belongsTo(Rack::class);
  }
  ```
- `rack_number` **tetap ada** di `$fillable` (tidak dihapus dari model) untuk kompatibilitas data lama, tapi tidak lagi diisi dari controller (§4.11).

### 4.11 FormRequests sparepart-branch

Di `StoreSparepartRequest`, `StoreSparepartToBranchRequest`, `UpdateSparepartBranchRequest`: ganti baris
```php
'rack_number' => ['nullable', 'string', 'max:30'],
```
menjadi
```php
'rack_id' => ['nullable', 'integer', 'exists:racks,id'],
```

### 4.12 Controller — `SparepartBranchController`

- `index()`: eager-load `rack` — `SparepartBranch::with(['sparepart', 'stock', 'rack'])`.
- `create()`/`createExisting()`: kirim variabel `$racks = Rack::where('is_active', true)->orderBy('code')->get()` ke view.
- `edit()`: `$sparepartBranch->load(['sparepart', 'rack'])`, kirim `$racks` yang sama ke view.
- `store()`: ganti `'rack_number' => $data['rack_number'] ?? null` menjadi `'rack_id' => $data['rack_id'] ?? null`.
- `storeExisting()`: perubahan yang sama.
- `update()`: `$request->validated()` sudah otomatis membawa `rack_id` bila rules diubah sesuai §4.11 — tidak perlu perubahan struktural tambahan.

### 4.13 Views sparepart-branches

**`create.blade.php`** & **`create-existing.blade.php`**: ganti input teks `rack_number` menjadi:
```blade
<div class="col-md-4 mb-3">
    <label for="rack_id" class="form-label">Rak</label>
    <select name="rack_id" id="rack_id" class="form-select @error('rack_id') is-invalid @enderror">
        <option value="">-- Tanpa Rak --</option>
        @foreach ($racks as $rack)
            <option value="{{ $rack->id }}" {{ (int) old('rack_id') === $rack->id ? 'selected' : '' }}>{{ $rack->code }}</option>
        @endforeach
    </select>
    @error('rack_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
```

**`edit.blade.php`**: field sama, pre-selected dari `old('rack_id', $sparepartBranch->rack_id)`.

**`index.blade.php`**: ganti `<td>{{ $sparepartBranch->rack_number ?? '-' }}</td>` menjadi `<td>{{ optional($sparepartBranch->rack)->code ?? '-' }}</td>`.

## 5. Testing Strategy

- **`RackManagementTest.php`** (baru, mirror `ServiceCatalogManagementTest.php`): index list, forbidden tanpa permission, store create, validasi required, tolak duplikat code, forbidden create tanpa permission, update edit + toggle nonaktif, update boleh pakai code sendiri (tidak dianggap duplikat), forbidden update tanpa permission, search by code, empty-state + CTA visibility, filter-bar render.
- **`SparepartBranchIndexAndCreateTest.php`**: sesuaikan test yang mengisi/assert `rack_number` menjadi `rack_id` + `Rack::create(...)`; tambah test baru: dropdown Rak di form create hanya menampilkan rak `is_active = true`; index menampilkan `rack->code`.
- **`SparepartBranchEditAndDeactivateTest.php`**: `test_update_saves_rack_price_minimum_stock_without_touching_is_active` disesuaikan untuk assert `rack_id` (bukan `rack_number`).
- **Model test**: relasi `SparepartBranch::rack()` dan `Rack::sparepartBranches()`, termasuk perilaku `nullOnDelete()` — menghapus sebuah `Rack` tidak menghapus `SparepartBranch` terkait, hanya men-set `rack_id` jadi null.
- **Integration test** (Task akhir milestone, mirip pola milestone sebelumnya): alur penuh create Rack → assign ke sparepart-branch via form → index menampilkan kode rak → nonaktifkan Rack → dropdown form berikutnya tidak lagi menampilkan rak tsb (tapi sparepart-branch yang sudah ter-assign tetap menyimpan `rack_id` yang sama, hanya sekarang menunjuk ke rak nonaktif).

## 6. Out of Scope

- Kolom `rack_number` **tidak dihapus** dari tabel `sparepart_branches` — dibiarkan (unused going-forward) untuk menghindari migration destruktif; pembersihan lanjutan (drop kolom) bisa jadi milestone terpisah nanti.
- `database/seeders/DemoMasterDataSeeder.php` yang saat ini mengisi `rack_number` untuk data demo **tidak diubah** — di luar cakupan; data demo tetap menampilkan "-" untuk kolom Rak baru sampai di-assign manual atau seeder diperbarui di milestone lain.
- Tidak ada aksi hapus/delete untuk Rack — hanya create/edit/toggle `is_active`, konsisten dengan ServiceCatalog.
- Tidak ada batasan jumlah sparepart per rak, kapasitas rak, atau hierarki rak (mis. gudang > rak > slot) — Rack adalah label lokasi datar/flat, sesuai spesifikasi awal.
