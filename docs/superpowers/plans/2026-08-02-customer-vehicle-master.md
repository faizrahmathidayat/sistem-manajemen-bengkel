# Customer & Kendaraan Master (Migration 003) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task — this is master-data CRUD following an already-shipped pattern (Branch/User), not auth-critical infrastructure, so per the project's process preference it runs **inline**, no subagent dispatch. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Customer and Kendaraan (vehicle) master data — customers, their many-branch assignment, a 3-level vehicle reference hierarchy (Kategori→Merk→Tipe), and vehicles themselves — with full CRUD screens wired into the existing Master Data sidebar.

**Architecture:** Six new tables (`customers`, `customer_branches`, `vehicle_categories`, `vehicle_brands`, `vehicle_types`, `vehicles`), each following the exact conventions already established by `branches`/`user_branches` (bigint PK, `HasAudit`, `is_active` toggle, no hard delete). Customer screens mirror the `User` controller/view shape (list → create → detail-with-tabs). Vehicle is a standalone module with cascading category→brand→type selects. A new combined "Referensi Kendaraan" screen manages the 3-level hierarchy inline (no modals — none exist anywhere in this codebase).

**Tech Stack:** Laravel 8, Blade, Bootstrap 5 (existing design tokens/`.status-dot`/`.card` conventions), vanilla JS + `fetch()` for AJAX (existing pattern from `users/_tab_cabang.blade.php`).

Design spec: `docs/superpowers/specs/2026-08-02-customer-vehicle-master-design.md`.

## Global Constraints

