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
            'tipo_aplicacion'         => ['nullable', 'string', 'max:255'],
            'packaging'               => ['nullable', 'string', 'max:255'],
            'claims'                  => ['nullable', 'string', 'max:2000'],
            'fecha_entrega_marketing' => ['nullable', 'date'],
            'cert_alergenos'          => ['nullable', 'boolean'],
            'cert_biodegradabilidad'  => ['nullable', 'boolean'],
            'cert_animal_testing'     => ['nullable', 'boolean'],
            'cert_coa'                => ['nullable', 'boolean'],
        ];
    }
}
