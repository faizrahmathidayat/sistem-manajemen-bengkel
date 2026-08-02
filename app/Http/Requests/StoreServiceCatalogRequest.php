<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceCatalogRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('service.create');
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:30', 'unique:service_catalogs,code'],
            'name' => ['required', 'string', 'max:150'],
            'default_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
