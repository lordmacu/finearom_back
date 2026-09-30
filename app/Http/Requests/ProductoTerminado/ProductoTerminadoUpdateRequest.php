<?php

namespace App\Http\Requests\ProductoTerminado;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductoTerminadoUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('productos_terminados', 'codigo')->ignore($this->route('productoTerminado')),
            ],
            'nombre' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
