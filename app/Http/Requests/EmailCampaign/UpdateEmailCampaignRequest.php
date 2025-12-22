<?php

namespace App\Http\Requests\EmailCampaign;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'campaign_name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'email_field_type' => ['required', 'string'],
            'body' => ['required', 'string'],
            'client_ids' => ['required', 'array', 'min:1'],
            'client_ids.*' => ['integer', 'exists:clients,id'],
            'custom_emails' => ['nullable', 'array'],
            'custom_emails.*' => ['email'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }
}

