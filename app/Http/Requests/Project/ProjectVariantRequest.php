<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // categoria, observaciones y el benchmark como referencia Finearom ya no
        // se capturan: el benchmark es una imagen + descripción de la variante
        return [
            'nombre'                  => ['required', 'string', 'max:255'],
            'descripcion'             => ['nullable', 'string', 'max:5000'],
            'benchmark_descripcion'   => ['nullable', 'string', 'max:2000'],
            'benchmark_imagen'        => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
            'remove_benchmark_imagen' => ['nullable', 'boolean'],
        ];
    }
}
