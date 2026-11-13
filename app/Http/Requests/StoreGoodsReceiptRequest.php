<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesReceiptPurchaseOrderLines;
use App\Models\PurchaseOrder;
use App\Support\PurchaseOrderStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * New receipts always originate from a purchase order: the branch, supplier and unit prices
 * come from the order, never from the submitted form.
 */
class StoreGoodsReceiptRequest extends FormRequest
{
    use ValidatesReceiptPurchaseOrderLines;

    public function authorize()
    {
        $order = PurchaseOrder::find((int) $this->input('purchase_order_id'));

        // A missing/unknown order is reported as a validation error below, not as a 403.
        return ! $order || $this->user()->hasPermissionToInBranch('receipt.create', $order->branch_id);
    }

    protected function prepareForValidation()
    {
        $this->merge(['lines' => $this->filteredPoLines()]);
    }

    public function rules()
    {
        return array_merge([
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'receipt_date' => ['required', 'date'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ], $this->poLineRules());
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('purchase_order_id')) {
                return;
            }

            $order = PurchaseOrder::find((int) $this->input('purchase_order_id'));
            if (! $order || ! in_array($order->status, PurchaseOrderStatus::RECEIVABLE, true)) {
                $validator->errors()->add('purchase_order_id', 'Pembelian belum diterbitkan atau sudah tidak dapat diterima.');

                return;
            }

            $this->validatePoLines($validator, $order);
        });
    }
}
