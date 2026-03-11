<?php

namespace App\Http\Requests\FineFragranceHouse;

use Illuminate\Foundation\Http\FormRequest;

class FineFragranceHouseUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $houseId = $this->route('fine_fragrance_house')?->id;

        return [
            'nombre'      => ['sometimes', 'string', 'max:255', "unique:fine_fragrance_houses,nombre,{$houseId}"],
            'descripcion' => ['sometimes', 'nullable', 'string'],
            'activo'      => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
