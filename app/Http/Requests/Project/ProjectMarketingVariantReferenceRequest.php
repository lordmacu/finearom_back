<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectMarketingVariant;
use Illuminate\Foundation\Http\FormRequest;

class ProjectMarketingVariantReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $max = ProjectMarketingVariant::MAX_REFERENCES;

        return [
            // 'present' y no 'required': un array vacío es válido, así
            // Desarrollo puede dejar la variante sin referencias.
            'referencias'                => ['present', 'array', "max:{$max}"],
            'referencias.*'              => ['array'],
            // id de una referencia existente: se actualiza en su lugar (conserva lo que cuelga de ella)
            'referencias.*.id'           => ['nullable', 'integer'],
            'referencias.*.referencia'   => ['nullable', 'string', 'max:200'],
            'referencias.*.codigo'       => ['nullable', 'string', 'max:100'],
            'referencias.*.aplicacion'   => ['nullable', 'string', 'max:200'],
            'referencias.*.dosis'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'referencias.*.precio'       => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        $max = ProjectMarketingVariant::MAX_REFERENCES;

        return [
            'referencias.max'         => "Una variante admite máximo {$max} referencias.",
            'referencias.present'     => 'Falta el listado de referencias.',
            'referencias.*.dosis.max' => 'La dosis es un porcentaje: no puede pasar de 100.',
            'referencias.*.precio.min' => 'El precio no puede ser negativo.',
        ];
    }
}
