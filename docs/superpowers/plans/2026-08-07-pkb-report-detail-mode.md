# Laporan PKB — Mode Tampilan Detail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Tampilan" toggle (`mode=rekap` default / `mode=detail`) to the existing Laporan PKB report, so users can switch between the current aggregated per-PKB table and a new line-item table showing every jasa/sparepart line that makes up each PKB, without leaving the page.

**Architecture:** Extends the existing `PkbReportController@index` (no new controller, no new route) with a `$mode` request param that branches which eager-loads/aggregates are attached to the paginated `WorkOrder` query, and passes `mode` to the existing `reports.pkb.index` view. The view gains a second, conditionally-rendered table block for Detail mode inside the same file — no new Blade files. Design doc: `docs/superpowers/specs/2026-08-07-pkb-report-detail-mode-design.md`.

**Tech Stack:** Laravel 8.75, PHP 7.4, MySQL 8 (tests run against real MySQL — `phpunit.xml` sets `DB_CONNECTION=mysql`, `DB_DATABASE=bengkel_testing`), Blade + Bootstrap 5, no SPA/build step.

## Global Constraints

- PHP 7.4.33 — no PHP 8+ syntax anywhere (no `match`, no named arguments, no constructor promotion).
- `$mode` is derived with a strict allow-list, never trusted raw: `$mode = request('mode') === 'detail' ? 'detail' : 'rekap';` — any other value (missing, empty, garbage) silently falls back to `rekap` (design Decision 1).
- Item names in Detail mode come from the line tables' own denormalized snapshot columns — `work_order_service_lines.description` and `work_order_sparepart_lines.item_name_snapshot` — **not** from eager-loading `serviceCatalog` or `sparepartBranch.sparepart`. Those relations are never touched by this feature (design Decision 2).
- Eager-loading/aggregation is mutually exclusive per mode: Rekap mode attaches `withSum('serviceLines as subtotal_service', 'line_total')` + `withSum('sparepartLines as subtotal_sparepart', 'line_total')` and never loads the full `serviceLines`/`sparepartLines` collections; Detail mode attaches `with(['serviceLines', 'sparepartLines'])` and never runs the two `withSum` subqueries (design Decision 3).
- Pagination stays `simplePaginate(15)` on `WorkOrder` in both modes — never re-paginate by line-row count (design Decision 4).
- Summary cards (`total_pkb`/`total_completed`/`total_value`) are computed identically in both modes, from the same `(clone $query)->selectRaw(...)` that already exists — this plan does not touch that block at all.
- Detail-mode table rows repeat the PKB-identifying columns (No. PKB, Tanggal, Customer & Kendaraan, Status) on every line row — no `rowspan` (design Decision 5; no `rowspan` pattern exists anywhere else in `resources/views`, don't introduce one here).
- A PKB with zero lines in both collections still renders exactly one row in Detail mode, with `—` in the item columns — it must never silently disappear from the table (design Decision 6).
- Status badge markup in Detail mode must exactly mirror the existing Rekap mapping already in `reports/pkb/index.blade.php` (`draft`→`status-inactive` "Draft", `open`→`status-active` "Dikonfirmasi", `shortage`→`status-warning` "Kurang Stok", `completed`→`status-active` "Selesai", `cancelled`→`status-danger` "Dibatalkan") — same classes as `work-orders/index.blade.php:43-52`.
- No new Blade partial files — both tables live in the existing `resources/views/reports/pkb/index.blade.php`, switched by a single `@if ($mode === 'detail') ... @else ... @endif` block (design Decision 7).
- Filter form, summary cards, empty-state partial, and pagination links are shared unchanged between modes — only the results table swaps (design Decision 8).

---

## Task 1: Controller `$mode` param, conditional eager-loading, backend tests

**Files:**
- Modify: `app/Http/Controllers/PkbReportController.php`
- Test: `tests/Feature/PkbReportControllerTest.php` (append)

**Interfaces:**
- Consumes: existing `$query` (branch/status/mechanic/date filters, unchanged), `WorkOrder::serviceLines()`/`sparepartLines()` relations (already defined, unchanged).
- Produces: view data key `mode` (string, `'rekap'` or `'detail'`) added to the existing `reports.pkb.index` payload — Task 2's view reads this to decide which table block to render and which `<option>` is `selected` in the new filter field. `workOrders` rows carry either `subtotal_service`/`subtotal_sparepart` attributes (Rekap) or loaded `serviceLines`/`sparepartLines` collections (Detail), never both.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PkbReportControllerTest.php` (inside the `PkbReportControllerTest` class, after `test_index_shows_empty_state_when_no_results_match_filter`):

```php
    public function test_index_defaults_to_rekap_mode_and_does_not_eager_load_line_collections(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
        $response->assertViewHas('workOrders', function ($workOrders) {
            $workOrder = $workOrders->first();

            return $workOrder->relationLoaded('serviceLines') === false
                && $workOrder->relationLoaded('sparepartLines') === false
                && array_key_exists('subtotal_service', $workOrder->getAttributes())
                && array_key_exists('subtotal_sparepart', $workOrder->getAttributes());
        });
    }

    public function test_index_detail_mode_eager_loads_line_collections_and_skips_subtotal_sums(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?mode=detail');

        $response->assertOk();
        $response->assertViewHas('mode', 'detail');
        $response->assertViewHas('workOrders', function ($workOrders) {
            $workOrder = $workOrders->first();

            return $workOrder->relationLoaded('serviceLines') === true
                && $workOrder->relationLoaded('sparepartLines') === true
                && ! array_key_exists('subtotal_service', $workOrder->getAttributes())
                && ! array_key_exists('subtotal_sparepart', $workOrder->getAttributes());
        });
    }

    public function test_index_invalid_mode_value_falls_back_to_rekap(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?mode=bogus');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
    }

    public function test_index_detail_mode_loaded_lines_carry_expected_snapshot_fields(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?mode=detail');

        $response->assertOk();
        $response->assertViewHas('workOrders', function ($workOrders) {
            $workOrder = $workOrders->first();
            $serviceLine = $workOrder->serviceLines->first();
            $sparepartLine = $workOrder->sparepartLines->first();

            return $serviceLine->description === 'Ganti Oli'
                && (float) $serviceLine->line_total === 100000.0
                && $sparepartLine->item_name_snapshot === 'Oli Mesin'
                && (float) $sparepartLine->line_total === 60000.0;
        });
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/PkbReportControllerTest.php`

Expected: the 4 new tests FAIL — `assertViewHas('mode', ...)` fails because the view is never passed a `mode` key today.

- [ ] **Step 3: Implement `$mode` and conditional eager-loading in the controller**

Edit `app/Http/Controllers/PkbReportController.php`. Add `$mode` derivation after the existing `$dateTo` line, and replace the `$workOrders` block and the final `view()` call:

```php
        $dateFrom = $this->parseDate(request('date_from'));
        $dateTo = $this->parseDate(request('date_to'));

        $mode = request('mode') === 'detail' ? 'detail' : 'rekap';

        $query = WorkOrder::query()
```

Replace the existing `$workOrders = ...` block (currently lines 61-67) with:

```php
        $workOrders = $query->with(['branch', 'customer', 'vehicle', 'mechanic']);

        if ($mode === 'detail') {
            $workOrders->with(['serviceLines', 'sparepartLines']);
        } else {
            $workOrders->withSum('serviceLines as subtotal_service', 'line_total')
                ->withSum('sparepartLines as subtotal_sparepart', 'line_total');
        }

        $workOrders = $workOrders->orderByDesc('work_order_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();
```

Replace the final `return view(...)` call with:

```php
        return view('reports.pkb.index', [
            'workOrders' => $workOrders,
            'summary' => $summary,
            'branches' => $permittedBranches,
            'selectedBranchIds' => $branchIds,
            'mechanicSearch' => $mechanicSearch,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'mode' => $mode,
        ]);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/PkbReportControllerTest.php`

Expected: all tests (the 4 new ones plus the pre-existing 13) PASS. The pre-existing tests must keep passing unmodified — they never send a `mode` param, so they exercise the unchanged Rekap default path.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`

Expected: all tests PASS (657 + 4 = 661), no regressions elsewhere.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PkbReportController.php tests/Feature/PkbReportControllerTest.php
git commit -m "feat: add mode param to pkb report for detail line-item eager loading"
```

---

## Task 2: UI — Tampilan filter, Detail table, browser verification

**Files:**
- Modify: `resources/views/reports/pkb/index.blade.php`
- Test: `tests/Feature/PkbReportControllerTest.php` (append)

**Interfaces:**
- Consumes: `mode` (string, from Task 1), `workOrders` (in Detail mode: each row has loaded `serviceLines`/`sparepartLines` collections; in Rekap mode: unchanged from before this plan).
- Produces: no new interfaces — this is the final task in the plan.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PkbReportControllerTest.php`:

```php
    public function test_index_renders_tampilan_filter_with_rekap_selected_by_default(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertSee('name="mode"', false);
        $response->assertSee('<option value="rekap" selected>Rekap</option>', false);
    }

    public function test_index_detail_mode_shows_line_item_columns_and_rows(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?mode=detail');

        $response->assertOk();
        $response->assertSee('Tipe Item');
        $response->assertSee('Nama Item/Jasa');
        $response->assertSee('Subtotal Line');
        $response->assertSee('Jasa');
        $response->assertSee('Sparepart');
        $response->assertSee('Ganti Oli');
        $response->assertSee('Oli Mesin');
        $response->assertSee('100.000');
        $response->assertSee('60.000');
    }

    public function test_index_rekap_mode_does_not_show_detail_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::COMPLETED, 100000, 60000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb');

        $response->assertOk();
        $response->assertDontSee('Tipe Item');
        $response->assertDontSee('Nama Item/Jasa');
        $response->assertSee('Subtotal Jasa');
        $response->assertSee('Subtotal Sparepart');
    }

    public function test_index_detail_mode_shows_placeholder_row_for_work_order_with_no_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $scenario = $this->makeScenario($branch);
        $draft = $this->makeWorkOrder($branch, $scenario, WorkOrderStatus::DRAFT, 0, 0);
        $draft->serviceLines()->delete();
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?mode=detail');

        $response->assertOk();
        $response->assertSee($draft->number);
        $response->assertSee('—');
    }

    public function test_index_detail_mode_shows_empty_state_when_no_results_match_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.pkb.view');

        $response = $this->actingAs($viewer)->get('/reports/pkb?mode=detail');

        $response->assertOk();
        $response->assertSee('Tidak ada PKB yang cocok dengan filter saat ini.');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/PkbReportControllerTest.php`

Expected: the 5 new tests FAIL — no "Tampilan" field, no "Tipe Item"/"Nama Item/Jasa"/"Subtotal Line" headers exist yet.

- [ ] **Step 3: Add the "Tampilan" filter field**

Edit `resources/views/reports/pkb/index.blade.php`. Add a new field inside the existing `<form>`, right after the "Sampai" (`date_to`) column and before the submit button column:

```blade
                <div class="col-md-2">
                    <label class="form-label small">Tampilan</label>
                    <select name="mode" class="form-select form-select-sm">
                        <option value="rekap" {{ $mode === 'rekap' ? 'selected' : '' }}>Rekap</option>
                        <option value="detail" {{ $mode === 'detail' ? 'selected' : '' }}>Detail</option>
                    </select>
                </div>
