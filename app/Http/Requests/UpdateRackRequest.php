<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRackRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('rack.edit');
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('racks', 'code')->ignore($this->route('rack'))],
        ];
    }
}
