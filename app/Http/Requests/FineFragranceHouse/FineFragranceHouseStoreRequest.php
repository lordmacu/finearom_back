<?php

namespace App\Http\Requests\FineFragranceHouse;

use Illuminate\Foundation\Http\FormRequest;

class FineFragranceHouseStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre'      => ['required', 'string', 'max:255', 'unique:fine_fragrance_houses,nombre'],
            'descripcion' => ['nullable', 'string'],
            'activo'      => ['nullable', 'boolean'],
        ];
    }
}
