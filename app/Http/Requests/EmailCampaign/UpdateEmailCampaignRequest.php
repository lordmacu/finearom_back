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
            // Sin clientes (solo emails personalizados) no hace falta elegir el campo de email
            'email_field_type' => ['nullable', 'array', 'required_with:client_ids'],
            'email_field_type.*' => ['required', 'string', 'in:email,executive_email,portfolio_contact_email,dispatch_confirmation_email,accounting_contact_email,compras_email,logistics_email'],
            'body' => ['required', 'string'],
            // Los clientes son opcionales si hay al menos un email personalizado
            'client_ids' => ['nullable', 'array', 'required_without:custom_emails'],
            'client_ids.*' => ['integer', 'exists:clients,id'],
            'custom_emails' => ['nullable', 'array', 'required_without:client_ids'],
            'custom_emails.*' => ['email'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_ids.required_without' => 'Selecciona al menos un cliente o agrega al menos un email personalizado.',
            'custom_emails.required_without' => 'Selecciona al menos un cliente o agrega al menos un email personalizado.',
            'email_field_type.required_with' => 'Selecciona el campo de email de los clientes.',
        ];
    }
}
