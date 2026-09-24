<?php

namespace App\Http\Requests\RawMaterial;

use Illuminate\Foundation\Http\FormRequest;

class RawMaterialStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo'         => ['nullable', 'string', 'max:100'],
            'nombre'         => ['required', 'string', 'max:255'],
            'costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'cas'            => ['nullable', 'string', 'max:255'],
            'descriptores'   => ['nullable', 'string', 'max:2000'],
        ];
    }
}
