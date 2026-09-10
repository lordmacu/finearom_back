<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectMarketingVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre'         => ['nullable', 'string', 'max:200'],
            'claims'         => ['nullable', 'string', 'max:2000'],
            'color_etiqueta' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'color_etiqueta.max' => 'El color de etiqueta no puede pasar de 50 caracteres.',
            'claims.max'         => 'Los claims no pueden pasar de 2000 caracteres.',
        ];
    }
}