- New tables: `bigint` auto-increment PK (`$table->id()`), `snake_case` plural names, `HasAudit` trait (`created_by`/`updated_by`, auto-stamped), `is_active` boolean toggle — **no hard delete anywhere in this plan**.
- `plate_number`/`frame_number`/`engine_number` are nullable + unique — MySQL's unique index already treats every `NULL` as distinct, so no generated-column trick is needed (unlike `user_branches.default_marker`).
- Cascading validation (`brand.category_id` must match submitted `category_id`; `type.brand_id` must match submitted `brand_id`) is enforced in the Form Request via `withValidator()`, not a DB trigger.
- Permission codes `customer.view/create/edit` and `vehicle.view/create/edit` already exist (seeded in migration 002, menus `master.customer`/`master.vehicle`) — **do not re-seed them**, only consume them in `$this->authorize(...)` calls.
- New permission codes this plan does add: `vehicle_reference.view`, `vehicle_reference.manage` (menu `master.vehicle_reference`), added to `MenuPermissionSeeder`.
- `customer_branches` assignment is gated by `customer.edit` — no dedicated `customer_branch.manage` permission code (deliberately simpler than `user_branches`, which does have its own `user_branch.manage` code; that precedent isn't being copied here, per the approved spec).
- No modals anywhere in this codebase — every dynamic interaction (including the vehicle-reference screen's add/edit) is inline AJAX with `fetch()`, matching `users/_tab_cabang.blade.php`.
- Every list/index endpoint uses `->simplePaginate()`, never `->paginate()`.
- Full TDD: write the failing test first, confirm the failure reason, implement, confirm green.

---

### Task 1: Data model — migrations, models

**Files:**
- Create: `database/migrations/2026_08_02_000004_create_customers_table.php`
- Create: `database/migrations/2026_08_02_000005_create_customer_branches_table.php`
- Create: `database/migrations/2026_08_02_000006_create_vehicle_categories_table.php`
- Create: `database/migrations/2026_08_02_000007_create_vehicle_brands_table.php`
- Create: `database/migrations/2026_08_02_000008_create_vehicle_types_table.php`
- Create: `database/migrations/2026_08_02_000009_create_vehicles_table.php`
- Create: `app/Models/Customer.php`
- Create: `app/Models/CustomerBranch.php`
- Create: `app/Models/VehicleCategory.php`
- Create: `app/Models/VehicleBrand.php`
- Create: `app/Models/VehicleType.php`
- Create: `app/Models/Vehicle.php`
- Test: `tests/Feature/CustomerVehicleModelTest.php`

**Interfaces:**
- Produces: `Customer` (fillable: `customer_type, name, stnk_name, address, phone, email, is_active`; relations `customerBranches()`, `branches()`, `hasAccessToBranch(int): bool`, `vehicles()`). `CustomerBranch` (fillable: `customer_id, branch_id, is_active`; relations `customer()`, `branch()`). `VehicleCategory` (fillable: `name, is_active`; relation `brands()`). `VehicleBrand` (fillable: `category_id, name, is_active`; relations `category()`, `types()`). `VehicleType` (fillable: `brand_id, name, is_active`; relation `brand()`). `Vehicle` (fillable: `customer_id, plate_number, frame_number, engine_number, category_id, brand_id, type_id, is_active`; relations `customer()`, `category()`, `brand()`, `type()`).

- [ ] **Step 1: Write the failing model tests**

Create `tests/Feature/CustomerVehicleModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerVehicleModelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeVehicle(Customer $customer, array $overrides = []): Vehicle
    {
        $category = VehicleCategory::create(['name' => 'Motor']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);

        return Vehicle::create(array_merge([
            'customer_id' => $customer->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'type_id' => $type->id,
        ], $overrides));
    }

    public function test_customer_can_be_created_with_fillable_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $customer = Customer::create([
            'customer_type' => 'INDIVIDUAL',
            'name' => 'Budi Santoso',
            'stnk_name' => 'Budi Santoso',
            'phone' => '081234567890',
        ]);

        $this->assertSame('Budi Santoso', $customer->name);
        $this->assertTrue($customer->is_active);
        $this->assertSame($user->id, $customer->created_by);
    }

    public function test_customer_branches_rejects_duplicate_pair(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);

        $this->expectException(QueryException::class);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
    }

    public function test_vehicle_brand_name_is_unique_per_category_but_reusable_across_categories(): void
    {
        $mobil = VehicleCategory::create(['name' => 'Mobil']);
        $motor = VehicleCategory::create(['name' => 'Motor']);

        VehicleBrand::create(['category_id' => $mobil->id, 'name' => 'Honda']);
        VehicleBrand::create(['category_id' => $motor->id, 'name' => 'Honda']);

        $this->assertSame(2, VehicleBrand::where('name', 'Honda')->count());

        $this->expectException(QueryException::class);
        VehicleBrand::create(['category_id' => $mobil->id, 'name' => 'Honda']);
    }

    public function test_vehicle_type_name_is_unique_per_brand(): void
    {
        $category = VehicleCategory::create(['name' => 'Motor']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);

        $this->expectException(QueryException::class);
        VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);
    }

    public function test_two_vehicles_can_both_have_no_plate_number(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);

        $this->makeVehicle($customer, ['plate_number' => null]);
        $this->makeVehicle($customer, ['plate_number' => null]);

        $this->assertSame(2, Vehicle::whereNull('plate_number')->count());
    }

    public function test_duplicate_plate_number_is_rejected(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $this->makeVehicle($customer, ['plate_number' => 'B 1234 XYZ']);

        $this->expectException(QueryException::class);
        $this->makeVehicle($customer, ['plate_number' => 'B 1234 XYZ']);
    }

    public function test_deleting_customer_cascades_to_customer_branches_and_vehicles(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $vehicle = $this->makeVehicle($customer);

        $customer->delete();

        $this->assertDatabaseMissing('customer_branches', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CustomerVehicleModelTest`
Expected: FAIL — tables/classes don't exist yet.

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_08_02_000004_create_customers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomersTable extends Migration
{
    public function up()
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_type', 20);
            $table->string('name', 150);
            $table->string('stnk_name', 150);
            $table->text('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 255)->nullable();
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
        Schema::dropIfExists('customers');
    }
}
```

`database/migrations/2026_08_02_000005_create_customer_branches_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomerBranchesTable extends Migration
{
    public function up()
    {
        Schema::create('customer_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['customer_id', 'branch_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('customer_branches');
    }
}
```

`database/migrations/2026_08_02_000006_create_vehicle_categories_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVehicleCategoriesTable extends Migration
{
    public function up()
    {
        Schema::create('vehicle_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
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
        Schema::dropIfExists('vehicle_categories');
    }
}
```

`database/migrations/2026_08_02_000007_create_vehicle_brands_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVehicleBrandsTable extends Migration
{
    public function up()
    {
        Schema::create('vehicle_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('vehicle_categories')->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'name']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('vehicle_brands');
    }
}
```

`database/migrations/2026_08_02_000008_create_vehicle_types_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVehicleTypesTable extends Migration
{
    public function up()
    {
        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('vehicle_brands')->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'name']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('vehicle_types');
    }
}
```

`database/migrations/2026_08_02_000009_create_vehicles_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVehiclesTable extends Migration
{
    public function up()
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('plate_number', 30)->nullable()->unique();
            $table->string('frame_number', 100)->nullable()->unique();
            $table->string('engine_number', 100)->nullable()->unique();
            $table->foreignId('category_id')->constrained('vehicle_categories');
            $table->foreignId('brand_id')->constrained('vehicle_brands');
            $table->foreignId('type_id')->constrained('vehicle_types');
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
        Schema::dropIfExists('vehicles');
    }
}
```

- [ ] **Step 4: Write the models**

`app/Models/Customer.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'customer_type', 'name', 'stnk_name', 'address', 'phone', 'email', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function customerBranches()
    {
        return $this->hasMany(CustomerBranch::class);
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'customer_branches')
            ->wherePivot('is_active', true);
    }

    public function hasAccessToBranch(int $branchId): bool
    {
        return $this->customerBranches()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->exists();
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }
}
```

`app/Models/CustomerBranch.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerBranch extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'branch_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
```

`app/Models/VehicleCategory.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleCategory extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function brands()
    {
        return $this->hasMany(VehicleBrand::class, 'category_id');
    }
}
```

`app/Models/VehicleBrand.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleBrand extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['category_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function category()
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id');
    }

    public function types()
    {
        return $this->hasMany(VehicleType::class, 'brand_id');
    }
}
```

`app/Models/VehicleType.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleType extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['brand_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function brand()
    {
        return $this->belongsTo(VehicleBrand::class, 'brand_id');
    }
}
```

`app/Models/Vehicle.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'customer_id', 'plate_number', 'frame_number', 'engine_number',
        'category_id', 'brand_id', 'type_id', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function category()
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id');
    }

    public function brand()
    {
        return $this->belongsTo(VehicleBrand::class, 'brand_id');
    }

    public function type()
    {
        return $this->belongsTo(VehicleType::class, 'type_id');
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=CustomerVehicleModelTest`
Expected: PASS, 7/7.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_02_000004_create_customers_table.php \
        database/migrations/2026_08_02_000005_create_customer_branches_table.php \
        database/migrations/2026_08_02_000006_create_vehicle_categories_table.php \
        database/migrations/2026_08_02_000007_create_vehicle_brands_table.php \
        database/migrations/2026_08_02_000008_create_vehicle_types_table.php \
        database/migrations/2026_08_02_000009_create_vehicles_table.php \
        app/Models/Customer.php app/Models/CustomerBranch.php \
        app/Models/VehicleCategory.php app/Models/VehicleBrand.php \
        app/Models/VehicleType.php app/Models/Vehicle.php \
        tests/Feature/CustomerVehicleModelTest.php
git commit -m "feat: add customer, vehicle, and vehicle reference hierarchy tables"
```

---

### Task 2: Vehicle reference permission seed + starter category seeder

**Files:**
- Modify: `database/seeders/MenuPermissionSeeder.php`
- Create: `database/seeders/VehicleCategorySeeder.php`
- Modify: `tests/Feature/MenuPermissionSeederTest.php` (add a method, don't touch existing ones)
- Create: `tests/Feature/VehicleCategorySeederTest.php`

**Interfaces:**
- Consumes: `Menu`/`Permission` models (Task 002 foundation, unchanged), `VehicleCategory::firstOrCreate()` (Task 1).
- Produces: permission codes `vehicle_reference.view`, `vehicle_reference.manage`; seeder class `Database\Seeders\VehicleCategorySeeder`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/MenuPermissionSeederTest.php` (new method, inside the existing class, after `test_seeder_marks_operational_menus_as_branch_scoped_and_others_as_global`):

```php
    public function test_seeder_creates_vehicle_reference_menu_and_permissions(): void
    {
        $this->seed(MenuPermissionSeeder::class);

        $this->assertDatabaseHas('menus', ['code' => 'master.vehicle_reference', 'is_branch_scoped' => false]);
        $this->assertDatabaseHas('permissions', ['code' => 'vehicle_reference.view']);
        $this->assertDatabaseHas('permissions', ['code' => 'vehicle_reference.manage']);
    }
```

Create `tests/Feature/VehicleCategorySeederTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\VehicleCategory;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_mobil_and_motor(): void
    {
        $this->seed(VehicleCategorySeeder::class);

        $this->assertDatabaseHas('vehicle_categories', ['name' => 'Mobil']);
        $this->assertDatabaseHas('vehicle_categories', ['name' => 'Motor']);
    }

    public function test_running_it_twice_does_not_duplicate(): void
    {
        $this->seed(VehicleCategorySeeder::class);
        $this->seed(VehicleCategorySeeder::class);

        $this->assertSame(2, VehicleCategory::count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=MenuPermissionSeederTest`
Expected: the new method FAILs (menu/permission not found). `php artisan test --filter=VehicleCategorySeederTest` FAILs (class not found).

- [ ] **Step 3: Add the `master.vehicle_reference` definition**

In `database/seeders/MenuPermissionSeeder.php`, inside `definitions()`, insert this array element immediately after the `master.vehicle` block (i.e. between `master.vehicle` and `master.mechanic`):

```php
            [
                'code' => 'master.vehicle_reference',
                'name' => 'Referensi Kendaraan',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'vehicle_reference.view', 'resource' => 'vehicle_reference', 'action' => 'view', 'description' => 'Melihat referensi kendaraan'],
                    ['code' => 'vehicle_reference.manage', 'resource' => 'vehicle_reference', 'action' => 'manage', 'description' => 'Mengelola referensi kendaraan'],
                ],
            ],
```

- [ ] **Step 4: Write `VehicleCategorySeeder`**

Create `database/seeders/VehicleCategorySeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\VehicleCategory;
use Illuminate\Database\Seeder;

class VehicleCategorySeeder extends Seeder
{
    public function run()
    {
        foreach (['Mobil', 'Motor'] as $name) {
            VehicleCategory::firstOrCreate(['name' => $name]);
        }
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=MenuPermissionSeederTest`
Expected: PASS, 4/4.

Run: `php artisan test --filter=VehicleCategorySeederTest`
Expected: PASS, 2/2.

- [ ] **Step 6: Commit**

```bash
git add database/seeders/MenuPermissionSeeder.php database/seeders/VehicleCategorySeeder.php \
        tests/Feature/MenuPermissionSeederTest.php tests/Feature/VehicleCategorySeederTest.php
git commit -m "feat: seed vehicle_reference permission and starter vehicle categories"
```

---

### Task 3: Customer CRUD screens

**Files:**
- Create: `app/Http/Requests/StoreCustomerRequest.php`
- Create: `app/Http/Requests/UpdateCustomerRequest.php`
- Create: `app/Http/Controllers/CustomerController.php`
- Create: `resources/views/customers/index.blade.php`
- Create: `resources/views/customers/create.blade.php`
- Create: `resources/views/customers/show.blade.php`
- Create: `resources/views/customers/_tab_profil.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CustomerManagementTest.php`

**Interfaces:**
- Consumes: `Customer` model (Task 1).
- Produces: routes `customers.index`, `customers.create`, `customers.store`, `customers.show`, `customers.update`. View `customers.show` renders tab includes `customers._tab_profil` (Task 3), `customers._tab_cabang` (Task 4, not yet created — reference it now, Task 4 supplies the file), `customers._tab_kendaraan` (Task 6, same).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/CustomerManagementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
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

    public function test_index_lists_customers_for_authorized_user(): void
    {
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get('/customers');

        $response->assertOk();
        $response->assertSee('Budi Santoso');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/customers');

        $response->assertForbidden();
    }

    public function test_store_creates_customer(): void
    {
        $user = $this->userWithPermissions(['customer.create']);

        $response = $this->actingAs($user)->post('/customers', [
            'customer_type' => 'INDIVIDUAL',
            'name' => 'Budi Santoso',
            'stnk_name' => 'Budi Santoso',
            'phone' => '081234567890',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/customers');
        $this->assertDatabaseHas('customers', ['name' => 'Budi Santoso']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithPermissions(['customer.create']);

        $response = $this->actingAs($user)->post('/customers', []);

        $response->assertSessionHasErrors(['customer_type', 'name', 'stnk_name']);
    }

    public function test_store_rejects_invalid_customer_type(): void
    {
        $user = $this->userWithPermissions(['customer.create']);

        $response = $this->actingAs($user)->post('/customers', [
            'customer_type' => 'GOVERNMENT',
            'name' => 'Budi Santoso',
            'stnk_name' => 'Budi Santoso',
        ]);

        $response->assertSessionHasErrors(['customer_type']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/customers', [
            'customer_type' => 'INDIVIDUAL',
            'name' => 'Budi Santoso',
            'stnk_name' => 'Budi Santoso',
        ]);

        $response->assertForbidden();
    }

    public function test_show_renders_profil_tab_for_authorized_user(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get("/customers/{$customer->id}");

        $response->assertOk();
        $response->assertSee('Budi Santoso');
    }

    public function test_update_edits_customer_and_can_deactivate(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['customer.edit']);

        $response = $this->actingAs($user)->put("/customers/{$customer->id}", [
            'customer_type' => 'INDIVIDUAL',
            'name' => 'Budi Santoso Edited',
            'stnk_name' => 'Budi Santoso',
            'is_active' => '0',
        ]);

        $response->assertRedirect("/customers/{$customer->id}");
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Budi Santoso Edited',
            'is_active' => false,
        ]);
    }

    public function test_update_is_forbidden_without_permission(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put("/customers/{$customer->id}", [
            'customer_type' => 'INDIVIDUAL',
            'name' => 'Budi Santoso Edited',
            'stnk_name' => 'Budi Santoso',
        ]);

        $response->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CustomerManagementTest`
Expected: FAIL — route/controller/views don't exist yet.

- [ ] **Step 3: Write the Form Requests**

`app/Http/Requests/StoreCustomerRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('customer.create');
    }

    public function rules()
    {
        return [
            'customer_type' => ['required', Rule::in(['COMPANY', 'INDIVIDUAL'])],
            'name' => ['required', 'string', 'max:150'],
            'stnk_name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
```

`app/Http/Requests/UpdateCustomerRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('customer.edit');
    }

    public function rules()
    {
        return [
            'customer_type' => ['required', Rule::in(['COMPANY', 'INDIVIDUAL'])],
            'name' => ['required', 'string', 'max:150'],
            'stnk_name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/CustomerController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Branch;
use App\Models\Customer;

class CustomerController extends Controller
{
    public function index()
    {
        $this->authorize('customer.view');

        $customers = Customer::orderBy('name')->simplePaginate(15);

        return view('customers.index', compact('customers'));
    }

    public function create()
    {
        $this->authorize('customer.create');

        $customer = new Customer();

        return view('customers.create', compact('customer'));
    }

    public function store(StoreCustomerRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        Customer::create($data);

        return redirect()->route('customers.index')->with('status', 'Customer berhasil ditambahkan.');
    }

    public function show(Customer $customer)
    {
        $this->authorize('customer.view');

        $customer->load(['customerBranches', 'vehicles.category', 'vehicles.brand', 'vehicles.type']);
        $allBranches = Branch::orderBy('name')->get();

        return view('customers.show', compact('customer', 'allBranches'));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $customer->update($data);

        return redirect()->route('customers.show', $customer)->with('status', 'Customer berhasil diperbarui.');
    }
}
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, add the `use` import near the other controller imports:

```php
use App\Http\Controllers\CustomerController;
```

Add this route group inside the `Route::middleware(['auth'])->group(...)` block, after the `branches` group and before the `users` group:

```php
    Route::prefix('customers')->name('customers.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::get('/create', [CustomerController::class, 'create'])->name('create');
        Route::post('/', [CustomerController::class, 'store'])->name('store');
        Route::get('/{customer}', [CustomerController::class, 'show'])->name('show');
        Route::put('/{customer}', [CustomerController::class, 'update'])->name('update');
    });
```

- [ ] **Step 6: Write the views**

`resources/views/customers/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Customer')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-badge me-2"></i>Customer</h1>
        @can('customer.create')
            <a href="{{ route('customers.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Tambah Customer
            </a>
        @endcan
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nama</th>
                        <th>Tipe</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr>
                            <td>{{ $customer->name }}</td>
                            <td>{{ $customer->customer_type === 'COMPANY' ? 'Perusahaan' : 'Perorangan' }}</td>
                            <td>{{ $customer->phone ?? '-' }}</td>
                            <td>
                                @if ($customer->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('customers.show', $customer) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-gear"></i> Kelola
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Belum ada customer.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $customers->links() }}
    </div>
@endsection
```

`resources/views/customers/create.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Tambah Customer')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-badge me-2"></i>Tambah Customer</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('customers.store') }}">
                @csrf

                <div class="mb-3">
                    <label for="customer_type" class="form-label">Tipe Customer</label>
                    <select name="customer_type" id="customer_type" class="form-select @error('customer_type') is-invalid @enderror" required>
                        <option value="">-- Pilih --</option>
                        <option value="INDIVIDUAL" {{ old('customer_type') === 'INDIVIDUAL' ? 'selected' : '' }}>Perorangan</option>
                        <option value="COMPANY" {{ old('customer_type') === 'COMPANY' ? 'selected' : '' }}>Perusahaan</option>
                    </select>
                    @error('customer_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="name" class="form-label">Nama Customer</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="stnk_name" class="form-label">Nama Sesuai STNK</label>
                    <input type="text" name="stnk_name" id="stnk_name" value="{{ old('stnk_name') }}" class="form-control @error('stnk_name') is-invalid @enderror" maxlength="150" required>
                    @error('stnk_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="address" class="form-label">Alamat</label>
                    <textarea name="address" id="address" class="form-control @error('address') is-invalid @enderror" rows="2">{{ old('address') }}</textarea>
                    @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
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

                <div class="form-check form-switch mb-4">
                    <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" checked>
                    <label for="is_active" class="form-check-label">Aktif</label>
                </div>

                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
                <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">Batal</a>
            </form>
        </div>
    </div>
@endsection
```

`resources/views/customers/_tab_profil.blade.php`:

```blade
<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ route('customers.update', $customer) }}">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="customer_type" class="form-label">Tipe Customer</label>
                <select name="customer_type" id="customer_type" class="form-select @error('customer_type') is-invalid @enderror" required>
                    <option value="INDIVIDUAL" {{ old('customer_type', $customer->customer_type) === 'INDIVIDUAL' ? 'selected' : '' }}>Perorangan</option>
                    <option value="COMPANY" {{ old('customer_type', $customer->customer_type) === 'COMPANY' ? 'selected' : '' }}>Perusahaan</option>
                </select>
                @error('customer_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="name" class="form-label">Nama Customer</label>
                <input type="text" name="name" id="name" value="{{ old('name', $customer->name) }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="stnk_name" class="form-label">Nama Sesuai STNK</label>
                <input type="text" name="stnk_name" id="stnk_name" value="{{ old('stnk_name', $customer->stnk_name) }}" class="form-control @error('stnk_name') is-invalid @enderror" maxlength="150" required>
                @error('stnk_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="address" class="form-label">Alamat</label>
                <textarea name="address" id="address" class="form-control @error('address') is-invalid @enderror" rows="2">{{ old('address', $customer->address) }}</textarea>
                @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="phone" class="form-label">Telepon</label>
                    <input type="text" name="phone" id="phone" value="{{ old('phone', $customer->phone) }}" class="form-control @error('phone') is-invalid @enderror" maxlength="50">
                    @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6 mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" name="email" id="email" value="{{ old('email', $customer->email) }}" class="form-control @error('email') is-invalid @enderror" maxlength="255">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-check form-switch mb-4">
                <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $customer->is_active) ? 'checked' : '' }}>
                <label for="is_active" class="form-check-label">Aktif</label>
            </div>

            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </form>
    </div>
</div>
```

`resources/views/customers/show.blade.php` — **this references `customers._tab_cabang` (Task 4) and `customers._tab_kendaraan` (Task 6), which don't exist yet.** That's expected: the test in this task only exercises the Profil tab content, so create the tabs unconditionally now with all three `@include`s; Tasks 4 and 6 will create the missing partials before this task's full page ever needs to render them in a browser. Do NOT skip creating the includes now — do not use `@can`-wrapped conditional includes as a workaround, just write the three tabs as described:

```blade
@extends('layouts.app')
@section('title', 'Detail Customer')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h4 mb-1"><i class="bi bi-person-badge me-2"></i>{{ $customer->name }}</h1>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted small">{{ $customer->customer_type === 'COMPANY' ? 'Perusahaan' : 'Perorangan' }}</span>
                @if ($customer->is_active)
                    <span class="status-dot status-active">Aktif</span>
                @else
                    <span class="status-dot status-inactive">Nonaktif</span>
                @endif
            </div>
        </div>
        <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary btn-sm">
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
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#kendaraan-pane" type="button" role="tab">
                <i class="bi bi-car-front me-1"></i> Kendaraan
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="profil-pane" role="tabpanel">
            @include('customers._tab_profil')
        </div>
        <div class="tab-pane fade" id="cabang-pane" role="tabpanel">
            @include('customers._tab_cabang')
        </div>
        <div class="tab-pane fade" id="kendaraan-pane" role="tabpanel">
            @include('customers._tab_kendaraan')
        </div>
    </div>
@endsection
```

Since `_tab_cabang.blade.php` and `_tab_kendaraan.blade.php` don't exist until Tasks 4/6, **temporarily** stub them here so this task's test suite passes on its own — create two placeholder files that Task 4/6 will overwrite with real content:

`resources/views/customers/_tab_cabang.blade.php` (placeholder, replaced in Task 4):
```blade
<div class="card shadow-sm"><div class="card-body text-muted">Memuat...</div></div>
```

`resources/views/customers/_tab_kendaraan.blade.php` (placeholder, replaced in Task 6):
```blade
<div class="card shadow-sm"><div class="card-body text-muted">Memuat...</div></div>
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=CustomerManagementTest`
Expected: PASS, 9/9.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/StoreCustomerRequest.php app/Http/Requests/UpdateCustomerRequest.php \
        app/Http/Controllers/CustomerController.php routes/web.php \
        resources/views/customers/ tests/Feature/CustomerManagementTest.php
git commit -m "feat: add customer list/create/detail screens"
```

---

### Task 4: Customer → Branch assignment (Cabang tab)

**Files:**
- Create: `app/Http/Controllers/CustomerBranchAssignmentController.php`
- Modify: `resources/views/customers/_tab_cabang.blade.php` (replace Task 3's placeholder)
- Modify: `routes/web.php`
- Test: `tests/Feature/CustomerBranchTabTest.php`

**Interfaces:**
- Consumes: `Customer`/`CustomerBranch`/`Branch` models (Task 1), `$customer->customerBranches` relation loaded by `CustomerController::show()` (Task 3).
- Produces: routes `customers.branches.store` (`POST /customers/{customer}/branches/{branch}`), `customers.branches.destroy` (`DELETE /customers/{customer}/branches/{branch}`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/CustomerBranchTabTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBranchTabTest extends TestCase
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
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $admin = $this->userWithPermissions(['customer.edit']);

        $response = $this->actingAs($admin)->postJson("/customers/{$customer->id}/branches/{$branch->id}");

        $response->assertOk();
        $this->assertDatabaseHas('customer_branches', [
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    public function test_unassigning_a_branch_deactivates_the_link(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $admin = $this->userWithPermissions(['customer.edit']);

        $response = $this->actingAs($admin)->deleteJson("/customers/{$customer->id}/branches/{$branch->id}");

        $response->assertOk();
        $this->assertDatabaseHas('customer_branches', [
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'is_active' => false,
        ]);
    }

    public function test_branch_endpoints_are_forbidden_without_permission(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/customers/{$customer->id}/branches/{$branch->id}")->assertForbidden();
    }

    public function test_show_page_renders_cabang_tab(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $admin = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($admin)->get("/customers/{$customer->id}");

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CustomerBranchTabTest`
Expected: FAIL — controller/routes don't exist, placeholder tab doesn't list branches.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/CustomerBranchAssignmentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;

class CustomerBranchAssignmentController extends Controller
{
    public function store(Customer $customer, Branch $branch)
    {
        $this->authorize('customer.edit');

        CustomerBranch::updateOrCreate(
            ['customer_id' => $customer->id, 'branch_id' => $branch->id],
            ['is_active' => true]
        );

        return response()->json(['message' => 'Cabang berhasil ditambahkan.']);
    }

    public function destroy(Customer $customer, Branch $branch)
    {
        $this->authorize('customer.edit');

        CustomerBranch::where('customer_id', $customer->id)
            ->where('branch_id', $branch->id)
            ->update(['is_active' => false]);

        return response()->json(['message' => 'Cabang berhasil dihapus dari customer.']);
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\CustomerBranchAssignmentController;
```

Inside the `customers` route group (created in Task 3), after the `update` route, add:

```php
        Route::prefix('{customer}/branches')->name('branches.')->group(function () {
            Route::post('/{branch}', [CustomerBranchAssignmentController::class, 'store'])->name('store');
            Route::delete('/{branch}', [CustomerBranchAssignmentController::class, 'destroy'])->name('destroy');
        });
```

- [ ] **Step 5: Replace the placeholder tab view**

Overwrite `resources/views/customers/_tab_cabang.blade.php`:

```blade
<div class="card shadow-sm">
    <div class="card-body">
        <p class="text-muted small">Centang cabang yang dapat melayani customer ini.</p>

        <div id="customer-branch-list">
            @foreach ($allBranches as $branch)
                @php($customerBranch = $customer->customerBranches->firstWhere('branch_id', $branch->id))
                <div class="d-flex align-items-center justify-content-between border-bottom py-2" data-branch-row="{{ $branch->id }}">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input customer-branch-toggle" id="branch-{{ $branch->id }}"
                            data-branch-id="{{ $branch->id }}"
                            {{ $customerBranch && $customerBranch->is_active ? 'checked' : '' }}>
                        <label class="form-check-label" for="branch-{{ $branch->id }}">{{ $branch->name }}</label>
                    </div>
                </div>
            @endforeach
        </div>

        <div id="customer-branch-feedback" class="small mt-3"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const customerId = {{ $customer->id }};
    const feedback = document.getElementById('customer-branch-feedback');

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

    document.querySelectorAll('.customer-branch-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', async function () {
            const branchId = this.dataset.branchId;
            try {
                if (this.checked) {
                    const data = await send(`/customers/${customerId}/branches/${branchId}`, 'POST');
                    showFeedback(data.message, false);
                } else {
                    const data = await send(`/customers/${customerId}/branches/${branchId}`, 'DELETE');
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

Run: `php artisan test --filter=CustomerBranchTabTest`
Expected: PASS, 4/4. Also re-run `php artisan test --filter=CustomerManagementTest` to confirm Task 3's tests still pass (the placeholder it depended on is now real content).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/CustomerBranchAssignmentController.php routes/web.php \
        resources/views/customers/_tab_cabang.blade.php tests/Feature/CustomerBranchTabTest.php
git commit -m "feat: add customer branch assignment tab"
```

---

### Task 5: Vehicle CRUD screens with cascading category/brand/type

**Files:**
- Create: `app/Http/Requests/StoreVehicleRequest.php`
- Create: `app/Http/Requests/UpdateVehicleRequest.php`
- Create: `app/Http/Controllers/VehicleController.php`
- Create: `app/Http/Controllers/VehicleReferenceLookupController.php`
- Create: `resources/views/vehicles/index.blade.php`
- Create: `resources/views/vehicles/create.blade.php`
- Create: `resources/views/vehicles/edit.blade.php`
- Create: `resources/views/vehicles/_form.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/VehicleManagementTest.php`

**Interfaces:**
- Consumes: `Vehicle`, `VehicleCategory`, `VehicleBrand`, `VehicleType`, `Customer` models (Task 1).
- Produces: routes `vehicles.index`, `vehicles.create`, `vehicles.store`, `vehicles.edit`, `vehicles.update`, `vehicles.lookup.brands` (`GET /vehicles/lookup/brands/{category}` → JSON `[{id,name}]`), `vehicles.lookup.types` (`GET /vehicles/lookup/types/{brand}` → JSON `[{id,name}]`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/VehicleManagementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleManagementTest extends TestCase
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

    protected function makeHierarchy(): array
    {
        $category = VehicleCategory::create(['name' => 'Motor']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);

        return compact('category', 'brand', 'type');
    }

    public function test_index_lists_vehicles_for_authorized_user(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->get('/vehicles');

        $response->assertOk();
        $response->assertSee('B 1234 XYZ');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/vehicles');

        $response->assertForbidden();
    }

    public function test_store_creates_vehicle(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        $user = $this->userWithPermissions(['vehicle.create']);

        $response = $this->actingAs($user)->post('/vehicles', [
            'customer_id' => $customer->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'type_id' => $type->id,
            'plate_number' => 'B 1234 XYZ',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/vehicles');
        $this->assertDatabaseHas('vehicles', ['plate_number' => 'B 1234 XYZ', 'customer_id' => $customer->id]);
    }

    public function test_store_rejects_brand_that_does_not_belong_to_selected_category(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        $otherCategory = VehicleCategory::create(['name' => 'Mobil']);
        $user = $this->userWithPermissions(['vehicle.create']);

        $response = $this->actingAs($user)->post('/vehicles', [
            'customer_id' => $customer->id,
            'category_id' => $otherCategory->id,
            'brand_id' => $brand->id,
            'type_id' => $type->id,
        ]);

        $response->assertSessionHasErrors(['brand_id']);
    }

    public function test_store_rejects_type_that_does_not_belong_to_selected_brand(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand] = $this->makeHierarchy();
        $otherBrand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Yamaha']);
        $otherType = VehicleType::create(['brand_id' => $otherBrand->id, 'name' => 'Mio']);
        $user = $this->userWithPermissions(['vehicle.create']);

        $response = $this->actingAs($user)->post('/vehicles', [
            'customer_id' => $customer->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'type_id' => $otherType->id,
        ]);

        $response->assertSessionHasErrors(['type_id']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/vehicles', [
            'customer_id' => $customer->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'type_id' => $type->id,
        ]);

        $response->assertForbidden();
    }

    public function test_update_edits_vehicle_and_can_deactivate(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        ['category' => $category, 'brand' => $brand, 'type' => $type] = $this->makeHierarchy();
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $user = $this->userWithPermissions(['vehicle.edit']);

        $response = $this->actingAs($user)->put("/vehicles/{$vehicle->id}", [
            'customer_id' => $customer->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'type_id' => $type->id,
            'plate_number' => 'B 1234 XYZ',
            'is_active' => '0',
        ]);

        $response->assertRedirect('/vehicles');
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'is_active' => false]);
    }

    public function test_lookup_returns_brands_scoped_to_category(): void
    {
        ['category' => $category] = $this->makeHierarchy();
        $otherCategory = VehicleCategory::create(['name' => 'Mobil']);
        VehicleBrand::create(['category_id' => $otherCategory->id, 'name' => 'Toyota']);
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->getJson("/vehicles/lookup/brands/{$category->id}");

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Honda']);
        $response->assertJsonMissing(['name' => 'Toyota']);
    }

    public function test_lookup_returns_types_scoped_to_brand(): void
    {
        ['brand' => $brand] = $this->makeHierarchy();
        $otherBrand = VehicleBrand::create(['category_id' => $brand->category_id, 'name' => 'Yamaha']);
        VehicleType::create(['brand_id' => $otherBrand->id, 'name' => 'Mio']);
        $user = $this->userWithPermissions(['vehicle.view']);

        $response = $this->actingAs($user)->getJson("/vehicles/lookup/types/{$brand->id}");

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Beat']);
        $response->assertJsonMissing(['name' => 'Mio']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=VehicleManagementTest`
Expected: FAIL — routes/controllers/views don't exist.

- [ ] **Step 3: Write the Form Requests**

`app/Http/Requests/StoreVehicleRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\VehicleBrand;
use App\Models\VehicleType;
use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('vehicle.create');
    }

    public function rules()
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'plate_number' => ['nullable', 'string', 'max:30', 'unique:vehicles,plate_number'],
            'frame_number' => ['nullable', 'string', 'max:100', 'unique:vehicles,frame_number'],
            'engine_number' => ['nullable', 'string', 'max:100', 'unique:vehicles,engine_number'],
            'category_id' => ['required', 'exists:vehicle_categories,id'],
            'brand_id' => ['required', 'exists:vehicle_brands,id'],
            'type_id' => ['required', 'exists:vehicle_types,id'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $brandId = $this->input('brand_id');
            $categoryId = $this->input('category_id');
            $typeId = $this->input('type_id');

            if ($brandId && $categoryId) {
                $brand = VehicleBrand::find($brandId);
                if ($brand && (int) $brand->category_id !== (int) $categoryId) {
                    $validator->errors()->add('brand_id', 'Merk yang dipilih tidak sesuai dengan kategori.');
                }
            }

            if ($typeId && $brandId) {
                $type = VehicleType::find($typeId);
                if ($type && (int) $type->brand_id !== (int) $brandId) {
                    $validator->errors()->add('type_id', 'Tipe yang dipilih tidak sesuai dengan merk.');
                }
            }
        });
    }
}
```

`app/Http/Requests/UpdateVehicleRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\VehicleBrand;
use App\Models\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('vehicle.edit');
    }

    public function rules()
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'plate_number' => ['nullable', 'string', 'max:30', Rule::unique('vehicles', 'plate_number')->ignore($this->route('vehicle'))],
            'frame_number' => ['nullable', 'string', 'max:100', Rule::unique('vehicles', 'frame_number')->ignore($this->route('vehicle'))],
            'engine_number' => ['nullable', 'string', 'max:100', Rule::unique('vehicles', 'engine_number')->ignore($this->route('vehicle'))],
            'category_id' => ['required', 'exists:vehicle_categories,id'],
            'brand_id' => ['required', 'exists:vehicle_brands,id'],
            'type_id' => ['required', 'exists:vehicle_types,id'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $brandId = $this->input('brand_id');
            $categoryId = $this->input('category_id');
            $typeId = $this->input('type_id');

            if ($brandId && $categoryId) {
                $brand = VehicleBrand::find($brandId);
                if ($brand && (int) $brand->category_id !== (int) $categoryId) {
                    $validator->errors()->add('brand_id', 'Merk yang dipilih tidak sesuai dengan kategori.');
                }
            }

            if ($typeId && $brandId) {
                $type = VehicleType::find($typeId);
                if ($type && (int) $type->brand_id !== (int) $brandId) {
                    $validator->errors()->add('type_id', 'Tipe yang dipilih tidak sesuai dengan merk.');
                }
            }
        });
    }
}
```

- [ ] **Step 4: Write the controllers**

`app/Http/Controllers/VehicleController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;

