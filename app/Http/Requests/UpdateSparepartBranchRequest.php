<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSparepartBranchRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('update', $this->route('sparepartBranch'));
    }

    public function rules()
    {
        return [
            'rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
