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

            if (isset($seen[$sparepartBranchId])) {
                $validator->errors()->add("lines.{$index}.sparepart_branch_id", 'Sparepart yang sama tidak boleh dimasukkan dua kali.');
            }
            $seen[$sparepartBranchId] = true;
        }
    }
}
