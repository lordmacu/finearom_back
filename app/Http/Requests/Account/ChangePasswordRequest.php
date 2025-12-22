<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'old_password' => ['required'],
            'new_password' => ['required', Password::defaults()],
            'confirm_password' => ['required', 'same:new_password'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->failed()) {
                return;
            }

            $user = $this->user();
            if (! $user) {
                return;
            }

            if (! Hash::check((string) $this->input('old_password'), (string) $user->password)) {
                $validator->errors()->add('old_password', __('Old password is incorrect.'));
            }
        });
    }
}

