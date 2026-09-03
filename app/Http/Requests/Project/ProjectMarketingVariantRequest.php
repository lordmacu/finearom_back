<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectMarketingVariant;
use Illuminate\Foundation\Http\FormRequest;

class ProjectMarketingVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $max = ProjectMarketingVariant::MAX_REFERENCES;

        return [
            'nombre'                         => ['nullable', 'string', 'max:200'],
            'referencias'                    => ['required', 'array', 'min:1', "max:{$max}"],
            'referencias.*.referencia'       => ['nullable', 'string', 'max:200'],
            'referencias.*.codigo'           => ['nullable', 'string', 'max:100'],
            'referencias.*.aplicacion'       => ['nullable', 'string', 'max:200'],
            'referencias.*.dosis'            => ['nullable', 'numeric', 'min:0', 'max:100'],
            'referencias.*.color_etiqueta'   => ['nullable', 'string', 'max:50'],
            'referencias.*.claims'           => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        $max = ProjectMarketingVariant::MAX_REFERENCES;

        return [
            'referencias.max'             => "Una variante admite máximo {$max} referencias.",
            'referencias.min'             => 'La variante necesita al menos una referencia.',
            'referencias.required'        => 'La variante necesita al menos una referencia.',
            'referencias.*.dosis.max'     => 'La dosis es un porcentaje: no puede pasar de 100.',
        ];
    }
}
