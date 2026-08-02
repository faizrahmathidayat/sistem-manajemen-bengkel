<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('customer.edit');
    }

    public function rules()
    {
        return [
            'customer_type' => ['required', Rule::in(['COMPANY', 'INDIVIDUAL'])],
            'name' => ['required', 'string', 'max:150'],
            'stnk_name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
