# Master Rak (Global) & Relasi ke Master Sparepart Cabang Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah modul Master Rak global (`racks`) dan hubungkan ke `sparepart_branches` lewat `rack_id`, menggantikan input teks bebas `rack_number` di UI dengan dropdown terstruktur.

**Architecture:** `Rack` adalah modul master-data global sederhana yang meniru `ServiceCatalog` 1:1 (model + controller tanpa Policy + views + permission non-branch-scoped). `sparepart_branches` dapat kolom `rack_id` (FK nullable, `nullOnDelete()`) menggantikan peran `rack_number` di form/tampilan tanpa menghapus kolom lama dari DB.

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL, Bootstrap 5.3.3, PHPUnit.

## Global Constraints

- `racks.code`: string, max 30, unique. Tidak ada field `name`/`price` — hanya `code` + `is_active`.
- Permission baru **global** (non-branch-scoped): `rack.view`, `rack.create`, `rack.edit`. Tidak ada `rack.delete` — hanya toggle `is_active` via update.
- Tidak ada Policy class untuk Rack — otorisasi langsung `$this->authorize('rack.xxx')`, mengikuti pola `ServiceCatalogController` persis (bukan pola `SparepartBranchPolicy` yang branch-scoped).
- `rack_id` di `sparepart_branches`: nullable, `constrained('racks')->nullOnDelete()`. Dropdown pemilihan Rak di form sparepart-branch **hanya** menampilkan rak dengan `is_active = true`, diurutkan `code`.
- Kolom `rack_number` di `sparepart_branches` **tidak dihapus** dari DB — hanya tidak lagi ditampilkan/diisi dari form manapun setelah milestone ini selesai.
- `database/seeders/DemoMasterDataSeeder.php` **tidak diubah** — di luar cakupan.
- Referensi spec lengkap: [docs/superpowers/specs/2026-08-11-master-rack-design.md](../specs/2026-08-11-master-rack-design.md)

---

### Task 1: Modul Master Rak Global

**Files:**
- Create: `database/migrations/2026_08_11_000002_create_racks_table.php`
- Create: `app/Models/Rack.php`
- Create: `app/Http/Requests/StoreRackRequest.php`
- Create: `app/Http/Requests/UpdateRackRequest.php`
- Create: `app/Http/Controllers/RackController.php`
- Create: `resources/views/racks/_form.blade.php`
- Create: `resources/views/racks/create.blade.php`
- Create: `resources/views/racks/edit.blade.php`
- Create: `resources/views/racks/index.blade.php`
- Modify: `routes/web.php`
- Modify: `database/seeders/MenuPermissionSeeder.php`
- Modify: `resources/views/partials/sidebar.blade.php`
- Test: `tests/Feature/RackManagementTest.php`

