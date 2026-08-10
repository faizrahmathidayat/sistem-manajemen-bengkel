# Penyesuaian Master Data Mekanik (NIP & Tanggal Bergabung) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah field `nip` (Nomor Induk Pegawai, manual, unik) dan `join_date` (Tanggal Bergabung) ke Master Mekanik, tampil di form Tambah/Edit dan tabel Index, serta muncul di label dropdown pemilihan mekanik pada form PKB.

**Architecture:** Migration menambah dua kolom nullable ke `mechanics` (dengan backfill best-effort untuk 9 baris lama), lalu diikuti perubahan Model, FormRequest validation, Controller search clause, Lookup label, dan tiga view (`create`, `_tab_profil`, `index`).

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL, Bootstrap 5.3.3, PHPUnit.

## Global Constraints

- `nip` **nullable** di level DB (dengan `unique` index) — TIDAK boleh `NOT NULL`. Alasan: 48 pemanggilan `Mechanic::create()` tanpa `nip` tersebar di 21 file test tak terkait, tanpa `MechanicFactory` untuk default. "Wajib" hanya ditegakkan di `StoreMechanicRequest`/`UpdateMechanicRequest`.
- `nip`: `string`, max 50 karakter, unique across `mechanics.nip`.
- `join_date`: `date`, nullable, tidak ada batasan range.
- Tidak ada `_form.blade.php` maupun `edit.blade.php` di module ini — form "edit" adalah `resources/views/mechanics/_tab_profil.blade.php` (di-include di `show.blade.php`, POST via PUT ke `mechanics.update`).
- Ikuti pola helper test lokal `userWithPermissions()` yang sudah di-copy-paste di tiap file test mekanik (`MechanicManagementTest.php`, `MechanicBranchTabTest.php`) — jangan diganti dengan trait bersama.
- Backfill 9 baris mekanik lama bersifat best-effort/kosmetik (tidak memenuhi constraint apa pun) — diverifikasi manual via `php artisan tinker`, bukan automated test.
- Referensi spec lengkap: [docs/superpowers/specs/2026-08-11-mechanic-improvements-design.md](../specs/2026-08-11-mechanic-improvements-design.md)

---

### Task 1: Migration + Model

**Files:**
- Create: `database/migrations/2026_08_11_000001_add_nip_and_join_date_to_mechanics_table.php`
- Modify: `app/Models/Mechanic.php`
- Test: `tests/Feature/MechanicServiceModelTest.php`

**Interfaces:**
- Produces: `mechanics.nip` (nullable string, unique), `mechanics.join_date` (nullable date). `Mechanic::$fillable` includes `'nip'`, `'join_date'`. `Mechanic::$casts['join_date'] = 'date'`.

- [ ] **Step 1: Write the failing test**

Edit `tests/Feature/MechanicServiceModelTest.php`, replace `test_mechanic_can_be_created_with_fillable_fields` with a version that also covers `nip`/`join_date`, and add a new cast test right after it:

```php
    public function test_mechanic_can_be_created_with_fillable_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $mechanic = Mechanic::create([
            'name' => 'Agus Setiawan',
            'phone' => '081234567890',
            'nip' => 'NIP-001',
            'join_date' => '2020-01-15',
        ]);

        $this->assertSame('Agus Setiawan', $mechanic->name);
        $this->assertSame('NIP-001', $mechanic->nip);
        $this->assertTrue($mechanic->is_active);
        $this->assertSame($user->id, $mechanic->created_by);
    }

    public function test_mechanic_join_date_is_cast_to_a_date(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'join_date' => '2020-01-15']);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $mechanic->join_date);
        $this->assertSame('2020-01-15', $mechanic->join_date->format('Y-m-d'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=MechanicServiceModelTest`
