<?php

namespace App\Http\Requests\ProjectPotential;

use Illuminate\Foundation\Http\FormRequest;

class ProjectPotentialIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ejecutivo'      => ['required', 'string', 'max:255'],
            'estado_externo' => ['nullable', 'in:En espera,Ganado,Perdido'],
            'anio'           => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }

    public function messages(): array
    {
        return ['ejecutivo.required' => 'Selecciona una ejecutiva.'];
    }
}
