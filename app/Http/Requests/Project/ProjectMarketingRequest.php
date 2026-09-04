<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectMarketingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Los arrays de archivos (logo_marca, benchmark_examples, catalog_etiquetas,
     * catalog_piramides, lista_presentaciones) NO se validan aquí a propósito:
     * los administra ProjectMarketingUploadController. Si el formulario los
     * mandara, una copia vieja en memoria borraría los archivos ya subidos.
     */
    public function rules(): array
    {
        return [
            'marketing'               => ['nullable', 'array'],
            'marketing.*'             => ['nullable', 'string', 'max:255'],
            'calidad'                 => ['nullable', 'array'],
            'calidad.*'               => ['nullable', 'string', 'max:255'],
            'obs_marketing'           => ['nullable', 'string', 'max:2000'],
            'obs_calidad'             => ['nullable', 'string', 'max:2000'],
            'marca'                   => ['nullable', 'string', 'max:255'],
            'tipo_envase'             => ['nullable', 'string', 'max:255'],
            'descripcion_detallada'   => ['nullable', 'string', 'max:5000'],
            'fecha_entrega_marketing' => ['nullable', 'date'],
        ];
    }
}
