<?php

namespace App\Http\Requests\Concerns;

use App\Models\PurchaseOrder;

trait ValidatesReceiptPurchaseOrderLines
{
    protected function filteredPoLines(): array
    {
        $lines = $this->input('lines', []);
        if (! is_array($lines)) {
            return [];
        }

        return array_values(array_filter($lines, function ($line) {
            return is_array($line) && ! empty($line['purchase_order_line_id']);
        }));
    }

    protected function poLineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.purchase_order_line_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Each line must belong to the purchase order, appear once, and not exceed the quantity
     * still open on it; at least one line must actually receive something. The authoritative
     * re-check happens again under lock when the receipt is posted.
     */
    protected function validatePoLines($validator, PurchaseOrder $purchaseOrder): void
    {
        $orderLines = $purchaseOrder->lines()->with('sparepartBranch.sparepart')->get()->keyBy('id');
        $seen = [];
        $total = 0;

        foreach ($this->input('lines', []) as $index => $line) {
            $lineId = (int) ($line['purchase_order_line_id'] ?? 0);
            if (! $lineId) {
                continue;
            }

            if (! $orderLines->has($lineId)) {
                $validator->errors()->add("lines.{$index}.purchase_order_line_id", 'Baris tidak termasuk dalam pembelian ini.');
                continue;
            }
            if (isset($seen[$lineId])) {
                $validator->errors()->add("lines.{$index}.purchase_order_line_id", 'Baris pembelian yang sama tidak boleh dimasukkan dua kali.');
                continue;
            }
            $seen[$lineId] = true;

            $qty = is_numeric($line['qty'] ?? null) ? (float) $line['qty'] : 0;
            $total += $qty;
            $remaining = $orderLines[$lineId]->remainingQty();
            if ($qty > $remaining) {
                $code = $orderLines[$lineId]->sparepartBranch->sparepart->code;
                $validator->errors()->add("lines.{$index}.qty", "Qty melebihi sisa pembelian untuk {$code} (sisa " . rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.') . ').');
            }
        }

        if ($total <= 0 && ! $validator->errors()->has('lines')) {
            $validator->errors()->add('lines', 'Isi qty diterima minimal pada satu baris.');
        }
    }
}
