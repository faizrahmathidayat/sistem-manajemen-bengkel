# Administrasi CRUD UI — Design

Status: approved by user, ready for implementation plan

## Purpose

The Foundation phase (`docs/superpowers/plans/2026-08-01-foundation-identity-access.md`) built and tested the backend for branches, users, branch assignment, and permission assignment, but there is no UI for any of it beyond login/logout. An admin currently can only create a second branch or user via `tinker`. This plan gives that backend a working, elegant, responsive Bootstrap 5 screen set, and replaces the current placeholder navbar-only layout with a real app shell (sidebar + topbar) that every future module's screens will build on.

## Scope

**In scope:**
- App shell rebuild: sidebar (left) + topbar, replacing `resources/views/layouts/app.blade.php`'s current plain navbar-only layout.
- Branches (Cabang) screen: list, create, edit, activate/deactivate.
- Users screen: list, create, and a detail page with three tabs — Profil, Cabang, Permission.

**Out of scope (explicitly deferred):**
- Audit Log screen — the `audit_logs` table doesn't exist yet (deferred to the reporting/audit plan, migration doc §14.1).
- Search/filtering on list screens — v1 ships with `simplePaginate` lists only; add search later if the list grows unwieldy in practice.
- Any module beyond Administrasi/Cabang (PKB, Invoice, Master Data for customer/vehicle/etc.) — separate future plans.

## Sidebar navigation

The sidebar shows only functional menu groups — no placeholder/disabled entries for modules that don't exist yet (PKB, Invoice, etc. stay off the sidebar until their own plans ship). For this plan, that's two groups, matching the already-seeded `menus` catalog:

- **Master Data** → Cabang (`master.branch` menu, gated by `branch.view`)
- **Administrasi** → Users (`administrasi.users` menu, gated by `user.view`)

Icons via Bootstrap Icons (CDN, `bootstrap-icons@1.11`) — no local build step, consistent with the existing Bootstrap-5-via-CDN convention. A sidebar item only renders if the logged-in user holds the gating permission for it (reuses `$user->can(...)`, no new authorization mechanism).

## Screens

### Branches (`/branches`)

Gated by `branch.view` / `branch.create` / `branch.edit` (already-seeded codes, no new permissions needed).

- **Index**: `simplePaginate` table — code, name, phone, status, edit link. "Tambah Cabang" button gated by `branch.create`.
- **Create/Edit**: single form (code, name, address, phone, email, is_active). No delete action anywhere — `is_active` toggle only, consistent with the project's no-hard-delete convention for master data.

### Users (`/users`)

Gated by `user.view` / `user.create` / `user.edit`, plus `user_branch.manage` and `user_permission.manage` for their respective tabs.

- **Index**: `simplePaginate` table — name, username, status, default branch, link to detail.
- **Create**: name, username, password, is_active. (Branch/permission assignment happens after creation, from the detail tabs — keeps the create form short.)
- **Detail** (`/users/{user}`), three Bootstrap tabs:

  **Tab 1 — Profil** (`user.edit`): name, username, is_active checkbox, optional password reset field (blank = unchanged). Standard form POST, not AJAX — infrequent, low-risk action, no need for the added complexity of an AJAX round trip here.

  Self-lockout guard: if the target user is the currently-logged-in user, the `is_active` checkbox is disabled in the form and the server independently rejects any attempt to deactivate one's own account (defense in depth — a disabled checkbox alone isn't a real guard against a replayed/crafted request).

  **Tab 2 — Cabang** (`user_branch.manage`): every branch listed with a checkbox (assigned/not) and a radio for "default". Toggling a checkbox fires an AJAX request that calls the existing `UserBranchService::assign()`/deactivation path immediately — no page-wide "Simpan" button. Each row shows a brief inline saved/error indicator after the request resolves.

  **Tab 3 — Permission** (`user_permission.manage`): an accordion, one panel per `Menu` (the 21 already-seeded menu groups), each panel listing that menu's permissions as checkboxes plus a "pilih semua" checkbox for the group. Same AJAX-per-checkbox pattern as the Cabang tab — check/uncheck grants/revokes immediately via `UserPermission::firstOrCreate()` / delete, no bulk save button.

  Self-lockout guard, scoped to self-revocation only: when the acting user attempts to revoke `user_permission.manage` from their *own* account, and no other active user would hold an active `user_permission.manage` grant afterward, the request is rejected (422) with a clear inline message. Revoking `user_permission.manage` from a *different* user is always allowed, even if that user currently happens to be the sole holder — that's a deliberate admin action on someone else's account, not a self-lockout scenario, and it's not this plan's job to second-guess it.

## Data flow / authorization

No new database tables or migrations. Every action reuses Foundation-phase infrastructure:
- `Branch` model + `HasAudit` for branches.
- `User`, `UserBranchService` (existing `assign()` method) for the Cabang tab.
- `UserPermission`, `Permission`, `Menu` models for the Permission tab.
- `Gate::before` / `AuthorizesByPermission::hasPermissionTo()` for all authorization — controllers call `$this->authorize('branch.edit')` etc. exactly like the pattern already established.
- Branch-scoping is not a concern in this plan: branches, users, and permission assignment are global-admin concerns, not scoped to the acting user's own assigned branches (matches the business doc — Administrasi is unscoped by design, unlike PKB/invoice which will be branch-scoped).

## Error handling

- Form validation errors (Profil tab, Branches create/edit, Users create): standard Laravel validation, redisplayed via Bootstrap `alert-danger` / per-field `is-invalid` feedback.
- AJAX endpoints (Cabang tab, Permission tab): return JSON `{message: string}` with a 4xx status on failure (validation, authorization, self-lockout); the frontend shows the message as an inline alert next to the affected row/checkbox, and reverts the checkbox's visual state if the request failed.

## Testing

Full TDD per action, following the existing Foundation-phase pattern (Feature tests, `RefreshDatabase`, real DB assertions — no mocking):
- Branches: index renders, create validates + persists, edit validates + persists, unauthorized user (missing `branch.*` permission) gets 403.
- Users: index renders, create validates + persists (password hashed), unauthorized gets 403.
- Profil tab: update persists, self-deactivation is rejected even via a crafted direct request bypassing the disabled checkbox.
- Cabang tab: AJAX assign/unassign/set-default persist correctly, unauthorized gets 403.
- Permission tab: AJAX grant/revoke persist correctly, unauthorized gets 403, self-revocation-of-last-`user_permission.manage` is rejected with a clear error, revoking a *different* user's last `user_permission.manage` is allowed.

## Execution

Per the project's updated process preference (see memory `bengkel-process-preferences`): this plan is small/medium and touches only already-tested backend, so it will be implemented directly (inline execution, no subagent-driven-development) — I implement each task myself in this session, self-review, and verify via the test suite plus a real browser check before considering it done.
