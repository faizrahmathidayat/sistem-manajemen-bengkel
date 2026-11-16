<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPurchaseRequestLines;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseRequestRequest extends FormRequest
{
    use ValidatesPurchaseRequestLines;

    public function authorize()
    {
        return $this->user()->can('update', $this->route('purchaseRequest'));
    }

    protected function prepareForValidation()
    {
        $this->merge(['lines' => $this->filteredLines()]);
    }

    public function rules()
    {
        return array_merge([
            'request_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_urgent' => ['nullable', 'boolean'],
        ], $this->lineRules());
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $this->validateLines($validator, (int) $this->route('purchaseRequest')->branch_id);
        });
    }
}
