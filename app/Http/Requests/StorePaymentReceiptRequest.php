<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Invoice;
use App\Support\InvoiceStatus;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentReceiptRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('payment.create', $branchId);
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'allocations' => array_values(array_filter($this->input('allocations', []), function ($line) {
                return ! empty($line['invoice_id']) && isset($line['allocated_amount']) && (float) $line['allocated_amount'] > 0;
            })),
        ]);
    }

    public function rules()
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', 'string', 'in:' . implode(',', \App\Support\PaymentMethod::ALL)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*' => ['array'],
            'allocations.*.invoice_id' => ['required', 'integer', 'exists:invoices,id', 'distinct'],
            'allocations.*.allocated_amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $branchId = (int) $this->input('branch_id');
            $customerId = (int) $this->input('customer_id');

            $customer = Customer::find($customerId);
            if ($customer && ! $customer->hasAccessToBranch($branchId)) {
                $validator->errors()->add('customer_id', 'Customer ini tidak terdaftar di cabang tersebut.');
            }

            $sumAllocations = round(collect($this->input('allocations', []))->sum(function ($line) {
                return (float) ($line['allocated_amount'] ?? 0);
            }), 2);
            $amount = round((float) $this->input('amount', 0), 2);

            if (abs($sumAllocations - $amount) > 0.0005) {
                $validator->errors()->add('amount', 'Total nominal pembayaran harus sama dengan total seluruh alokasi invoice.');
            }

            foreach ($this->input('allocations', []) as $index => $line) {
                $invoiceId = $line['invoice_id'] ?? null;
                if (! $invoiceId) {
                    continue;
                }
                $invoice = Invoice::find($invoiceId);
                if (! $invoice) {
                    continue;
                }
                if ((int) $invoice->branch_id !== $branchId || (int) $invoice->customer_id !== $customerId) {
                    $validator->errors()->add("allocations.{$index}.invoice_id", 'Invoice bukan milik cabang/customer ini.');
                    continue;
                }
                if (! in_array($invoice->status, [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID], true)) {
                    $validator->errors()->add("allocations.{$index}.invoice_id", 'Invoice ini tidak memiliki sisa piutang yang bisa dibayar.');
                }
            }
        });
    }
}