```

- [ ] **Step 4: Wrap the existing table in `@if ($mode === 'detail') ... @else ... @endif` and add the Detail table**

Replace the entire `<div class="card"> ... </div>` block that contains the results table (currently the block starting at `<div class="card">` right after the summary cards `</div>` and ending at its matching `</div>`) with:

```blade
    <div class="card">
        <div class="table-responsive">
        @if ($mode === 'detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. PKB</th>
                        <th>Tanggal</th>
                        <th>Customer &amp; Kendaraan</th>
                        <th>Tipe Item</th>
                        <th>Nama Item/Jasa</th>
                        <th>Qty</th>
                        <th>Harga Satuan</th>
                        <th>Subtotal Line</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($workOrders as $workOrder)
                        @php
                            switch ($workOrder->status) {
                                case \App\Support\WorkOrderStatus::DRAFT:
                                    $statusBadge = '<span class="status-dot status-inactive">Draft</span>';
                                    break;
                                case \App\Support\WorkOrderStatus::OPEN:
                                    $statusBadge = '<span class="status-dot status-active">Dikonfirmasi</span>';
                                    break;
                                case \App\Support\WorkOrderStatus::SHORTAGE:
                                    $statusBadge = '<span class="status-dot status-warning">Kurang Stok</span>';
                                    break;
                                case \App\Support\WorkOrderStatus::COMPLETED:
                                    $statusBadge = '<span class="status-dot status-active">Selesai</span>';
                                    break;
                                default:
                                    $statusBadge = '<span class="status-dot status-danger">Dibatalkan</span>';
                            }
                            $lines = $workOrder->serviceLines->map(function ($line) {
                                return ['type' => 'Jasa', 'name' => $line->description, 'qty' => $line->qty, 'price' => $line->unit_price, 'total' => $line->line_total];
                            })->concat($workOrder->sparepartLines->map(function ($line) {
                                return ['type' => 'Sparepart', 'name' => $line->item_name_snapshot, 'qty' => $line->qty, 'price' => $line->unit_price, 'total' => $line->line_total];
                            }));
                        @endphp
                        @if ($lines->isEmpty())
                            <tr>
                                <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                                <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                                <td>
                                    {{ $workOrder->customer->name }}<br>
                                    <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                                </td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>{!! $statusBadge !!}</td>
                            </tr>
                        @else
                            @foreach ($lines as $line)
                                <tr>
                                    <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                                    <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                                    <td>
                                        {{ $workOrder->customer->name }}<br>
                                        <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                                    </td>
                                    <td>{{ $line['type'] }}</td>
                                    <td>{{ $line['name'] }}</td>
                                    <td>{{ number_format($line['qty'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($line['price'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($line['total'], 0, ',', '.') }}</td>
                                    <td>{!! $statusBadge !!}</td>
                                </tr>
                            @endforeach
                        @endif
                    @empty
                        <tr>
                            <td colspan="9" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-bar-graph',
                                    'title' => 'Belum ada data PKB',
                                    'description' => 'Tidak ada PKB yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. PKB</th>
                        <th>Tanggal</th>
                        <th>Customer &amp; Kendaraan</th>
                        <th>Mekanik</th>
                        <th>Subtotal Jasa</th>
                        <th>Subtotal Sparepart</th>
                        <th>Grand Total</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($workOrders as $workOrder)
                        @php($subtotalService = $workOrder->subtotal_service ?? 0)
                        @php($subtotalSparepart = $workOrder->subtotal_sparepart ?? 0)
                        <tr>
                            <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                            <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                            <td>
                                {{ $workOrder->customer->name }}<br>
                                <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                            </td>
                            <td>{{ $workOrder->mechanic->name }}</td>
                            <td>{{ number_format($subtotalService, 0, ',', '.') }}</td>
                            <td>{{ number_format($subtotalSparepart, 0, ',', '.') }}</td>
                            <td>{{ number_format($subtotalService + $subtotalSparepart, 0, ',', '.') }}</td>
                            <td>
                                @if ($workOrder->status === \App\Support\WorkOrderStatus::DRAFT)
                                    <span class="status-dot status-inactive">Draft</span>
                                @elseif ($workOrder->status === \App\Support\WorkOrderStatus::OPEN)
                                    <span class="status-dot status-active">Dikonfirmasi</span>
                                @elseif ($workOrder->status === \App\Support\WorkOrderStatus::SHORTAGE)
                                    <span class="status-dot status-warning">Kurang Stok</span>
                                @elseif ($workOrder->status === \App\Support\WorkOrderStatus::COMPLETED)
                                    <span class="status-dot status-active">Selesai</span>
                                @else
                                    <span class="status-dot status-danger">Dibatalkan</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-bar-graph',
                                    'title' => 'Belum ada data PKB',
                                    'description' => 'Tidak ada PKB yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @endif
        </div>
    </div>
```

Note: `&mdash;` renders as `—`, matching what the Task 1-derived tests expect via `assertSee('—')`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/PkbReportControllerTest.php`

Expected: all tests (5 new + 17 from Task 1 and before) PASS.

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`

Expected: all tests PASS (661 + 5 = 666), no regressions.

- [ ] **Step 7: Manual browser verification**

Using the dev server and real existing PKB data in the dev database (same approach as the base report's verification — no new synthetic fixtures needed unless the existing 6 PKBs don't already cover a multi-line-type case):
1. Log in as a user with `report.pkb.view` on at least one branch (reuse or recreate a short-lived demo user + grant, cleaned up after).
2. Load `/reports/pkb` — confirm "Tampilan" shows "Rekap" selected, table unchanged from before this plan.
3. Switch "Tampilan" to "Detail" and submit — confirm the table now shows 9 columns, with one row per jasa/sparepart line, correct Tipe Item labels, correct item names (matching what `work-orders/show.blade.php` shows for the same PKB), correct Qty/Harga Satuan/Subtotal Line values.
4. Confirm existing filters (Cabang, Status, Mekanik, Tanggal) still narrow results correctly while in Detail mode, and that switching back to "Rekap" with the same filters still applied shows the same narrowed PKB set in the aggregated table.
5. Confirm a PKB with no lines of a given type (e.g. no sparepart lines) shows Jasa rows only, and if it genuinely has zero lines at all, shows the single `—` placeholder row.
6. Confirm the empty-state renders correctly in Detail mode when filters match nothing.
7. Clean up any demo user/permission created for verification; leave existing PKB data untouched.

- [ ] **Step 8: Commit**

```bash
git add resources/views/reports/pkb/index.blade.php tests/Feature/PkbReportControllerTest.php
git commit -m "feat: add detail line-item view mode to pkb report"
```

---

## Final Step

After Task 2 passes and the full suite is green, report the final test count and a short end-to-end summary (what shipped, what's next). Do not start any of the remaining 3 Laporan placeholders (Laporan Invoice, PKB vs Invoice, Laporan Sparepart) or any other scope without explicit user instruction.
