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
            'codigo'           => ['nullable', 'string', 'max:100'],
            'nombre'           => ['required', 'string', 'max:255'],
            'tipo'             => ['required', 'in:corazon,materia_prima'],
            'unidad'           => ['required', 'in:kg,g,L,ml,un'],
            'costo_unitario'   => ['nullable', 'numeric', 'min:0'],
            'stock_disponible' => ['nullable', 'numeric', 'min:0'],
            'descripcion'      => ['nullable', 'string', 'max:2000'],
            'proveedor'        => ['nullable', 'string', 'max:255'],
            'activo'           => ['nullable', 'boolean'],
        ];
    }
}
