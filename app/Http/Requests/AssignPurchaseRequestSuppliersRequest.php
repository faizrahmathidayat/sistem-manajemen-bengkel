<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;

class AssignPurchaseRequestSuppliersRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('assignSupplier', $this->route('purchaseRequest'));
    }

    protected function prepareForValidation()
    {
        $lines = $this->input('lines', []);
        $this->merge(['lines' => is_array($lines) ? $lines : []]);
    }

    public function rules()
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $purchaseRequest = $this->route('purchaseRequest');
            $currentSuppliers = $purchaseRequest->lines()->pluck('supplier_id', 'id');

            foreach ($this->input('lines', []) as $lineId => $line) {
                if (! $currentSuppliers->has((int) $lineId)) {
                    $validator->errors()->add("lines.{$lineId}", 'Baris tidak termasuk dalam permintaan ini.');
                    continue;
                }

                $supplierId = $line['supplier_id'] ?? null;
                if ($supplierId === null || $supplierId === '') {
                    continue;
                }

                $supplier = Supplier::find($supplierId);
                $unchanged = (int) $currentSuppliers->get((int) $lineId) === (int) $supplierId;
                if ($supplier && ! $supplier->is_active && ! $unchanged) {
                    $validator->errors()->add("lines.{$lineId}.supplier_id", 'Supplier tidak aktif.');
                }
            }
        });
    }
}
