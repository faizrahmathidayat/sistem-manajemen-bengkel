<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRackRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('rack.create');
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:30', 'unique:racks,code'],
        ];
    }
}
