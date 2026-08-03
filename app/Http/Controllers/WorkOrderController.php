<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\ServiceCatalog;
use App\Models\SparepartBranch;
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

    public function show(WorkOrder $workOrder)
    {
        $this->authorize('view', $workOrder);

        $workOrder->load(['branch', 'customer', 'vehicle', 'mechanic', 'serviceLines', 'sparepartLines']);

        return view('work-orders.show', compact('workOrder'));
    }

    public function edit(WorkOrder $workOrder)
    {
        $this->authorize('update', $workOrder);

        $workOrder->load(['serviceLines', 'sparepartLines']);
        $customers = Customer::whereHas('customerBranches', function ($query) use ($workOrder) {
            $query->where('branch_id', $workOrder->branch_id)->where('is_active', true);
        })->where('is_active', true)->orderBy('name')->get();
        $mechanics = Mechanic::whereHas('mechanicBranches', function ($query) use ($workOrder) {
            $query->where('branch_id', $workOrder->branch_id)->where('is_active', true);
        })->where('is_active', true)->orderBy('name')->get();
        $sparepartBranches = SparepartBranch::with(['sparepart', 'stock'])
            ->where('branch_id', $workOrder->branch_id)
            ->where('is_active', true)
            ->get();
        $serviceCatalogs = ServiceCatalog::where('is_active', true)->orderBy('name')->get();
        $vehicles = $workOrder->customer->vehicles()->where('is_active', true)->orderBy('plate_number')->get();

        $sparepartOptionsForEdit = $sparepartBranches->map(function ($sb) {
            return [
                'id' => $sb->id,
                'code' => $sb->sparepart->code,
                'name' => $sb->sparepart->name,
                'selling_price' => (float) $sb->selling_price,
                'available_qty' => (float) $sb->stock->available_qty,
            ];
        })->values();

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
            'customers',
            'mechanics',
            'sparepartBranches',
            'serviceCatalogs',
            'vehicles',
            'sparepartOptionsForEdit',
            'existingServiceLines',
            'existingSparepartLines'
        ));
    }

    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $workOrder) {
            $workOrder->update([
                'customer_id' => $data['customer_id'],
                'vehicle_id' => $data['vehicle_id'],
                'mechanic_id' => $data['mechanic_id'],
                'work_order_date' => $data['work_order_date'],
                'odometer_km' => $data['odometer_km'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncServiceLines($workOrder, $data['services'] ?? []);
            $this->syncSparepartLines($workOrder, $data['spareparts'] ?? []);
        });

        return redirect()->route('work-orders.show', $workOrder)->with('status', 'PKB berhasil diperbarui.');
    }

    public function cancel(WorkOrder $workOrder)
    {
        $this->authorize('cancel', $workOrder);

        $workOrder->update(['status' => WorkOrderStatus::CANCELLED]);

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