**Interfaces:**
- Produces: `Rack::$fillable = ['code', 'is_active', 'created_by', 'updated_by']`, `Rack::$casts = ['is_active' => 'boolean']`. Routes named `racks.index`/`racks.create`/`racks.store`/`racks.edit`/`racks.update`. Permission codes `rack.view`/`rack.create`/`rack.edit`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RackManagementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Rack;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RackManagementTest extends TestCase
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

    public function test_index_lists_racks_for_authorized_user(): void
    {
        Rack::create(['code' => 'A1']);
        $user = $this->userWithPermissions(['rack.view']);

        $response = $this->actingAs($user)->get('/racks');

        $response->assertOk();
        $response->assertSee('A1');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/racks');

        $response->assertForbidden();
    }

    public function test_store_creates_rack(): void
    {
        $user = $this->userWithPermissions(['rack.create']);

        $response = $this->actingAs($user)->post('/racks', [
            'code' => 'A1',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/racks');
        $this->assertDatabaseHas('racks', ['code' => 'A1']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithPermissions(['rack.create']);

        $response = $this->actingAs($user)->post('/racks', []);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        Rack::create(['code' => 'A1']);
        $user = $this->userWithPermissions(['rack.create']);

        $response = $this->actingAs($user)->post('/racks', ['code' => 'A1']);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/racks', ['code' => 'A1']);

        $response->assertForbidden();
    }

    public function test_update_edits_rack_and_can_deactivate(): void
    {
        $rack = Rack::create(['code' => 'A1']);
        $user = $this->userWithPermissions(['rack.edit']);

        $response = $this->actingAs($user)->put("/racks/{$rack->id}", [
            'code' => 'A2',
            'is_active' => '0',
        ]);

        $response->assertRedirect('/racks');
        $this->assertDatabaseHas('racks', ['id' => $rack->id, 'code' => 'A2', 'is_active' => false]);
    }

    public function test_update_allows_keeping_the_same_code(): void
    {
        $rack = Rack::create(['code' => 'A1']);
        $user = $this->userWithPermissions(['rack.edit']);

        $response = $this->actingAs($user)->put("/racks/{$rack->id}", [
            'code' => 'A1',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/racks');
        $response->assertSessionDoesntHaveErrors();
    }

    public function test_update_is_forbidden_without_permission(): void
    {
        $rack = Rack::create(['code' => 'A1']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put("/racks/{$rack->id}", ['code' => 'A2']);

        $response->assertForbidden();
    }

    public function test_index_search_by_code_filters_results(): void
    {
        Rack::create(['code' => 'A1']);
        Rack::create(['code' => 'B2']);
        $user = $this->userWithPermissions(['rack.view']);

        $response = $this->actingAs($user)->get('/racks?q=A1');

        $response->assertOk();
        $response->assertSee('A1');
        $response->assertDontSee('B2');
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        Rack::create(['code' => 'A1']);
        $user = $this->userWithPermissions(['rack.view']);

        $response = $this->actingAs($user)->get('/racks?q[]=A1');

        $response->assertOk();
        $response->assertSee('A1');
    }

    public function test_index_shows_empty_state_when_no_racks_match(): void
    {
        $user = $this->userWithPermissions(['rack.view']);

        $response = $this->actingAs($user)->get('/racks');

        $response->assertOk();
        $response->assertSee('Belum ada rak');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['rack.view', 'rack.create']);

        $response = $this->actingAs($user)->get('/racks');

        $response->assertOk();
        $response->assertSee('Tambah Rak Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['rack.view']);

        $response = $this->actingAs($user)->get('/racks');

        $response->assertOk();
        $response->assertDontSee('Tambah Rak Pertama');
    }

    public function test_index_renders_filter_bar(): void
    {
        $user = $this->userWithPermissions(['rack.view']);

        $response = $this->actingAs($user)->get('/racks');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari kode rak...');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=RackManagementTest`
Expected: FAIL — route `[GET] /racks` not found (nothing exists yet).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_11_000002_create_racks_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRacksTable extends Migration
{
    public function up()
    {
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
    }

    public function down()
    {
        Schema::dropIfExists('racks');
    }
}
```

- [ ] **Step 4: Create the model**

Create `app/Models/Rack.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rack extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['code', 'is_active', 'created_by', 'updated_by'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function sparepartBranches()
    {
        return $this->hasMany(SparepartBranch::class);
    }
}
```

- [ ] **Step 5: Create the FormRequests**

Create `app/Http/Requests/StoreRackRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRackRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('rack.create');
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:30', 'unique:racks,code'],
        ];
    }
}
```

Create `app/Http/Requests/UpdateRackRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRackRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('rack.edit');
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('racks', 'code')->ignore($this->route('rack'))],
        ];
    }
}
```

- [ ] **Step 6: Create the controller**

Create `app/Http/Controllers/RackController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRackRequest;
use App\Http\Requests\UpdateRackRequest;
use App\Models\Rack;

