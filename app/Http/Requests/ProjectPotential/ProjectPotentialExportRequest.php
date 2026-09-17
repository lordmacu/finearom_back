<?php

namespace App\Http\Requests\ProjectPotential;

use Illuminate\Foundation\Http\FormRequest;

class ProjectPotentialExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Los mismos filtros del listado; sin ejecutiva se descargan todas
        return [
            'ejecutivo'      => ['nullable', 'string', 'max:255'],
            'estado_externo' => ['nullable', 'in:En espera,Ganado,Perdido'],
            'anio'           => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
