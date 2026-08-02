# Migration 004 — Mekanik & Jasa Service Master — Design

Status: approved by user, ready for implementation plan

## Purpose

Migration 002 seeded the permission catalog for `master.mechanic` and `master.service` (`mechanic.view/create/edit`, `service.view/create/edit`) but built no tables or UI for them. This is migration 004 from `Rencana_Migrasi_Database_Sistem_Bengkel.md` §7: mechanic master data with per-branch assignment (so PKB's mechanic dropdown can be scoped to the PKB's branch), and a flat service catalog with a default price. Pure master-data module, no PKB dependency (migration 006, doesn't exist yet).

## Scope

**In scope:**
- `mechanics` table + `mechanic_branches` (many-to-many mechanic↔branch, structurally identical to `customer_branches` from migration 003).
- `service_catalogs` table — flat, no branch relation.
- Mechanic screens: list, create, detail page with 2 tabs (Profil, Cabang) — same shape as Customer from migration 003.
- Service Catalog screens: list, create, edit — flat CRUD, same shape as Branch (no detail/tabs page).
- Sidebar: two new Master Data entries, Mekanik and Jasa Service.

**Explicitly out of scope / deferred:**
- Per-branch service pricing. The business flow doc raises this as an open question ("Apakah harga jasa service juga dapat berbeda per cabang?"), but the source migration doc's technical schema for `service_catalogs` has a single global `default_price` with no `service_branches` pivot table (unlike `sparepart_branches` in migration 005, which explicitly exists for that purpose). This plan follows the technical schema as authoritative: one global `default_price` per service, usable as a starting value that can be overridden free-text per PKB line item later (per the business doc's "jasa service boleh free-text" note). If per-branch service pricing is ever needed, it is a separate future migration — not a deviation to smuggle into this one.
- Anything PKB-related (mechanic/service selection on a PKB) — migration 006 doesn't exist yet.
- `mechanic.delete` / hard delete anywhere — `is_active` toggle only, same convention as every other master table.

## Data model

All new tables: `bigint` auto-increment PK, `snake_case` plural names, `HasAudit` trait (`created_by`/`updated_by`), `is_active` boolean toggle instead of hard delete.

### `mechanics`

```text
id, name, phone (nullable), email (nullable), address (nullable, text),
is_active, created_at, created_by, updated_at, updated_by
```

### `mechanic_branches`

```text
id, mechanic_id (FK mechanics, cascade), branch_id (FK branches, cascade), is_active,
unique(mechanic_id, branch_id)
```

Structurally identical to `customer_branches` — same toggle-assignment pattern, same AJAX controller shape, gated by `mechanic.edit` (no dedicated `mechanic_branch.manage` permission — same simplification already applied to `customer_branches` rather than the heavier `user_branches`/`user_branch.manage` precedent).

### `service_catalogs`

```text
id, code (varchar 30, unique), name (varchar 150), default_price (decimal 18,2),
is_active, created_at, created_by, updated_at, updated_by
```

Flat — no relation to branches or mechanics. `code` follows the same convention as `branches.code` (short unique identifier).

## Permissions

Already seeded (migration 002, unchanged by this plan):
- `master.mechanic` → `mechanic.view`, `mechanic.create`, `mechanic.edit`
- `master.service` → `service.view`, `service.create`, `service.edit`

No new menus or permission codes needed — this migration is fully covered by the existing catalog, unlike migration 003 which needed to add `master.vehicle_reference`.

## UI — Screens & routes

### Mechanic (`/mechanics`)

Mirrors the `Customer` controller/view shape from migration 003:
- `index` — `simplePaginate`, search by name/phone.
- `create` — single-step form (name, phone, email, address, is_active).
- `show` — detail page, 2 tabs:
  - **Profil** — edit the fields above.
  - **Cabang** — toggle-per-checkbox branch assignment via AJAX (new `MechanicBranchAssignmentController`, `POST`/`DELETE /mechanics/{mechanic}/branches/{branch}`, mirrors `CustomerBranchAssignmentController` 1:1), gated by `mechanic.edit`.
- `update` — from the Profil tab.

### Service Catalog (`/service-catalogs`)

Mirrors the `Branch` controller/view shape (flat, no detail/tabs page):
- `index` — `simplePaginate`, search by code/name.
- `create` — single form (code, name, default_price, is_active).
- `edit` — same form fields, same `_form.blade.php`-partial pattern as Branch.
- `update` — from the edit page.

### Sidebar

Two new items under the existing "Master Data" heading, following the pattern already established for Customer/Vehicle in migration 003 (each gated by its own `.view` permission, outer section `@if` already covers arbitrary Master Data permissions): Mekanik, Jasa Service.

## Testing

Full TDD, mirroring migration 003's test patterns (`CustomerManagementTest`, `CustomerBranchTabTest`, `BranchManagementTest`):
- Model tests: `mechanic_branches` unique constraint, cascade deletes.
- Mechanic controller tests: permission gating, CRUD flow, show renders Profil tab.
- Mechanic branch-assignment tests: grant/revoke persist, 403 without `mechanic.edit`, show page renders Cabang tab (mirrors `CustomerBranchTabTest` exactly).
- Service Catalog controller tests: permission gating, CRUD flow including `code` uniqueness validation (mirrors `BranchManagementTest` exactly, including the update-can-deactivate case).
- Sidebar test: Mekanik/Jasa Service links appear only for users holding the corresponding `.view` permission.

## Execution

Master-data CRUD following an established pattern (Branch and Customer already shipped both of this migration's shapes — flat CRUD and tabbed-detail CRUD) is not auth-critical infrastructure — per the project's process preference, this runs **inline**, no subagent dispatch, same policy as migration 003.
