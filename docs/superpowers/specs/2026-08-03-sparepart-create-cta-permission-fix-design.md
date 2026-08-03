# Sparepart Create-CTA Permission Fix — Design

Status: approved by user, ready for implementation plan

## Purpose

Follow-up to the "Sparepart Baru — Branch Selection" fix (merged as commit `0229461`), which let `SparepartBranchController::create()` show a dropdown of every branch the user has `sparepart.create` in, instead of hard-403ing when the session's "current" branch (resolved via `sparepart.view`) lacked create permission. That fix's own final whole-branch review found that three entry points *leading to* the create form still gate visibility on the wrong permission or the wrong scope, so a user can now successfully reach and use the create form once they get there, but may never see a button/link/CTA that gets them there in the first place — or, worse, see a button that leads to a misleading "no access" page.

## Scope

**In scope — three gating fixes, all changing "current branch only" checks to "any branch the user has `sparepart.create` in":**

1. **Dashboard "Sparepart Baru" button** (`resources/views/dashboard/index.blade.php:13`): currently `@if (auth()->user()->branchesWithPermission('sparepart.view')->isNotEmpty())` — wrong permission entirely (checks view, not create). Change to `auth()->user()->branchesWithPermission('sparepart.create')->isNotEmpty()`.

2. **Master Sparepart header "Sparepart Baru" link** (`resources/views/sparepart-branches/index.blade.php:7-14`): currently one `@if (auth()->user()->hasPermissionToInBranch('sparepart.create', $currentBranch->id))` wraps both the "Tambah dari Cabang Lain" link and the "Sparepart Baru" link. Split into two independent conditions:
   - "Tambah dari Cabang Lain" keeps the existing current-branch-only check (`hasPermissionToInBranch('sparepart.create', $currentBranch->id)`) — unchanged, since `createExisting()` genuinely only operates against the currently-active branch (still resolved via `resolveCurrentBranch()`, which uses `sparepart.view` — untouched, out of scope here).
   - "Sparepart Baru" gets the same any-branch check as the dashboard button: `auth()->user()->branchesWithPermission('sparepart.create')->isNotEmpty()`.

3. **Empty-state CTA** (`resources/views/sparepart-branches/index.blade.php:90`, the `ctaVisible` param passed to `partials.empty-state`): currently `auth()->user()->hasPermissionToInBranch('sparepart.create', $currentBranch->id)` — same any-branch fix as #1/#2's "Sparepart Baru" link, since this CTA links to the exact same `sparepart-branches.create` route.

**Explicitly out of scope:**
- `createExisting()`, `storeExisting()`, `resolveCurrentBranch()`, `create-existing.blade.php` — untouched. "Tambah dari Cabang Lain" remains current-branch-scoped by design (see #2 above).
- `store()`, `StoreSparepartRequest`, `SparepartBranchController::create()` itself (the dropdown logic from the prior fix) — untouched; this spec is purely about *visibility* of links leading to that already-correct form.
- No new permission codes, no schema changes, no changes to `User::branchesWithPermission()` itself (already generic, already used with `sparepart.view` elsewhere — this spec is its second-and-third callers with `sparepart.create`, alongside the prior fix's first caller in `create()`).

## Design

**`resources/views/dashboard/index.blade.php`**, line 13, change the permission code only:

```blade
@if (auth()->user()->branchesWithPermission('sparepart.create')->isNotEmpty())
```

(Everything else in that block — the `<a href="{{ route('sparepart-branches.create') }}">` and its contents — is unchanged.)

**`resources/views/sparepart-branches/index.blade.php`**, lines 6-15, replacing the single wrapping `@if` with two:

```blade
        <div class="d-flex gap-2">
            @if (auth()->user()->hasPermissionToInBranch('sparepart.create', $currentBranch->id))
                <a href="{{ route('sparepart-branches.createExisting') }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-link-45deg"></i> Tambah dari Cabang Lain
                </a>
            @endif
            @if (auth()->user()->branchesWithPermission('sparepart.create')->isNotEmpty())
                <a href="{{ route('sparepart-branches.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> Sparepart Baru
                </a>
            @endif
        </div>
```

**`resources/views/sparepart-branches/index.blade.php`**, line 90, changing only the `ctaVisible` value:

```blade
                                    'ctaVisible' => auth()->user()->branchesWithPermission('sparepart.create')->isNotEmpty(),
```

## Testing

**Modify one existing test** in `tests/Feature/SparepartBranchIndexAndCreateTest.php`:
- `test_empty_state_cta_hidden_when_user_lacks_create_permission_in_current_branch` currently grants `sparepart.create` in a branch OTHER than the current one and asserts the CTA is hidden — this is precisely the old (buggy) behavior this spec corrects. Rename to `test_empty_state_cta_shown_when_user_has_create_permission_in_a_different_branch` and flip its assertion from `assertDontSee('Sparepart Baru')` to `assertSee('Sparepart Baru')`.

**Add new tests** (same file, reusing the existing `grantBranchPermission()` helper):
- Empty-state CTA is hidden when the user has `sparepart.create` in zero branches at all (the genuinely-hidden case the renamed test no longer covers).
- "Sparepart Baru" header link is visible when the user has `sparepart.create` only in a branch other than the currently-active one.
- "Sparepart Baru" header link is hidden when the user has `sparepart.create` in zero branches.
- "Tambah dari Cabang Lain" link remains hidden when the user has `sparepart.create` only in a different branch than the current one (proves the split didn't accidentally loosen this link's gate too).

**Add new tests** in `tests/Feature/DashboardTest.php` (reusing its existing `protected function grantBranchPermission(User $user, Branch $branch, string $code): void` helper at `tests/Feature/DashboardTest.php:19-28` — identical shape to the one in `SparepartBranchIndexAndCreateTest.php`):
- Dashboard "Sparepart Baru" button is visible for a user with `sparepart.create` in some branch (regardless of whether they also have `sparepart.view` there).
- Dashboard "Sparepart Baru" button is hidden for a user with `sparepart.view` only (no `sparepart.create` anywhere) — this is the regression test for the exact bug being fixed (previously: button shown, leading to a misleading no-access page).

**Full-suite + text-collision grep** (standard project practice): run `php artisan test`, then grep the changed/new visible strings ("Sparepart Baru", "Tambah dari Cabang Lain") against `tests/Feature/AppShellTest.php` and `tests/Feature/DashboardTest.php` for accidental collisions — a quick check already done during this spec's research confirmed zero existing matches in `AppShellTest.php`, but re-confirm at implementation time since `DashboardTest.php` will gain new assertions on "Sparepart Baru" itself.

## Execution

Single task, inline execution recommended — three small, mechanical Blade permission-check swaps across two files, smaller in scope than the prior branch-selection fix.
