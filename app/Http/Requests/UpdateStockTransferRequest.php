<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStockTransferRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('update', $this->route('stockTransfer'));
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
            'to_branch_id' => ['required', 'integer', 'exists:branches,id'],
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
            $fromBranchId = (int) $this->route('stockTransfer')->from_branch_id;
            $toBranchId = (int) $this->input('to_branch_id');

            if ($toBranchId === $fromBranchId) {
                $validator->errors()->add('to_branch_id', 'Cabang tujuan tidak boleh sama dengan cabang asal.');
            }

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
