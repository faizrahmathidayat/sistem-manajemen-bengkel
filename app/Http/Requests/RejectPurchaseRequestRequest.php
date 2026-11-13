<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectPurchaseRequestRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('reject', $this->route('purchaseRequest'));
    }

    public function rules()
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'step_no' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages()
    {
        return [
            'reason.required' => 'Alasan penolakan wajib diisi.',
        ];
    }
}
