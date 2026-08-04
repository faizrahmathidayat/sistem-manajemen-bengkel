<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OverrideShortageRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('overrideShortage', $this->route('workOrder'));
    }

    public function rules()
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
