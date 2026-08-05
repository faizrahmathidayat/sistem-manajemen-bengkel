# Kartu Stok (Stock Card) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task (single task, inline execution — no subagent dispatch needed, decided by the user to conserve tokens for this small, read-only, non-concurrent piece of work). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the "Kartu Stok" (Stock Card) module — the remaining unbuilt piece of migration 008. A dedicated, read-only, paginated ledger page over the existing `inventory_movements` table, plus wiring the Dashboard's existing "Kartu Stok" tab (currently dummy data) to the same real data.

**Architecture:** No new tables, no new permission codes, no new business logic — pure read/report layer. Reuses the session-persisted branch-switcher already built for Master Sparepart (`current_sparepart_branch_id` session key, `sparepart-branches/_branch_switcher_select.blade.php` partial) rather than inventing a new one, since Kartu Stok is conceptually a drill-down from Master Sparepart's per-branch context.

**Tech Stack:** Laravel 8.75, PHP 7.4.33 (no PHP 8 syntax), PHPUnit feature tests (`RefreshDatabase`).

## Global Constraints

- PHP runtime is 7.4.33 — no PHP 8-only syntax anywhere.
- No new permission code — gate on `sparepart.view` (matching the sidebar placeholder's existing gating condition, unchanged).
- No new tables/migrations — query `inventory_movements` and `sparepart_branch_stocks` as they already exist.
- The mutation history table shows ONLY `inventory_movements` rows — never merge in `inventory_reservations` data into the same timeline (a deliberate decision from brainstorming: reservations don't move quantity, they're shown only as the existing live "Stok Reservasi" stat).
- Mutation history is ordered chronologically ascending (`orderBy('movement_at')->orderBy('id')`) — NOT descending like every other list page in this app. This is a deliberate exception, not an oversight — do not "fix" it to match other list pages' `orderByDesc` convention.
- `->simplePaginate(20)`, never `->paginate()`.
- Reuse the existing branch-switcher session key `current_sparepart_branch_id` and its partial (`sparepart-branches/_branch_switcher_select.blade.php`) as-is — do not create a new session key or a new switcher partial.
- Reference resolution (`reference_type`/`reference_id` → parent document number + link) must degrade gracefully: if the referenced line/document can't be found, show the raw `reference_type`/`reference_id` as plain text, never throw.

---

### Task 1: Stock Card page, reference resolution, sidebar wiring, and Dashboard tab real-data wiring

**Files:**
- Create: `app/Http/Controllers/StockCardController.php`
- Create: `resources/views/stock-card/index.blade.php`
- Create: `resources/views/stock-card/no-access.blade.php`
- Modify: `routes/web.php` (add a new `stock-card` route)
- Modify: `resources/views/partials/sidebar.blade.php` (swap the Kartu Stok placeholder for a real link)
- Modify: `app/Http/Controllers/DashboardController.php` (replace `dummyMutationRows()` usage with real data)
- Test: `tests/Feature/StockCardTest.php` (new)
- Modify: `tests/Feature/DashboardTest.php` (extend — verify real mutation data appears)
- Modify: `tests/Feature/AppShellTest.php` (extend `test_sidebar_shows_kartu_stok_placeholder_alongside_master_sparepart` with the load-bearing route assertion)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/StockCardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\GoodsReceiptStatus;
use App\Support\InventoryMovementType;
use App\Support\StockAdjustmentStatus;
use App\Support\TransferStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCardTest extends TestCase
{
    use RefreshDatabase;

    protected function grantBranchPermission(User $user, Branch $branch, string $code): void
    {
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    protected function makeSparepartBranch(Branch $branch): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);

        return SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
    }

    public function test_index_shows_no_access_page_without_any_sparepart_view_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/stock-card');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_index_renders_with_branch_and_sparepart_selected(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $response->assertSee('OLI-01');
    }

    public function test_index_shows_empty_state_when_selected_sparepart_has_no_movements(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $response->assertSee('Belum ada riwayat mutasi');
    }

    public function test_index_lists_movements_chronologically_ascending(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        InventoryMovement::create([
            'movement_at' => now()->subDays(2), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 10, 'qty_out' => 0,
            'balance_after' => 10, 'reference_type' => 'goods_receipt_line', 'reference_id' => 1,
        ]);
        InventoryMovement::create([
            'movement_at' => now()->subDay(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::ADJUSTMENT_OUT, 'qty_in' => 0, 'qty_out' => 3,
            'balance_after' => 7, 'reference_type' => 'stock_adjustment_line', 'reference_id' => 1,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $content = $response->getContent();
        // The RECEIPT row's balance (10) must render before the ADJUSTMENT_OUT
        // row's balance (7) in the raw HTML, proving ascending chronological
        // order rather than the app's usual descending one.
        $this->assertLessThan(strpos($content, '7'), strpos($content, '10'));
    }

    public function test_index_resolves_reference_to_goods_receipt_number_and_link(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $goodsReceipt = GoodsReceipt::create([
            'number' => 'PB/JKT/202608/00001', 'branch_id' => $branch->id, 'receipt_date' => now()->format('Y-m-d'),
            'status' => GoodsReceiptStatus::POSTED,
        ]);
        $line = GoodsReceiptLine::create([
            'goods_receipt_id' => $goodsReceipt->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'qty' => 10, 'purchase_price' => 40000, 'line_total' => 400000,
        ]);
        InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 10, 'qty_out' => 0,
            'balance_after' => 10, 'reference_type' => 'goods_receipt_line', 'reference_id' => $line->id,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $response->assertSee('PB/JKT/202608/00001');
        $response->assertSee(route('goods-receipts.show', $goodsReceipt), false);
    }

    public function test_index_resolves_reference_to_stock_adjustment_number_and_link(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $stockAdjustment = StockAdjustment::create([
            'number' => 'SA/JKT/202608/00001', 'branch_id' => $branch->id, 'adjustment_date' => now()->format('Y-m-d'),
            'reason' => 'Opname', 'status' => StockAdjustmentStatus::POSTED,
        ]);
        $line = StockAdjustmentLine::create([
            'stock_adjustment_id' => $stockAdjustment->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'system_qty' => 10, 'physical_qty' => 8, 'adjustment_qty' => -2, 'reason' => 'Rusak',
        ]);
        InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::ADJUSTMENT_OUT, 'qty_in' => 0, 'qty_out' => 2,
            'balance_after' => 8, 'reference_type' => 'stock_adjustment_line', 'reference_id' => $line->id,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $response->assertSee('SA/JKT/202608/00001');
        $response->assertSee(route('stock-adjustments.show', $stockAdjustment), false);
    }

    public function test_index_resolves_reference_to_stock_transfer_number_and_link(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $otherBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $sparepart = $sparepartBranch->sparepart;
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $otherBranch->id, 'selling_price' => 60000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $stockTransfer = StockTransfer::create([
            'number' => 'ST/BDG/202608/00001', 'from_branch_id' => $otherBranch->id, 'to_branch_id' => $branch->id,
            'transfer_date' => now()->format('Y-m-d'), 'status' => TransferStatus::RECEIVED,
        ]);
        $line = StockTransferLine::create(['stock_transfer_id' => $stockTransfer->id, 'sparepart_id' => $sparepart->id, 'qty' => 5]);
        InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => InventoryMovementType::TRANSFER_IN, 'qty_in' => 5, 'qty_out' => 0,
            'balance_after' => 5, 'reference_type' => 'stock_transfer_line', 'reference_id' => $line->id,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $response->assertSee('ST/BDG/202608/00001');
        $response->assertSee(route('stock-transfers.show', $stockTransfer), false);
    }

    public function test_index_paginates_at_twenty_rows(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        for ($i = 0; $i < 25; $i++) {
            InventoryMovement::create([
                'movement_at' => now()->subMinutes(25 - $i), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
                'movement_type' => InventoryMovementType::RECEIPT, 'qty_in' => 1, 'qty_out' => 0,
                'balance_after' => $i + 1, 'reference_type' => 'goods_receipt_line', 'reference_id' => $i + 1,
            ]);
        }

        $response = $this->actingAs(User::find($user->id))->get("/stock-card?branch_id={$branch->id}&sparepart_id={$sparepartBranch->sparepart_id}");

        $response->assertOk();
        $response->assertSee('Selanjutnya');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StockCardTest`
Expected: FAIL — controller/routes/views don't exist yet.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/StockCardController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockAdjustmentLine;
use App\Models\StockTransferLine;
use App\Support\InventoryMovementType;

class StockCardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $allowedBranches = $user->branchesWithPermission('sparepart.view');

        if ($allowedBranches->isEmpty()) {
            return view('stock-card.no-access');
        }

        $requestedBranchId = request('branch_id');
        if ($requestedBranchId && $allowedBranches->firstWhere('id', (int) $requestedBranchId)) {
            session(['current_sparepart_branch_id' => (int) $requestedBranchId]);
        }

        $currentBranch = $allowedBranches->firstWhere('id', session('current_sparepart_branch_id'))
            ?? $allowedBranches->first();
        session(['current_sparepart_branch_id' => $currentBranch->id]);

        $spareparts = Sparepart::where('is_active', true)
            ->whereHas('sparepartBranches', function ($query) use ($currentBranch) {
                $query->where('branch_id', $currentBranch->id)->where('is_active', true);
            })
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $requestedSparepartId = filter_var(request('sparepart_id'), FILTER_VALIDATE_INT) ?: null;
        $selectedSparepart = $requestedSparepartId
            ? $spareparts->firstWhere('id', $requestedSparepartId)
            : null;
        $selectedSparepart = $selectedSparepart ?? $spareparts->first();

        $sparepartBranch = $selectedSparepart
            ? SparepartBranch::where('sparepart_id', $selectedSparepart->id)->where('branch_id', $currentBranch->id)->first()
            : null;

        $stat = ['onHand' => 0.0, 'reserved' => 0.0, 'available' => 0.0];
        $movements = collect();

        if ($sparepartBranch) {
            $stock = $sparepartBranch->stock;
            $onHand = (float) $stock->on_hand_qty;
            $reserved = (float) $stock->reserved_qty;
            $stat = ['onHand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];

            $movements = InventoryMovement::where('sparepart_branch_id', $sparepartBranch->id)
                ->orderBy('movement_at')
                ->orderBy('id')
                ->simplePaginate(20)
                ->withQueryString();

            $movements->getCollection()->transform(function (InventoryMovement $movement) {
                return $this->decorateMovement($movement);
            });
        }

        return view('stock-card.index', [
            'allowedBranches' => $allowedBranches,
            'currentBranch' => $currentBranch,
            'spareparts' => $spareparts,
            'selectedSparepart' => $selectedSparepart,
            'stat' => $stat,
            'movements' => $movements,
        ]);
    }

    protected function decorateMovement(InventoryMovement $movement): array
    {
        $typeLabels = [
            InventoryMovementType::RECEIPT => 'Penerimaan',
            InventoryMovementType::ADJUSTMENT_IN => 'Penyesuaian Masuk',
            InventoryMovementType::ADJUSTMENT_OUT => 'Penyesuaian Keluar',
            InventoryMovementType::TRANSFER_IN => 'Transfer Masuk',
            InventoryMovementType::TRANSFER_OUT => 'Transfer Keluar',
        ];

        return [
            'movement_at' => $movement->movement_at,
            'type_label' => $typeLabels[$movement->movement_type] ?? $movement->movement_type,
            'reference' => $this->resolveReference($movement->reference_type, $movement->reference_id),
            'qty_in' => (float) $movement->qty_in,
            'qty_out' => (float) $movement->qty_out,
            'balance_after' => (float) $movement->balance_after,
        ];
    }

    protected function resolveReference(string $referenceType, int $referenceId): array
    {
        switch ($referenceType) {
            case 'goods_receipt_line':
                $line = GoodsReceiptLine::with('goodsReceipt')->find($referenceId);
                if ($line && $line->goodsReceipt) {
                    return ['number' => $line->goodsReceipt->number, 'route' => route('goods-receipts.show', $line->goodsReceipt)];
                }
                break;
            case 'stock_adjustment_line':
                $line = StockAdjustmentLine::with('stockAdjustment')->find($referenceId);
                if ($line && $line->stockAdjustment) {
                    return ['number' => $line->stockAdjustment->number, 'route' => route('stock-adjustments.show', $line->stockAdjustment)];
                }
                break;
            case 'stock_transfer_line':
                $line = StockTransferLine::with('stockTransfer')->find($referenceId);
                if ($line && $line->stockTransfer) {
                    return ['number' => $line->stockTransfer->number, 'route' => route('stock-transfers.show', $line->stockTransfer)];
                }
                break;
        }

        return ['number' => "{$referenceType} #{$referenceId}", 'route' => null];
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the `use App\Http\Controllers\StockCardController;` import near the other controller imports, and add this route inside the authenticated middleware group (near the `sparepart-branches` group):

```php
    Route::get('/stock-card', [StockCardController::class, 'index'])->name('stock-card.index');
```

- [ ] **Step 5: Create the views**

Create `resources/views/stock-card/no-access.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Kartu Stok')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-card-list me-2"></i>Kartu Stok</h1>
    </div>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            Anda belum memiliki akses kartu stok di cabang manapun. Hubungi admin untuk meminta akses.
        </div>
    </div>
@endsection
```

Create `resources/views/stock-card/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Kartu Stok')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-card-list me-2"></i>Kartu Stok</h1>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('stock-card.index') }}" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Cabang</label>
                    <select name="branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        @foreach ($allowedBranches as $branch)
                            <option value="{{ $branch->id }}" {{ $branch->id === $currentBranch->id ? 'selected' : '' }}>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small mb-1">Sparepart</label>
                    <select name="sparepart_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        @forelse ($spareparts as $sparepart)
                            <option value="{{ $sparepart->id }}" {{ $selectedSparepart && $sparepart->id === $selectedSparepart->id ? 'selected' : '' }}>
                                {{ $sparepart->code }} &mdash; {{ $sparepart->name }}
                            </option>
                        @empty
                            <option value="">Belum ada sparepart di cabang ini</option>
                        @endforelse
                    </select>
                </div>
            </form>
        </div>
    </div>

    @if ($selectedSparepart)
        <div class="row g-3 mb-3">
            <div class="col-sm-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-value">{{ number_format($stat['onHand'], 0, ',', '.') }}</div>
                        <div class="stat-label">Stok Fisik</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-value">{{ number_format($stat['reserved'], 0, ',', '.') }}</div>
                        <div class="stat-label">Stok Reservasi</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-value" style="color: var(--color-success);">{{ number_format($stat['available'], 0, ',', '.') }}</div>
                        <div class="stat-label">Stok Tersedia</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <th>Tipe Mutasi</th>
                            <th>Referensi</th>
                            <th class="text-end">Masuk</th>
                            <th class="text-end">Keluar</th>
                            <th class="text-end">Saldo Akhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($movements as $row)
                            <tr>
                                <td>{{ $row['movement_at']->format('d/m/Y H:i') }}</td>
                                <td><span class="status-dot status-active">{{ $row['type_label'] }}</span></td>
                                <td>
                                    @if ($row['reference']['route'])
                                        <a href="{{ $row['reference']['route'] }}"><code>{{ $row['reference']['number'] }}</code></a>
                                    @else
                                        <code>{{ $row['reference']['number'] }}</code>
                                    @endif
                                </td>
                                <td class="text-end">{{ $row['qty_in'] > 0 ? number_format($row['qty_in'], 0, ',', '.') : '-' }}</td>
                                <td class="text-end">{{ $row['qty_out'] > 0 ? number_format($row['qty_out'], 0, ',', '.') : '-' }}</td>
                                <td class="text-end">{{ number_format($row['balance_after'], 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-0">
                                    @include('partials.empty-state', [
                                        'icon' => 'bi-card-list',
                                        'title' => 'Belum ada riwayat mutasi',
                                        'description' => 'Sparepart ini belum pernah mengalami mutasi stok di cabang ini.',
                                        'ctaVisible' => false,
                                    ])
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">
            {{ $movements->links() }}
        </div>
    @else
        @include('partials.empty-state', [
            'icon' => 'bi-box-seam',
            'title' => 'Belum ada sparepart di cabang ini',
            'description' => 'Tidak ada sparepart yang dikonfigurasi di cabang ini untuk ditampilkan kartu stoknya.',
            'ctaVisible' => false,
        ])
    @endif
@endsection
```

Check `partials/empty-state.blade.php`'s exact parameter contract before writing the above (it may require `ctaRoute`/`ctaLabel` even when `ctaVisible` is false, or may accept their absence gracefully) — read the partial first and adjust the two `@include('partials.empty-state', ...)` calls above to match its actual required/optional parameters exactly.

- [ ] **Step 6: Wire the sidebar**

In `resources/views/partials/sidebar.blade.php`, find the Kartu Stok placeholder (nested inside the `sparepart.view` block, right after the Master Sparepart link):

```blade
        <li class="nav-item">
            <span class="nav-link nav-link-disabled">
                <i class="bi bi-card-list me-2"></i> Kartu Stok
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </li>
```

Replace with:

```blade
        <li class="nav-item">
            <a href="{{ route('stock-card.index') }}" class="nav-link {{ request()->routeIs('stock-card.*') ? 'active' : '' }}">
                <i class="bi bi-card-list me-2"></i> Kartu Stok
            </a>
        </li>
```

- [ ] **Step 7: Update the sidebar test**

In `tests/Feature/AppShellTest.php`, find `test_sidebar_shows_kartu_stok_placeholder_alongside_master_sparepart` and add one line after the existing `assertSee('bi-card-list', false)`:

```php
        $response->assertSee(route('stock-card.index'), false);
```

(Leave the method name and the rest of the test unchanged — the icon-class assertion is still valid and still needed since the Dashboard's own tab button text collides.)

- [ ] **Step 8: Wire the Dashboard tab to real data**

In `app/Http/Controllers/DashboardController.php`, find `computeKartuStok()` and replace the `'mutations' => $this->dummyMutationRows(),` line. The Dashboard's `$scopedBranchIds` can contain MULTIPLE branch IDs (unlike the dedicated `/stock-card` page, which always operates on exactly one branch via its own switcher) — for this preview tab, use just the FIRST scoped branch to resolve a concrete `sparepart_branch_id` and pull its last 5 movements (descending, newest-first — this is a compact preview, not the full ledger, so the usual list-page convention applies here, unlike the dedicated page):

```php
    protected function computeKartuStok(array $scopedBranchIds, ?int $sparepartId): array
    {
        $spareparts = Sparepart::where('is_active', true)
            ->whereHas('sparepartBranches', function ($query) use ($scopedBranchIds) {
                $query->whereIn('branch_id', $scopedBranchIds)->where('is_active', true);
            })
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $resolvedId = $sparepartId ?? optional($spareparts->first())->id;

        $selected = ['id' => $resolvedId, 'onHand' => 0.0, 'reserved' => 0.0, 'available' => 0.0];
        $mutations = [];

        if ($resolvedId && ! empty($scopedBranchIds)) {
            $totals = SparepartBranch::where('sparepart_id', $resolvedId)
                ->whereIn('branch_id', $scopedBranchIds)
                ->where('is_active', true)
                ->join('sparepart_branch_stocks', 'sparepart_branch_stocks.sparepart_branch_id', '=', 'sparepart_branches.id')
                ->selectRaw('SUM(sparepart_branch_stocks.on_hand_qty) as on_hand, SUM(sparepart_branch_stocks.reserved_qty) as reserved')
                ->first();

            $onHand = (float) ($totals->on_hand ?? 0);
            $reserved = (float) ($totals->reserved ?? 0);
            $selected = ['id' => $resolvedId, 'onHand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];

            // This preview shows only the first scoped branch's ledger (a single
            // running balance can't be meaningfully merged across branches) —
            // the dedicated /stock-card page always operates on one branch via
            // its own switcher and has no such ambiguity.
            $firstBranchSparepartBranch = SparepartBranch::where('sparepart_id', $resolvedId)
                ->whereIn('branch_id', $scopedBranchIds)
                ->where('is_active', true)
                ->first();

            if ($firstBranchSparepartBranch) {
                $typeLabels = [
                    \App\Support\InventoryMovementType::RECEIPT => 'Penerimaan',
                    \App\Support\InventoryMovementType::ADJUSTMENT_IN => 'Penyesuaian Masuk',
                    \App\Support\InventoryMovementType::ADJUSTMENT_OUT => 'Penyesuaian Keluar',
                    \App\Support\InventoryMovementType::TRANSFER_IN => 'Transfer Masuk',
                    \App\Support\InventoryMovementType::TRANSFER_OUT => 'Transfer Keluar',
                ];

                $mutations = \App\Models\InventoryMovement::where('sparepart_branch_id', $firstBranchSparepartBranch->id)
                    ->orderByDesc('movement_at')
                    ->orderByDesc('id')
                    ->limit(5)
                    ->get()
                    ->map(function ($movement) use ($typeLabels) {
                        return [
                            'date' => $movement->movement_at->format('d/m/Y H:i'),
                            'type' => $typeLabels[$movement->movement_type] ?? $movement->movement_type,
                            'reference' => "{$movement->reference_type} #{$movement->reference_id}",
                            'in' => (float) $movement->qty_in > 0 ? number_format($movement->qty_in, 0, ',', '.') : '-',
                            'out' => (float) $movement->qty_out > 0 ? number_format($movement->qty_out, 0, ',', '.') : '-',
                            'reserved' => 0,
                            'balance' => number_format($movement->balance_after, 0, ',', '.'),
                        ];
                    })
                    ->all();
            }
        }

        return [
            'spareparts' => $spareparts->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->all(),
            'selected' => $selected,
            'mutations' => $mutations,
        ];
    }
```

Remove the now-unused `dummyMutationRows()` method entirely (grep the file to confirm nothing else calls it before deleting).

Note: this preview's "Referensi" column deliberately stays as raw `{reference_type} #{reference_id}` text (NOT resolved to a document number/link like the dedicated page) — per the spec, the preview is meant to stay compact; full resolution with links lives only on `/stock-card`.

- [ ] **Step 9: Add/extend the Dashboard test**

In `tests/Feature/DashboardTest.php`, add a test proving the tab now shows real data:

```php
    public function test_kartu_stok_tab_shows_real_movement_data(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \App\Models\InventoryMovement::create([
            'movement_at' => now(), 'branch_id' => $branch->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'movement_type' => \App\Support\InventoryMovementType::RECEIPT, 'qty_in' => 10, 'qty_out' => 0,
            'balance_after' => 10, 'reference_type' => 'goods_receipt_line', 'reference_id' => 1,
        ]);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        $permission = Permission::firstOrCreate(['code' => 'sparepart.view'], ['resource' => 'sparepart', 'action' => 'view', 'description' => 'Melihat sparepart']);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get("/dashboard?branch_ids[]={$branch->id}&sparepart_id={$sparepart->id}");

        $response->assertOk();
        $response->assertDontSee('RCV-2026080001');
        $response->assertSee('Penerimaan');
    }
```

Check `tests/Feature/DashboardTest.php`'s existing `use` imports (`Branch`, `Sparepart`, `SparepartBranch`, `User`, `UserBranchService`, `Permission`, `UserBranchPermission`) and add any missing ones — most likely already present since the file already tests other Dashboard sections.

- [ ] **Step 10: Run tests to verify they pass**

Run: `php artisan test --filter=StockCardTest`
Expected: PASS, all tests.

Run: `php artisan test --filter=DashboardTest`
Expected: PASS, including the new test.

Run: `php artisan test --filter=AppShellTest`
Expected: PASS, including the updated Kartu Stok test.

- [ ] **Step 11: Run the full suite**

Run: `php artisan test`
Expected: all tests PASS — 516 baseline + roughly 9 new (7 in `StockCardTest`, 1 in `DashboardTest`) = approximately 525. Treat this as an estimate to sanity-check against, not an exact gate — confirm the actual final count and report it.

- [ ] **Step 12: Commit**

```bash
git add app/Http/Controllers/StockCardController.php app/Http/Controllers/DashboardController.php resources/views/stock-card resources/views/partials/sidebar.blade.php routes/web.php tests/Feature/StockCardTest.php tests/Feature/DashboardTest.php tests/Feature/AppShellTest.php
git commit -m "feat: add kartu stok (stock card) page and wire dashboard tab to real data"
```

---

## Self-Review Notes

- **Spec coverage:** dedicated page, reference resolution for all 3 existing movement sources, chronological ordering, Dashboard tab real-data wiring, sidebar wiring — all covered by this single task.
- **Placeholder scan:** none found, except the explicit instruction in Step 5 to verify `partials/empty-state.blade.php`'s exact parameter contract before finalizing — this is a deliberate "check before use" instruction, not a placeholder.
- **Type consistency:** `InventoryMovementType` constants referenced identically to how `StockAdjustmentController`/`GoodsReceiptController`/`StockTransferController` already use them.
- **Scope check:** appropriately small — 1 task, no new tables, no new permission codes, no concurrency/locking concerns (pure read path). Consistent with the user's choice to execute this inline rather than via subagent dispatch.
