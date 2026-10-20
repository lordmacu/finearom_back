<?php

namespace App\Http\Requests\Proforma;

use Illuminate\Foundation\Http\FormRequest;

class ProformaManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => 'required|integer|exists:clients,id',
            'date' => 'nullable|date',
            'flete' => 'nullable|numeric|min:0',
            'lines' => 'required|array|min:1',
            'lines.*.code' => 'required|string|max:100',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.price_usd' => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'Selecciona un cliente',
            'lines.required' => 'Agrega al menos un producto',
            'lines.min' => 'Agrega al menos un producto',
            'lines.*.quantity.gt' => 'La cantidad debe ser mayor a 0',
        ];
    }
}
