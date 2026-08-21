<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class StoreStockTransferRequest extends FormRequest
{
    public function authorize()
    {
        $fromBranchId = (int) $this->input('from_branch_id');

        return $fromBranchId && $this->user()->hasPermissionToInBranch('stock_transfer.create', $fromBranchId);
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'lines' => array_values(array_filter($this->input('lines', []), function ($line) {
                return ! empty($line['sparepart_id']);
            })),
        ]);
    }

    public function rules()
    {
        return [
            'from_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'to_branch_id' => ['required', 'integer', 'exists:branches,id', 'different:from_branch_id'],
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.sparepart_id' => ['required', 'integer', 'exists:spareparts,id', 'distinct'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $fromBranchId = (int) $this->input('from_branch_id');
            $toBranchId = (int) $this->input('to_branch_id');

            foreach ($this->input('lines', []) as $index => $line) {
                $sparepartId = $line['sparepart_id'] ?? null;
                if (! $sparepartId) {
                    continue;
                }

                $existsAtOrigin = SparepartBranch::where('sparepart_id', $sparepartId)
                    ->where('branch_id', $fromBranchId)->where('is_active', true)->exists();
                if (! $existsAtOrigin) {
                    $validator->errors()->add("lines.{$index}.sparepart_id", 'Sparepart belum dikonfigurasi atau tidak aktif di cabang asal.');

                    continue;
                }

                $existsAtDestination = SparepartBranch::where('sparepart_id', $sparepartId)
                    ->where('branch_id', $toBranchId)->where('is_active', true)->exists();
                if (! $existsAtDestination) {
                    $validator->errors()->add("lines.{$index}.sparepart_id", 'Sparepart belum dikonfigurasi atau tidak aktif di cabang tujuan.');
                }
            }
        });
    }
}
