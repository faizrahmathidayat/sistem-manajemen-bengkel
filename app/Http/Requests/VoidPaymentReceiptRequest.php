<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidPaymentReceiptRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('void', $this->route('paymentReceipt'));
    }

    public function rules()
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
