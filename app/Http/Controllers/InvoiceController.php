<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Services\InvoiceService;
use App\Support\InvoiceStatus;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        return view('invoices.edit', compact('invoice'));
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        $data = $request->validated();

        $noLongerDraft = false;

        DB::transaction(function () use ($data, $invoice, &$noLongerDraft) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($fresh->status !== InvoiceStatus::DRAFT) {
                $noLongerDraft = true;

                return;
            }

            $subtotal = (float) $fresh->subtotal_service + (float) $fresh->subtotal_sparepart;
            $discountPercent = (float) $data['discount_percent'];
            $taxPercent = (float) $data['tax_percent'];
            $discountAmount = round($subtotal * $discountPercent / 100, 2);
            $taxableBase = $subtotal - $discountAmount;
            $taxAmount = round($taxableBase * $taxPercent / 100, 2);

            $fresh->update([
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
                'tax_percent' => $taxPercent,
                'tax_amount' => $taxAmount,
                'grand_total' => round($taxableBase + $taxAmount, 2),
                'notes' => $data['notes'] ?? null,
            ]);
        });

        if ($noLongerDraft) {
            return redirect()->route('invoices.show', $invoice)->with('error', 'Invoice sudah tidak berstatus draft, tidak bisa diubah lagi.');
        }

        return redirect()->route('invoices.show', $invoice)->with('status', 'Invoice berhasil diperbarui.');
    }
}
