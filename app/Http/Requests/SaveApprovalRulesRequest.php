<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveApprovalRulesRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('approval_setting.manage');
    }

    protected function prepareForValidation()
    {
        $rules = $this->input('rules', []);
        $this->merge(['rules' => is_array($rules) ? $rules : []]);
    }

    public function rules()
    {
        // An empty list is allowed through here so the service can report the readable
        // "minimal satu aturan" message under the 'rules' key.
        return [
            'rules' => ['array'],
            'rules.*' => ['array'],
        ];
    }
}
