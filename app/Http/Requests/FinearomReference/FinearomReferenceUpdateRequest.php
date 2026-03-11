<?php

namespace App\Http\Requests\FinearomReference;

use Illuminate\Foundation\Http\FormRequest;

class FinearomReferenceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo'               => ['sometimes', 'nullable', 'string', 'max:100'],
            'nombre'               => ['sometimes', 'required', 'string', 'max:255'],
            'precio'               => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'dosis'                => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tipo_producto'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'descripcion_olfativa' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
