<?php

namespace App\Http\Requests\Corazon;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorazonUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo'      => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('raw_materials', 'codigo')->ignore($this->route('corazon')),
            ],
            'nombre'      => ['sometimes', 'required', 'string', 'max:255'],
            'descripcion' => ['sometimes', 'required', 'string', 'max:2000'],
        ];
    }
}
