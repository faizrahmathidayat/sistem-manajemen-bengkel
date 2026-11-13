<?php

namespace App\Http\Requests;

use App\Support\PurchaseRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelPurchaseRequestRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('cancel', $this->route('purchaseRequest'));
    }

    public function rules()
    {
        return [
            // Cancelling a request that is already in approval interrupts other people's work,
            // so it must say why; before submission a reason is optional.
            'reason' => [
                Rule::requiredIf(function () {
                    return $this->route('purchaseRequest')->status === PurchaseRequestStatus::WAITING_APPROVAL;
                }),
                'nullable', 'string', 'max:1000',
            ],
        ];
    }

    public function messages()
    {
        return [
            'reason.required' => 'Alasan pembatalan wajib diisi untuk permintaan yang sedang menunggu approval.',
        ];
    }
}