class RackController extends Controller
{
    public function index()
    {
        $this->authorize('rack.view');

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $racks = Rack::orderBy('code')
            ->when($search, function ($query, $q) {
                $query->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->simplePaginate(15)
            ->withQueryString();

        return view('racks.index', compact('racks'))->with('search', $search);
    }

    public function create()
    {
        $this->authorize('rack.create');

        $rack = new Rack();

        return view('racks.create', compact('rack'));
    }

    public function store(StoreRackRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        Rack::create($data);

        return redirect()->route('racks.index')->with('status', 'Rak berhasil ditambahkan.');
    }

    public function edit(Rack $rack)
    {
        $this->authorize('rack.edit');

        return view('racks.edit', compact('rack'));
    }

    public function update(UpdateRackRequest $request, Rack $rack)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $rack->update($data);

        return redirect()->route('racks.index')->with('status', 'Rak berhasil diperbarui.');
    }
}
```

- [ ] **Step 7: Create the views**

Create `resources/views/racks/_form.blade.php`:

```blade
@csrf
@isset($method)
    @method($method)
@endisset

<div class="mb-3">
    <label for="code" class="form-label">Kode Rak</label>
    <input type="text" name="code" id="code" value="{{ old('code', $rack->code) }}" class="form-control @error('code') is-invalid @enderror" maxlength="30" required>
    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="form-check form-switch mb-4">
    <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $rack->exists ? $rack->is_active : true) ? 'checked' : '' }}>
    <label for="is_active" class="form-check-label">Aktif</label>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
<a href="{{ route('racks.index') }}" class="btn btn-outline-secondary">Batal</a>
```

Create `resources/views/racks/create.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Tambah Rak')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>Tambah Rak</h1>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('racks.store') }}">
                @include('racks._form')
            </form>
        </div>
    </div>
@endsection
```

Create `resources/views/racks/edit.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Ubah Rak')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>Ubah Rak</h1>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('racks.update', $rack) }}">
                @php($method = 'PUT')
                @include('racks._form')
            </form>
        </div>
    </div>
@endsection
```

Create `resources/views/racks/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Rak')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>Rak</h1>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Cari kode rak...',
        'searchValue' => $search,
        'branchFilterBranches' => null,
        'branchFilterSelected' => [],
        'actionsHtml' => auth()->user()->can('rack.create')
            ? '<a href="' . route('racks.create') . '" class="btn btn-primary btn-sm ms-2"><i class="bi bi-plus-lg"></i> Tambah Rak</a>'
            : '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($racks as $rack)
                        <tr>
                            <td><code>{{ $rack->code }}</code></td>
                            <td>
                                @if ($rack->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('rack.edit')
                                    <a href="{{ route('racks.edit', $rack) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-grid-3x3-gap',
                                    'title' => 'Belum ada rak',
                                    'description' => 'Mulai dengan menambahkan rak pertama.',
                                    'ctaRoute' => 'racks.create',
                                    'ctaLabel' => '+ Tambah Rak Pertama',
                                    'ctaPermission' => 'rack.create',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $racks->links() }}
    </div>
@endsection
```

- [ ] **Step 8: Add routes**

In `routes/web.php`, add `use App\Http\Controllers\RackController;` near the other controller imports (alongside `use App\Http\Controllers\ServiceCatalogController;` at line 23), and add a new route group right after the `service-catalogs` group (after line 110, before line 112's `sparepart-branches` group):

```php
    Route::prefix('racks')->name('racks.')->group(function () {
        Route::get('/', [RackController::class, 'index'])->name('index');
        Route::get('/create', [RackController::class, 'create'])->name('create');
        Route::post('/', [RackController::class, 'store'])->name('store');
        Route::get('/{rack}/edit', [RackController::class, 'edit'])->name('edit');
        Route::put('/{rack}', [RackController::class, 'update'])->name('update');
    });
