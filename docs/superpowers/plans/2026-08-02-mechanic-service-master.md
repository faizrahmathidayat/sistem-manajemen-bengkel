# Mekanik & Jasa Service Master (Migration 004) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task — this is master-data CRUD following already-shipped patterns (Branch's flat CRUD, Customer's tabbed-detail CRUD), not auth-critical infrastructure, so per the project's process preference it runs **inline**, no subagent dispatch. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Mekanik (mechanic) master data with per-branch assignment, and a flat Jasa Service (service catalog) master data table, both wired into the existing Master Data sidebar.

**Architecture:** Three new tables (`mechanics`, `mechanic_branches`, `service_catalogs`), each following the exact conventions already established (bigint PK, `HasAudit`, `is_active` toggle). Mechanic screens mirror the `Customer` controller/view shape from migration 003 (list → create → detail-with-tabs: Profil + Cabang). Service Catalog mirrors the `Branch` controller/view shape (flat list → create → edit, no detail/tabs page).

**Tech Stack:** Laravel 8 (`^8.75` — pinned; do not use `Request` helper methods added in later Laravel versions, e.g. `Request::integer()` does not exist here), Blade, Bootstrap 5 (existing design tokens/`.status-dot`/`.card` conventions), vanilla JS + `fetch()` for AJAX (existing pattern from `customers/_tab_cabang.blade.php`).

Design spec: `docs/superpowers/specs/2026-08-02-mechanic-service-master-design.md`.

## Global Constraints

