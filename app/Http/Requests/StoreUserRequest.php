<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('user.create');
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'phone_number' => ['nullable', 'string', 'max:30', function ($attribute, $value, $fail) {
                if ($value !== null && trim($value) !== '' && \App\Support\PhoneNumber::normalize($value) === null) {
                    $fail('Nomor HP tidak valid. Gunakan format 0812… atau 62812….');
                }
            }],
            'password' => ['required', 'string', 'min:6'],
        ];
    }
}
