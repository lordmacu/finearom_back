<?php

namespace App\Http\Requests\ProjectPotential;

use Illuminate\Foundation\Http\FormRequest;

class ProjectPotentialExportRequest extends FormRequest
{
    use ResolvesPotentialYears;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Los mismos filtros del listado; sin ejecutiva se descargan todas
        return array_merge($this->yearRules(), [
            'ejecutivo'      => ['nullable', 'string', 'max:255'],
            'estado_externo' => ['nullable', 'in:En espera,Ganado,Perdido'],
        ]);
    }
}
