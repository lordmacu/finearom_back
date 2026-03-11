<?php

namespace App\Http\Requests\ReferenceFormulaLine;

use Illuminate\Foundation\Http\FormRequest;

class ReferenceFormulaLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'raw_material_id' => ['required', 'integer', 'exists:raw_materials,id'],
            'porcentaje'      => ['required', 'numeric', 'min:0.0001', 'max:100'],
            'notas'           => ['nullable', 'string', 'max:500'],
        ];
    }
}
