<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\WorkOrder;
use App\Services\InvoiceService;
use App\Support\InvoiceDetailItemType;
use DomainException;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('invoice.view');

        if ($permittedBranches->isEmpty()) {
            return view('invoices.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $invoices = Invoice::with(['branch', 'customer'])
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($search, function ($query, $q) {
                $query->where('number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('invoices.index', compact('invoices'))
            ->with('branches', $permittedBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function store(Request $request)
    {
        $workOrder = WorkOrder::findOrFail($request->input('work_order_id'));
        $this->authorize('create', [Invoice::class, $workOrder]);

        try {
            $invoice = (new InvoiceService())->createFromWorkOrder($workOrder);
        } catch (DomainException $e) {
            return redirect()->route('work-orders.show', $workOrder)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice draft berhasil dibuat.');
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $invoice->load(['branch', 'customer', 'workOrder', 'details']);

        return view('invoices.show', compact('invoice'));
    }

    public function edit(Invoice $invoice)
    {
        $this->authorize('update', $invoice);

        $invoice->load('details');

        $existingServiceLines = $invoice->details->where('item_type', InvoiceDetailItemType::SERVICE)->map(function (InvoiceDetail $detail) {
            return [
                'work_order_service_line_id' => $detail->work_order_service_line_id,
                'description' => $detail->description,
                'qty' => (float) $detail->qty,
                'unit_price' => (float) $detail->unit_price,
            ];
        })->values();

        $existingSparepartLines = $invoice->details->where('item_type', InvoiceDetailItemType::SPAREPART)->map(function (InvoiceDetail $detail) {
            return [
                'work_order_sparepart_line_id' => $detail->work_order_sparepart_line_id,
                'sparepart_branch_id' => $detail->sparepart_branch_id,
                'item_code_snapshot' => $detail->item_code_snapshot,
                'description' => $detail->description,
                'qty' => (float) $detail->qty,
                'unit_price' => (float) $detail->unit_price,
            ];
        })->values();

        return view('invoices.edit', compact('invoice', 'existingServiceLines', 'existingSparepartLines'));
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        try {
            (new InvoiceService())->updateInvoice($invoice, $request->validated());
        } catch (DomainException $e) {
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice berhasil diperbarui.');
    }

    public function post(Invoice $invoice)
    {
        $this->authorize('post', $invoice);

        try {
            (new InvoiceService())->postInvoice($invoice);
        } catch (DomainException $e) {
            return redirect()->route('invoices.show', $invoice)->with('error', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice berhasil diposting.');
    }
}
