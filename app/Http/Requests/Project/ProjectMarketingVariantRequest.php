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
            'referencia'      => ['nullable', 'string', 'max:200'],
            'codigo'          => ['nullable', 'string', 'max:100'],
            'aplicacion'      => ['nullable', 'string', 'max:200'],
            'dosis'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'color_etiqueta'  => ['nullable', 'string', 'max:50'],
            'claims'          => ['nullable', 'string', 'max:2000'],
        ];
    }
}