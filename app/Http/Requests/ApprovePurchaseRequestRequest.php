<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApprovePurchaseRequestRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('approve', $this->route('purchaseRequest'));
    }

    public function rules()
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
            'step_no' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
