<?php

namespace App\Http\Requests\EmailCampaign;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmailCampaignRequest extends FormRequest
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
            'client_ids' => ['nullable', 'array', 'required_without_all:custom_emails,internal_emails'],
            'client_ids.*' => ['integer', 'exists:clients,id'],
            'custom_emails' => ['nullable', 'array', 'required_without_all:client_ids,internal_emails'],
            'internal_emails' => ['nullable', 'array', 'required_without_all:client_ids,custom_emails'],
            'internal_emails.*' => ['email'],
            'custom_emails.*' => ['email'],
            'include_header' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_ids.required_without_all' => 'Selecciona al menos un cliente, un correo interno o un email personalizado.',
            'custom_emails.required_without_all' => 'Selecciona al menos un cliente, un correo interno o un email personalizado.',
            'internal_emails.required_without_all' => 'Selecciona al menos un cliente, un correo interno o un email personalizado.',
            'email_field_type.required_with' => 'Selecciona el campo de email de los clientes.',
        ];
    }
}
