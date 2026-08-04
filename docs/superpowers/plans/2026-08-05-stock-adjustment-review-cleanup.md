# Stock Adjustment — Deferred Minor Findings Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 4 deferred Minor findings from migration 008b (Stock Adjustment)'s final whole-branch review, all confirmed non-blocking cosmetic/coverage gaps at merge time, now requested by the user before starting 008c: (1) add a project's first error-flash convention and use it for the reservation-rejection message, (2) fix `update()`'s silent-success-on-lost-race message to match the pattern already used in the module's other 4 lifecycle actions, (3) tighten a test assertion that couldn't actually detect a removed status-badge partial, (4) make the status-badge partial's fallback branch explicit instead of a silent catch-all.

**Architecture:** No new tables, no new routes, no new permission codes. Two of the four fixes are one-line-shaped (test assertion, Blade `@else`→`@elseif`); the other two follow patterns already proven elsewhere in this codebase (the layout gains a second flash block parallel to its existing one; `update()` adopts the exact hoisted-boolean-flag shape already used by `submit()`/`approve()`/`post()`/`cancel()` in the same file).

**Tech Stack:** Laravel 8.75, PHP 7.4.33 (no PHP 8 syntax), PHPUnit feature tests (`RefreshDatabase`).

## Global Constraints

- PHP runtime is 7.4.33 — no PHP 8-only syntax anywhere.
- The new `session('error')` flash key is used ONLY for `StockAdjustmentController::post()`'s reservation-rejection message. Every other flash in this controller (success messages, and the "no longer in status X" lost-race messages on `submit`/`approve`/`post`/`cancel`/`update`) continues to use `session('status')` exactly as before — those are not user errors, just accurate status reporting.
- Do not retrofit any other controller (`WorkOrderController`, `GoodsReceiptController`) to use the new `error` key — out of scope, confirmed with the user.
- The status-badge partial's new fallback branch must not throw an exception — one bad/future status must not break an entire list page render. It must render a visually distinct "unknown" badge, not silently reuse the "Dibatalkan" label.
- Do not change the meaning or wording of any EXISTING flash message except the one reservation-rejection message being moved from `status` to `error` — this is a surgical fix, not a wording pass.

---

### Task 1: Error-flash convention, update() message fix, badge test + partial hardening

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `app/Http/Controllers/StockAdjustmentController.php`
- Modify: `resources/views/stock-adjustments/_status_badge.blade.php`
- Modify: `tests/Feature/StockAdjustmentManagementTest.php`

**Interfaces:**
- Consumes: nothing new — all classes/routes already exist from migration 008b.
- Produces: nothing consumed by later work in this plan (single-task plan).

- [ ] **Step 1: Write the failing tests first**

Add these 3 new test methods to `tests/Feature/StockAdjustmentManagementTest.php` (place them near the existing `test_post_rejects_the_whole_batch_*` tests, e.g. right after `test_post_rejects_the_whole_batch_even_when_only_one_of_multiple_lines_violates_reserved_qty`):

```php
    public function test_post_rejection_message_uses_the_error_flash_key_not_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $sparepartBranch->stock()->update(['reserved_qty' => 8]);
        $poster = User::factory()->create();
        $this->grantBranchPermission($poster, $branch, 'stock_adjustment.post');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::APPROVED,
        ]);
        \App\Models\StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 5, 'adjustment_qty' => -5, 'reason' => 'Rusak',
        ]);

        $response = $this->actingAs(User::find($poster->id))->patch("/stock-adjustments/{$stockAdjustment->id}/post");

        $response->assertSessionHas('error', function ($message) {
            return str_contains($message, 'OLI-01') && str_contains($message, 'PKB terkait');
        });
        $response->assertSessionMissing('status');
    }

    public function test_update_second_call_with_a_stale_in_memory_status_flashes_an_accurate_message(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, '', 10);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');
        $this->actingAs(User::find($user->id))->post('/stock-adjustments', $this->baseStorePayload($branch, $sparepartBranch));
        $stockAdjustment = StockAdjustment::first();
        $this->actingAs(User::find($user->id));
        // Mirrors test_submit_second_call_with_a_stale_in_memory_status_flashes_an_accurate_message:
        // two requests both loaded the record while it was still DRAFT; the controller must
        // re-check status from a locked, freshly-read row inside the transaction, and the losing
        // call's response must say so instead of falsely claiming an update happened.
        $staleOne = StockAdjustment::find($stockAdjustment->id);
        $staleTwo = StockAdjustment::find($stockAdjustment->id);
        $updatePayload = [
            'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Revisi',
            'lines' => [['sparepart_branch_id' => $sparepartBranch->id, 'physical_qty' => 9, 'reason' => 'x']],
        ];

        $controller = app(\App\Http\Controllers\StockAdjustmentController::class);
        $updateRequestOne = \App\Http\Requests\UpdateStockAdjustmentRequest::create(
            "/stock-adjustments/{$stockAdjustment->id}", 'PUT', $updatePayload
        );
        $updateRequestOne->setContainer(app())->setRouteResolver(fn () => (new \Illuminate\Routing\Route('PUT', '/stock-adjustments/{stockAdjustment}', []))->bind($updateRequestOne)->setParameter('stockAdjustment', $staleOne));
        $controller->update($updateRequestOne, $staleOne);
        // First call succeeds and moves the document past DRAFT for the purposes of this test by
        // directly flipping status — simulating a concurrent submit() winning the race instead of
        // re-deriving the full FormRequest plumbing a second time for an equivalent scenario.
        StockAdjustment::whereKey($stockAdjustment->id)->update(['status' => StockAdjustmentStatus::PENDING_APPROVAL]);

        $updateRequestTwo = \App\Http\Requests\UpdateStockAdjustmentRequest::create(
            "/stock-adjustments/{$stockAdjustment->id}", 'PUT', $updatePayload
        );
        $updateRequestTwo->setContainer(app())->setRouteResolver(fn () => (new \Illuminate\Routing\Route('PUT', '/stock-adjustments/{stockAdjustment}', []))->bind($updateRequestTwo)->setParameter('stockAdjustment', $staleTwo));
        $controller->update($updateRequestTwo, $staleTwo);

        $this->assertStringContainsString('sudah tidak dalam status draft', session('status'));
    }

    public function test_status_badge_partial_shows_unknown_label_for_an_unrecognized_status(): void
    {
        $view = $this->blade(
            "@include('stock-adjustments._status_badge', ['status' => \$status])",
            ['status' => 'some_future_status_not_yet_handled']
        );

        $view->assertSee('Status tidak dikenal');
    }
```

