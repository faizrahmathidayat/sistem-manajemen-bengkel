<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceCatalogBulkRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('service.create');
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
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*' => ['array'],
            'lines.*.code' => ['required', 'string', 'max:30', 'distinct', 'unique:service_catalogs,code'],
            'lines.*.name' => ['required', 'string', 'max:150'],
            'lines.*.default_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
