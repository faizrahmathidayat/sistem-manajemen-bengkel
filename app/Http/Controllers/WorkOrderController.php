<?php

namespace App\Http\Controllers;

use App\Http\Requests\OverrideShortageRequest;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Models\Branch;
use App\Models\InventoryReservation;
use App\Models\ServiceCatalog;
use App\Models\SparepartBranch;
use App\Models\SparepartBranchStock;
use App\Models\WorkOrder;
use App\Models\WorkOrderServiceLine;
use App\Models\WorkOrderSparepartLine;
use App\Services\DocumentNumberGenerator;
use App\Support\WorkOrderStatus;
use Illuminate\Support\Facades\DB;

class WorkOrderController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('pkb.view');

        if ($permittedBranches->isEmpty()) {
            return view('work-orders.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $workOrders = WorkOrder::with(['branch', 'customer', 'vehicle', 'mechanic'])
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($search, function ($query, $q) {
                $query->where('number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->orderByDesc('work_order_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('work-orders.index', compact('workOrders'))
            ->with('branches', $permittedBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function create()
    {
        $branches = auth()->user()->branchesWithPermission('pkb.create');

        if ($branches->isEmpty()) {
            return view('work-orders.no-access');
        }

        return view('work-orders.create', compact('branches'));
    }

    public function store(StoreWorkOrderRequest $request)
    {
        $data = $request->validated();
        $branch = Branch::findOrFail($data['branch_id']);

        $workOrder = DB::transaction(function () use ($data, $branch) {
            $workOrder = WorkOrder::create([
                'number' => (new DocumentNumberGenerator())->next($branch, 'PKB'),
                'branch_id' => $branch->id,
                'customer_id' => $data['customer_id'],
                'vehicle_id' => $data['vehicle_id'],
                'mechanic_id' => $data['mechanic_id'],
                'work_order_date' => $data['work_order_date'],
                'odometer_km' => $data['odometer_km'] ?? null,
                'status' => WorkOrderStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncServiceLines($workOrder, $data['services'] ?? []);
            $this->syncSparepartLines($workOrder, $data['spareparts'] ?? []);

            return $workOrder;
        });

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB berhasil dibuat.');
    }

    public function confirm(WorkOrder $workOrder)
    {
        $this->authorize('confirm', $workOrder);

        $alreadyProcessed = false;

        DB::transaction(function () use ($workOrder, &$alreadyProcessed) {
            $fresh = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->first();

            if ($fresh->status !== WorkOrderStatus::DRAFT) {
                $alreadyProcessed = true;

                return;
            }

            $lines = $workOrder->sparepartLines()->reorder()->orderBy('sparepart_branch_id')->get();
            $hasShortage = false;

            foreach ($lines as $line) {
                $stock = SparepartBranchStock::where('sparepart_branch_id', $line->sparepart_branch_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $available = $stock->on_hand_qty - $stock->reserved_qty;
                $reserveQty = min($available, $line->qty);

                if ($reserveQty > 0) {
                    InventoryReservation::create([
                        'branch_id' => $workOrder->branch_id,
                        'sparepart_branch_id' => $line->sparepart_branch_id,
                        'reservation_type' => 'pkb',
                        'reference_type' => 'work_order_sparepart_line',
                        'reference_id' => $line->id,
                        'qty' => $reserveQty,
                        'created_by' => auth()->id(),
                    ]);

                    $stock->reserved_qty += $reserveQty;
                    $stock->save();
                }

                if ((float) $reserveQty < (float) $line->qty) {
                    $hasShortage = true;
                }
            }

            $fresh->status = $hasShortage ? WorkOrderStatus::SHORTAGE : WorkOrderStatus::OPEN;
            $fresh->save();
        });

        if ($alreadyProcessed) {
            return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB sudah tidak dalam status draft.');
        }

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB berhasil dikonfirmasi.');
    }

    public function overrideShortage(OverrideShortageRequest $request, WorkOrder $workOrder)
    {
        $workOrder->update([
            'shortage_override_reason' => $request->validated()['reason'],
            'shortage_overridden_by' => auth()->id(),
            'shortage_overridden_at' => now(),
        ]);

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'Kekurangan stok berhasil dicatat sebagai disetujui.');
    }

    public function show(WorkOrder $workOrder)
    {
        $this->authorize('view', $workOrder);

        $workOrder->load(['branch', 'customer', 'vehicle', 'mechanic', 'serviceLines', 'sparepartLines.reservations']);

        return view('work-orders.show', compact('workOrder'));
    }

    public function edit(WorkOrder $workOrder)
    {
        $this->authorize('update', $workOrder);

        $workOrder->load(['customer', 'vehicle', 'mechanic', 'serviceLines', 'sparepartLines']);

        $serviceCatalogs = ServiceCatalog::where('is_active', true)->orderBy('name')->get();
        $vehicles = $workOrder->customer->vehicles()->where('is_active', true)->orderBy('plate_number')->get();
        if ($workOrder->vehicle && ! $vehicles->contains('id', $workOrder->vehicle->id)) {
            $vehicles->push($workOrder->vehicle);
        }

        $existingServiceLines = $workOrder->serviceLines->map(function ($line) {
            return [
                'service_catalog_id' => $line->service_catalog_id,
                'description' => $line->description,
                'qty' => (float) $line->qty,
                'unit_price' => (float) $line->unit_price,
            ];
        })->values();

        $existingSparepartLines = $workOrder->sparepartLines->map(function ($line) {
            return [
                'sparepart_branch_id' => $line->sparepart_branch_id,
                'qty' => (float) $line->qty,
                'unit_price' => (float) $line->unit_price,
            ];
        })->values();

        return view('work-orders.edit', compact(
            'workOrder',
            'serviceCatalogs',
            'vehicles',
            'existingServiceLines',
            'existingSparepartLines'
        ));
    }

    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder)
    {
        $data = $request->validated();

        $noLongerDraft = false;

        DB::transaction(function () use ($data, $workOrder, &$noLongerDraft) {
            // Re-lock and re-verify status here even though UpdateWorkOrderRequest::authorize()
            // already checked the Policy (DRAFT-only) using the route-bound instance: Eloquent's
            // update() issues NO SQL when no header field is dirty (the common case when only
            // lines changed), so without an explicit lock a concurrent confirm() could sneak in
            // between the HTTP-layer authorization check and this transaction, read the
            // about-to-be-deleted lines, create reservations against them, and commit OPEN/
            // SHORTAGE — leaving those reservations (and reserved_qty) orphaned forever once
            // syncServiceLines()/syncSparepartLines() delete-and-recreate the lines below.
            $fresh = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->first();

            if ($fresh->status !== WorkOrderStatus::DRAFT) {
                $noLongerDraft = true;

                return;
            }

            $fresh->update([
                'customer_id' => $data['customer_id'],
                'vehicle_id' => $data['vehicle_id'],
                'mechanic_id' => $data['mechanic_id'],
                'work_order_date' => $data['work_order_date'],
                'odometer_km' => $data['odometer_km'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncServiceLines($fresh, $data['services'] ?? []);
            $this->syncSparepartLines($fresh, $data['spareparts'] ?? []);
        });

        if ($noLongerDraft) {
            return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB sudah tidak dalam status draft, tidak bisa diubah lagi.');
        }

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB berhasil diperbarui.');
    }

    public function cancel(WorkOrder $workOrder)
    {
        $this->authorize('cancel', $workOrder);

        $alreadyCancelled = false;

        DB::transaction(function () use ($workOrder, &$alreadyCancelled) {
            $fresh = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->first();

            if (! in_array($fresh->status, [WorkOrderStatus::DRAFT, WorkOrderStatus::OPEN, WorkOrderStatus::SHORTAGE], true)) {
                $alreadyCancelled = true;

                return;
            }

            if (in_array($fresh->status, [WorkOrderStatus::OPEN, WorkOrderStatus::SHORTAGE], true)) {
                $lines = $workOrder->sparepartLines()->reorder()->orderBy('sparepart_branch_id')->get();

                foreach ($lines as $line) {
                    // Lock the stock row BEFORE querying/locking this line's reservations, so the
                    // lock order here matches confirm()'s (work_orders row -> stock rows in
                    // sparepart_branch_id order -> reservation rows). Locking reservations first
                    // (as a prior fix did) takes a wide, unindexed lock over inventory_reservations
                    // (no index on reference_type/reference_id) before touching the stock row,
                    // which is the inverse of confirm()'s order and reintroduces an AB-BA deadlock
                    // between concurrent confirm()/cancel() calls sharing a sparepart.
                    $stock = SparepartBranchStock::where('sparepart_branch_id', $line->sparepart_branch_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    // No lockForUpdate() here — the WorkOrder row lock taken above already
                    // guarantees exclusive access to this work order's reservations (confirm()
                    // and cancel() are the only two paths that ever create or release
                    // reservations, and both lock the WorkOrder row first). A locking read on
                    // this query would take next-key/gap locks under REPEATABLE-READ because it
                    // filters via the non-unique reference_type/reference_id index, which can
                    // deadlock (AB-BA) against confirm()'s reservation inserts for a different
                    // work order sharing the same sparepart.
                    $activeReservations = $line->reservations()->where('status', 'active')->get();

                    foreach ($activeReservations as $reservation) {
                        $stock->reserved_qty -= $reservation->qty;

                        $reservation->status = 'released';
                        $reservation->save();
                    }

                    $stock->save();
                }
            }

            $fresh->status = WorkOrderStatus::CANCELLED;
            $fresh->save();
        });

        if ($alreadyCancelled) {
            return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB sudah dibatalkan sebelumnya.');
        }

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB berhasil dibatalkan.');
    }

    protected function syncServiceLines(WorkOrder $workOrder, array $lines): void
    {
        $workOrder->serviceLines()->delete();

        foreach (array_values(array_filter($lines)) as $index => $line) {
            $qty = (float) $line['qty'];
            $unitPrice = (float) $line['unit_price'];
            WorkOrderServiceLine::create([
                'work_order_id' => $workOrder->id,
                'service_catalog_id' => $line['service_catalog_id'] ?? null,
                'description' => $line['description'],
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => round($qty * $unitPrice, 2),
                'sort_order' => $index,
            ]);
        }
    }

    protected function syncSparepartLines(WorkOrder $workOrder, array $lines): void
    {
        $workOrder->sparepartLines()->delete();

        foreach (array_values(array_filter($lines)) as $index => $line) {
            $sparepartBranch = SparepartBranch::with('sparepart')->findOrFail($line['sparepart_branch_id']);
            $qty = (float) $line['qty'];
            $unitPrice = (float) $line['unit_price'];
            WorkOrderSparepartLine::create([
                'work_order_id' => $workOrder->id,
                'sparepart_branch_id' => $sparepartBranch->id,
                'item_code_snapshot' => $sparepartBranch->sparepart->code,
                'item_name_snapshot' => $sparepartBranch->sparepart->name,
                'qty' => $qty,
                'default_unit_price' => $sparepartBranch->selling_price,
                'unit_price' => $unitPrice,
                'line_total' => round($qty * $unitPrice, 2),
                'sort_order' => $index,
            ]);
        }
    }
}
