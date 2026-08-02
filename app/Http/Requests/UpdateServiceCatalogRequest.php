<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceCatalogRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('service.edit');
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('service_catalogs', 'code')->ignore($this->route('serviceCatalog'))],
            'name' => ['required', 'string', 'max:150'],
            'default_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
