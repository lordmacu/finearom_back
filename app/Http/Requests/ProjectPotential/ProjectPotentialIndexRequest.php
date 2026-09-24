<?php

namespace App\Http\Requests\ProjectPotential;

use Illuminate\Foundation\Http\FormRequest;

class ProjectPotentialIndexRequest extends FormRequest
{
    use ResolvesPotentialYears;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->yearRules(), [
            'ejecutivo'      => ['required', 'string', 'max:255'],
            'estado_externo' => ['nullable', 'in:Sin definir,Cancelado,Ganado,Perdido'],
        ]);
    }

    public function messages(): array
    {
        return ['ejecutivo.required' => 'Selecciona una ejecutiva.'];
    }
}
