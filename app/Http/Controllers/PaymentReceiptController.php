<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentReceiptRequest;
use App\Http\Requests\VoidPaymentReceiptRequest;
use App\Models\PaymentReceipt;
use App\Services\PaymentService;
use App\Support\PaymentMethod;
use DomainException;

class PaymentReceiptController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $permittedBranches = $user->branchesWithPermission('payment.view');

        if ($permittedBranches->isEmpty()) {
            return view('payment-receipts.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($permittedBranches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $paymentReceipts = PaymentReceipt::with(['branch', 'customer'])
            ->whereIn('branch_id', $permittedBranches->pluck('id'))
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($search, function ($query, $q) {
                $query->where('number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->simplePaginate(15)
            ->withQueryString();

        return view('payment-receipts.index', compact('paymentReceipts'))
            ->with('branches', $permittedBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function create()
    {
        $branches = auth()->user()->branchesWithPermission('payment.create');

        if ($branches->isEmpty()) {
            return view('payment-receipts.no-access');
        }

        return view('payment-receipts.create', [
            'branches' => $branches,
            'paymentMethods' => PaymentMethod::LABELS,
        ]);
    }

    public function store(StorePaymentReceiptRequest $request)
    {
        try {
            $receipt = (new PaymentService())->createPaymentReceipt($request->validated());
        } catch (DomainException $e) {
            return redirect()->route('payment-receipts.create')->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('payment-receipts.show', $receipt)->with('status', 'Pembayaran berhasil dicatat.');
    }

    public function show(PaymentReceipt $paymentReceipt)
    {
        $this->authorize('view', $paymentReceipt);

        $paymentReceipt->load(['branch', 'customer', 'voidedBy', 'allocations.invoice']);

        return view('payment-receipts.show', compact('paymentReceipt'));
    }

    public function void(VoidPaymentReceiptRequest $request, PaymentReceipt $paymentReceipt)
    {
        try {
            (new PaymentService())->voidPaymentReceipt($paymentReceipt, $request->validated()['reason']);
        } catch (DomainException $e) {
            return redirect()->route('payment-receipts.show', $paymentReceipt)->with('error', $e->getMessage());
        }

        return redirect()->route('payment-receipts.show', $paymentReceipt)->with('status', 'Pembayaran berhasil di-void.');
    }
}
