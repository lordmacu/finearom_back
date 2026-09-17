<?php

namespace App\Http\Requests\ProjectPotential;

use App\Models\ProjectPotentialReference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectPotentialSelectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'present': un array vacío deja el proyecto sin referencias seleccionadas
            'selecciones'                         => ['present', 'array'],
            'selecciones.*.reference_id'          => ['required', 'integer', 'distinct'],
            'selecciones.*.kg_anio'               => ['nullable', 'numeric', 'min:0'],
            'selecciones.*.fecha_primer_despacho' => ['nullable', 'date'],
            'selecciones.*.venta_anio_usd'        => ['nullable', 'numeric', 'min:0'],
            'selecciones.*.frecuencia_compra'     => ['nullable', Rule::in(ProjectPotentialReference::FRECUENCIAS)],
            'selecciones.*.seguimiento'           => ['nullable', 'string', 'max:2000'],
            'selecciones.*.estado'                => ['required', Rule::in(ProjectPotentialReference::ESTADOS)],
            'selecciones.*.probabilidad'          => ['nullable', Rule::in(ProjectPotentialReference::PROBABILIDADES)],
        ];
    }

    public function messages(): array
    {
        return [
            'selecciones.*.kg_anio.min'        => 'Los Kg/año no pueden ser negativos.',
            'selecciones.*.venta_anio_usd.min' => 'La venta del año no puede ser negativa.',
            'selecciones.*.estado.required'    => 'Cada referencia seleccionada necesita un estado.',
        ];
    }
}
