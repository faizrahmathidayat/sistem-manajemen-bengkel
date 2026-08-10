<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMechanicRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('mechanic.edit');
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'nip' => ['required', 'string', 'max:50', Rule::unique('mechanics', 'nip')->ignore($this->route('mechanic'))],
            'join_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
        ];
    }
}