class VehicleController extends Controller
{
    public function index()
    {
        $this->authorize('vehicle.view');

        $vehicles = Vehicle::with(['customer', 'category', 'brand', 'type'])
            ->when(request('customer_id'), fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when(request('q'), function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('plate_number', 'like', "%{$q}%")
                        ->orWhere('frame_number', 'like', "%{$q}%")
                        ->orWhere('engine_number', 'like', "%{$q}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->simplePaginate(15)
            ->withQueryString();

        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        return view('vehicles.index', compact('vehicles', 'customers'));
    }

    public function create()
    {
        $this->authorize('vehicle.create');

        $vehicle = new Vehicle();
        $customers = Customer::where('is_active', true)->orderBy('name')->get();
        $categories = VehicleCategory::where('is_active', true)->orderBy('name')->get();
        $brands = collect();
        $types = collect();
        $selectedCustomerId = request()->integer('customer_id') ?: null;

        return view('vehicles.create', compact('vehicle', 'customers', 'categories', 'brands', 'types', 'selectedCustomerId'));
    }

    public function store(StoreVehicleRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', true);

        Vehicle::create($data);

        return redirect()->route('vehicles.index')->with('status', 'Kendaraan berhasil ditambahkan.');
    }

    public function edit(Vehicle $vehicle)
    {
        $this->authorize('vehicle.edit');

        $customers = Customer::where('is_active', true)->orderBy('name')->get();
        $categories = VehicleCategory::where('is_active', true)->orderBy('name')->get();
        $brands = VehicleBrand::where('category_id', $vehicle->category_id)->where('is_active', true)->orderBy('name')->get();
        $types = VehicleType::where('brand_id', $vehicle->brand_id)->where('is_active', true)->orderBy('name')->get();
        $selectedCustomerId = null;

        return view('vehicles.edit', compact('vehicle', 'customers', 'categories', 'brands', 'types', 'selectedCustomerId'));
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $vehicle->update($data);

        return redirect()->route('vehicles.index')->with('status', 'Kendaraan berhasil diperbarui.');
    }
}
```

`app/Http/Controllers/VehicleReferenceLookupController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;

class VehicleReferenceLookupController extends Controller
{
    public function brandsByCategory(VehicleCategory $category)
    {
        $this->authorize('vehicle.view');

        return response()->json(
            VehicleBrand::where('category_id', $category->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
        );
    }

    public function typesByBrand(VehicleBrand $brand)
    {
        $this->authorize('vehicle.view');

        return response()->json(
            VehicleType::where('brand_id', $brand->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
        );
    }
}
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, add the imports:

```php
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleReferenceLookupController;
```

Add this route group after the `customers` group:

```php
    Route::prefix('vehicles')->name('vehicles.')->group(function () {
        Route::get('/', [VehicleController::class, 'index'])->name('index');
        Route::get('/create', [VehicleController::class, 'create'])->name('create');
        Route::post('/', [VehicleController::class, 'store'])->name('store');
        Route::get('/lookup/brands/{category}', [VehicleReferenceLookupController::class, 'brandsByCategory'])->name('lookup.brands');
        Route::get('/lookup/types/{brand}', [VehicleReferenceLookupController::class, 'typesByBrand'])->name('lookup.types');
        Route::get('/{vehicle}/edit', [VehicleController::class, 'edit'])->name('edit');
        Route::put('/{vehicle}', [VehicleController::class, 'update'])->name('update');
    });
```

- [ ] **Step 6: Write the views**

`resources/views/vehicles/_form.blade.php`:

```blade
@csrf
@isset($method)
    @method($method)
@endisset

<div class="mb-3">
    <label for="customer_id" class="form-label">Customer</label>
    <select name="customer_id" id="customer_id" class="form-select @error('customer_id') is-invalid @enderror" required>
        <option value="">-- Pilih Customer --</option>
        @foreach ($customers as $customer)
            <option value="{{ $customer->id }}" {{ (int) old('customer_id', $vehicle->customer_id ?? $selectedCustomerId) === $customer->id ? 'selected' : '' }}>
                {{ $customer->name }}
            </option>
        @endforeach
    </select>
    @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="row">
    <div class="col-md-4 mb-3">
        <label for="category_id" class="form-label">Kategori</label>
        <select name="category_id" id="category_id" class="form-select @error('category_id') is-invalid @enderror" required>
            <option value="">-- Pilih Kategori --</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" {{ (int) old('category_id', $vehicle->category_id) === $category->id ? 'selected' : '' }}>
                    {{ $category->name }}
                </option>
            @endforeach
        </select>
        @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label for="brand_id" class="form-label">Merk</label>
        <select name="brand_id" id="brand_id" class="form-select @error('brand_id') is-invalid @enderror" required>
            <option value="">-- Pilih Merk --</option>
            @foreach ($brands as $brand)
                <option value="{{ $brand->id }}" {{ (int) old('brand_id', $vehicle->brand_id) === $brand->id ? 'selected' : '' }}>
                    {{ $brand->name }}
                </option>
            @endforeach
        </select>
        @error('brand_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label for="type_id" class="form-label">Tipe</label>
        <select name="type_id" id="type_id" class="form-select @error('type_id') is-invalid @enderror" required>
            <option value="">-- Pilih Tipe --</option>
            @foreach ($types as $type)
                <option value="{{ $type->id }}" {{ (int) old('type_id', $vehicle->type_id) === $type->id ? 'selected' : '' }}>
                    {{ $type->name }}
                </option>
            @endforeach
        </select>
        @error('type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="row">
    <div class="col-md-4 mb-3">
        <label for="plate_number" class="form-label">No. Polisi</label>
        <input type="text" name="plate_number" id="plate_number" value="{{ old('plate_number', $vehicle->plate_number) }}" class="form-control @error('plate_number') is-invalid @enderror" maxlength="30">
        @error('plate_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label for="frame_number" class="form-label">No. Rangka</label>
        <input type="text" name="frame_number" id="frame_number" value="{{ old('frame_number', $vehicle->frame_number) }}" class="form-control @error('frame_number') is-invalid @enderror" maxlength="100">
        @error('frame_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label for="engine_number" class="form-label">No. Mesin</label>
        <input type="text" name="engine_number" id="engine_number" value="{{ old('engine_number', $vehicle->engine_number) }}" class="form-control @error('engine_number') is-invalid @enderror" maxlength="100">
        @error('engine_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="form-check form-switch mb-4">
    <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" {{ old('is_active', $vehicle->exists ? $vehicle->is_active : true) ? 'checked' : '' }}>
    <label for="is_active" class="form-check-label">Aktif</label>
</div>

<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
<a href="{{ route('vehicles.index') }}" class="btn btn-outline-secondary">Batal</a>

@push('scripts')
<script>
(function () {
    const categorySelect = document.getElementById('category_id');
    const brandSelect = document.getElementById('brand_id');
    const typeSelect = document.getElementById('type_id');

    async function fetchOptions(url) {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        return response.json();
    }

    function fillSelect(select, items, placeholder) {
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        items.forEach(function (item) {
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.name;
            select.appendChild(option);
        });
    }

    categorySelect.addEventListener('change', async function () {
        fillSelect(typeSelect, [], '-- Pilih Tipe --');
        if (!this.value) {
            fillSelect(brandSelect, [], '-- Pilih Merk --');
            return;
        }
        const brands = await fetchOptions(`/vehicles/lookup/brands/${this.value}`);
        fillSelect(brandSelect, brands, '-- Pilih Merk --');
    });

    brandSelect.addEventListener('change', async function () {
        if (!this.value) {
            fillSelect(typeSelect, [], '-- Pilih Tipe --');
            return;
        }
        const types = await fetchOptions(`/vehicles/lookup/types/${this.value}`);
        fillSelect(typeSelect, types, '-- Pilih Tipe --');
    });
})();
</script>
@endpush
```

`resources/views/vehicles/create.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Tambah Kendaraan')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-car-front me-2"></i>Tambah Kendaraan</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('vehicles.store') }}">
                @include('vehicles._form')
            </form>
        </div>
    </div>
@endsection
```

`resources/views/vehicles/edit.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Ubah Kendaraan')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-car-front me-2"></i>Ubah Kendaraan</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('vehicles.update', $vehicle) }}">
                @php($method = 'PUT')
                @include('vehicles._form')
            </form>
        </div>
    </div>
@endsection
```

`resources/views/vehicles/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Kendaraan')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-car-front me-2"></i>Kendaraan</h1>
        @can('vehicle.create')
            <a href="{{ route('vehicles.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Tambah Kendaraan
            </a>
        @endcan
    </div>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-4">
            <select name="customer_id" class="form-select form-select-sm">
                <option value="">-- Semua Customer --</option>
                @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}" {{ (int) request('customer_id') === $customer->id ? 'selected' : '' }}>{{ $customer->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <input type="text" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Cari no. polisi/rangka/mesin">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Cari</button>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Polisi</th>
                        <th>Customer</th>
                        <th>Kategori / Merk / Tipe</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vehicles as $vehicle)
                        <tr>
                            <td><code>{{ $vehicle->plate_number ?? '-' }}</code></td>
                            <td>{{ $vehicle->customer->name }}</td>
                            <td>{{ $vehicle->category->name }} / {{ $vehicle->brand->name }} / {{ $vehicle->type->name }}</td>
                            <td>
                                @if ($vehicle->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('vehicle.edit')
                                    <a href="{{ route('vehicles.edit', $vehicle) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Belum ada kendaraan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $vehicles->links() }}
    </div>
@endsection
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=VehicleManagementTest`
Expected: PASS, 9/9.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/StoreVehicleRequest.php app/Http/Requests/UpdateVehicleRequest.php \
        app/Http/Controllers/VehicleController.php app/Http/Controllers/VehicleReferenceLookupController.php \
        routes/web.php resources/views/vehicles/ tests/Feature/VehicleManagementTest.php
git commit -m "feat: add vehicle list/create/edit screens with cascading category/brand/type"
```

---

### Task 6: Kendaraan tab on Customer detail page

**Files:**
- Modify: `resources/views/customers/_tab_kendaraan.blade.php` (replace Task 3's placeholder)
- Test: `tests/Feature/CustomerVehicleTabTest.php`

**Interfaces:**
- Consumes: `$customer->vehicles` (eager-loaded with `category`/`brand`/`type` by `CustomerController::show()`, Task 3), `vehicles.create`/`vehicles.edit` routes (Task 5).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/CustomerVehicleTabTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerVehicleTabTest extends TestCase
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

    public function test_show_page_renders_kendaraan_tab_with_customers_vehicles(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $category = VehicleCategory::create(['name' => 'Motor']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);
        Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get("/customers/{$customer->id}");

        $response->assertOk();
        $response->assertSee('B 1234 XYZ');
    }

    public function test_tambah_kendaraan_link_shown_when_authorized(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $user = $this->userWithPermissions(['customer.view', 'vehicle.create']);

        $response = $this->actingAs($user)->get("/customers/{$customer->id}");

        $response->assertOk();
        $response->assertSee(route('vehicles.create', ['customer_id' => $customer->id]), false);
    }

    public function test_tambah_kendaraan_link_hidden_without_vehicle_create_permission(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get("/customers/{$customer->id}");

        $response->assertOk();
        $response->assertDontSee(route('vehicles.create', ['customer_id' => $customer->id]), false);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CustomerVehicleTabTest`
Expected: FAIL — placeholder tab shows nothing.

- [ ] **Step 3: Replace the placeholder tab view**

Overwrite `resources/views/customers/_tab_kendaraan.blade.php`:

```blade
<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="text-muted small mb-0">Kendaraan milik customer ini.</p>
            @can('vehicle.create')
                <a href="{{ route('vehicles.create', ['customer_id' => $customer->id]) }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> Tambah Kendaraan
                </a>
            @endcan
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Polisi</th>
                        <th>Kategori / Merk / Tipe</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customer->vehicles as $vehicle)
                        <tr>
                            <td><code>{{ $vehicle->plate_number ?? '-' }}</code></td>
                            <td>{{ $vehicle->category->name }} / {{ $vehicle->brand->name }} / {{ $vehicle->type->name }}</td>
                            <td>
                                @if ($vehicle->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('vehicle.edit')
                                    <a href="{{ route('vehicles.edit', $vehicle) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">Belum ada kendaraan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=CustomerVehicleTabTest`
Expected: PASS, 3/3.

- [ ] **Step 5: Commit**

```bash
git add resources/views/customers/_tab_kendaraan.blade.php tests/Feature/CustomerVehicleTabTest.php
git commit -m "feat: add kendaraan tab to customer detail page"
```

---

### Task 7: Referensi Kendaraan screen (3-column drill-down)

**Files:**
- Create: `app/Http/Controllers/VehicleReferenceController.php`
- Create: `resources/views/vehicle-references/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/VehicleReferenceControllerTest.php`

**Interfaces:**
- Consumes: `VehicleCategory`, `VehicleBrand`, `VehicleType` models (Task 1), `vehicle_reference.view`/`vehicle_reference.manage` permissions (Task 2).
- Produces: routes `vehicle-references.index`, `.categories.store`, `.categories.update`, `.brands.store`, `.brands.update`, `.types.store`, `.types.update`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/VehicleReferenceControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleReferenceControllerTest extends TestCase
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

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/vehicle-references')->assertForbidden();
    }

    public function test_index_renders_categories_for_authorized_user(): void
    {
        VehicleCategory::create(['name' => 'Motor']);
        $user = $this->userWithPermissions(['vehicle_reference.view']);

        $response = $this->actingAs($user)->get('/vehicle-references');

        $response->assertOk();
        $response->assertSee('Motor');
    }

    public function test_store_category_creates_it(): void
    {
        $user = $this->userWithPermissions(['vehicle_reference.manage']);

        $response = $this->actingAs($user)->postJson('/vehicle-references/categories', ['name' => 'Motor']);

        $response->assertOk();
        $this->assertDatabaseHas('vehicle_categories', ['name' => 'Motor']);
    }

    public function test_store_category_is_forbidden_without_manage_permission(): void
    {
        $user = $this->userWithPermissions(['vehicle_reference.view']);

        $this->actingAs($user)->postJson('/vehicle-references/categories', ['name' => 'Motor'])->assertForbidden();
    }

    public function test_update_category_can_deactivate(): void
    {
        $category = VehicleCategory::create(['name' => 'Motor']);
        $user = $this->userWithPermissions(['vehicle_reference.manage']);

        $response = $this->actingAs($user)->putJson("/vehicle-references/categories/{$category->id}", [
            'name' => 'Motor',
            'is_active' => false,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('vehicle_categories', ['id' => $category->id, 'is_active' => false]);
    }

    public function test_store_brand_rejects_duplicate_name_within_same_category(): void
    {
        $category = VehicleCategory::create(['name' => 'Motor']);
        VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $user = $this->userWithPermissions(['vehicle_reference.manage']);

        $response = $this->actingAs($user)->postJson('/vehicle-references/brands', [
            'category_id' => $category->id,
            'name' => 'Honda',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_type_creates_it_under_brand(): void
    {
        $category = VehicleCategory::create(['name' => 'Motor']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $user = $this->userWithPermissions(['vehicle_reference.manage']);

        $response = $this->actingAs($user)->postJson('/vehicle-references/types', [
            'brand_id' => $brand->id,
            'name' => 'Beat',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('vehicle_types', ['brand_id' => $brand->id, 'name' => 'Beat']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=VehicleReferenceControllerTest`
Expected: FAIL — route/controller/view don't exist.

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/VehicleReferenceController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use Illuminate\Http\Request;

class VehicleReferenceController extends Controller
{
    public function index()
    {
        $this->authorize('vehicle_reference.view');

        $categories = VehicleCategory::with('brands.types')->orderBy('name')->get();

        return view('vehicle-references.index', compact('categories'));
    }

    public function storeCategory(Request $request)
    {
        $this->authorize('vehicle_reference.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:vehicle_categories,name'],
        ]);

        $category = VehicleCategory::create($data);

        return response()->json(['message' => 'Kategori berhasil ditambahkan.', 'category' => $category]);
    }

    public function updateCategory(Request $request, VehicleCategory $category)
    {
        $this->authorize('vehicle_reference.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:vehicle_categories,name,' . $category->id],
            'is_active' => ['required', 'boolean'],
        ]);

        $category->update($data);

        return response()->json(['message' => 'Kategori berhasil diperbarui.', 'category' => $category]);
    }

    public function storeBrand(Request $request)
    {
        $this->authorize('vehicle_reference.manage');

        $data = $request->validate([
            'category_id' => ['required', 'exists:vehicle_categories,id'],
            'name' => ['required', 'string', 'max:150'],
        ]);

        $exists = VehicleBrand::where('category_id', $data['category_id'])->where('name', $data['name'])->exists();
        if ($exists) {
            return response()->json(['message' => 'Merk dengan nama ini sudah ada pada kategori tersebut.'], 422);
        }

        $brand = VehicleBrand::create($data);

        return response()->json(['message' => 'Merk berhasil ditambahkan.', 'brand' => $brand]);
    }

    public function updateBrand(Request $request, VehicleBrand $brand)
    {
        $this->authorize('vehicle_reference.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
        ]);

        $exists = VehicleBrand::where('category_id', $brand->category_id)
            ->where('name', $data['name'])
            ->where('id', '!=', $brand->id)
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Merk dengan nama ini sudah ada pada kategori tersebut.'], 422);
        }

        $brand->update($data);

        return response()->json(['message' => 'Merk berhasil diperbarui.', 'brand' => $brand]);
    }

    public function storeType(Request $request)
    {
        $this->authorize('vehicle_reference.manage');

        $data = $request->validate([
            'brand_id' => ['required', 'exists:vehicle_brands,id'],
            'name' => ['required', 'string', 'max:150'],
        ]);

        $exists = VehicleType::where('brand_id', $data['brand_id'])->where('name', $data['name'])->exists();
        if ($exists) {
            return response()->json(['message' => 'Tipe dengan nama ini sudah ada pada merk tersebut.'], 422);
        }

        $type = VehicleType::create($data);

        return response()->json(['message' => 'Tipe berhasil ditambahkan.', 'type' => $type]);
    }

    public function updateType(Request $request, VehicleType $type)
    {
        $this->authorize('vehicle_reference.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
        ]);

        $exists = VehicleType::where('brand_id', $type->brand_id)
            ->where('name', $data['name'])
            ->where('id', '!=', $type->id)
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Tipe dengan nama ini sudah ada pada merk tersebut.'], 422);
        }

        $type->update($data);

        return response()->json(['message' => 'Tipe berhasil diperbarui.', 'type' => $type]);
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\VehicleReferenceController;
```

Add this route group after the `vehicles` group:

```php
    Route::prefix('vehicle-references')->name('vehicle-references.')->group(function () {
        Route::get('/', [VehicleReferenceController::class, 'index'])->name('index');
        Route::post('/categories', [VehicleReferenceController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [VehicleReferenceController::class, 'updateCategory'])->name('categories.update');
        Route::post('/brands', [VehicleReferenceController::class, 'storeBrand'])->name('brands.store');
        Route::put('/brands/{brand}', [VehicleReferenceController::class, 'updateBrand'])->name('brands.update');
        Route::post('/types', [VehicleReferenceController::class, 'storeType'])->name('types.store');
        Route::put('/types/{type}', [VehicleReferenceController::class, 'updateType'])->name('types.update');
    });
```

- [ ] **Step 5: Write the view**

`resources/views/vehicle-references/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Referensi Kendaraan')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-diagram-3 me-2"></i>Referensi Kendaraan</h1>
    </div>

    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Kategori</div>
                <ul class="list-group list-group-flush" id="category-list"></ul>
                @can('vehicle_reference.manage')
                <div class="card-body border-top" id="category-add-row">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="category-add-toggle">
                        <i class="bi bi-plus-lg"></i> Tambah Kategori
                    </button>
                    <form id="category-add-form" class="d-none mt-2 d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" id="category-add-name" maxlength="150" required>
                        <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                    </form>
                </div>
                @endcan
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Merk</div>
                <ul class="list-group list-group-flush" id="brand-list"></ul>
                @can('vehicle_reference.manage')
                <div class="card-body border-top" id="brand-add-row" style="display:none;">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="brand-add-toggle">
                        <i class="bi bi-plus-lg"></i> Tambah Merk
                    </button>
                    <form id="brand-add-form" class="d-none mt-2 d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" id="brand-add-name" maxlength="150" required>
                        <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                    </form>
                </div>
                @endcan
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Tipe</div>
                <ul class="list-group list-group-flush" id="type-list"></ul>
                @can('vehicle_reference.manage')
                <div class="card-body border-top" id="type-add-row" style="display:none;">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="type-add-toggle">
                        <i class="bi bi-plus-lg"></i> Tambah Tipe
                    </button>
                    <form id="type-add-form" class="d-none mt-2 d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" id="type-add-name" maxlength="150" required>
                        <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                    </form>
                </div>
                @endcan
            </div>
        </div>
    </div>

    <div id="reference-feedback" class="small mt-2"></div>
@endsection

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const canManage = @json(auth()->user()->can('vehicle_reference.manage'));
    let categories = @json($categories);
    let selectedCategoryId = null;
    let selectedBrandId = null;

    const feedback = document.getElementById('reference-feedback');
    function showFeedback(message, isError) {
        feedback.textContent = message;
        feedback.className = 'small mt-2 ' + (isError ? 'text-danger' : 'text-success');
    }

    async function send(url, method, body) {
        const response = await fetch(url, {
            method,
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.message || 'Terjadi kesalahan.');
        }
        return data;
    }

    function renderList(listEl, items, activeId, onSelect, onToggle) {
        listEl.innerHTML = '';
        items.forEach(function (item) {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-center' + (item.id === activeId ? ' active' : '');
            li.style.cursor = 'pointer';

            const label = document.createElement('span');
            label.textContent = item.name + (item.is_active ? '' : ' (nonaktif)');
            label.addEventListener('click', function () { onSelect(item.id); });
            li.appendChild(label);

            if (canManage && onToggle) {
                const toggleBtn = document.createElement('button');
                toggleBtn.type = 'button';
                toggleBtn.className = 'btn btn-sm btn-outline-secondary';
                toggleBtn.textContent = item.is_active ? 'Nonaktifkan' : 'Aktifkan';
                toggleBtn.addEventListener('click', function (e) { e.stopPropagation(); onToggle(item); });
                li.appendChild(toggleBtn);
            }

            listEl.appendChild(li);
        });
    }

    function renderCategories() {
        renderList(document.getElementById('category-list'), categories, selectedCategoryId, selectCategory, canManage ? toggleCategory : null);
    }

    function renderBrands() {
        const category = categories.find(function (c) { return c.id === selectedCategoryId; });
        const brands = category ? category.brands : [];
        renderList(document.getElementById('brand-list'), brands, selectedBrandId, selectBrand, canManage ? toggleBrand : null);
        document.getElementById('brand-add-row').style.display = category ? '' : 'none';
    }

    function renderTypes() {
        const category = categories.find(function (c) { return c.id === selectedCategoryId; });
        const brand = category ? category.brands.find(function (b) { return b.id === selectedBrandId; }) : null;
        const types = brand ? brand.types : [];
        renderList(document.getElementById('type-list'), types, null, function () {}, canManage ? toggleType : null);
        document.getElementById('type-add-row').style.display = brand ? '' : 'none';
    }

    function selectCategory(id) {
        selectedCategoryId = id;
        selectedBrandId = null;
        renderCategories();
        renderBrands();
        renderTypes();
    }

    function selectBrand(id) {
        selectedBrandId = id;
        renderBrands();
        renderTypes();
    }

    async function toggleCategory(item) {
        try {
            const data = await send(`/vehicle-references/categories/${item.id}`, 'PUT', { name: item.name, is_active: !item.is_active });
            item.is_active = data.category.is_active;
            renderCategories();
            showFeedback(data.message, false);
        } catch (error) {
            showFeedback(error.message, true);
        }
    }

    async function toggleBrand(item) {
        try {
            const data = await send(`/vehicle-references/brands/${item.id}`, 'PUT', { name: item.name, is_active: !item.is_active });
            item.is_active = data.brand.is_active;
            renderBrands();
            showFeedback(data.message, false);
        } catch (error) {
            showFeedback(error.message, true);
        }
    }

    async function toggleType(item) {
        try {
            const data = await send(`/vehicle-references/types/${item.id}`, 'PUT', { name: item.name, is_active: !item.is_active });
            item.is_active = data.type.is_active;
            renderTypes();
            showFeedback(data.message, false);
        } catch (error) {
            showFeedback(error.message, true);
        }
    }

    if (canManage) {
        document.getElementById('category-add-toggle').addEventListener('click', function () {
            document.getElementById('category-add-form').classList.toggle('d-none');
        });
        document.getElementById('category-add-form').addEventListener('submit', async function (e) {
            e.preventDefault();
            const input = document.getElementById('category-add-name');
            try {
                const data = await send('/vehicle-references/categories', 'POST', { name: input.value });
                categories.push(Object.assign(data.category, { brands: [] }));
                input.value = '';
                renderCategories();
                showFeedback(data.message, false);
            } catch (error) {
                showFeedback(error.message, true);
            }
        });

        document.getElementById('brand-add-toggle').addEventListener('click', function () {
            document.getElementById('brand-add-form').classList.toggle('d-none');
        });
        document.getElementById('brand-add-form').addEventListener('submit', async function (e) {
            e.preventDefault();
            const input = document.getElementById('brand-add-name');
            try {
                const data = await send('/vehicle-references/brands', 'POST', { category_id: selectedCategoryId, name: input.value });
                const category = categories.find(function (c) { return c.id === selectedCategoryId; });
                category.brands.push(Object.assign(data.brand, { types: [] }));
                input.value = '';
                renderBrands();
                showFeedback(data.message, false);
            } catch (error) {
                showFeedback(error.message, true);
            }
        });

        document.getElementById('type-add-toggle').addEventListener('click', function () {
            document.getElementById('type-add-form').classList.toggle('d-none');
        });
        document.getElementById('type-add-form').addEventListener('submit', async function (e) {
            e.preventDefault();
            const input = document.getElementById('type-add-name');
            try {
                const data = await send('/vehicle-references/types', 'POST', { brand_id: selectedBrandId, name: input.value });
                const category = categories.find(function (c) { return c.id === selectedCategoryId; });
                const brand = category.brands.find(function (b) { return b.id === selectedBrandId; });
                brand.types.push(data.type);
                input.value = '';
                renderTypes();
                showFeedback(data.message, false);
            } catch (error) {
                showFeedback(error.message, true);
            }
        });
    }

    renderCategories();
    renderBrands();
    renderTypes();
})();
</script>
@endpush
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=VehicleReferenceControllerTest`
Expected: PASS, 7/7.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/VehicleReferenceController.php routes/web.php \
        resources/views/vehicle-references/ tests/Feature/VehicleReferenceControllerTest.php
git commit -m "feat: add referensi kendaraan drill-down screen"
```

---

### Task 8: Sidebar wiring + full-suite verification

**Files:**
- Modify: `resources/views/partials/sidebar.blade.php`
- Modify: `tests/Feature/AppShellTest.php` (add methods, don't touch existing ones)

**Interfaces:**
- Consumes: `customers.index`, `vehicles.index`, `vehicle-references.index` routes (Tasks 3/5/7) and their `.view` permission codes.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AppShellTest.php` (new methods, inside the existing class):

```php
    public function test_sidebar_shows_customer_link_without_requiring_branch_view_permission(): void
    {
        $permission = Permission::create([
            'code' => 'customer.view',
            'resource' => 'customer',
            'action' => 'view',
            'description' => 'Melihat customer',
        ]);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('customers.index'), false);
        $response->assertDontSee(route('branches.index'), false);
    }

    public function test_sidebar_shows_vehicle_and_vehicle_reference_links_when_authorized(): void
    {
        $user = User::factory()->create();

        foreach (['vehicle.view', 'vehicle_reference.view'] as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::create(['code' => $code, 'resource' => $resource, 'action' => $action, 'description' => $code]);
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('vehicles.index'), false);
        $response->assertSee(route('vehicle-references.index'), false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AppShellTest`
Expected: FAIL — sidebar has no Customer/Kendaraan/Referensi Kendaraan links yet, and the outer `@if ($user && $user->can('branch.view'))` on the Master Data section would hide the whole section from a `customer.view`-only user even after the links are added, if left unfixed.

- [ ] **Step 3: Update the sidebar**

In `resources/views/partials/sidebar.blade.php`, replace the entire Master Data block (the first `@if`/`@endif`) with:

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

Note the `branch.view` link is now also wrapped in its own `@can` (it previously relied solely on the outer `@if`) — this keeps the section visible to any Master Data permission holder while still hiding individual links the user lacks.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AppShellTest`
Expected: PASS, 5/5.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: PASS, all tests green (baseline 83 + this plan's new tests).

- [ ] **Step 6: Commit**

```bash
git add resources/views/partials/sidebar.blade.php tests/Feature/AppShellTest.php
git commit -m "feat: wire customer/vehicle/vehicle-reference links into Master Data sidebar"
```

---

## Manual verification checklist (after all tasks complete)

1. `php artisan migrate` then `php artisan db:seed --class=VehicleCategorySeeder` on the dev `laravel` database (not `bengkel_testing` — check `.env` `DB_DATABASE` first, per project memory).
2. Log in as `faiz_rahmat` (has `customer.*`/`vehicle.*` globally per `DemoUsersSeeder`). Confirm the sidebar shows Customer, Kendaraan, Referensi Kendaraan under Master Data.
3. Create a customer, assign it to a branch via the Cabang tab, confirm the checkbox persists on reload.
4. From the customer's Kendaraan tab, click "Tambah Kendaraan", confirm the customer is pre-selected, pick a category/brand/type via the cascading dropdowns, save, confirm it appears back on the Kendaraan tab.
5. On `/vehicle-references`, add a category, a brand under it, a type under that brand, confirm all three appear without a page reload; deactivate one and confirm the "(nonaktif)" label appears.
