<?php

namespace App\Http\Requests\EmailCampaign;

use Illuminate\Foundation\Http\FormRequest;

class ResendCustomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'email' => ['required', 'string'],
            'additional_attachments' => ['nullable', 'array'],
            'additional_attachments.*' => ['file', 'max:10240'],
        ];
    }
}