- New tables: `bigint` auto-increment PK (`$table->id()`), `snake_case` plural names, `HasAudit` trait (`created_by`/`updated_by`, auto-stamped), `is_active` boolean toggle — **no hard delete anywhere in this plan**.
- Permission codes `mechanic.view/create/edit` and `service.view/create/edit` already exist (seeded in migration 002, menus `master.mechanic`/`master.service`) — **do not re-seed them**, only consume them in `$this->authorize(...)` calls. No new menus or permission codes needed in this plan.
- `mechanic_branches` assignment is gated by `mechanic.edit` — no dedicated `mechanic_branch.manage` permission code (same simplification as `customer_branches` in migration 003, not the heavier `user_branches`/`user_branch.manage` precedent).
- `service_catalogs.default_price` is a single global price — no per-branch pricing table. This is a deliberate scope decision (see spec's "Explicitly out of scope" section), not an oversight.
- **Laravel 8 pinned.** Do not call `Request::integer()`, `Request::string()`, or other helper methods added in Laravel 9+. To read and cast a query param, use `request()->query('x') ? (int) request()->query('x') : null`. Every task that adds a query-param-driven controller action must include a test that actually `->get()`s that route directly — not only a test that a link to it renders elsewhere (this exact gap let a `Request::integer()` bug ship silently in migration 003).
- Every list/index endpoint uses `->simplePaginate()`, never `->paginate()`.
- Full TDD: write the failing test first, confirm the failure reason, implement, confirm green.

---

### Task 1: Data model — migrations, models

**Files:**
- Create: `database/migrations/2026_08_02_000010_create_mechanics_table.php`
- Create: `database/migrations/2026_08_02_000011_create_mechanic_branches_table.php`
- Create: `database/migrations/2026_08_02_000012_create_service_catalogs_table.php`
- Create: `app/Models/Mechanic.php`
- Create: `app/Models/MechanicBranch.php`
- Create: `app/Models/ServiceCatalog.php`
- Test: `tests/Feature/MechanicServiceModelTest.php`

**Interfaces:**
- Produces: `Mechanic` (fillable: `name, phone, email, address, is_active`; relations `mechanicBranches()`, `branches()`, `hasAccessToBranch(int): bool`). `MechanicBranch` (fillable: `mechanic_id, branch_id, is_active`; relations `mechanic()`, `branch()`). `ServiceCatalog` (fillable: `code, name, default_price, is_active`; no relations).

- [ ] **Step 1: Write the failing model tests**

Create `tests/Feature/MechanicServiceModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MechanicServiceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_mechanic_can_be_created_with_fillable_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $mechanic = Mechanic::create([
            'name' => 'Agus Setiawan',
            'phone' => '081234567890',
        ]);

        $this->assertSame('Agus Setiawan', $mechanic->name);
        $this->assertTrue($mechanic->is_active);
        $this->assertSame($user->id, $mechanic->created_by);
    }

    public function test_mechanic_branches_rejects_duplicate_pair(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);

        $this->expectException(QueryException::class);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
    }

    public function test_deleting_mechanic_cascades_to_mechanic_branches(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);

        $mechanic->delete();

        $this->assertDatabaseMissing('mechanic_branches', ['mechanic_id' => $mechanic->id]);
    }

    public function test_service_catalog_can_be_created_with_fillable_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $service = ServiceCatalog::create([
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli',
            'default_price' => 75000,
        ]);

        $this->assertSame('Ganti Oli', $service->name);
        $this->assertSame('75000.00', $service->default_price);
        $this->assertTrue($service->is_active);
        $this->assertSame($user->id, $service->created_by);
    }

    public function test_service_catalog_code_is_unique(): void
    {
        ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);

        $this->expectException(QueryException::class);
        ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli Duplikat', 'default_price' => 50000]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=MechanicServiceModelTest`
Expected: FAIL — tables/classes don't exist yet.

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_08_02_000010_create_mechanics_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMechanicsTable extends Migration
{
    public function up()
    {
        Schema::create('mechanics', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('address')->nullable();
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
        Schema::dropIfExists('mechanics');
    }
}
```

`database/migrations/2026_08_02_000011_create_mechanic_branches_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMechanicBranchesTable extends Migration
{
    public function up()
    {
        Schema::create('mechanic_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mechanic_id')->constrained('mechanics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['mechanic_id', 'branch_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('mechanic_branches');
    }
}
```

`database/migrations/2026_08_02_000012_create_service_catalogs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServiceCatalogsTable extends Migration
{
    public function up()
    {
        Schema::create('service_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->decimal('default_price', 18, 2);
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
        Schema::dropIfExists('service_catalogs');
    }
}
```

- [ ] **Step 4: Write the models**

`app/Models/Mechanic.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mechanic extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'name', 'phone', 'email', 'address', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function mechanicBranches()
    {
        return $this->hasMany(MechanicBranch::class);
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'mechanic_branches')
            ->wherePivot('is_active', true);
    }

    public function hasAccessToBranch(int $branchId): bool
    {
        return $this->mechanicBranches()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->exists();
    }
}
```

`app/Models/MechanicBranch.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MechanicBranch extends Model
{
    use HasFactory;

    protected $fillable = ['mechanic_id', 'branch_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function mechanic()
    {
        return $this->belongsTo(Mechanic::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
```

`app/Models/ServiceCatalog.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceCatalog extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['code', 'name', 'default_price', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=MechanicServiceModelTest`
Expected: PASS, 5/5.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_02_000010_create_mechanics_table.php \
        database/migrations/2026_08_02_000011_create_mechanic_branches_table.php \
        database/migrations/2026_08_02_000012_create_service_catalogs_table.php \
        app/Models/Mechanic.php app/Models/MechanicBranch.php app/Models/ServiceCatalog.php \
        tests/Feature/MechanicServiceModelTest.php
git commit -m "feat: add mechanic and service catalog tables"
```

---

### Task 2: Mechanic CRUD screens

**Files:**
- Create: `app/Http/Requests/StoreMechanicRequest.php`
- Create: `app/Http/Requests/UpdateMechanicRequest.php`
- Create: `app/Http/Controllers/MechanicController.php`
- Create: `resources/views/mechanics/index.blade.php`
- Create: `resources/views/mechanics/create.blade.php`
- Create: `resources/views/mechanics/show.blade.php`
- Create: `resources/views/mechanics/_tab_profil.blade.php`
- Create: `resources/views/mechanics/_tab_cabang.blade.php` (placeholder, replaced in Task 3)
- Modify: `routes/web.php`
- Test: `tests/Feature/MechanicManagementTest.php`

**Interfaces:**
- Consumes: `Mechanic` model (Task 1).
- Produces: routes `mechanics.index`, `mechanics.create`, `mechanics.store`, `mechanics.show`, `mechanics.update`. View `mechanics.show` renders tab include `mechanics._tab_cabang` (Task 3 supplies the real content; this task creates a placeholder so the page renders now).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/MechanicManagementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Mechanic;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MechanicManagementTest extends TestCase
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

    public function test_index_lists_mechanics_for_authorized_user(): void
    {
        Mechanic::create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/mechanics');

        $response->assertForbidden();
    }

    public function test_store_creates_mechanic(): void
    {
        $user = $this->userWithPermissions(['mechanic.create']);

        $response = $this->actingAs($user)->post('/mechanics', [
            'name' => 'Agus Setiawan',
            'phone' => '081234567890',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/mechanics');
        $this->assertDatabaseHas('mechanics', ['name' => 'Agus Setiawan']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithPermissions(['mechanic.create']);

        $response = $this->actingAs($user)->post('/mechanics', []);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/mechanics', ['name' => 'Agus Setiawan']);

        $response->assertForbidden();
    }

    public function test_show_renders_profil_tab_for_authorized_user(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($user)->get("/mechanics/{$mechanic->id}");

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
    }

    public function test_update_edits_mechanic_and_can_deactivate(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermissions(['mechanic.edit']);

        $response = $this->actingAs($user)->put("/mechanics/{$mechanic->id}", [
            'name' => 'Agus Setiawan Edited',
            'is_active' => '0',
        ]);

        $response->assertRedirect("/mechanics/{$mechanic->id}");
        $this->assertDatabaseHas('mechanics', [
            'id' => $mechanic->id,
            'name' => 'Agus Setiawan Edited',
            'is_active' => false,
        ]);
    }

    public function test_update_is_forbidden_without_permission(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put("/mechanics/{$mechanic->id}", ['name' => 'Agus Setiawan Edited']);

        $response->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=MechanicManagementTest`
Expected: FAIL — route/controller/views don't exist yet.

- [ ] **Step 3: Write the Form Requests**

`app/Http/Requests/StoreMechanicRequest.php`:

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
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
        ];
    }
}
```

`app/Http/Requests/UpdateMechanicRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/MechanicController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMechanicRequest;
use App\Http\Requests\UpdateMechanicRequest;
use App\Models\Branch;
use App\Models\Mechanic;

class MechanicController extends Controller
{
    public function index()
    {
        $this->authorize('mechanic.view');

        $mechanics = Mechanic::orderBy('name')->simplePaginate(15);

        return view('mechanics.index', compact('mechanics'));
    }

    public function create()
    {
        $this->authorize('mechanic.create');

        $mechanic = new Mechanic();

        return view('mechanics.create', compact('mechanic'));
    }

    public function store(StoreMechanicRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        Mechanic::create($data);

        return redirect()->route('mechanics.index')->with('status', 'Mekanik berhasil ditambahkan.');
    }

    public function show(Mechanic $mechanic)
    {
        $this->authorize('mechanic.view');

        $mechanic->load('mechanicBranches');
        $allBranches = Branch::orderBy('name')->get();

        return view('mechanics.show', compact('mechanic', 'allBranches'));
    }

    public function update(UpdateMechanicRequest $request, Mechanic $mechanic)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $mechanic->update($data);

        return redirect()->route('mechanics.show', $mechanic)->with('status', 'Mekanik berhasil diperbarui.');
    }
}
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\MechanicController;
```

Add this route group after the `vehicle-references` group and before the `users` group:

```php
    Route::prefix('mechanics')->name('mechanics.')->group(function () {
        Route::get('/', [MechanicController::class, 'index'])->name('index');
        Route::get('/create', [MechanicController::class, 'create'])->name('create');
        Route::post('/', [MechanicController::class, 'store'])->name('store');
        Route::get('/{mechanic}', [MechanicController::class, 'show'])->name('show');
        Route::put('/{mechanic}', [MechanicController::class, 'update'])->name('update');
    });
```

- [ ] **Step 6: Write the views**

`resources/views/mechanics/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Mekanik')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-gear me-2"></i>Mekanik</h1>
        @can('mechanic.create')
            <a href="{{ route('mechanics.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Tambah Mekanik
            </a>
        @endcan
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nama</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mechanics as $mechanic)
                        <tr>
                            <td>{{ $mechanic->name }}</td>
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
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">Belum ada mekanik.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $mechanics->links() }}
    </div>
@endsection
```

`resources/views/mechanics/create.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Tambah Mekanik')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-gear me-2"></i>Tambah Mekanik</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('mechanics.store') }}">
                @csrf

                <div class="mb-3">
                    <label for="name" class="form-label">Nama Mekanik</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="phone" class="form-label">Telepon</label>
                        <input type="text" name="phone" id="phone" value="{{ old('phone') }}" class="form-control @error('phone') is-invalid @enderror" maxlength="50">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" maxlength="255">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="address" class="form-label">Alamat</label>
                    <textarea name="address" id="address" class="form-control @error('address') is-invalid @enderror" rows="2">{{ old('address') }}</textarea>
                    @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-check form-switch mb-4">
                    <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" checked>
                    <label for="is_active" class="form-check-label">Aktif</label>
                </div>

                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
                <a href="{{ route('mechanics.index') }}" class="btn btn-outline-secondary">Batal</a>
            </form>
        </div>
    </div>
@endsection
```

`resources/views/mechanics/_tab_profil.blade.php`:

```blade
<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ route('mechanics.update', $mechanic) }}">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="name" class="form-label">Nama Mekanik</label>
                <input type="text" name="name" id="name" value="{{ old('name', $mechanic->name) }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="phone" class="form-label">Telepon</label>
                    <input type="text" name="phone" id="phone" value="{{ old('phone', $mechanic->phone) }}" class="form-control @error('phone') is-invalid @enderror" maxlength="50">
                    @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6 mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" name="email" id="email" value="{{ old('email', $mechanic->email) }}" class="form-control @error('email') is-invalid @enderror" maxlength="255">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-3">
                <label for="address" class="form-label">Alamat</label>
                <textarea name="address" id="address" class="form-control @error('address') is-invalid @enderror" rows="2">{{ old('address', $mechanic->address) }}</textarea>
                @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-check form-switch mb-4">
                <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $mechanic->is_active) ? 'checked' : '' }}>
                <label for="is_active" class="form-check-label">Aktif</label>
            </div>

            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </form>
    </div>
</div>
```

`resources/views/mechanics/show.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Detail Mekanik')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h4 mb-1"><i class="bi bi-person-gear me-2"></i>{{ $mechanic->name }}</h1>
            @if ($mechanic->is_active)
                <span class="status-dot status-active">Aktif</span>
            @else
                <span class="status-dot status-inactive">Nonaktif</span>
            @endif
        </div>
        <a href="{{ route('mechanics.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Kembali
        </a>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#profil-pane" type="button" role="tab">
                <i class="bi bi-person me-1"></i> Profil
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#cabang-pane" type="button" role="tab">
                <i class="bi bi-shop me-1"></i> Cabang
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="profil-pane" role="tabpanel">
            @include('mechanics._tab_profil')
        </div>
        <div class="tab-pane fade" id="cabang-pane" role="tabpanel">
            @include('mechanics._tab_cabang')
        </div>
    </div>
@endsection
```

Placeholder `resources/views/mechanics/_tab_cabang.blade.php` (replaced in Task 3):

```blade
<div class="card shadow-sm"><div class="card-body text-muted">Memuat...</div></div>
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=MechanicManagementTest`
Expected: PASS, 8/8.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/StoreMechanicRequest.php app/Http/Requests/UpdateMechanicRequest.php \
        app/Http/Controllers/MechanicController.php routes/web.php \
        resources/views/mechanics/ tests/Feature/MechanicManagementTest.php
git commit -m "feat: add mechanic list/create/detail screens"
```

---

### Task 3: Mechanic → Branch assignment (Cabang tab)

**Files:**
- Create: `app/Http/Controllers/MechanicBranchAssignmentController.php`
- Modify: `resources/views/mechanics/_tab_cabang.blade.php` (replace Task 2's placeholder)
- Modify: `routes/web.php`
- Test: `tests/Feature/MechanicBranchTabTest.php`

**Interfaces:**
- Consumes: `Mechanic`/`MechanicBranch`/`Branch` models (Task 1), `$mechanic->mechanicBranches` relation loaded by `MechanicController::show()` (Task 2).
- Produces: routes `mechanics.branches.store` (`POST /mechanics/{mechanic}/branches/{branch}`), `mechanics.branches.destroy` (`DELETE /mechanics/{mechanic}/branches/{branch}`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/MechanicBranchTabTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MechanicBranchTabTest extends TestCase
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

    public function test_assigning_a_branch_creates_active_link(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $admin = $this->userWithPermissions(['mechanic.edit']);

        $response = $this->actingAs($admin)->postJson("/mechanics/{$mechanic->id}/branches/{$branch->id}");

        $response->assertOk();
        $this->assertDatabaseHas('mechanic_branches', [
            'mechanic_id' => $mechanic->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    public function test_unassigning_a_branch_deactivates_the_link(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $admin = $this->userWithPermissions(['mechanic.edit']);

        $response = $this->actingAs($admin)->deleteJson("/mechanics/{$mechanic->id}/branches/{$branch->id}");

        $response->assertOk();
        $this->assertDatabaseHas('mechanic_branches', [
            'mechanic_id' => $mechanic->id,
            'branch_id' => $branch->id,
            'is_active' => false,
        ]);
    }

    public function test_branch_endpoints_are_forbidden_without_permission(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/mechanics/{$mechanic->id}/branches/{$branch->id}")->assertForbidden();
    }

    public function test_show_page_renders_cabang_tab(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $admin = $this->userWithPermissions(['mechanic.view']);

        $response = $this->actingAs($admin)->get("/mechanics/{$mechanic->id}");

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=MechanicBranchTabTest`
Expected: FAIL — controller/routes don't exist, placeholder tab doesn't list branches.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/MechanicBranchAssignmentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;

class MechanicBranchAssignmentController extends Controller
{
    public function store(Mechanic $mechanic, Branch $branch)
    {
        $this->authorize('mechanic.edit');

        MechanicBranch::updateOrCreate(
            ['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id],
            ['is_active' => true]
        );

        return response()->json(['message' => 'Cabang berhasil ditambahkan.']);
    }

    public function destroy(Mechanic $mechanic, Branch $branch)
    {
        $this->authorize('mechanic.edit');

        MechanicBranch::where('mechanic_id', $mechanic->id)
            ->where('branch_id', $branch->id)
            ->update(['is_active' => false]);

        return response()->json(['message' => 'Cabang berhasil dihapus dari mekanik.']);
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\MechanicBranchAssignmentController;
```

Inside the `mechanics` route group (created in Task 2), after the `update` route, add:

```php
        Route::prefix('{mechanic}/branches')->name('branches.')->group(function () {
            Route::post('/{branch}', [MechanicBranchAssignmentController::class, 'store'])->name('store');
            Route::delete('/{branch}', [MechanicBranchAssignmentController::class, 'destroy'])->name('destroy');
        });
```

- [ ] **Step 5: Replace the placeholder tab view**

Overwrite `resources/views/mechanics/_tab_cabang.blade.php`:

```blade
<div class="card shadow-sm">
    <div class="card-body">
        <p class="text-muted small">Centang cabang yang dapat menugaskan mekanik ini.</p>

        <div id="mechanic-branch-list">
            @foreach ($allBranches as $branch)
                @php($mechanicBranch = $mechanic->mechanicBranches->firstWhere('branch_id', $branch->id))
                <div class="d-flex align-items-center justify-content-between border-bottom py-2" data-branch-row="{{ $branch->id }}">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input mechanic-branch-toggle" id="branch-{{ $branch->id }}"
                            data-branch-id="{{ $branch->id }}"
                            {{ $mechanicBranch && $mechanicBranch->is_active ? 'checked' : '' }}>
                        <label class="form-check-label" for="branch-{{ $branch->id }}">{{ $branch->name }}</label>
                    </div>
                </div>
            @endforeach
        </div>

        <div id="mechanic-branch-feedback" class="small mt-3"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const mechanicId = {{ $mechanic->id }};
    const feedback = document.getElementById('mechanic-branch-feedback');

    function showFeedback(message, isError) {
        feedback.textContent = message;
        feedback.className = 'small mt-3 ' + (isError ? 'text-danger' : 'text-success');
    }

    async function send(url, method) {
        const response = await fetch(url, {
            method,
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.message || 'Terjadi kesalahan.');
        }
        return data;
    }

    document.querySelectorAll('.mechanic-branch-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', async function () {
            const branchId = this.dataset.branchId;
            try {
                if (this.checked) {
                    const data = await send(`/mechanics/${mechanicId}/branches/${branchId}`, 'POST');
                    showFeedback(data.message, false);
                } else {
                    const data = await send(`/mechanics/${mechanicId}/branches/${branchId}`, 'DELETE');
                    showFeedback(data.message, false);
                }
            } catch (error) {
                this.checked = !this.checked;
                showFeedback(error.message, true);
            }
        });
    });
})();
</script>
@endpush
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=MechanicBranchTabTest`
Expected: PASS, 4/4. Also re-run `php artisan test --filter=MechanicManagementTest` to confirm Task 2's tests still pass.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/MechanicBranchAssignmentController.php routes/web.php \
        resources/views/mechanics/_tab_cabang.blade.php tests/Feature/MechanicBranchTabTest.php
git commit -m "feat: add mechanic branch assignment tab"
```

---

### Task 4: Service Catalog CRUD screens (flat)

**Files:**
- Create: `app/Http/Requests/StoreServiceCatalogRequest.php`
- Create: `app/Http/Requests/UpdateServiceCatalogRequest.php`
- Create: `app/Http/Controllers/ServiceCatalogController.php`
- Create: `resources/views/service-catalogs/index.blade.php`
- Create: `resources/views/service-catalogs/create.blade.php`
- Create: `resources/views/service-catalogs/edit.blade.php`
- Create: `resources/views/service-catalogs/_form.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ServiceCatalogManagementTest.php`

**Interfaces:**
- Consumes: `ServiceCatalog` model (Task 1).
- Produces: routes `service-catalogs.index`, `service-catalogs.create`, `service-catalogs.store`, `service-catalogs.edit`, `service-catalogs.update`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ServiceCatalogManagementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogManagementTest extends TestCase
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

    public function test_index_lists_service_catalogs_for_authorized_user(): void
    {
        ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        $user = $this->userWithPermissions(['service.view']);

        $response = $this->actingAs($user)->get('/service-catalogs');

        $response->assertOk();
        $response->assertSee('Ganti Oli');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/service-catalogs');

        $response->assertForbidden();
    }

    public function test_store_creates_service_catalog(): void
    {
        $user = $this->userWithPermissions(['service.create']);

        $response = $this->actingAs($user)->post('/service-catalogs', [
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli',
            'default_price' => '75000',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/service-catalogs');
        $this->assertDatabaseHas('service_catalogs', ['code' => 'GANTI-OLI', 'name' => 'Ganti Oli']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithPermissions(['service.create']);

        $response = $this->actingAs($user)->post('/service-catalogs', []);

        $response->assertSessionHasErrors(['code', 'name', 'default_price']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        $user = $this->userWithPermissions(['service.create']);

        $response = $this->actingAs($user)->post('/service-catalogs', [
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli Lagi',
            'default_price' => '50000',
        ]);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/service-catalogs', [
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli',
            'default_price' => '75000',
        ]);

        $response->assertForbidden();
    }

    public function test_update_edits_service_catalog_and_can_deactivate(): void
    {
        $service = ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        $user = $this->userWithPermissions(['service.edit']);

        $response = $this->actingAs($user)->put("/service-catalogs/{$service->id}", [
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli Mesin',
            'default_price' => '80000',
            'is_active' => '0',
        ]);

        $response->assertRedirect('/service-catalogs');
        $this->assertDatabaseHas('service_catalogs', [
            'id' => $service->id,
            'name' => 'Ganti Oli Mesin',
            'is_active' => false,
        ]);
    }

    public function test_update_allows_keeping_the_same_code(): void
    {
        $service = ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        $user = $this->userWithPermissions(['service.edit']);

        $response = $this->actingAs($user)->put("/service-catalogs/{$service->id}", [
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli',
            'default_price' => '75000',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/service-catalogs');
        $response->assertSessionDoesntHaveErrors();
    }

    public function test_update_is_forbidden_without_permission(): void
    {
        $service = ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put("/service-catalogs/{$service->id}", [
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli Edited',
            'default_price' => '75000',
        ]);

        $response->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ServiceCatalogManagementTest`
Expected: FAIL — route/controller/views don't exist.

- [ ] **Step 3: Write the Form Requests**

`app/Http/Requests/StoreServiceCatalogRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceCatalogRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('service.create');
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:30', 'unique:service_catalogs,code'],
            'name' => ['required', 'string', 'max:150'],
            'default_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
```

`app/Http/Requests/UpdateServiceCatalogRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceCatalogRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('service.edit');
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('service_catalogs', 'code')->ignore($this->route('serviceCatalog'))],
            'name' => ['required', 'string', 'max:150'],
            'default_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/ServiceCatalogController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceCatalogRequest;
use App\Http\Requests\UpdateServiceCatalogRequest;
use App\Models\ServiceCatalog;

class ServiceCatalogController extends Controller
{
    public function index()
    {
        $this->authorize('service.view');

        $serviceCatalogs = ServiceCatalog::orderBy('name')->simplePaginate(15);

        return view('service-catalogs.index', compact('serviceCatalogs'));
    }

    public function create()
    {
        $this->authorize('service.create');

        $serviceCatalog = new ServiceCatalog();

        return view('service-catalogs.create', compact('serviceCatalog'));
    }

    public function store(StoreServiceCatalogRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        ServiceCatalog::create($data);

        return redirect()->route('service-catalogs.index')->with('status', 'Jasa service berhasil ditambahkan.');
    }

    public function edit(ServiceCatalog $serviceCatalog)
    {
        $this->authorize('service.edit');

        return view('service-catalogs.edit', compact('serviceCatalog'));
    }

    public function update(UpdateServiceCatalogRequest $request, ServiceCatalog $serviceCatalog)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $serviceCatalog->update($data);

        return redirect()->route('service-catalogs.index')->with('status', 'Jasa service berhasil diperbarui.');
    }
}
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\ServiceCatalogController;
```

Add this route group after the `mechanics` group:

```php
    Route::prefix('service-catalogs')->name('service-catalogs.')->group(function () {
        Route::get('/', [ServiceCatalogController::class, 'index'])->name('index');
        Route::get('/create', [ServiceCatalogController::class, 'create'])->name('create');
        Route::post('/', [ServiceCatalogController::class, 'store'])->name('store');
        Route::get('/{serviceCatalog}/edit', [ServiceCatalogController::class, 'edit'])->name('edit');
        Route::put('/{serviceCatalog}', [ServiceCatalogController::class, 'update'])->name('update');
    });
```

- [ ] **Step 6: Write the views**

`resources/views/service-catalogs/_form.blade.php`:

```blade
@csrf
@isset($method)
    @method($method)
@endisset

<div class="mb-3">
    <label for="code" class="form-label">Kode Jasa</label>
    <input type="text" name="code" id="code" value="{{ old('code', $serviceCatalog->code) }}" class="form-control @error('code') is-invalid @enderror" maxlength="30" required>
    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="name" class="form-label">Nama Jasa</label>
    <input type="text" name="name" id="name" value="{{ old('name', $serviceCatalog->name) }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="default_price" class="form-label">Harga Default</label>
    <input type="number" step="0.01" min="0" name="default_price" id="default_price" value="{{ old('default_price', $serviceCatalog->default_price) }}" class="form-control @error('default_price') is-invalid @enderror" required>
    @error('default_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="form-check form-switch mb-4">
    <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $serviceCatalog->exists ? $serviceCatalog->is_active : true) ? 'checked' : '' }}>
    <label for="is_active" class="form-check-label">Aktif</label>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
<a href="{{ route('service-catalogs.index') }}" class="btn btn-outline-secondary">Batal</a>
```

`resources/views/service-catalogs/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Jasa Service')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-tools me-2"></i>Jasa Service</h1>
        @can('service.create')
            <a href="{{ route('service-catalogs.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Tambah Jasa
            </a>
        @endcan
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Harga Default</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($serviceCatalogs as $serviceCatalog)
                        <tr>
                            <td><code>{{ $serviceCatalog->code }}</code></td>
                            <td>{{ $serviceCatalog->name }}</td>
                            <td>{{ number_format($serviceCatalog->default_price, 0, ',', '.') }}</td>
                            <td>
                                @if ($serviceCatalog->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('service.edit')
                                    <a href="{{ route('service-catalogs.edit', $serviceCatalog) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Belum ada jasa service.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $serviceCatalogs->links() }}
    </div>
@endsection
```

`resources/views/service-catalogs/create.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Tambah Jasa Service')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-tools me-2"></i>Tambah Jasa Service</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('service-catalogs.store') }}">
                @include('service-catalogs._form')
            </form>
        </div>
    </div>
@endsection
```

`resources/views/service-catalogs/edit.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Ubah Jasa Service')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-tools me-2"></i>Ubah Jasa Service</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('service-catalogs.update', $serviceCatalog) }}">
                @php($method = 'PUT')
                @include('service-catalogs._form')
            </form>
        </div>
    </div>
@endsection
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=ServiceCatalogManagementTest`
Expected: PASS, 9/9.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/StoreServiceCatalogRequest.php app/Http/Requests/UpdateServiceCatalogRequest.php \
        app/Http/Controllers/ServiceCatalogController.php routes/web.php \
        resources/views/service-catalogs/ tests/Feature/ServiceCatalogManagementTest.php
git commit -m "feat: add service catalog list/create/edit screens"
```

---

### Task 5: Sidebar wiring + full-suite verification

**Files:**
- Modify: `resources/views/partials/sidebar.blade.php`
- Modify: `tests/Feature/AppShellTest.php` (add methods, don't touch existing ones)

**Interfaces:**
- Consumes: `mechanics.index`, `service-catalogs.index` routes (Tasks 2/4) and their `.view` permission codes.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AppShellTest.php` (new method, inside the existing class, after `test_sidebar_shows_vehicle_and_vehicle_reference_links_when_authorized`):

```php
    public function test_sidebar_shows_mechanic_and_service_links_when_authorized(): void
    {
        $user = User::factory()->create();

        foreach (['mechanic.view', 'service.view'] as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::create(['code' => $code, 'resource' => $resource, 'action' => $action, 'description' => $code]);
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('mechanics.index'), false);
        $response->assertSee(route('service-catalogs.index'), false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AppShellTest`
Expected: the new method FAILs — sidebar has no Mekanik/Jasa Service links yet.

- [ ] **Step 3: Update the sidebar**

In `resources/views/partials/sidebar.blade.php`, the Master Data section currently looks like this:

```blade
@if ($user && ($user->can('branch.view') || $user->can('customer.view') || $user->can('vehicle.view') || $user->can('vehicle_reference.view')))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Master Data</div>
    <ul class="nav flex-column mb-3">
        @can('branch.view')
        <li class="nav-item">
            <a href="{{ route('branches.index') }}" class="nav-link {{ request()->routeIs('branches.*') ? 'active' : '' }}">
                <i class="bi bi-shop me-2"></i> Cabang
            </a>
        </li>
        @endcan
        @can('customer.view')
        <li class="nav-item">
            <a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.*') ? 'active' : '' }}">
                <i class="bi bi-person-badge me-2"></i> Customer
            </a>
        </li>
        @endcan
        @can('vehicle.view')
        <li class="nav-item">
            <a href="{{ route('vehicles.index') }}" class="nav-link {{ request()->routeIs('vehicles.*') ? 'active' : '' }}">
                <i class="bi bi-car-front me-2"></i> Kendaraan
            </a>
        </li>
        @endcan
        @can('vehicle_reference.view')
        <li class="nav-item">
            <a href="{{ route('vehicle-references.index') }}" class="nav-link {{ request()->routeIs('vehicle-references.*') ? 'active' : '' }}">
                <i class="bi bi-diagram-3 me-2"></i> Referensi Kendaraan
            </a>
        </li>
        @endcan
    </ul>
@endif
```

Replace it with (widened outer condition to include the two new permissions, two new `@can` blocks added after the `vehicle_reference.view` block):

```blade
@if ($user && ($user->can('branch.view') || $user->can('customer.view') || $user->can('vehicle.view') || $user->can('vehicle_reference.view') || $user->can('mechanic.view') || $user->can('service.view')))
    <div class="sidebar-heading px-2 mb-1 mt-2 text-uppercase">Master Data</div>
    <ul class="nav flex-column mb-3">
        @can('branch.view')
        <li class="nav-item">
            <a href="{{ route('branches.index') }}" class="nav-link {{ request()->routeIs('branches.*') ? 'active' : '' }}">
                <i class="bi bi-shop me-2"></i> Cabang
            </a>
        </li>
        @endcan
        @can('customer.view')
        <li class="nav-item">
            <a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.*') ? 'active' : '' }}">
                <i class="bi bi-person-badge me-2"></i> Customer
            </a>
        </li>
        @endcan
        @can('vehicle.view')
        <li class="nav-item">
            <a href="{{ route('vehicles.index') }}" class="nav-link {{ request()->routeIs('vehicles.*') ? 'active' : '' }}">
                <i class="bi bi-car-front me-2"></i> Kendaraan
            </a>
        </li>
        @endcan
        @can('vehicle_reference.view')
        <li class="nav-item">
            <a href="{{ route('vehicle-references.index') }}" class="nav-link {{ request()->routeIs('vehicle-references.*') ? 'active' : '' }}">
                <i class="bi bi-diagram-3 me-2"></i> Referensi Kendaraan
            </a>
        </li>
        @endcan
        @can('mechanic.view')
        <li class="nav-item">
            <a href="{{ route('mechanics.index') }}" class="nav-link {{ request()->routeIs('mechanics.*') ? 'active' : '' }}">
                <i class="bi bi-person-gear me-2"></i> Mekanik
            </a>
        </li>
        @endcan
        @can('service.view')
        <li class="nav-item">
            <a href="{{ route('service-catalogs.index') }}" class="nav-link {{ request()->routeIs('service-catalogs.*') ? 'active' : '' }}">
                <i class="bi bi-tools me-2"></i> Jasa Service
            </a>
        </li>
        @endcan
    </ul>
@endif
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AppShellTest`
Expected: PASS, 6/6.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS, all tests green (baseline 129 + this plan's new tests).

- [ ] **Step 6: Commit**

```bash
git add resources/views/partials/sidebar.blade.php tests/Feature/AppShellTest.php
git commit -m "feat: wire mechanic/service-catalog links into Master Data sidebar"
```

---

## Manual verification checklist (after all tasks complete)

1. `php artisan migrate` on the dev `laravel` database (not `bengkel_testing` — check `.env` `DB_DATABASE` first, per project memory).
2. Log in as `faiz_rahmat`. Confirm the sidebar shows Mekanik and Jasa Service under Master Data.
3. Create a mechanic, assign it to a branch via the Cabang tab, confirm the checkbox persists on reload.
4. Create a service catalog entry, confirm it appears in the list with the formatted price, edit it and confirm the code-uniqueness validation allows keeping its own code unchanged.