**Note on the `test_update_second_call_...` test**: if constructing `UpdateStockAdjustmentRequest` manually proves awkward in practice (Laravel's `FormRequest::create()` + manual route binding can be fiddly), an acceptable alternative is to call the controller's `update()` method directly with a plain array cast to a stub matching what `$request->validated()` would return, OR to drop the manual-request approach entirely and instead prove the same thing through a real HTTP PUT with a slightly different race simulation: issue a real `PUT` request, and BEFORE it hits the controller, use a middleware-free trick — actually, the simplest reliable approach is: perform a real `PUT` via `$this->put(...)`, but first manually flip the record's status in the DB (`StockAdjustment::whereKey($stockAdjustment->id)->update(['status' => StockAdjustmentStatus::PENDING_APPROVAL])`) so that by the time the real HTTP request's `authorize()`/Policy check runs against the ALREADY-updated DB row, `UpdateStockAdjustmentRequest::authorize()` (which calls `$this->user()->can('update', $this->route('stockAdjustment'))`) will itself return `false` since Policy already denies non-DRAFT — which tests the Policy layer, not the controller's in-transaction race window specifically. Since the Policy-layer denial is a DIFFERENT (also real, but earlier) protection than the in-transaction hoisted-flag, prefer the manual-request/direct-controller-call approach shown above to specifically exercise the race window past the Policy check; if that proves too awkward to get working, a simpler acceptable substitute is to directly unit-test the flag logic by temporarily disabling/bypassing the Policy check (e.g. via `Gate::before` override in the test) — use your judgment on whichever gets a real, passing, meaningful test with the least fuss, and note in your report which approach you used and why.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockAdjustmentManagementTest`
Expected: `test_post_rejection_message_uses_the_error_flash_key_not_status` FAILS (current code flashes `status`, not `error`). `test_update_second_call_with_a_stale_in_memory_status_flashes_an_accurate_message` FAILS or errors (no hoisted flag exists yet in `update()`, so the message won't match). `test_status_badge_partial_shows_unknown_label_for_an_unrecognized_status` FAILS (partial doesn't have this branch yet).

Also update the 2 PRE-EXISTING tests that currently assert the rejection message via `status` — they must be changed as part of this same step (not left broken):
- `test_post_rejects_the_whole_batch_when_any_line_physical_qty_is_below_reserved_qty` (around line 611-613): change `$response->assertSessionHas('status', function ($message) {...})` to `$response->assertSessionHas('error', function ($message) {...})` and add `$response->assertSessionMissing('status');` right after.
- `test_post_rejects_the_whole_batch_even_when_only_one_of_multiple_lines_violates_reserved_qty`: this one doesn't currently assert on the flash key at all (only on DB state), so no change needed there — but double check by reading the current test body first.

- [ ] **Step 3: Add the error-flash block to the shared layout**

In `resources/views/layouts/app.blade.php`, immediately after the existing block:

```blade
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif
```

add:

```blade
            @if (session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif
```

- [ ] **Step 4: Change the reservation-rejection flash key in the controller**

In `app/Http/Controllers/StockAdjustmentController.php`, inside `post()`, find:

```php
        if (! empty($reservationViolations)) {
            $message = 'Tidak bisa memposting: ' . implode('; ', $reservationViolations) . '. Selesaikan atau batalkan PKB terkait dahulu.';

            return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', $message);
        }
```

Change `->with('status', $message)` to `->with('error', $message)`. Do not change anything else in this block or in any other `return redirect()->...->with('status', ...)` call in this file.

- [ ] **Step 5: Add the hoisted-flag pattern to `update()`**

In `app/Http/Controllers/StockAdjustmentController.php`, change `update()` from:

```php
    public function update(UpdateStockAdjustmentRequest $request, StockAdjustment $stockAdjustment)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $stockAdjustment) {
            $fresh = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->first();
            if ($fresh->status !== StockAdjustmentStatus::DRAFT) {
                return;
            }

            $fresh->update([
                'adjustment_date' => $data['adjustment_date'],
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($fresh, $data['lines']);
        });

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil diperbarui.');
    }
```

to:

```php
    public function update(UpdateStockAdjustmentRequest $request, StockAdjustment $stockAdjustment)
    {
        $data = $request->validated();

        $noLongerDraft = false;

        DB::transaction(function () use ($data, $stockAdjustment, &$noLongerDraft) {
            $fresh = StockAdjustment::whereKey($stockAdjustment->id)->lockForUpdate()->first();
            if ($fresh->status !== StockAdjustmentStatus::DRAFT) {
                $noLongerDraft = true;

                return;
            }

            $fresh->update([
                'adjustment_date' => $data['adjustment_date'],
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($fresh, $data['lines']);
        });

        if ($noLongerDraft) {
            return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment ini sudah tidak dalam status draft.');
        }

        return redirect()->route('stock-adjustments.show', $stockAdjustment)->with('status', 'Stock adjustment berhasil diperbarui.');
    }
```

This is byte-for-byte the same shape already used in `submit()` in this same file — copy that method's structure exactly, just adapted to `update()`'s existing body.

- [ ] **Step 6: Tighten the badge test assertion**

In `tests/Feature/StockAdjustmentManagementTest.php`, find `test_show_renders_status_badge_and_approval_info_when_approved` and change:

```php
        $response->assertSee('Disetujui');
```

to:

```php
        $response->assertSee('<span class="status-dot status-active">Disetujui</span>', false);
```

Leave `$response->assertSee('Budi Approver');` unchanged.

- [ ] **Step 7: Make the status-badge partial's fallback explicit**

Replace the full contents of `resources/views/stock-adjustments/_status_badge.blade.php` with:

```blade
@if ($status === \App\Support\StockAdjustmentStatus::DRAFT)
    <span class="status-dot status-active">Draft</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::PENDING_APPROVAL)
    <span class="status-dot status-active">Diajukan</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::APPROVED)
    <span class="status-dot status-active">Disetujui</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::POSTED)
    <span class="status-dot status-active">Diposting</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::CANCELLED)
    <span class="status-dot status-inactive">Dibatalkan</span>
@else
    <span class="status-dot status-inactive">Status tidak dikenal</span>
@endif
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --filter=StockAdjustmentManagementTest`
Expected: all tests in this file PASS, including the 3 new ones and the 1 updated pre-existing one.

- [ ] **Step 9: Run the full suite**

Run: `php artisan test`
Expected: 458 passed (455 baseline + 3 new; the 1 modified pre-existing test doesn't change the total count).

- [ ] **Step 10: Manual sanity check of the layout change**

Since this touches a shared layout used by every authenticated page, quickly grep for any other place that might read `session('error')` with different expectations, to make sure this key isn't already used for something else:

Run: `grep -rn "session('error')\|session(\"error\")" app/ resources/`
Expected: no matches before this change (confirming `error` is a genuinely unused key, not colliding with anything).

- [ ] **Step 11: Commit**

```bash
git add resources/views/layouts/app.blade.php app/Http/Controllers/StockAdjustmentController.php resources/views/stock-adjustments/_status_badge.blade.php tests/Feature/StockAdjustmentManagementTest.php
git commit -m "fix: add error-flash convention, correct update() race message, and harden status badge"
```

---

## Self-Review Notes

- **Spec coverage:** all 4 items from the design spec are covered by this single task.
- **Placeholder scan:** none found.
- **Type consistency:** `StockAdjustmentStatus` constants referenced identically to how the rest of the controller/views already use them.
- **Scope check:** appropriately small — 1 task, 4 files, no new tables/routes/permissions. The Step 1 test for `update()`'s race condition is flagged with explicit latitude for the implementer to choose the most reliable construction technique, since manually constructing a `FormRequest` is more fiddly than the other tests in this file and the plan author could not fully verify the exact manual-binding incantation compiles cleanly without running it — this is a deliberate, disclosed judgment call rather than an oversight.
