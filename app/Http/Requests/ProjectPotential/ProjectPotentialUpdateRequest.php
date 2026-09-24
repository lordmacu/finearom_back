<?php

namespace App\Http\Requests\ProjectPotential;

use Illuminate\Foundation\Http\FormRequest;

class ProjectPotentialUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Solo el Kg se edita; el USD se calcula (Precio × Kg) en el modelo
        return [
            'potencial_anual_kg' => ['present', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'potencial_anual_kg.present' => 'Indica el potencial en Kg.',
            'potencial_anual_kg.min'     => 'El potencial en Kg no puede ser negativo.',
        ];
    }
}
