<?php

namespace App\Http\Requests\EmailCampaign;

use Illuminate\Foundation\Http\FormRequest;

class ResendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email_field_type' => ['nullable', 'string'],
            'custom_email' => ['nullable', 'email'],
        ];
    }
}

