<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class ClientUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $clientId = $this->route('clientId');

        // Si es el flujo público, el ID viene encriptado en el token
        if (!$clientId && $this->route('token')) {
            try {
                $clientId = decrypt($this->route('token'));
            } catch (\Exception $e) {
                // Token inválido, dejar clientId como null
            }
        }

        return [
            'client_name' => ['required', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:255', 'unique:clients,nit,' . $clientId],
            'payment_type' => ['nullable', 'in:cash,credit'],
            'email' => ['required', 'string', 'max:255', 'unique:clients,email,' . $clientId],
            'executive_email' => ['nullable', 'string', 'max:500'],
            'executive_phone' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'registration_address' => ['nullable', 'string', 'max:255'],
            'registration_city' => ['nullable', 'string', 'max:255'],
            'billing_closure' => ['nullable', 'string', 'max:255'],
            'billing_closure_date' => ['nullable', 'date'],
            'commercial_conditions' => ['nullable', 'string'],
            'commercial_terms' => ['nullable', 'string'],
            'proforma_invoice' => ['nullable', 'boolean'],
            'payment_method' => ['nullable', 'in:1,2'],
            'payment_day' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
            'dispatch_confirmation_email' => ['nullable', 'string', 'max:500'],
            'accounting_contact_email' => ['nullable', 'string', 'max:500'],
            'portfolio_contact_email' => ['nullable', 'string', 'max:500'],
            'compras_email' => ['nullable', 'string', 'max:500'],
            'logistics_email' => ['nullable', 'string', 'max:500'],
            'trm' => ['nullable', 'string', 'max:255'],
            'temporal_mark' => ['nullable', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'is_in_free_zone' => ['nullable', 'boolean'],
            'operation_type' => ['nullable', 'in:nacional,extranjero'],
            'purchasing_contact_name' => ['nullable', 'string', 'max:255'],
            'purchasing_contact_phone' => ['nullable', 'string', 'max:255'],
            'purchasing_contact_email' => ['nullable', 'string', 'max:500'],
            'logistics_contact_name' => ['nullable', 'string', 'max:255'],
            'logistics_contact_phone' => ['nullable', 'string', 'max:255'],
            'dispatch_conditions' => ['nullable', 'string'],
            'billing_contact_name' => ['nullable', 'string', 'max:255'],
            'billing_contact_phone' => ['nullable', 'string', 'max:255'],
            'taxpayer_type' => ['nullable', 'string', 'max:255'],
            'credit_term' => ['nullable', 'integer'],
            'client_type' => ['nullable', 'string', 'max:10'],
            'lead_time' => ['nullable', 'integer'],
            'portfolio_contact_name' => ['nullable', 'string', 'max:255'],
            'portfolio_contact_phone' => ['nullable', 'string', 'max:255'],
            'r_and_d_contact_name' => ['nullable', 'string', 'max:255'],
            'r_and_d_contact_phone' => ['nullable', 'string', 'max:255'],
            'r_and_d_contact_email' => ['nullable', 'string', 'max:500'],
            'data_consent' => ['nullable', 'boolean'],
            'marketing_consent' => ['nullable', 'boolean'],
            'ica' => ['nullable', 'numeric'],
            'retefuente' => ['nullable', 'numeric'],
            'reteiva' => ['nullable', 'numeric'],
            'iva' => ['nullable', 'numeric'],
            'rut_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'camara_comercio_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'cedula_representante_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'declaracion_renta_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'estados_financieros_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'nit.unique' => 'Ya existe un cliente con este NIT. Para crear uno nuevo debes eliminar el existente.',
            'email.unique' => 'Ya existe un cliente con este email. Para crear uno nuevo debes eliminar el existente.',
        ];
    }
}
