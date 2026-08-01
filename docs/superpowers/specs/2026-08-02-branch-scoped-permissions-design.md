# Branch-Scoped Permissions — Design

Status: approved by user, ready for implementation plan

## Purpose

The Foundation phase built one flat permission model: `user_permissions` grants a permission code to a user globally, uniform across every branch they're assigned to. The business requirement has since been clarified: for **operational** work, the same user can legitimately hold a *different* permission set per branch — e.g. a user with access to Bengkel 1 and Bengkel 2 might be allowed to create invoices at Bengkel 1 but only view them at Bengkel 2. **Administrasi and Master Data permissions stay global** (unchanged) — those aren't "work done at a branch," they're account/master-data management, and the user confirmed they should keep behaving exactly as today.

## Scope

**In scope:**
- A new `user_branch_permissions` table + model for per-(user, branch, permission) grants, covering the **Operasional**, **Persediaan**, and **Reporting** menu categories (12 of the 21 seeded menus: `operasional.pkb`, `operasional.invoice`, `operasional.payment`, `persediaan.sparepart`, `persediaan.receipt`, `persediaan.stock_adjustment`, `persediaan.stock_transfer`, `reporting.pkb`, `reporting.invoice`, `reporting.receivable`, `reporting.pkb_invoice_gap`, `reporting.sparepart`).
- `menus.is_branch_scoped` boolean column, explicit per menu (not inferred from code prefix), backfilled by the seeder.
- `AuthorizesByPermission::hasPermissionToInBranch(string $code, int $branchId): bool` — new method for future Policies (PKB, invoice, etc.) to call. This is additive.
- Administrasi CRUD UI's Permission tab: split into branch sub-tabs (Operasional/Persediaan/Reporting, per branch) + one unscoped section (Administrasi/Master Data, unchanged mechanism).
- `DemoUsersSeeder` updated to grant Romi's `pkb.view`/`pkb.create` and Syilawati's `invoice.view`/`invoice.create` through the new per-branch mechanism (both are single-branch users today, so this changes *how* the grant is stored, not what access they end up with).

**Explicitly out of scope / unchanged:**
- The existing `user_permissions` table, `UserPermission` model, `hasPermissionTo(string $code): bool`, and `Gate::before` wiring — all untouched. Administrasi/Master Data permission checks keep working exactly as they do today.
- No Policies are written in this plan — PKB/Invoice/etc. don't exist yet. `hasPermissionToInBranch()` is built and tested in isolation; wiring it into a Policy is each future module's own job (already noted in project memory).
- No UI for granting Administrasi/Master Data permissions changes — same accordion, same AJAX endpoints, same table.

## Data model

### `user_branch_permissions` (new table)

Mirrors `user_permissions` with an added `branch_id`:

```text
id, user_id (FK users, cascade), branch_id (FK branches, cascade),
permission_id (FK permissions, cascade), granted_by (FK users, nullable, null-on-delete),
timestamps
unique(user_id, branch_id, permission_id)
```

No nullable-`branch_id` trick, no generated-column workaround needed — this table only ever holds branch-scoped grants, so `branch_id` is always required (`NOT NULL`), and the three-column unique index works cleanly (no NULL-uniqueness ambiguity, unlike the `user_branches.default_marker` case from the Foundation phase).

### `menus.is_branch_scoped` (new column)

`boolean, not null, default false`, added via migration and explicitly set by `MenuPermissionSeeder` for each of the 21 menus (`true` for the 12 Operasional/Persediaan/Reporting menus listed above, `false` for the 9 Master Data/Administrasi menus) — explicit per-menu data, not a runtime `Str::startsWith($menu->code, ...)` check, so a future menu's scoping is a deliberate seeder decision, not an accident of naming.

## Authorization

`AuthorizesByPermission` trait gains:

```php
public function branchPermissionCodes(int $branchId): array   // cached per branch, joins user_branch_permissions + permissions, is_active only
public function hasPermissionToInBranch(string $code, int $branchId): bool
```