```

- [ ] **Step 9: Add permission seeder block**

In `database/seeders/MenuPermissionSeeder.php`, add a new block right after the `master.service` block (after line 195, before the `administrasi.users` block):

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

- [ ] **Step 10: Add sidebar link**

In `resources/views/partials/sidebar.blade.php`, line 79, extend the gate condition:

```blade
@if ($user && ($user->can('branch.view') || $user->can('customer.view') || $user->can('vehicle.view') || $user->can('vehicle_reference.view') || $user->can('mechanic.view') || $user->can('service.view') || $user->can('rack.view')))
```

Then add a new block right after the `@can('service.view') ... @endcan` block (after line 123, before the closing `</ul>` at line 124):

```blade
        @can('rack.view')
        <li class="nav-item">
            <a href="{{ route('racks.index') }}" class="nav-link {{ request()->routeIs('racks.*') ? 'active' : '' }}">
                <i class="bi bi-grid-3x3-gap me-2"></i> Rack
            </a>
        </li>
        @endcan
```

- [ ] **Step 11: Run migration and seeder**

Run: `php artisan migrate`
Run: `php artisan db:seed --class="Database\Seeders\MenuPermissionSeeder"`
Expected: `racks` table created; `rack.view`/`rack.create`/`rack.edit` permissions and `master.rack` menu now exist in the dev DB (seeder uses `updateOrCreate`, safe to re-run).

- [ ] **Step 12: Run tests to verify they pass**

Run: `php artisan test --filter=RackManagementTest`
Expected: PASS (15 tests)

- [ ] **Step 13: Run full test suite to check for regressions**

Run: `php artisan test`
Expected: all tests pass.

- [ ] **Step 14: Commit**

```bash
git add database/migrations/2026_08_11_000002_create_racks_table.php app/Models/Rack.php app/Http/Requests/StoreRackRequest.php app/Http/Requests/UpdateRackRequest.php app/Http/Controllers/RackController.php resources/views/racks routes/web.php database/seeders/MenuPermissionSeeder.php resources/views/partials/sidebar.blade.php tests/Feature/RackManagementTest.php
git commit -m "feat: add master rack module"
```

---

### Task 2: Integrasi Rak ke Master Sparepart Cabang

**Files:**
- Create: `database/migrations/2026_08_11_000003_add_rack_id_to_sparepart_branches_table.php`
- Modify: `app/Models/SparepartBranch.php`
- Modify: `app/Http/Requests/StoreSparepartRequest.php`
- Modify: `app/Http/Requests/StoreSparepartToBranchRequest.php`
- Modify: `app/Http/Requests/UpdateSparepartBranchRequest.php`
- Modify: `app/Http/Controllers/SparepartBranchController.php`
- Modify: `resources/views/sparepart-branches/create.blade.php`
- Modify: `resources/views/sparepart-branches/create-existing.blade.php`
- Modify: `resources/views/sparepart-branches/edit.blade.php`
- Modify: `resources/views/sparepart-branches/index.blade.php`
- Test: `tests/Feature/SparepartBranchIndexAndCreateTest.php`
- Test: `tests/Feature/SparepartBranchEditAndDeactivateTest.php`

**Interfaces:**
- Consumes: `Rack` model from Task 1.
- Produces: `SparepartBranch::rack()` `belongsTo(Rack::class)`, `SparepartBranch::$fillable` includes `'rack_id'`. `sparepart-branches.create`/`.createExisting`/`.edit` views receive a `$racks` collection of active racks ordered by `code`.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/SparepartBranchIndexAndCreateTest.php`, add `use App\Models\Rack;` to the imports, then add two new tests after `test_create_new_sparepart_creates_identity_branch_config_and_zeroed_stock`:

