# Migration 005 — Sparepart & Saldo Stok Cabang — Design

Status: approved by user, ready for implementation plan

## Purpose

Migration 002 seeded the permission catalog for `persediaan.sparepart` (`sparepart.view/create/edit/delete`) as a **branch-scoped** menu, but built no tables or UI for it. This is migration 005 from `Rencana_Migrasi_Database_Sistem_Bengkel.md` §8: sparepart master data with identity separated from per-branch configuration (rack, selling price, minimum stock) and per-branch stock balance, so that transfer between branches (migration 008) has a clean home for "the same item, different branch state."

This is also the **first module in the project that is genuinely branch-scoped end to end**. Every prior master-data module (Branch, User, Customer, Vehicle, Mechanic, Service Catalog) lives under Administrasi/Master Data, which uses the original global permission mechanism (`can('x.view')` with no branch argument). `persediaan.sparepart` is one of the 12 Operasional/Persediaan/Reporting menus that only ever grants permission **per branch** (`user_branch_permissions` + `hasPermissionToInBranch()`), a primitive that was built in the branch-scoped-permissions migration but has had no consumer until now. There is no global fallback: a user with zero branch-level `sparepart.view` grants cannot see this module at all, in any branch.

## Scope

**In scope:**
- `spareparts` (identity: code, name, is_active) + `sparepart_branches` (per-branch config: rack, selling price, minimum stock, is_active) + `sparepart_branch_stocks` (per-branch balance: on_hand_qty, reserved_qty).
- A `SparepartBranchPolicy` — the project's first Policy — enforcing `hasPermissionToInBranch()` per record, closing the gap `Gate::before` deliberately left open for argument-based checks.
- A branch-switcher navigation pattern for the module (session-persisted "current sparepart branch"), since there is no branch-agnostic view of this data to fall back to.
- Two creation flows: brand-new sparepart (creates identity + branch config + stock=0 together), and attaching an already-existing sparepart identity to another branch (separate config, separate stock row).
- Sidebar: new "Persediaan" section with a "Master Sparepart" entry (first entry under this heading — Persediaan menus haven't had any UI yet).

**Explicitly out of scope / deferred:**
- Any write path for `on_hand_qty` or `reserved_qty`. Both start and stay at 0 through this migration. `reserved_qty` only ever changes once `inventory_reservations` (migration 007) exists; `on_hand_qty` only ever changes once Penerimaan Barang (migration 008) exists. Stock is displayed read-only. (User decision: keep this migration strictly to master + branch config, no stopgap manual stock-entry field.)
- Low-stock alerting/reporting against `minimum_stock` — that's migration 011 (`report.sparepart.view`). This migration only stores the field.
- A deactivate/reactivate toggle on `spareparts.is_active` (the identity row itself). No business need yet to deactivate an item globally while leaving per-branch configs active; `sparepart.delete` ("Menonaktifkan sparepart") only ever toggles a `sparepart_branches` row. `spareparts.is_active` is written `true` at creation and not otherwise exposed in this migration's UI.
- Anything PKB/transfer-related — migrations 006-008 don't exist yet.

## Data model

All new tables: `bigint` auto-increment PK, `snake_case` plural names, `HasAudit` trait on `spareparts` and `sparepart_branches` (`created_by`/`updated_by`); `sparepart_branch_stocks` is a pure balance row keyed by `sparepart_branch_id`, no audit columns (nothing manually edits it yet).

### `spareparts`

```text
id, code (varchar 30, unique), name (varchar 150), is_active,
created_at, created_by, updated_at, updated_by
```

### `sparepart_branches`

```text
id, sparepart_id (FK spareparts, cascade), branch_id (FK branches, cascade),
rack_number (varchar 30, nullable), selling_price (decimal 18,2),
minimum_stock (decimal 18,3, default 0), is_active,
created_at, created_by, updated_at, updated_by
unique(sparepart_id, branch_id)
```

### `sparepart_branch_stocks`

```text
sparepart_branch_id (PK, FK sparepart_branches, cascade),
on_hand_qty (decimal 18,3, not null, default 0),
reserved_qty (decimal 18,3, not null, default 0),
updated_at (timestamp, not null)
```

Model exposes `available_qty` as an accessor (`on_hand_qty - reserved_qty`), not a stored column — matches the source doc's formula and avoids a second source of truth.

DB-level check constraint (MySQL 8 supports `CHECK` since 8.0.16):

```sql
ALTER TABLE sparepart_branch_stocks
  ADD CONSTRAINT ck_stock_nonnegative
  CHECK (on_hand_qty >= 0 AND reserved_qty >= 0 AND reserved_qty <= on_hand_qty);
```

Since both columns are always 0 in this migration, the constraint can't be exercised by real data yet, but it's cheap to add now and matches the source doc's explicit intent to prevent oversell "by default."

`sparepart_branch_stocks` row is created (all zeros) in the same transaction as its parent `sparepart_branches` row — never created lazily or on first stock movement.

## Permissions

Already seeded (migration 002, unchanged by this plan): `persediaan.sparepart` → `sparepart.view`, `sparepart.create`, `sparepart.edit`, `sparepart.delete`, all branch-scoped (`is_branch_scoped: true`).

No new menus or permission codes needed.

## Authorization

New `App\Policies\SparepartBranchPolicy`, registered in `AuthServiceProvider::$policies` against `SparepartBranch::class`:

```text
view(User $user, SparepartBranch $sparepartBranch): bool
  => $user->hasPermissionToInBranch('sparepart.view', $sparepartBranch->branch_id)

update(User $user, SparepartBranch $sparepartBranch): bool
  => $user->hasPermissionToInBranch('sparepart.edit', $sparepartBranch->branch_id)

delete(User $user, SparepartBranch $sparepartBranch): bool
  => $user->hasPermissionToInBranch('sparepart.delete', $sparepartBranch->branch_id)
```

Controllers call `$this->authorize('view'|'update'|'delete', $sparepartBranch)` — argument-based, so `Gate::before` defers to this Policy exactly as designed. There is no `create` Policy method: creating a config row has no existing `SparepartBranch` instance to authorize against, so it's checked directly in the controller against the *branch the user is currently switched into*: `$user->hasPermissionToInBranch('sparepart.create', $currentBranchId)`.

Every controller action must resolve "which branch" explicitly (from the `SparepartBranch` route-model-bound record for view/update, or from the switcher-selected branch for index/create) — there is no code path where `sparepart.*` is checked without a branch id, since none exists in `user_branch_permissions` without one.

**Test priority:** this Policy is the first one in the codebase and the template every future branch-scoped module (PKB, invoice, receipt, transfer, adjustment, payment) will copy. Cross-branch IDOR must be tested explicitly — a user with `sparepart.view` in Branch A hitting `/sparepart-branches/{id}` for a Branch B record via direct URL must get 403, not silently scoped data.

## Navigation & UI

### Branch switcher

Since there's no branch-agnostic view of sparepart data, the module needs a "which branch am I looking at" concept that doesn't exist anywhere else in the app yet:
- A dropdown at the top of every Sparepart screen listing only branches where the logged-in user holds `sparepart.view`.
- Selection is stored in session (`current_sparepart_branch_id`) and persists across requests within the module; switching re-filters the index and changes which branch a new "create" targets.
- If the user has `sparepart.view` in exactly one branch, the switcher still renders (for consistency and because branch grants can change later) but has nothing to switch to.
- If the user has `sparepart.view` in zero branches, the module renders an empty-state page rather than the list (mirrors how a user with no permissions currently sees no Master Data links at all, just applied one level deeper).

This pattern is intentionally being established here for reuse by migration 006+ rather than invented ad hoc per module.

### Screens (`/sparepart-branches`, resource name reflects that almost everything here is branch-scoped, not the identity table)

- **Index** — `simplePaginate`, filtered to the switcher's selected branch, search by code/name. Columns: code, name, rack, selling price, minimum stock, available stock (always 0 for now), status.
- **Create — sparepart baru** — single form: code, name (identity fields) + rack, selling price, minimum stock (branch-config fields) for the currently-selected branch. Creates `spareparts` + `sparepart_branches` + `sparepart_branch_stocks` (zeros) in one DB transaction. `code` uniqueness validated globally against `spareparts`, not per branch.
- **Create — tambah sparepart existing ke cabang ini** — a search/select step over active `spareparts` not yet configured for the selected branch (i.e. no `sparepart_branches` row for `(sparepart_id, selected_branch_id)`), then the same branch-config fields (rack, price, min stock) for the new row. This is the feature the whole identity/config split exists for.
- **Edit** — branch-config fields only (rack, selling price, minimum stock, is_active); identity (code/name) is not editable from here in this migration — no business case yet for renaming an item that might be shared across branches, and it avoids needing to decide how a rename should propagate.
- No detail/tabs page — flat CRUD like Branch/Service Catalog, since there's no sub-resource analogous to Cabang/Kendaraan tabs (the stock balance is just a read-only column, not a manageable tab).

### Sidebar

New "Persediaan" section (first Persediaan-menu UI in the sidebar) with one entry, "Master Sparepart", gated by having `sparepart.view` in at least one branch (i.e. `branchPermissionCodes()` non-empty for that code across any assigned branch) rather than the existing Master Data pattern's plain `@can`.

## Testing

Full TDD:
- Model tests: `sparepart_branches` unique constraint (sparepart_id, branch_id), cascade deletes, `SparepartBranchStock::available_qty` accessor, non-negative/reserved-≤-on-hand check constraint (insert violating rows via raw query, expect DB exception).
- Policy tests: grants/denies for view and update, explicit cross-branch denial case (permission in Branch A, record in Branch B → denied).
- Feature tests: branch switcher (persists selection, filters index, empty-state with zero branch grants), create-new-sparepart flow (creates all three rows, code-uniqueness validation), attach-existing-to-branch flow (existing identity, new config+stock row, excludes already-configured items from the picker), edit (branch-config only, identity fields untouched), deactivate (toggles `sparepart_branches.is_active`, `spareparts.is_active` untouched), 403 on direct-URL cross-branch access.
- Sidebar test: Persediaan/Master Sparepart link appears only for users holding `sparepart.view` in at least one branch, absent for users with zero branch grants even if they hold unrelated global permissions.

## Execution

Recommend **`subagent-driven-development` in a worktree**, not the inline path used for migrations 003/004. Rationale: this is not "CRUD following an already-shipped pattern" — it's the first Policy in the codebase and the first real enforcement of branch-scoped permissions, both of which future security-sensitive modules (PKB, invoice, payment) will copy verbatim. The branch-scoped-permissions migration itself (the permission *model*) got full rigor including a final whole-branch review that caught 3 gaps the per-task reviews missed; this migration is the first *consumer* of that model and deserves the same scrutiny rather than being waved through as routine master-data work. Confirm this at planning time.
