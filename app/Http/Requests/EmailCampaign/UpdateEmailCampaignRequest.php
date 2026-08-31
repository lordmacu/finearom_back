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
            'email_field_type' => ['required', 'array', 'min:1'],
            'email_field_type.*' => ['required', 'string', 'in:email,executive_email,portfolio_contact_email,dispatch_confirmation_email,accounting_contact_email,compras_email,logistics_email'],
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