```php
    public function test_create_new_sparepart_saves_rack_id(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');
        $rack = Rack::create(['code' => 'A1']);
        $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response = $this->post('/sparepart-branches', [
            'branch_id' => $branch->id,
            'code' => 'BAN-01',
            'name' => 'Ban Depan',
            'rack_id' => $rack->id,
            'selling_price' => 150000,
            'minimum_stock' => 2,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $sparepartBranch = SparepartBranch::whereHas('sparepart', fn ($q) => $q->where('code', 'BAN-01'))->first();
        $this->assertSame($rack->id, $sparepartBranch->rack_id);
    }

    public function test_create_form_lists_only_active_racks_in_dropdown(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.create');
        Rack::create(['code' => 'A1', 'is_active' => true]);
        Rack::create(['code' => 'B2', 'is_active' => false]);

        $response = $this->actingAs($user)->get('/sparepart-branches/create');

        $response->assertOk();
        $response->assertSee('A1');
        $response->assertDontSee('B2');
    }
```

In `tests/Feature/SparepartBranchEditAndDeactivateTest.php`, add `use App\Models\Rack;` to the imports, then replace `test_update_saves_rack_price_minimum_stock_without_touching_is_active` and add a new dropdown test right after it:

```php
    public function test_update_saves_rack_id_price_minimum_stock_without_touching_is_active(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $rack = Rack::create(['code' => 'C3']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.edit');

        $response = $this->actingAs(User::find($user->id))->put("/sparepart-branches/{$sparepartBranch->id}", [
            'rack_id' => $rack->id,
            'selling_price' => 175000,
            'minimum_stock' => 4,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $this->assertDatabaseHas('sparepart_branches', [
            'id' => $sparepartBranch->id,
            'rack_id' => $rack->id,
            'selling_price' => 175000,
            'is_active' => true,
        ]);
    }

    public function test_edit_form_lists_only_active_racks_in_dropdown(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        Rack::create(['code' => 'A1', 'is_active' => true]);
        Rack::create(['code' => 'B2', 'is_active' => false]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.edit');

        $response = $this->actingAs(User::find($user->id))->get("/sparepart-branches/{$sparepartBranch->id}/edit");

        $response->assertOk();
        $response->assertSee('A1');
        $response->assertDontSee('B2');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest,SparepartBranchEditAndDeactivateTest`
