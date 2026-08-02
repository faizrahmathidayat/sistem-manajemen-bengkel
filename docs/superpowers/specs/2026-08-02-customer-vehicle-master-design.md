# Migration 003 — Customer & Kendaraan Master — Design

Status: approved by user, ready for implementation plan

## Purpose

The Foundation phase (migration 002) seeded the permission catalog for `master.customer` and `master.vehicle` (menus + `customer.view/create/edit`, `vehicle.view/create/edit` permission codes) but built no tables or UI for them. This is migration 003 from `Rencana_Migrasi_Database_Sistem_Bengkel.md`: customer master data, the many-branch relationship customers need (a customer can be served at more than one cabang), and vehicle master data with its 3-level reference hierarchy (Kategori → Merk → Tipe). This is a pure master-data module — no PKB/Invoice dependency yet (those are migrations 006+ and don't exist in the codebase).

## Scope

**In scope:**
- `customers` table + `customer_branches` (many-to-many customer↔branch, mirrors `user_branches`).
- `vehicle_categories` / `vehicle_brands` / `vehicle_types` — 3-level reference hierarchy, brand belongs to a category, type belongs to a brand.
- `vehicles` table (customer's vehicles), with cascading category/brand/type validation.
- Customer screens: list, create, detail page with 3 tabs (Profil, Cabang, Kendaraan).
- Vehicle screens: standalone list/create/edit (customer as a field), reachable both from the sidebar and from a customer's Kendaraan tab.
- One combined "Referensi Kendaraan" screen (3-column drill-down: pick category → see its brands → pick brand → see its types), AJAX add/edit in place, no full-page reloads.
- New menu `master.vehicle_reference` with `vehicle_reference.view` / `vehicle_reference.manage` permission codes, seeded via `MenuPermissionSeeder` (global, `is_branch_scoped = false`, same tier as the other Master Data menus).
- A small non-demo seeder for starter vehicle categories (e.g. Mobil, Motor) — real reference data every install needs, not test-only demo data.

**Explicitly out of scope / deferred:**
- Anything PKB/Invoice-related (customer-branch pairing gets *validated* by future PKB creation, but no PKB code exists yet to validate against).
- `vehicle.delete` / hard delete anywhere — same `is_active` toggle convention as every other master table, no exceptions.
- Search/autocomplete widgets (select2 etc.) for the customer dropdown on the vehicle form — plain `<select>` is enough at current expected data volumes; revisit only if it proves painful.
- Granular `view/create/edit` split for vehicle reference data — deliberately collapsed into `view`/`manage` (see Permissions) since it's low-churn, single-admin-touches-it data.

## Data model

All new tables: `bigint` auto-increment PK, `snake_case` plural names, `HasAudit` trait (`created_by`/`updated_by`), `is_active` boolean toggle instead of hard delete — same conventions as every table so far.

### `customers`

```text
id, customer_type (string: 'COMPANY' | 'INDIVIDUAL', validated in Form Request — no MySQL ENUM),
name, stnk_name, address, phone, email (nullable),
is_active, created_at, created_by, updated_at, updated_by
```

### `customer_branches`

```text
id, customer_id (FK customers, cascade), branch_id (FK branches, cascade), is_active,
unique(customer_id, branch_id)
```

Structurally identical to `user_branches` — same toggle-assignment pattern, same AJAX controller shape.

### `vehicle_categories`

```text
id, name (unique), is_active, created_at, created_by, updated_at, updated_by
```

### `vehicle_brands`

```text
id, category_id (FK vehicle_categories, cascade), name, is_active, created_at, created_by, updated_at, updated_by
unique(category_id, name)
```

### `vehicle_types`

```text
id, brand_id (FK vehicle_brands, cascade), name, is_active, created_at, created_by, updated_at, updated_by
unique(brand_id, name)
```

### `vehicles`

```text
id, customer_id (FK customers, cascade),
plate_number (nullable, unique), frame_number (nullable, unique), engine_number (nullable, unique),
category_id (FK vehicle_categories), brand_id (FK vehicle_brands), type_id (FK vehicle_types),
is_active, created_at, created_by, updated_at, updated_by
```

`bigint` PK, deviating from the source doc's UUID example — same deviation already established project-wide (branches/users/etc. all use `bigint`). The three identifier columns being nullable+unique needs no generated-column trick (unlike `user_branches.default_marker`): MySQL's unique index treats each `NULL` as distinct, so "unique only when present" is the native behavior, not a workaround.

**Cascading validation** (required by the source doc — "brand sesuai category, type sesuai brand"): enforced in the vehicle's Form Request via a custom rule — reject if the submitted `brand.category_id !== category_id`, or `type.brand_id !== brand_id`. App-layer validation, not a DB trigger, consistent with how this project handles cross-field business rules elsewhere.

## Permissions

Already seeded (migration 002, unchanged by this plan):
- `master.customer` → `customer.view`, `customer.create`, `customer.edit`
- `master.vehicle` → `vehicle.view`, `vehicle.create`, `vehicle.edit`

New, added to `MenuPermissionSeeder` by this plan:
- `master.vehicle_reference` → `vehicle_reference.view`, `vehicle_reference.manage`

`manage` covers create+edit+deactivate across all three hierarchy levels in one permission — splitting into per-level, per-action codes (9 codes for 3 levels × 3 actions) would be pure ceremony for data that's touched rarely and only by whoever administers master data. Same `is_branch_scoped = false` tier as the rest of Master Data.

`customer_branches` and vehicle CRUD have no dedicated permission of their own — they're governed by `customer.*`/`vehicle.*` same as `user_branches` is governed by `user.*` (no separate "assign branch" permission code exists for users either).

## UI — Screens & routes

### Customer (`/customers`)

Mirrors the `User` controller/view shape exactly:
- `index` — `simplePaginate`, search by name/phone.
- `create` — single-step form (type, name, STNK name, address, phone, email).
- `show` — detail page, 3 tabs:
  - **Profil** — edit the fields above.
  - **Cabang** — toggle-per-checkbox branch assignment via AJAX (new `CustomerBranchAssignmentController`, `POST`/`DELETE /customers/{customer}/branches/{branch}`, mirrors `UserBranchAssignmentController` 1:1), gated by `customer.edit`.
  - **Kendaraan** — lists this customer's vehicles, "Tambah Kendaraan" link opens the vehicle create form with `customer_id` pre-filled via query param.
- `update` — from the Profil tab.

### Vehicle (`/vehicles`)

Standalone module, not nested under `/customers`:
- `index` — list with columns plate number / customer / category-brand-type, filter by customer, search by plate/frame/engine number.
- `create` / `edit` — cascading selects: choosing a category populates the brand `<select>` (AJAX fetch, same "fetch on change" pattern as other dynamic dropdowns in this codebase), choosing a brand populates the type `<select>`. Customer field pre-selected when arrived at via the Kendaraan tab's "Tambah Kendaraan" link, otherwise a plain `<select>` over all active customers.

### Referensi Kendaraan (`/vehicle-references`)

One controller, one view, three columns:
- Column 1 lists categories (click to select, highlights active selection).
- Column 2 lists the selected category's brands.
- Column 3 lists the selected brand's types.
- Add/edit/deactivate at each level happens in place via AJAX with an inline row form (no modals — this codebase has no modal pattern anywhere yet, and every existing dynamic interaction, e.g. checkbox toggles, is inline). Each column gets a persistent "+ Tambah" row at the bottom that reveals a small inline text-input form on click; editing a row swaps it for the same inline form pre-filled, submitted via AJAX, no full page reload.
- Gated by `vehicle_reference.view` (page access) / `vehicle_reference.manage` (the add/edit/deactivate actions).

### Sidebar

Three new items under the existing "Master Data" heading (`resources/views/partials/sidebar.blade.php`), each gated by its own `.view` permission, following the existing `@if ($user && $user->can(...))` pattern: Customer, Kendaraan, Referensi Kendaraan.

## Seed data

New non-demo seeder (runs in every environment, unlike `DemoUsersSeeder`) with a small starter set of vehicle categories per the source doc's "Kategori kendaraan dasar bila disepakati": **Mobil**, **Motor**. No starter brands/types — those are populated by whoever administers the system, categories are the only level worth shipping as a default since every install needs at least these two to be usable.

## Testing

Full TDD, mirroring existing test patterns (`UserManagementTest`, `UserBranchAssignmentController` tests):
- Model tests: relations, unique constraints (`customer_branches`, `vehicle_brands`, `vehicle_types`, and the three nullable-unique vehicle identifier columns — confirm two vehicles can both have `plate_number = null`).
- Form Request tests: customer_type validation, cascading brand/type rejection (brand not in submitted category → rejected; type not in submitted brand → rejected).
- Controller tests: permission gating (403 without the relevant `.view`/`.create`/`.edit`), CRUD flow for customers/vehicles, `CustomerBranchAssignmentController` grant/revoke (mirrors `UserBranchAssignmentController` test shape).
- Vehicle reference AJAX endpoints: add/edit/deactivate at each level, brand list scoped to its category, type list scoped to its brand.
- Sidebar renders new items only for users holding the corresponding `.view` permission.
- New seeder seeds Mobil/Motor cleanly and idempotently (re-running doesn't duplicate).

## Execution

Master-data CRUD following an established pattern (Branch/User already shipped this exact shape) is not auth-critical infrastructure — per the project's process preference, this runs **inline**, no subagent dispatch, same policy as the first Administrasi CRUD UI pass.
