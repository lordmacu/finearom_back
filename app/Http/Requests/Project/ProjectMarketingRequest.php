<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectMarketingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
            'variante'                => ['nullable', 'string', 'max:255'],
            'tipo_aplicacion'         => ['nullable', 'string', 'max:255'],
            'tipo_envase'             => ['nullable', 'string', 'max:255'],
            'packaging'               => ['nullable', 'string', 'max:255'],
            'claims'                  => ['nullable', 'string', 'max:2000'],
            'benchmark_links'         => ['nullable', 'string', 'max:5000'],
            'benchmark_examples'      => ['nullable', 'array'],
            'benchmark_examples.*'    => ['nullable', 'string'],
            'catalog_etiquetas'       => ['nullable', 'array'],
            'catalog_etiquetas.*'     => ['nullable', 'string'],
            'catalog_piramides'        => ['nullable', 'array'],
            'catalog_piramides.*'     => ['nullable', 'string'],
            'lista_presentaciones'    => ['nullable', 'array'],
            'lista_presentaciones.*'  => ['nullable', 'string'],
            'descripcion_detallada'   => ['nullable', 'string', 'max:5000'],
            'fecha_entrega_marketing' => ['nullable', 'date'],
        ];
    }
}
