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
            // Provisionales 320/330: marca y la materia prima 300 equivalente
            'pendiente_equivalencia' => ['sometimes', 'boolean'],
            'equivalente_id' => [
                'sometimes', 'nullable', 'integer',
                function (string $attribute, $value, $fail) {
                    $eq = \App\Models\RawMaterial::find($value);
                    $actual = $this->route('rawMaterial');
                    if (!$eq || $eq->tipo !== 'materia_prima' || $eq->pendiente_equivalencia || ($actual && $eq->id === $actual->id)) {
                        $fail('El equivalente debe ser otra materia prima que no esté pendiente de equivalencia.');
                    }
                },
            ],
        ];
    }
}