Expected: FAIL — `test_mechanic_can_be_created_with_fillable_fields` fails on `assertSame('NIP-001', $mechanic->nip)` (property doesn't exist / is null because `nip` isn't in `$fillable` yet and the column doesn't exist); `test_mechanic_join_date_is_cast_to_a_date` errors because the `join_date` column doesn't exist yet.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_11_000001_add_nip_and_join_date_to_mechanics_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddNipAndJoinDateToMechanicsTable extends Migration
{
    public function up()
    {
        Schema::table('mechanics', function (Blueprint $table) {
            $table->string('nip', 50)->nullable()->unique()->after('name');
            $table->date('join_date')->nullable()->after('nip');
        });

        DB::table('mechanics')->whereNull('nip')->orderBy('id')->each(function ($mechanic) {
            DB::table('mechanics')->where('id', $mechanic->id)->update([
                'nip' => 'LEGACY-' . str_pad($mechanic->id, 6, '0', STR_PAD_LEFT),
            ]);
        });
    }

    public function down()
    {
        Schema::table('mechanics', function (Blueprint $table) {
            $table->dropUnique(['nip']);
            $table->dropColumn(['nip', 'join_date']);
        });
    }
}
```

- [ ] **Step 4: Run the migration**

Run: `php artisan migrate`
Expected: `2026_08_11_000001_add_nip_and_join_date_to_mechanics_table` runs successfully (this also runs against the dev DB with 9 existing mechanic rows — the backfill loop executes now).

- [ ] **Step 5: Verify backfill manually on the dev DB**

Run: `php artisan tinker --execute="App\Models\Mechanic::whereNull('nip')->count() && print(App\Models\Mechanic::pluck('nip', 'id'));"`

Simpler one-liner: `php artisan tinker --execute="echo App\Models\Mechanic::whereNotNull('nip')->count() . ' / ' . App\Models\Mechanic::count();"`
Expected: both numbers equal (e.g. `9 / 9`), confirming all 9 pre-existing rows got a `LEGACY-00000N` value.

- [ ] **Step 6: Update the model**

Edit `app/Models/Mechanic.php`:

```php
    protected $fillable = [
        'name', 'phone', 'email', 'address', 'is_active', 'nip', 'join_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'join_date' => 'date',
    ];
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=MechanicServiceModelTest`
Expected: PASS (5 tests)

- [ ] **Step 8: Run full test suite to check for regressions**

Run: `php artisan test`
Expected: all tests pass — the 48 pre-existing `Mechanic::create()` call sites across other files are unaffected since `nip` is nullable.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_11_000001_add_nip_and_join_date_to_mechanics_table.php app/Models/Mechanic.php tests/Feature/MechanicServiceModelTest.php
git commit -m "feat: add nip and join_date columns to mechanics"
```

---

### Task 2: Validation + Controller Search + Lookup Label

**Files:**
- Modify: `app/Http/Requests/StoreMechanicRequest.php`
- Modify: `app/Http/Requests/UpdateMechanicRequest.php`
- Modify: `app/Http/Controllers/MechanicController.php`
- Modify: `app/Http/Controllers/LookupController.php`
- Test: `tests/Feature/MechanicManagementTest.php`

**Interfaces:**
- Consumes: `Mechanic::$fillable`/`$casts` from Task 1.
- Produces: `StoreMechanicRequest`/`UpdateMechanicRequest` reject missing/duplicate `nip` with a `services.0...`-style session error on key `nip`; `MechanicController::index()` search matches `nip` too; `LookupController::mechanics()` JSON items have `text` formatted as `"{name} ({nip})"` when `nip` is present, else just `"{name}"`.

- [ ] **Step 1: Write the failing tests**

Edit `tests/Feature/MechanicManagementTest.php`. First, extend the existing `test_store_creates_mechanic` and `test_store_validates_required_fields`:

```php
    public function test_store_creates_mechanic(): void
    {
        $user = $this->userWithPermissions(['mechanic.create']);

        $response = $this->actingAs($user)->post('/mechanics', [
            'name' => 'Agus Setiawan',
            'phone' => '081234567890',
            'nip' => 'NIP-001',
            'join_date' => '2020-01-15',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/mechanics');
        $this->assertDatabaseHas('mechanics', [
            'name' => 'Agus Setiawan',
            'nip' => 'NIP-001',
            'join_date' => '2020-01-15',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithPermissions(['mechanic.create']);

        $response = $this->actingAs($user)->post('/mechanics', []);

        $response->assertSessionHasErrors(['name', 'nip']);
    }
```

Then add new tests after `test_store_is_forbidden_without_permission`:

```php
    public function test_store_rejects_duplicate_nip(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'NIP-001']);
        $user = $this->userWithPermissions(['mechanic.create']);

        $response = $this->actingAs($user)->post('/mechanics', [
            'name' => 'Budi Santoso',
            'nip' => 'NIP-001',
        ]);

        $response->assertSessionHasErrors(['nip']);
        $this->assertDatabaseMissing('mechanics', ['name' => 'Budi Santoso']);
    }
```

And after `test_update_is_forbidden_without_permission`:

```php
    public function test_update_rejects_nip_already_used_by_another_mechanic(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'NIP-001']);
        $mechanicB = Mechanic::create(['name' => 'Budi Santoso', 'nip' => 'NIP-002']);
        $user = $this->userWithPermissions(['mechanic.edit']);

        $response = $this->actingAs($user)->put("/mechanics/{$mechanicB->id}", [
            'name' => 'Budi Santoso',
            'nip' => 'NIP-001',
        ]);

        $response->assertSessionHasErrors(['nip']);
    }

    public function test_update_allows_keeping_own_nip_unchanged(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'NIP-001']);
        $user = $this->userWithPermissions(['mechanic.edit']);

        $response = $this->actingAs($user)->put("/mechanics/{$mechanic->id}", [
            'name' => 'Agus Setiawan Edited',
            'nip' => 'NIP-001',
        ]);

        $response->assertRedirect("/mechanics/{$mechanic->id}");
        $response->assertSessionDoesntHaveErrors(['nip']);
    }
```

And after `test_index_search_by_phone_filters_results`:

```php
    public function test_index_search_by_nip_filters_results(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'NIP-001']);
        Mechanic::create(['name' => 'Budi Santoso', 'nip' => 'NIP-002']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics?q=NIP-001');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=MechanicManagementTest`
Expected: FAIL — `test_store_creates_mechanic` (no `nip` column asserted, currently passes validation silently since `nip` isn't validated — will fail on the `assertDatabaseHas` for `nip`/`join_date` since those keys aren't mass-assigned by the request... actually they ARE fillable now from Task 1, but not yet in `$request->validated()` since not in `rules()`, so they won't be present in `$data` — assertion fails), `test_store_validates_required_fields` fails (no `nip` error raised), `test_store_rejects_duplicate_nip` fails (no rejection happens, `Budi Santoso` gets created), `test_update_rejects_nip_already_used_by_another_mechanic` fails (no rejection), `test_index_search_by_nip_filters_results` fails (search doesn't match `nip` yet).

- [ ] **Step 3: Update StoreMechanicRequest**

Edit `app/Http/Requests/StoreMechanicRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMechanicRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('mechanic.create');
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'nip' => ['required', 'string', 'max:50', 'unique:mechanics,nip'],
            'join_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
        ];
    }
}
```

- [ ] **Step 4: Update UpdateMechanicRequest**

Edit `app/Http/Requests/UpdateMechanicRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMechanicRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('mechanic.edit');
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'nip' => ['required', 'string', 'max:50', Rule::unique('mechanics', 'nip')->ignore($this->route('mechanic'))],
            'join_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
        ];
    }
}
```

- [ ] **Step 5: Update MechanicController::index() search clause**

In `app/Http/Controllers/MechanicController.php`, replace the `->when($search, ...)` block inside `index()`:

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

- [ ] **Step 6: Update LookupController::mechanics() label**

In `app/Http/Controllers/LookupController.php`, inside `mechanics()`, replace the final `->map(...)`:

```php
        return response()->json(
            $query->orderBy('name')->limit(20)->get()
                ->map(fn (Mechanic $mechanic) => [
                    'id' => $mechanic->id,
                    'text' => $mechanic->nip ? "{$mechanic->name} ({$mechanic->nip})" : $mechanic->name,
                ])
                ->values()
        );
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=MechanicManagementTest`
Expected: PASS (21 tests)

- [ ] **Step 8: Run full test suite to check for regressions**

Run: `php artisan test`
Expected: all tests pass.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/StoreMechanicRequest.php app/Http/Requests/UpdateMechanicRequest.php app/Http/Controllers/MechanicController.php app/Http/Controllers/LookupController.php tests/Feature/MechanicManagementTest.php
git commit -m "feat: validate nip uniqueness and search/lookup by nip"
```

---

### Task 3: UI Views

**Files:**
- Modify: `resources/views/mechanics/create.blade.php`
- Modify: `resources/views/mechanics/_tab_profil.blade.php`
- Modify: `resources/views/mechanics/index.blade.php`
- Test: `tests/Feature/MechanicManagementTest.php`

**Interfaces:**
- Consumes: `nip`/`join_date` validation from Task 2, `Mechanic::$casts['join_date'] = 'date'` from Task 1 (so `$mechanic->join_date` is a `Carbon` instance in views, needs `optional()->format(...)`).

- [ ] **Step 1: Write the failing tests**

Edit `tests/Feature/MechanicManagementTest.php`. Update `test_index_renders_filter_bar` (placeholder text changes):

```php
    public function test_index_renders_filter_bar(): void
    {
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari nama, telepon, atau NIP...');
    }
```

Add new tests after `test_show_renders_profil_tab_for_authorized_user`:

```php
    public function test_show_page_prefills_nip_and_join_date_in_profil_tab(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'NIP-001', 'join_date' => '2020-01-15']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get("/mechanics/{$mechanic->id}");

        $response->assertOk();
        $response->assertSee('value="NIP-001"', false);
        $response->assertSee('value="2020-01-15"', false);
    }
