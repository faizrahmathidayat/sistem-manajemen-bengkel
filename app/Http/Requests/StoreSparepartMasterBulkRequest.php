<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSparepartMasterBulkRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('sparepart.create', $branchId);
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'lines' => array_values(array_filter($this->input('lines', []), function ($line) {
                return ! empty($line['code']) || ! empty($line['name']);
            })),
        ]);
    }

    public function rules()
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*' => ['array'],
            'lines.*.code' => ['required', 'string', 'max:30', 'distinct', 'unique:spareparts,code'],
            'lines.*.name' => ['required', 'string', 'max:150'],
            'lines.*.rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'lines.*.selling_price' => ['required', 'numeric', 'min:0'],
            'lines.*.minimum_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
