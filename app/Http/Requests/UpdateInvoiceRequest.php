<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('update', $this->route('invoice'));
    }

    public function rules()
    {
        return [
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_percent' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