```

Add after `test_index_lists_mechanics_for_authorized_user`:

```php
    public function test_index_lists_nip_and_join_date_columns(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'NIP-001', 'join_date' => '2020-01-15']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertSee('NIP-001');
        $response->assertSee('15/01/2020');
    }

    public function test_index_shows_dash_for_mechanic_without_nip_or_join_date(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=MechanicManagementTest`
Expected: FAIL — `test_index_renders_filter_bar` fails (old placeholder text still rendered), `test_show_page_prefills_nip_and_join_date_in_profil_tab` fails (no such inputs in `_tab_profil.blade.php`), `test_index_lists_nip_and_join_date_columns` fails (no NIP/date columns rendered).

- [ ] **Step 3: Update create.blade.php**

In `resources/views/mechanics/create.blade.php`, insert a new row right after the closing `</div>` of the "Nama Mekanik" field's `mb-3` div, and before the existing `<div class="row">` that holds Telepon/Email:

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

- [ ] **Step 4: Update _tab_profil.blade.php**

In `resources/views/mechanics/_tab_profil.blade.php`, insert the same row (pre-filled) right after the "Nama Mekanik" field's `mb-3` div, before the existing Telepon/Email `<div class="row">`:

```blade
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="nip" class="form-label">NIP</label>
                    <input type="text" name="nip" id="nip" value="{{ old('nip', $mechanic->nip) }}" class="form-control @error('nip') is-invalid @enderror" maxlength="50" required>
                    @error('nip')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6 mb-3">
                    <label for="join_date" class="form-label">Tanggal Bergabung</label>
                    <input type="date" name="join_date" id="join_date" value="{{ old('join_date', optional($mechanic->join_date)->format('Y-m-d')) }}" class="form-control @error('join_date') is-invalid @enderror">
                    @error('join_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
```

- [ ] **Step 5: Update index.blade.php**

In `resources/views/mechanics/index.blade.php`:

1. Change the `searchPlaceholder` passed to `partials.list-filter-bar`:
```blade
        'searchPlaceholder' => 'Cari nama, telepon, atau NIP...',
```

2. Change the table header:
```blade
                    <tr>
                        <th>Nama</th>
                        <th>NIP</th>
                        <th>Tanggal Bergabung</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
```

3. Change the table body row:
```blade
                        <tr>
                            <td>{{ $mechanic->name }}</td>
                            <td>{{ $mechanic->nip ?? '-' }}</td>
                            <td>{{ $mechanic->join_date ? $mechanic->join_date->format('d/m/Y') : '-' }}</td>
                            <td>{{ $mechanic->phone ?? '-' }}</td>
                            <td>
                                @if ($mechanic->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('mechanics.show', $mechanic) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-gear"></i> Kelola
                                </a>
                            </td>
                        </tr>
```

4. Change the empty-state `colspan` from `4` to `6`:
```blade
                            <td colspan="6" class="p-0">
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=MechanicManagementTest`
Expected: PASS (25 tests)

- [ ] **Step 7: Run full test suite to check for regressions**

Run: `php artisan test`
Expected: all tests pass.

- [ ] **Step 8: Commit**

```bash
git add resources/views/mechanics/create.blade.php resources/views/mechanics/_tab_profil.blade.php resources/views/mechanics/index.blade.php tests/Feature/MechanicManagementTest.php
git commit -m "feat: show nip and join_date fields in mechanic forms and list"
```

---

### Task 4: End-to-End Integration Test & Manual Verification

**Files:**
- Create: `tests/Feature/MechanicNipIntegrationTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1-3.

- [ ] **Step 1: Write the integration test**

Create `tests/Feature/MechanicNipIntegrationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Mechanic;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MechanicNipIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermissions(array $codes): User
    {
        $user = User::factory()->create();

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $code]
            );
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        return User::find($user->id);
    }

    public function test_full_lifecycle_create_search_and_lookup_label_includes_nip(): void
    {
        $user = $this->userWithPermissions(['mechanic.create', 'mechanic.view', 'mechanic.edit']);

        $createResponse = $this->actingAs($user)->post('/mechanics', [
            'name' => 'Agus Setiawan',
            'nip' => 'NIP-2020-001',
            'join_date' => '2020-03-01',
            'is_active' => '1',
        ]);
        $createResponse->assertRedirect('/mechanics');

        $mechanic = Mechanic::where('nip', 'NIP-2020-001')->firstOrFail();

        $indexResponse = $this->actingAs($user)->get('/mechanics?q=NIP-2020-001');
        $indexResponse->assertOk();
        $indexResponse->assertSee('Agus Setiawan');

        $showResponse = $this->actingAs($user)->get("/mechanics/{$mechanic->id}");
        $showResponse->assertOk();
        $showResponse->assertSee('value="NIP-2020-001"', false);

        $lookupResponse = $this->actingAs($user)->getJson('/lookup/mechanics?q=Agus');
        $lookupResponse->assertOk();
        $lookupResponse->assertJsonFragment(['text' => 'Agus Setiawan (NIP-2020-001)']);

        $duplicateResponse = $this->actingAs($user)->post('/mechanics', [
            'name' => 'Budi Santoso',
            'nip' => 'NIP-2020-001',
        ]);
        $duplicateResponse->assertSessionHasErrors(['nip']);
    }

    public function test_mechanic_created_without_nip_still_appears_safely_in_index_and_lookup(): void
    {
        Mechanic::create(['name' => 'Mekanik Lama Tanpa Nip']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $indexResponse = $this->actingAs($user)->get('/mechanics');
        $indexResponse->assertOk();
        $indexResponse->assertSee('Mekanik Lama Tanpa Nip');

        $lookupResponse = $this->actingAs($user)->getJson('/lookup/mechanics?q=Lama');
        $lookupResponse->assertOk();
        $lookupResponse->assertJsonFragment(['text' => 'Mekanik Lama Tanpa Nip']);
    }
}
```

- [ ] **Step 2: Run the new tests**

Run: `php artisan test --filter=MechanicNipIntegrationTest`
Expected: PASS (2 tests)

- [ ] **Step 3: Run full test suite**

Run: `php artisan test`
Expected: 100% pass, no regressions.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/MechanicNipIntegrationTest.php
git commit -m "test: add end-to-end coverage for mechanic nip and join_date"
```

- [ ] **Step 5: Manual browser verification**

Using the browser preview (`sistem-manajemen-bengkel` launch config), as a user with `mechanic.create`/`mechanic.view`/`mechanic.edit`:
1. Open `/mechanics` — confirm the search placeholder now reads "Cari nama, telepon, atau NIP..." and the table shows NIP / Tanggal Bergabung columns (existing 9 mechanics show their `LEGACY-00000N` backfilled NIP).
2. Open `/mechanics/create` — confirm NIP is a required text field and Tanggal Bergabung is a date picker.
3. Submit with an NIP that already exists — confirm a validation error appears on the NIP field and no mechanic is created.
4. Submit with a unique NIP and a join date — confirm redirect to `/mechanics` and the new row shows both values.
5. Open the new mechanic's detail page (Profil tab) — confirm NIP/Tanggal Bergabung are pre-filled, edit one, save, and confirm the update persists.
6. Open a PKB (work order) create/edit form, open the mechanic dropdown — confirm the label shows `"{nama} ({nip})"` for the mechanic just created.
7. Search `/mechanics?q=` with an NIP substring — confirm it filters correctly.

- [ ] **Step 6: Present milestone closing summary**

Summarize: migration + backfill confirmed on dev DB (9/9 legacy rows), all new/changed tests passing, full suite green, manual verification checklist above completed.
