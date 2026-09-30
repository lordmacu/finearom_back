<?php

namespace App\Http\Requests\Corazon;

use Illuminate\Foundation\Http\FormRequest;

class CorazonStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo'      => ['required', 'string', 'max:100', 'unique:raw_materials,codigo'],
            'nombre'      => ['required', 'string', 'max:255'],
            'descripcion' => ['required', 'string', 'max:2000'],
        ];
    }
}
