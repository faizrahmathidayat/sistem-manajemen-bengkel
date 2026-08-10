# Penyesuaian Master Data Mekanik (NIP & Tanggal Bergabung) — Design Spec

**Date:** 2026-08-11
**Status:** Approved

## 1. Background

Master Mekanik (`mechanics` table) saat ini hanya menyimpan `name`, `phone`, `email`, `address`, `is_active`. Bengkel membutuhkan dua field tambahan untuk keperluan administrasi kepegawaian:

- **NIP** (Nomor Induk Pegawai) — ID pegawai internal, diinput manual, wajib, dan unik.
- **Tanggal Bergabung** (join date) — opsional.

Saat spec ini ditulis, tabel `mechanics` di database sudah berisi **9 baris data existing** tanpa NIP. Ini menjadi pertimbangan utama strategi migrasi.

## 2. Codebase Audit (ringkasan)

- `app/Models/Mechanic.php` — fillable: `name, phone, email, address, is_active`; tidak ada `$casts` untuk tanggal.
- `app/Http/Controllers/MechanicController.php` — `index()` (search by name/phone + branch filter), `create()`, `store()`, `show()` (dua tab: Profil & Cabang), `update()`. **Tidak ada `edit()` action maupun `destroy()`.**
- `app/Http/Requests/StoreMechanicRequest.php` / `UpdateMechanicRequest.php` — rules identik untuk `name, phone, email, address`.
- Struktur view aktual **berbeda dari asumsi awal**: tidak ada `_form.blade.php` maupun `edit.blade.php`. Yang ada:
  - `resources/views/mechanics/create.blade.php` — form mandiri untuk tambah mekanik.
  - `resources/views/mechanics/show.blade.php` — halaman detail dengan dua tab Bootstrap (Profil, Cabang).
  - `resources/views/mechanics/_tab_profil.blade.php` — form edit inline (POST ke `mechanics.update` via PUT), di-include di tab Profil. Ini adalah "form edit" yang sesungguhnya.
  - `resources/views/mechanics/_tab_cabang.blade.php` — checkbox penugasan cabang, tidak terdampak perubahan ini.
  - `resources/views/mechanics/index.blade.php` — tabel listing dengan search bar (`partials.list-filter-bar`) dan empty-state.
- `app/Http/Controllers/LookupController.php::mechanics()` — endpoint JSON untuk Select2 dropdown pemilihan mekanik di form PKB (`create.blade.php`/`edit.blade.php` work-orders). Response per item: `['id' => ..., 'text' => $mechanic->name]`. Select2 (`public/js/select2-ajax-picker.js`) merender label langsung dari field `text` (tanpa `templateResult` kustom), sehingga NIP harus digabung langsung ke string `text` di controller jika ingin tampil di dropdown.
- Test suite: `MechanicManagementTest.php` (CRUD + search + filter + empty-state, 16 test), `MechanicBranchTabTest.php` (branch assignment, 4 test), `MechanicServiceModelTest.php` (model-level, 3 test relevan). Semua pakai helper lokal `userWithPermissions()` (permission global, bukan branch-scoped) yang di-copy-paste per file — pola ini akan diikuti, bukan diganti.
- Routes: `routes/web.php` mendefinisikan grup `mechanics.*` manual (bukan `Route::resource`) — tidak ada route `edit`/`destroy` untuk mekanik itu sendiri.

## 3. Decisions

Keputusan berikut dikonfirmasi bersama user sebelum spec ini ditulis:

