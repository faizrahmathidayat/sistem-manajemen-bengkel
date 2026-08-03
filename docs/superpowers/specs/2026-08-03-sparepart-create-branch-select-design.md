# Sparepart Baru — Branch Selection Design

Status: approved by user, ready for implementation plan

## Purpose

A small, targeted fix identified while reviewing the merged Master Sparepart screen (sub-project 4 of the UI redesign track): the "Sparepart Baru" create form (`sparepart-branches.create`) silently assumes the branch you're creating in is whichever branch happens to be active in the index page's session-backed switcher, with no way to pick a different one. Worse, `SparepartBranchController::create()` resolves that "current" branch via `sparepart.view` permission (`resolveCurrentBranch()` → `branchesWithPermission('sparepart.view')`), then separately checks `sparepart.create` on that exact branch and hard-`abort(403)`s if it fails — so a user with `sparepart.create` in Branch B but whose session happens to be parked on Branch A (where they only have `sparepart.view`) cannot create a sparepart at all, even though they have a perfectly valid branch to create in. This spec replaces the implicit single-branch assumption with an explicit dropdown scoped to branches the user can actually create in.

## Scope

**In scope:**
- `SparepartBranchController::create()`: instead of resolving one "current" branch and aborting if it lacks `sparepart.create`, compute the full list of branches the user has `sparepart.create` in (`auth()->user()->branchesWithPermission('sparepart.create')`). If that list is empty, show the existing `sparepart-branches.no-access` view (reusing the pattern already used by `index()` when `branchesWithPermission('sparepart.view')` is empty). Otherwise render the create form with that branch list.
- `resources/views/sparepart-branches/create.blade.php`: replace the `<input type="hidden" name="branch_id">` with a `<select name="branch_id">` populated from the branch list, defaulting to the branch currently active in the index page's session switcher (`session('current_sparepart_branch_id')`) if — and only if — that branch is one of the options; otherwise default to the first option. This preserves today's convenient default for the common case (user already browsing the branch they want to add to) while adding the ability to pick a different valid branch.
- `store()` and `StoreSparepartRequest` are unchanged — `authorize()` already re-validates `hasPermissionToInBranch('sparepart.create', $branchId)` against whatever `branch_id` is submitted, so allowing the user to pick from a `<select>` introduces no new security surface; the server-side check is identical whether `branch_id` arrived from a hidden field or a dropdown.
- After a successful `store()`, the index page's active branch context is **not** changed — `session('current_sparepart_branch_id')` is left exactly as it was before the create, even if the user picked a different branch in the dropdown. The user redirected back to `sparepart-branches.index` will see whatever branch was previously active, not necessarily the one they just created into. (Confirmed explicitly with the user — this is deliberate, not an oversight.)

**Explicitly out of scope:**
- `createExisting()` / `create-existing.blade.php` ("Tambah dari Cabang Lain") is **not** touched by this spec. That flow adds an *existing* sparepart identity to a branch's config, and its available-sparepart list (`Sparepart::whereDoesntHave('sparepartBranches', ...)`) is computed against one specific branch server-side at page-load time — making its branch selectable would require an AJAX re-fetch of the available-sparepart list on every branch change, which is a separate, larger piece of work the user did not ask for here.
- `index()`, `storeExisting()`, `edit()`, `update()`, `deactivate()`, `activate()` — untouched.
- No change to the underlying `sparepart.view` vs `sparepart.create` permission model, `hasPermissionToInBranch()`, or `branchesWithPermission()` itself (the latter is a pre-existing, already-generic method on `User` — this spec is its second caller, alongside `index()`/`resolveCurrentBranch()`'s `sparepart.view` usage, which remains unchanged).

## Design

`SparepartBranchController::create()`, replacing the current body:

```php
public function create()
{
    $branches = auth()->user()->branchesWithPermission('sparepart.create');

    if ($branches->isEmpty()) {
        return view('sparepart-branches.no-access');
    }

    $currentBranchId = session('current_sparepart_branch_id');
    $selectedBranch = $branches->firstWhere('id', $currentBranchId) ?? $branches->first();

    return view('sparepart-branches.create', compact('branches', 'selectedBranch'));
}
```

Note: this drops the `resolveCurrentBranch()` call and the `hasPermissionToInBranch('sparepart.create', ...)` + `abort(403)` pair entirely from this action — `branchesWithPermission('sparepart.create')` already only returns branches where that exact check passes, so filtering the list up front makes the separate abort unreachable dead code. `resolveCurrentBranch()` itself is not deleted (it's still used by `createExisting()`, which is out of scope).

`resources/views/sparepart-branches/create.blade.php`, replacing the hidden `branch_id` input and the `<h1>` (which currently hardcodes `{{ $branch->name }}` — `$branch` no longer exists, replaced by `$branches`/`$selectedBranch`):

```blade
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i>Sparepart Baru</h1>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('sparepart-branches.store') }}">
                @csrf
                <div class="mb-3">
                    <label for="branch_id" class="form-label">Cabang</label>
                    <select name="branch_id" id="branch_id" class="form-select @error('branch_id') is-invalid @enderror" required>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" {{ (int) old('branch_id', $selectedBranch->id) === $branch->id ? 'selected' : '' }}>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label for="code" class="form-label">Kode Sparepart</label>
                    <input type="text" name="code" id="code" value="{{ old('code') }}" class="form-control @error('code') is-invalid @enderror" maxlength="30" required>
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <!-- name / rack_number / selling_price / minimum_stock fields unchanged -->
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
                <a href="{{ route('sparepart-branches.index') }}" class="btn btn-outline-secondary">Batal</a>
            </form>
        </div>
    </div>
```

`old('branch_id', $selectedBranch->id)` follows the same fallback-to-default pattern already used by every other field on this form (`old('minimum_stock', 0)`), so a validation-error round-trip re-selects whatever the user actually submitted, not the original default.

## Testing

Extend the existing sparepart-branch test file (locate the exact class name at plan time — likely `SparepartBranchManagementTest` or similar, alongside `SparepartBranchAuthorizationTest`):
- `create()` renders the branch dropdown listing every branch the user has `sparepart.create` in (assert `assertSee` for each branch name, or check the option count/values directly against the response content).
- A user with `sparepart.create` in Branch B but not in Branch A (session parked on Branch A, e.g. because Branch A is where they have `sparepart.view`) can still load `/sparepart-branches/create` successfully and see Branch B in the dropdown — this is the regression test for the exact bug this spec fixes (previously: hard 403).
- Submitting `store()` with a `branch_id` the user does NOT have `sparepart.create` in is still rejected (403) — proves `StoreSparepartRequest::authorize()`'s existing re-validation isn't weakened by the dropdown.
- After a successful create into a non-session-default branch, `session('current_sparepart_branch_id')` is unchanged (still whatever it was before) — regression test for the "context does not switch" decision.
- A user with `sparepart.create` in zero branches still sees `sparepart-branches.no-access` at `/sparepart-branches/create` (mirrors the existing `index()` empty-access test, if one exists — check `SparepartBranchAuthorizationTest` for the equivalent `index()` case and mirror its shape).

## Execution

Single task, inline execution recommended — this is a small, single-file-pair change (one controller method, one view) with no new architectural pattern, smaller in scope than any prior sub-project in the UI redesign track.
