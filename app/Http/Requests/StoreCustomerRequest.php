<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesBundledVehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    use ValidatesBundledVehicle;

    public function authorize()
    {
        return $this->user()->can('customer.create');
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'name' => is_string($this->name) ? mb_strtoupper($this->name) : $this->name,
            'stnk_name' => is_string($this->stnk_name) ? mb_strtoupper($this->stnk_name) : $this->stnk_name,
        ]);
        $this->uppercaseBundledVehicleFields();
    }

    public function rules()
    {
        return array_merge([
            'customer_type' => ['required', Rule::in(['COMPANY', 'INDIVIDUAL'])],
            'name' => ['required', 'string', 'max:150'],
            'stnk_name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ], $this->bundledVehicleRules());
    }

    public function withValidator($validator)
    {
        $validator->after(fn ($validator) => $this->validateBundledVehicle($validator));
    }
}
