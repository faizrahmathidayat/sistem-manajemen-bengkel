<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('supplier.edit');
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('suppliers', 'code')->ignore($this->route('supplier'))],
            'name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'account_number' => ['nullable', 'string', 'max:60'],
        ];
    }
}
