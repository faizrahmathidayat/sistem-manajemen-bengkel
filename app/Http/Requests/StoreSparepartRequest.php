<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSparepartRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('sparepart.create', $branchId);
    }

    public function rules()
    {
        return [
            'branch_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:30', 'unique:spareparts,code'],
            'name' => ['required', 'string', 'max:150'],
            'rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'selling_price' => ['required', 'integer', 'min:0'],
            'minimum_stock' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
