<?php

namespace App\Http\Requests\ProductoTerminado;

use Illuminate\Foundation\Http\FormRequest;

class ProductoTerminadoStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:100', 'unique:productos_terminados,codigo'],
            'nombre' => ['required', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
