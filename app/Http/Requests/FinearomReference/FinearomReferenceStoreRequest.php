<?php

namespace App\Http\Requests\FinearomReference;

use Illuminate\Foundation\Http\FormRequest;

class FinearomReferenceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo'               => ['nullable', 'string', 'max:100'],
            'nombre'               => ['required', 'string', 'max:255'],
            'precio'               => ['nullable', 'numeric', 'min:0'],
            'dosis'                => ['nullable', 'numeric', 'min:0'],
            'tipo_producto'        => ['nullable', 'string', 'max:255'],
            'descripcion_olfativa' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