Expected: FAIL — the 4 new/renamed tests fail (`rack_id` column doesn't exist yet, `$racks` variable undefined in views).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_08_11_000003_add_rack_id_to_sparepart_branches_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRackIdToSparepartBranchesTable extends Migration
{
    public function up()
    {
        Schema::table('sparepart_branches', function (Blueprint $table) {
            $table->foreignId('rack_id')->nullable()->after('rack_number')->constrained('racks')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('sparepart_branches', function (Blueprint $table) {
            $table->dropForeign(['rack_id']);
            $table->dropColumn('rack_id');
        });
    }
}
```

- [ ] **Step 4: Run the migration**

Run: `php artisan migrate`

- [ ] **Step 5: Update the model**

In `app/Models/SparepartBranch.php`:

```php
    protected $fillable = ['sparepart_id', 'branch_id', 'rack_number', 'rack_id', 'selling_price', 'minimum_stock', 'is_active'];
```

Add a new relation after `stock()`:

```php
    public function rack()
    {
        return $this->belongsTo(Rack::class);
    }
```

- [ ] **Step 6: Update the FormRequests**

In `app/Http/Requests/StoreSparepartRequest.php`, replace:
```php
            'rack_number' => ['nullable', 'string', 'max:30'],
```
with:
```php
            'rack_id' => ['nullable', 'integer', 'exists:racks,id'],
```

In `app/Http/Requests/StoreSparepartToBranchRequest.php`, same replacement.

In `app/Http/Requests/UpdateSparepartBranchRequest.php`, same replacement.

- [ ] **Step 7: Update the controller**

In `app/Http/Controllers/SparepartBranchController.php`:

1. Add `use App\Models\Rack;` to the imports.
2. In `index()`, change the eager-load line:
```php
        $sparepartBranches = SparepartBranch::with(['sparepart', 'stock', 'rack'])
```
3. In `create()`, add `$racks` and pass it to the view:
```php
    public function create()
    {
        $branches = auth()->user()->branchesWithPermission('sparepart.create');

        if ($branches->isEmpty()) {
            return view('sparepart-branches.no-access');
        }

        $currentBranchId = session('current_sparepart_branch_id');
        $selectedBranch = $branches->firstWhere('id', $currentBranchId) ?? $branches->first();
        $racks = Rack::where('is_active', true)->orderBy('code')->get();

        return view('sparepart-branches.create', compact('branches', 'selectedBranch', 'racks'));
    }
```
4. In `store()`, change:
```php
                'rack_number' => $data['rack_number'] ?? null,
```
to:
```php
                'rack_id' => $data['rack_id'] ?? null,
```
5. In `createExisting()`, add `$racks`:
```php
    public function createExisting()
    {
        $branch = $this->resolveCurrentBranch(auth()->user());

        if (! $branch || ! auth()->user()->hasPermissionToInBranch('sparepart.create', $branch->id)) {
            abort(403);
        }

        $racks = Rack::where('is_active', true)->orderBy('code')->get();

        return view('sparepart-branches.create-existing', compact('branch', 'racks'));
    }
```
6. In `storeExisting()`, same change as `store()`:
```php
                'rack_id' => $data['rack_id'] ?? null,
```
7. In `edit()`, add `$racks`:
```php
    public function edit(SparepartBranch $sparepartBranch)
    {
        $this->authorize('update', $sparepartBranch);

        $sparepartBranch->load('sparepart');
        $racks = Rack::where('is_active', true)->orderBy('code')->get();

        return view('sparepart-branches.edit', compact('sparepartBranch', 'racks'));
    }
```

- [ ] **Step 8: Update the views**

In `resources/views/sparepart-branches/create.blade.php`, replace the "Rak" field block:
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

In `resources/views/sparepart-branches/create-existing.blade.php`, same replacement.

In `resources/views/sparepart-branches/edit.blade.php`, same field but pre-selected from the model:
```blade
                    <div class="col-md-4 mb-3">
                        <label for="rack_id" class="form-label">Rak</label>
                        <select name="rack_id" id="rack_id" class="form-select @error('rack_id') is-invalid @enderror">
                            <option value="">-- Tanpa Rak --</option>
                            @foreach ($racks as $rack)
                                <option value="{{ $rack->id }}" {{ (int) old('rack_id', $sparepartBranch->rack_id) === $rack->id ? 'selected' : '' }}>{{ $rack->code }}</option>
                            @endforeach
                        </select>
                        @error('rack_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
```

In `resources/views/sparepart-branches/index.blade.php`, replace:
```blade
                            <td>{{ $sparepartBranch->rack_number ?? '-' }}</td>
```
with:
```blade
                            <td>{{ optional($sparepartBranch->rack)->code ?? '-' }}</td>
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest,SparepartBranchEditAndDeactivateTest`
Expected: PASS

- [ ] **Step 10: Run full test suite to check for regressions**

Run: `php artisan test`
Expected: all tests pass. (No other pre-existing test besides the one renamed in Step 1 references `rack_number` in an assertion — the remaining `rack_number` payload keys elsewhere in `SparepartBranchIndexAndCreateTest.php` are harmless unvalidated extra input and won't fail; they're cleaned up in Task 3.)

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_08_11_000003_add_rack_id_to_sparepart_branches_table.php app/Models/SparepartBranch.php app/Http/Requests/StoreSparepartRequest.php app/Http/Requests/StoreSparepartToBranchRequest.php app/Http/Requests/UpdateSparepartBranchRequest.php app/Http/Controllers/SparepartBranchController.php resources/views/sparepart-branches/create.blade.php resources/views/sparepart-branches/create-existing.blade.php resources/views/sparepart-branches/edit.blade.php resources/views/sparepart-branches/index.blade.php tests/Feature/SparepartBranchIndexAndCreateTest.php tests/Feature/SparepartBranchEditAndDeactivateTest.php
git commit -m "feat: link sparepart branches to master rack via rack_id"
```

---

### Task 3: Penyesuaian Test Suite Eksisting ke `rack_id`

**Files:**
- Test: `tests/Feature/SparepartBranchIndexAndCreateTest.php`

**Interfaces:**
- Consumes: `Rack` model, `rack_id` field from Task 2.

This task has no new behavior to chase (Task 2 already implemented and verified `rack_id` end-to-end) — it's a straight cleanup of the 4 remaining test payloads in `SparepartBranchIndexAndCreateTest.php` that still submit the retired `rack_number` field, so the whole suite is internally consistent and no test silently exercises a dead field.

- [ ] **Step 1: Update the 4 stale payloads**

In `tests/Feature/SparepartBranchIndexAndCreateTest.php`:

1. `test_create_new_sparepart_writes_to_authorized_branch_even_when_view_permission_fallback_differs` — add `$rack = Rack::create(['code' => 'A1']);` before the `post()` call, replace `'rack_number' => 'A1',` with `'rack_id' => $rack->id,`, and strengthen the existing assertion block by adding:
```php
        $this->assertSame($rack->id, $sparepartBranch->rack_id);
```

2. `test_store_existing_writes_to_authorized_branch_even_when_view_permission_fallback_differs` — add `$rack = Rack::create(['code' => 'B2']);` before the `post()` call, replace `'rack_number' => 'B2',` with `'rack_id' => $rack->id,`, and add:
```php
        $this->assertSame($rack->id, $sparepartBranch->rack_id);
```

3. `test_store_existing_attaches_sparepart_to_branch_with_new_config_and_stock` — add `$rack = Rack::create(['code' => 'B2']);` before the `post()` call, replace `'rack_number' => 'B2',` with `'rack_id' => $rack->id,`, and add:
```php
        $this->assertSame($rack->id, $sparepartBranch->rack_id);
```

4. `test_create_new_sparepart_creates_identity_branch_config_and_zeroed_stock` — this one already predates Task 2 and still has `'rack_number' => 'A1',` in its payload from before this milestone. Add `$rack = Rack::create(['code' => 'A1']);` before the `post()` call and replace `'rack_number' => 'A1',` with `'rack_id' => $rack->id,`. No new assertion needed here — `test_create_new_sparepart_saves_rack_id` (added in Task 2) already covers the `rack_id`-specific assertion; this test's job is verifying stock/identity creation, not rack linkage.

- [ ] **Step 2: Run tests to verify they still pass**

Run: `php artisan test --filter=SparepartBranchIndexAndCreateTest`
Expected: PASS — this is a non-behavioral cleanup, so nothing should have been red at any point; this step confirms the rewritten payloads didn't introduce a typo or break an assertion.

- [ ] **Step 3: Run full test suite to check for regressions**

Run: `php artisan test`
Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/SparepartBranchIndexAndCreateTest.php
git commit -m "test: migrate remaining sparepart branch test payloads from rack_number to rack_id"
```

---

### Task 4: End-to-End Integration Test Suite & Manual Verification

**Files:**
- Create: `tests/Feature/MasterRackIntegrationTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1-3.

- [ ] **Step 1: Write the integration test**

Create `tests/Feature/MasterRackIntegrationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterRackIntegrationTest extends TestCase
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

    protected function grantBranchPermission(User $user, Branch $branch, string $code): void
    {
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    public function test_full_lifecycle_create_rack_assign_to_sparepart_and_display_in_index(): void
    {
        $rackUser = $this->userWithPermissions(['rack.view', 'rack.create', 'rack.edit']);
        $createRackResponse = $this->actingAs($rackUser)->post('/racks', ['code' => 'A1', 'is_active' => '1']);
        $createRackResponse->assertRedirect('/racks');
        $rack = Rack::where('code', 'A1')->firstOrFail();

        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartUser = User::factory()->create();
        $this->grantBranchPermission($sparepartUser, $branch, 'sparepart.view');
        $this->grantBranchPermission($sparepartUser, $branch, 'sparepart.create');
        $this->actingAs($sparepartUser)->get('/sparepart-branches');

        $storeResponse = $this->post('/sparepart-branches', [
            'branch_id' => $branch->id,
            'code' => 'BAN-01',
            'name' => 'Ban Depan',
            'rack_id' => $rack->id,
            'selling_price' => 150000,
            'minimum_stock' => 2,
        ]);
        $storeResponse->assertRedirect('/sparepart-branches');

        $indexResponse = $this->actingAs($sparepartUser)->get('/sparepart-branches?branch_id=' . $branch->id);
        $indexResponse->assertOk();
        $indexResponse->assertSee('A1');

        $deactivateRackResponse = $this->actingAs($rackUser)->put("/racks/{$rack->id}", ['code' => 'A1', 'is_active' => '0']);
        $deactivateRackResponse->assertRedirect('/racks');

        $createFormResponse = $this->actingAs($sparepartUser)->get('/sparepart-branches/create');
        $createFormResponse->assertOk();
        $createFormResponse->assertDontSee('>A1<', false);

        $sparepartBranch = SparepartBranch::whereHas('sparepart', fn ($q) => $q->where('code', 'BAN-01'))->first();
        $sparepartBranch->refresh();
        $this->assertSame($rack->id, $sparepartBranch->rack_id, 'Deactivating a rack must not clear rack_id on an already-linked sparepart branch.');
    }

    public function test_deleting_a_rack_nulls_out_rack_id_on_linked_sparepart_branches(): void
    {
        $rack = Rack::create(['code' => 'A1']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $sparepartBranch = SparepartBranch::create([
            'sparepart_id' => $sparepart->id,
            'branch_id' => $branch->id,
            'rack_id' => $rack->id,
            'selling_price' => 100000,
        ]);

        $rack->delete();

        $sparepartBranch->refresh();
        $this->assertNull($sparepartBranch->rack_id);
        $this->assertDatabaseHas('sparepart_branches', ['id' => $sparepartBranch->id]);
    }
}
```

- [ ] **Step 2: Run the new tests**

Run: `php artisan test --filter=MasterRackIntegrationTest`
Expected: PASS (2 tests)

- [ ] **Step 3: Run full test suite**

Run: `php artisan test`
Expected: 100% pass, no regressions.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/MasterRackIntegrationTest.php
git commit -m "test: add end-to-end coverage for master rack integration"
```

- [ ] **Step 5: Manual browser verification**

Using the browser preview (`sistem-manajemen-bengkel` launch config), grant the demo user `rack.view`/`rack.create`/`rack.edit` (global `UserPermission`) via tinker if not already present, then:
1. Open `/racks` — confirm empty-state or list renders, sidebar shows a "Rack" link under Master Data.
2. Create a rack with a unique code — confirm redirect and it appears in the list.
3. Attempt to create a second rack with the same code — confirm a validation error appears.
4. Edit the rack, toggle it inactive, save — confirm the status updates.
5. Open `/sparepart-branches/create` (or `create-existing`) — confirm the Rak dropdown lists only active racks (the one just deactivated should be absent), and that submitting with a rack selected persists `rack_id`.
6. Open `/sparepart-branches` index — confirm the "Rak" column shows the assigned rack's code (or "-" for unassigned rows).
7. Edit an existing sparepart-branch row — confirm the Rak dropdown pre-selects the currently assigned rack.

- [ ] **Step 6: Present milestone closing summary**

Summarize: all 4 tasks complete, full suite green, manual verification checklist above completed.
