<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\InvoiceStatus;

class PaymentLookupController extends Controller
{
    public function outstandingInvoicesByCustomer(Customer $customer)
    {
        $branchId = (int) request('branch_id');
        abort_if($branchId <= 0, 400, 'branch_id is required.');
        abort_unless(auth()->user()->hasPermissionToInBranch('payment.create', $branchId), 403);
        abort_unless($customer->hasAccessToBranch($branchId), 403);

        $invoices = $customer->invoices()
            ->where('branch_id', $branchId)
            ->whereIn('status', [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID])
            ->orderBy('invoice_date')
            ->get();

        return response()->json(
            $invoices->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'invoice_date' => $invoice->invoice_date->format('d/m/Y'),
                    'grand_total' => (float) $invoice->grand_total,
                    'paid_amount' => (float) $invoice->paid_amount,
                    'outstanding_amount' => (float) $invoice->outstanding_amount,
                ];
            })->values()
        );
    }
}