1. **Strategi NIP untuk data lama:** Backfill otomatis via migration dengan placeholder unik berbasis `id` (format `LEGACY-{id}` dengan zero-padding, mis. `LEGACY-000001`) untuk 9 baris existing.
2. **Search di index Mekanik:** NIP ditambahkan sebagai kolom yang dicari, selain nama & telepon.
3. **Label dropdown PKB (`LookupController::mechanics`):** NIP ditampilkan dalam label opsi dropdown pemilihan mekanik di form PKB, format `"{name} ({nip})"` — konsisten dengan pola gabungan label yang sudah dipakai untuk sparepart (`code — name`) di controller yang sama.
4. **Constraint DB untuk `nip` — REVISI:** Keputusan awal (`NOT NULL` ketat di DB) dibatalkan setelah audit lebih lanjut menemukan **48 pemanggilan `Mechanic::create()` tanpa `nip` tersebar di 21 file test tak terkait** (dashboard, invoice, work order, lookup, dll — bukan hanya test khusus mekanik), dan tidak ada `MechanicFactory` yang bisa menyuplai default unik. Menerapkan `NOT NULL` akan mematahkan seluruh 48 call-site tersebut dan memperluas lingkup Task 1 secara signifikan di luar milestone ini.
   **Keputusan final:** kolom `nip` tetap **nullable** di level DB (dengan `unique` index — MySQL mengizinkan banyak baris `NULL` pada kolom unique). "Wajib diisi" ditegakkan **hanya di layer validasi** (`StoreMechanicRequest`/`UpdateMechanicRequest`), yaitu jalur HTTP form yang sesungguhnya dipakai user. 48 test call-site lain tidak perlu disentuh — tetap membuat mekanik dengan `nip = null`, yang sah secara skema. Backfill 9 baris lama tetap dilakukan (§4.1) agar data existing konsisten dan tidak menampilkan "-" selamanya, tapi sifatnya kini kosmetik/best-effort, bukan syarat migrasi yang mem-block constraint.

## 4. Design

### 4.1 Migration

File baru: `database/migrations/2026_08_11_000001_add_nip_and_join_date_to_mechanics_table.php`.

- `up()`:
  1. Tambah kolom `nip` (`string(50)`, nullable, `unique`, posisi setelah `name`) dan `join_date` (`date`, nullable, posisi setelah `nip`) dalam satu `Schema::table` pass.
  2. Backfill best-effort: iterasi baris `mechanics` yang `nip` masih null, set `nip = 'LEGACY-' . str_pad($id, 6, '0', STR_PAD_LEFT)`.
- `down()`: drop kolom `nip`, `join_date` (drop unique index otomatis ikut ter-drop bersama kolom di MySQL).

`nip` **tetap nullable** di skema (lihat §3 poin 4) — backfill hanya mengisi data lama secara best-effort agar tidak tampil kosong di UI, bukan untuk memenuhi constraint `NOT NULL` yang memang tidak diterapkan.

### 4.2 Model — `app/Models/Mechanic.php`

```php
protected $fillable = [
    'name', 'phone', 'email', 'address', 'is_active', 'nip', 'join_date',
];

protected $casts = [
    'is_active' => 'boolean',
    'join_date' => 'date',
];
```

### 4.3 Validasi — `StoreMechanicRequest` / `UpdateMechanicRequest`

`StoreMechanicRequest::rules()` tambah:
```php
'nip' => ['required', 'string', 'max:50', 'unique:mechanics,nip'],
'join_date' => ['nullable', 'date'],
```

`UpdateMechanicRequest::rules()` tambah:
```php
'nip' => ['required', 'string', 'max:50', Rule::unique('mechanics', 'nip')->ignore($this->route('mechanic'))],
'join_date' => ['nullable', 'date'],
```
(`use Illuminate\Validation\Rule;` ditambahkan ke `UpdateMechanicRequest`.)

`MechanicController::store()`/`update()` tidak perlu perubahan struktural — keduanya sudah meneruskan `$request->validated()` langsung ke `Mechanic::create()`/`update()`.

### 4.4 Controller — `MechanicController::index()`

Klausa search diperluas dari `name OR phone` menjadi `name OR phone OR nip`:
```php
->when($search, function ($query, $q) {
    $query->where(function ($inner) use ($q) {
        $escaped = addcslashes($q, '%_\\');
        $inner->where('name', 'like', '%' . $escaped . '%')
            ->orWhere('phone', 'like', '%' . $escaped . '%')
            ->orWhere('nip', 'like', '%' . $escaped . '%');
    });
})
```

### 4.5 Lookup — `LookupController::mechanics()`

```php
->map(fn (Mechanic $mechanic) => [
    'id' => $mechanic->id,
    'text' => $mechanic->nip ? "{$mechanic->name} ({$mechanic->nip})" : $mechanic->name,
])
```

