<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportGoodsReceiptLinesRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('receipt.create', $branchId);
    }

    public function rules()
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ];
    }
}
