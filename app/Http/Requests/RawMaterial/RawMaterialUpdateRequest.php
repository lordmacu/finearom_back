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
            'codigo'           => ['sometimes', 'nullable', 'string', 'max:100'],
            'nombre'           => ['sometimes', 'required', 'string', 'max:255'],
            'tipo'             => ['sometimes', 'required', 'in:corazon,materia_prima'],
            'unidad'           => ['sometimes', 'required', 'in:kg,g,L,ml,un'],
            'costo_unitario'   => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock_disponible' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'descripcion'      => ['sometimes', 'nullable', 'string', 'max:2000'],
            'proveedor'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'activo'           => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
