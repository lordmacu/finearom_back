<?php

namespace App\Http\Requests\ProjectPotential;

use Illuminate\Foundation\Http\FormRequest;

class ProjectPotentialKgRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'potencial_anual_kg' => ['present', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return ['potencial_anual_kg.min' => 'El potencial no puede ser negativo.'];
    }
}