### 4.6 Views

**`resources/views/mechanics/create.blade.php`** — tambah dua field baru dalam row bersama field existing (pola row `col-md-6` yang sudah ada untuk phone/email diperluas atau field baru ditambah sebagai row baru):
```blade
<div class="row">
    <div class="col-md-6 mb-3">
        <label for="nip" class="form-label">NIP</label>
        <input type="text" name="nip" id="nip" value="{{ old('nip') }}" class="form-control @error('nip') is-invalid @enderror" maxlength="50" required>
        @error('nip')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label for="join_date" class="form-label">Tanggal Bergabung</label>
        <input type="date" name="join_date" id="join_date" value="{{ old('join_date') }}" class="form-control @error('join_date') is-invalid @enderror">
        @error('join_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
```
Ditempatkan setelah field Nama Mekanik, sebelum row Telepon/Email.

**`resources/views/mechanics/_tab_profil.blade.php`** — field yang sama, pre-filled dari `old('nip', $mechanic->nip)` dan `old('join_date', optional($mechanic->join_date)->format('Y-m-d'))`.

**`resources/views/mechanics/index.blade.php`**:
- Search placeholder di `partials.list-filter-bar` diubah jadi `'Cari nama, telepon, atau NIP...'`.
- Header tabel tambah dua kolom: `<th>NIP</th>` dan `<th>Tanggal Bergabung</th>`, ditempatkan setelah kolom Nama.
- Body baris tambah `<td>{{ $mechanic->nip }}</td>` dan `<td>{{ optional($mechanic->join_date)->format('d/m/Y') ?? '-' }}</td>`.
- `colspan` pada empty-state row disesuaikan dari `4` menjadi `6`.

## 5. Testing Strategy

- **`MechanicServiceModelTest.php`**: tambah assertion `nip`/`join_date` di test fillable existing, plus test baru untuk cast `join_date` sebagai instance `Carbon`/`date`.
- **`MechanicManagementTest.php`**:
  - `test_store_creates_mechanic` diperluas untuk assert `nip`/`join_date` tersimpan.
  - `test_store_validates_required_fields` diperluas assert `nip` required.
  - Test baru: `test_store_rejects_duplicate_nip`.
  - Test baru: `test_update_rejects_nip_already_used_by_another_mechanic`.
  - Test baru: `test_update_allows_keeping_own_nip_unchanged`.
  - Test baru: `test_index_search_by_nip_filters_results`.
  - Test baru: `test_index_lists_nip_and_join_date_columns`.
- **Migration/backfill**: `RefreshDatabase` menjalankan seluruh migration dari kosong di setiap test, sehingga skenario "data lama tanpa NIP" tidak pernah tereproduksi secara alami lewat PHPUnit — backfill logic dalam migration ini murni untuk data existing di database dev/production. Backfill **diverifikasi manual**: pada Task 1, setelah migration dijalankan (`php artisan migrate`) terhadap database dev yang sudah punya 9 baris mekanik existing, verifikasi lewat `php artisan tinker` bahwa ke-9 baris tersebut mendapat `nip` unik berformat `LEGACY-000001` dst. Automated test memverifikasi bagian deterministic & reproducible: constraint `unique` bekerja untuk baris baru, validasi `required` di layer FormRequest, dan 48 call-site `Mechanic::create()` lain di seluruh suite tetap lulus tanpa perubahan karena `nip` nullable di skema (§ test uniqueness di atas).
- **Lookup**: test baru di file yang relevan (`WorkOrderLookupTest.php` atau `MechanicManagementTest.php`) — `test_mechanic_lookup_label_includes_nip`.

## 6. Out of Scope

- Field NIP/join_date **tidak** ditambahkan ke `resources/views/work-orders/*` (show/print PKB) — PKB hanya menampilkan nama mekanik seperti sekarang, kecuali label dropdown pemilihan mekanik (§4.5).
- Tidak ada perubahan pada `_tab_cabang.blade.php` atau `MechanicBranchAssignmentController`.
- Tidak ada penambahan route `edit`/`destroy` untuk mekanik — struktur routing existing (show+inline-edit) dipertahankan apa adanya.
