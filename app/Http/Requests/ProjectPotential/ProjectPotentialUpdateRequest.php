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
        // Se edita un campo a la vez desde la tabla; al menos uno debe venir
        return [
            'potencial_anual_usd' => ['required_without:potencial_anual_kg', 'nullable', 'numeric', 'min:0'],
            'potencial_anual_kg'  => ['required_without:potencial_anual_usd', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'potencial_anual_usd.min' => 'El potencial en USD no puede ser negativo.',
            'potencial_anual_kg.min'  => 'El potencial en Kg no puede ser negativo.',
            'required_without'        => 'Indica el potencial en USD o en Kg.',
        ];
    }
}
