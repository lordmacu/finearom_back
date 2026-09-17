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
        // Se edita un campo a la vez desde la tabla (vacío = borrar el valor)
        return [
            'potencial_anual_usd' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'potencial_anual_kg'  => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        // Al menos uno de los dos tiene que venir en la petición, aunque sea null
        $validator->after(function ($validator) {
            if (!$this->has('potencial_anual_usd') && !$this->has('potencial_anual_kg')) {
                $validator->errors()->add('potencial_anual_usd', 'Indica el potencial en USD o en Kg.');
                $validator->errors()->add('potencial_anual_kg', 'Indica el potencial en USD o en Kg.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'potencial_anual_usd.min' => 'El potencial en USD no puede ser negativo.',
            'potencial_anual_kg.min'  => 'El potencial en Kg no puede ser negativo.',
        ];
    }
}