`hasPermissionTo(string $code): bool` (global) is **not modified** — different table, different cache property, zero shared code path. This is a deliberate choice to keep the already-tested global path provably unaffected, at the cost of some duplication between `permissionCodes()`/`hasPermissionTo()` and `branchPermissionCodes()`/`hasPermissionToInBranch()`. Revisit only if a third variant appears later — not worth abstracting for two.

Same active-user short-circuit as the existing method (`! $this->is_active` → `false` immediately).

## UI — Permission tab redesign

Current: one accordion listing all 21 menus flat.

New: two sections in the Permission tab —

1. **Per-branch section** — one sub-tab per branch the user has active access to (from `user_branches`), labelled with the branch name. Each sub-tab shows an accordion of only the 12 branch-scoped menus, checkboxes reflecting/saving to `user_branch_permissions` for that specific branch (AJAX per checkbox, same pattern as the existing Cabang/Permission tabs — grant = POST, revoke = DELETE, immediate save, inline feedback). If the user has zero assigned branches, this section shows a short message ("Tetapkan cabang dulu di tab Cabang sebelum mengatur permission operasional.") instead of an empty tab strip.
2. **Global section** (unchanged mechanism) — the existing flat accordion, now filtered to only the 9 unscoped menus, still backed by `user_permissions` / the existing `UserPermissionAssignmentController` routes. The existing self-lockout guard (can't strip your own last `user_permission.manage`) is untouched — that permission lives in this section.

## Routes / Controller

New `UserBranchPermissionAssignmentController` (mirrors `UserPermissionAssignmentController`):
- `POST /users/{user}/branches/{branch}/permissions/{permission}` → grant
- `DELETE /users/{user}/branches/{branch}/permissions/{permission}` → revoke

Both gated by `user_permission.manage` (same permission code governs both the global and per-branch grant UI — it's one "manage permissions" capability, just with two different target tables depending on which section of the tab you're in). No new permission code needed.

No self-lockout guard applies here — `user_permission.manage` itself is never granted through this branch-scoped path (it's in the unscoped 9-menu set), so there's no "strip your own last copy of it" scenario to guard against in this controller.

## `DemoUsersSeeder` changes

Replace Romi's `pkb.view`/`pkb.create` grants and Syilawati's `invoice.view`/`invoice.create` grants with `user_branch_permissions` rows scoped to Bengkel 1 (both are already Bengkel-1-only users). Their `report.*.view` grants also move to the per-branch table (Reporting is now branch-scoped), same branch. Net access for the two demo users is unchanged (they're single-branch, so "global" vs "this one branch" was already equivalent for them) — only the storage mechanism changes, which is exactly the point of this seeder update: prove the new grant path works end-to-end for realistic demo accounts.

## Testing

Full TDD, mirroring the existing `UserPermissionTabTest` patterns:
- `user_branch_permissions` migration + model: grant/revoke persists, unique constraint holds, cascade deletes work.
- `AuthorizesByPermission::hasPermissionToInBranch()`: granted-in-that-branch → true; granted-in-a-different-branch → false; not granted at all → false; inactive permission → false; inactive user → false (existing active-user short-circuit still applies).
- `UserBranchPermissionAssignmentController`: grant/revoke persist correctly, 403 without `user_permission.manage`, granting the same code in two different branches for the same user produces two independent rows (revoking one doesn't touch the other).
- Permission tab renders branch sub-tabs matching the target user's `user_branches`, only shows the 12 branch-scoped menus per sub-tab and the 9 unscoped menus in the global section, and shows the "assign a branch first" message when the user has none.
- `DemoUsersSeeder` still seeds cleanly and Romi/Syilawati end up with the same effective permissions as before (via the new mechanism).

## Execution

Per the project's process preference, this touches core, already-shipped authorization infrastructure (`AuthorizesByPermission`, permission data model) — even though the change is additive, it's auth-critical enough to run through `superpowers:subagent-driven-development` (cheap config: Haiku implementer/reviewer per task, Sonnet final whole-branch review) rather than inline execution.
