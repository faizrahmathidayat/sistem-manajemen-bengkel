# Administrasi (Users) Redesign — Design

Status: approved by user, ready for implementation plan

## Purpose

Sub-project 5 — the final sub-project of the UI redesign track (sub-projects 1-4 merged: Design System Foundation, Dashboard, Foundation v3 + Master Data group 1, Master Data group 2). Rolls the now five-times-proven `list-filter-bar`/`empty-state` pattern onto the last remaining list screen: Users. `UserController::index()` currently has no search at all and `users/index.blade.php` is a bare table, structurally identical to Mekanik's pre-retrofit state.

## Scope

**In scope:**
- **Users** (`users/index.blade.php`, `UserController::index()`): add real search (name/username) + multi-branch filter (reusing `branch-multiselect-filter.blade.php` via `list-filter-bar`'s existing `branchFilterBranches` slot — `User::branches()` already exists, same shape as `Customer::customerBranches`/`Mechanic::mechanicBranches`) + `empty-state`. Query logic is the same copy-and-substitute pattern already used for Mekanik in group 2, substituting `User::branches()` directly (a user's OWN branch memberships, not a pivot-through-another-model relation like Customer/Mekanik have — `whereHas('branches', ...)` instead of `whereHas('customerBranches'/'mechanicBranches', ...)`).

**Explicitly out of scope:**
- The Users detail page (`users/show.blade.php`, 3 tabs: Profil/Cabang/Permission) — matches the scope boundary already established in every prior sub-project in this track (Customer's `show.blade.php`, Mekanik's `show.blade.php`, and every create/edit form were all left untouched). It already inherits the global visual depth upgrades (card shadow, sidebar, navbar) automatically via the shared design tokens — no changes needed here.
- No `?q[]=x` regression fix is needed — unlike Cabang/Kendaraan/Sparepart, `UserController::index()` currently has zero search logic at all (this is new functionality, not a retrofit of pre-existing broken code), so the sanitize-once-in-controller pattern is applied correctly from the start with no legacy bug to fix.

## Users

`UserController::index()`:
```php
public function index()
{
    $this->authorize('user.view');

    $branchIds = collect(request('branch_ids', []))
        ->map(fn ($id) => (int) $id)
        ->intersect(auth()->user()->branches->pluck('id'))
        ->values()->all();

    $search = is_string(request('q')) ? trim(request('q')) : null;

    $users = User::orderBy('name')
        ->when($search, function ($query, $q) {
            $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', '%' . addcslashes($q, '%_\\') . '%')
                    ->orWhere('username', 'like', '%' . addcslashes($q, '%_\\') . '%');
            });
        })
        ->when($branchIds, fn ($query) => $query->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds)))
        ->simplePaginate(15)
        ->withQueryString();

    $userBranches = auth()->user()->branches;

    return view('users.index', compact('users'))
        ->with('branches', $userBranches)
        ->with('selectedBranchIds', $branchIds)
        ->with('search', $search);
}
```
Note: `whereHas('branches', ...)` filters against `User::branches()`, which already scopes to active pivot rows (`wherePivot('is_active', true)`) via the relation's own definition — no extra `where('is_active', true)` needed inside the closure (unlike Customer/Mekanik's `customerBranches`/`mechanicBranches`, which are the *pivot model* relations and need the pivot's `is_active` filtered explicitly since they're not pre-filtered belongsToMany relations). The column reference `branches.id` (not just `id`) is used to avoid ambiguity with `users.id` inside the `whereHas` join.

View retrofit mirrors `mechanics/index.blade.php`'s post-retrofit shape exactly — icon `bi-people`, title "Belum ada user", CTA "+ Tambah User Pertama", gated by `user.create`. The existing "Cabang Default" column (via `optional($user->defaultBranch())->name`) is unchanged.

## Testing

- `UserManagementTest` (check the existing file's exact name at plan time — likely `UserManagementTest.php`, following this project's `{Model}ManagementTest` convention): search by name filters results; search by username filters results; branch filter scopes to selected branch (using `User::branches()`, not a pivot-model relation — construct the test with `(new UserBranchService())->assign($user, $branch)` for both the acting admin and the target users being filtered, since branch membership here IS the filtered relation itself, not a separate pivot table); branch filter drops branch_ids the user isn't assigned to; empty state renders; empty-state CTA shown/hidden by `user.create`; filter bar renders.
- Full suite + explicit text-collision grep (standard practice for every sub-project in this track) — check "Belum ada user" and "Cari nama atau username..." (or whatever exact placeholder is chosen at plan time) against `AppShellTest`/`DashboardTest`.

## Execution

Recommend the same approach as groups 1-2 (inline execution in a worktree, zero fix rounds both times) — a single task should suffice given this is now the sixth application of an identical, fully-proven pattern with no new architectural wrinkle (unlike Sparepart's branch-switcher/`empty-state` extension in group 2). This closes out the UI redesign track entirely — no further sub-projects remain on the roadmap after this one.
