<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesReceiptPurchaseOrderLines;
use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Draft receipts that came from a purchase order only change quantities (prices and branch
 * stay with the order). Legacy drafts without an order keep the original free-form rules.
 */
class UpdateGoodsReceiptRequest extends FormRequest
{
    use ValidatesReceiptPurchaseOrderLines;

    public function authorize()
    {
        return $this->user()->can('update', $this->route('goodsReceipt'));
    }

    protected function fromPurchaseOrder(): bool
    {
        return (bool) $this->route('goodsReceipt')->purchase_order_id;
    }

    protected function prepareForValidation()
    {
        if ($this->fromPurchaseOrder()) {
            $this->merge(['lines' => $this->filteredPoLines()]);

            return;
        }

        $this->merge([
            'lines' => array_values(array_filter($this->input('lines', []), function ($line) {
                return ! empty($line['sparepart_branch_id']);
            })),
        ]);
    }

    public function rules()
    {
        $header = [
            'receipt_date' => ['required', 'date'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];

        if ($this->fromPurchaseOrder()) {
            return array_merge($header, $this->poLineRules());
        }

        return array_merge($header, [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.sparepart_branch_id' => ['required', 'integer', 'exists:sparepart_branches,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.purchase_price' => ['required', 'numeric', 'min:0'],
        ]);
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $receipt = $this->route('goodsReceipt');

            if ($this->fromPurchaseOrder()) {
                $this->validatePoLines($validator, $receipt->purchaseOrder);

                return;
            }

            $branchId = (int) $receipt->branch_id;
            foreach ($this->input('lines', []) as $index => $line) {
                $sparepartBranchId = $line['sparepart_branch_id'] ?? null;
                if (! $sparepartBranchId) {
                    continue;
                }
                $sparepartBranch = SparepartBranch::find($sparepartBranchId);
                if (! $sparepartBranch || ! $sparepartBranch->is_active || (int) $sparepartBranch->branch_id !== $branchId) {
                    $validator->errors()->add("lines.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang penerimaan ini.');
                }
            }
        });
    }
}
