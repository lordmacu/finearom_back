<?php

namespace App\Http\Requests\RawMaterial;

use Illuminate\Foundation\Http\FormRequest;

class RawMaterialUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'nombre'       => ['sometimes', 'required', 'string', 'max:255'],
            'cas'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'descriptores' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
