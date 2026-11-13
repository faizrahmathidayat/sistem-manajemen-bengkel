<?php

namespace App\Http\Requests\Concerns;

use App\Models\SparepartBranch;

trait ValidatesPurchaseRequestLines
{
    protected function filteredLines(): array
    {
        $lines = $this->input('lines', []);
        if (! is_array($lines)) {
            return [];
        }

        return array_values(array_filter($lines, function ($line) {
            return is_array($line) && ! empty($line['sparepart_branch_id']);
        }));
    }

    private function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    protected function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.sparepart_branch_id' => ['required', 'integer', 'exists:sparepart_branches,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Every line must be an active sparepart of the request's branch, and a sparepart may
     * appear only once.
     */
    protected function validateLines($validator, int $branchId): void
    {
        $seen = [];

        foreach ($this->input('lines', []) as $index => $line) {
            $sparepartBranchId = $line['sparepart_branch_id'] ?? null;
            if (! $sparepartBranchId) {
                continue;
            }

            $sparepartBranch = SparepartBranch::find($sparepartBranchId);
            if (! $sparepartBranch || ! $sparepartBranch->is_active || (int) $sparepartBranch->branch_id !== $branchId) {
                $validator->errors()->add("lines.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang permintaan ini.');
                continue;
            }

            // Requests are only for items that ran low: available stock (on hand minus what PKBs
            // have reserved) must be below the branch's minimum stock.
            $available = $sparepartBranch->stock ? (float) $sparepartBranch->stock->available_qty : 0.0;
            $minimum = (float) $sparepartBranch->minimum_stock;
            if (! ($available < $minimum)) {
                $validator->errors()->add(
                    "lines.{$index}.sparepart_branch_id",
                    'Stok tersedia ' . $this->plain($available) . ' belum di bawah stok minimal ' . $this->plain($minimum)
                    . '. Permintaan hanya untuk barang yang stok tersedianya di bawah stok minimal.'
                );
                continue;
            }

            if (isset($seen[$sparepartBranchId])) {
                $validator->errors()->add("lines.{$index}.sparepart_branch_id", 'Sparepart yang sama tidak boleh dimasukkan dua kali.');
            }
            $seen[$sparepartBranchId] = true;
        }
    }
}
