<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('user.edit') && $this->user()->can('update', $this->route('user'));
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($this->route('user'))],
            'password' => ['nullable', 'string', 'min:6'],
            'telegram_chat_id' => ['nullable', 'string', 'max:20', function ($attribute, $value, $fail) {
                if ($value !== null && trim($value) !== '' && ! preg_match('/^-?\d{5,19}$/', trim($value))) {
                    $fail('Chat ID Telegram harus berupa angka (grup diawali tanda minus).');
                }
            }],
            'notify_enabled' => ['nullable', 'boolean'],
            'phone_number' => ['nullable', 'string', 'max:30', function ($attribute, $value, $fail) {
                if ($value !== null && trim($value) !== '' && \App\Support\PhoneNumber::normalize($value) === null) {
                    $fail('Nomor HP tidak valid. Gunakan format 0812… atau 62812….');
                }
            }],
        ];
    }
}
