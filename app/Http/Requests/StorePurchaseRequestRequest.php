<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPurchaseRequestLines;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequestRequest extends FormRequest
{
    use ValidatesPurchaseRequestLines;

    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('purchase_request.create', $branchId);
    }

    protected function prepareForValidation()
    {
        $this->merge(['lines' => $this->filteredLines()]);
    }

    public function rules()
    {
        return array_merge([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'request_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_urgent' => ['nullable', 'boolean'],
        ], $this->lineRules());
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $this->validateLines($validator, (int) $this->input('branch_id'));
        });
    }
}
