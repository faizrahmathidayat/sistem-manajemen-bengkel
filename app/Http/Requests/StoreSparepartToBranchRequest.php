<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class StoreSparepartToBranchRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = session('current_sparepart_branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('sparepart.create', (int) $branchId);
    }

    public function rules()
    {
        return [
            'sparepart_id' => ['required', 'integer', 'exists:spareparts,id'],
            'rack_number' => ['nullable', 'string', 'max:30'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $branchId = (int) session('current_sparepart_branch_id');

            if ($this->sparepart_id && SparepartBranch::where('sparepart_id', $this->sparepart_id)->where('branch_id', $branchId)->exists()) {
                $validator->errors()->add('sparepart_id', 'Sparepart ini sudah terkonfigurasi di cabang ini.');
            }
        });
    }
}
