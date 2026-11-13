<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveApprovalLevelsRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('approval_setting.manage');
    }

    public function rules()
    {
        return [
            'levels' => ['required', 'array', 'min:1'],
            'levels.*' => ['array'],
        ];
    }
}
