<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportServiceCatalogLinesRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('service.create');
    }

    public function rules()
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ];
    }
}
