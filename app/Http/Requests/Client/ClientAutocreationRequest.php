<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class ClientAutocreationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:clients,email'],
            'nit' => ['nullable', 'string', 'max:255', 'unique:clients,nit'],
            'executive_name' => ['nullable', 'string', 'max:255'],
            'executive_email' => ['nullable', 'email', 'max:255'],
            'executive_phone' => ['nullable', 'string', 'max:255'],
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
