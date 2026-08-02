<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSparepartRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = session('current_sparepart_branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('sparepart.create', (int) $branchId);
    }

    public function rules()
    {
        return [
            'code' => ['required', 'string', 'max:30', 'unique:spareparts,code'],
            'name' => ['required', 'string', 'max:150'],
            'rack_number' => ['nullable', 'string', 'max:30'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
