<?php

namespace App\Http\Requests\FineFragrance;

use Illuminate\Foundation\Http\FormRequest;

class FineFragranceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fine_fragrance_house_id' => ['sometimes', 'integer', 'exists:fine_fragrance_houses,id'],
            'contratipo'              => ['sometimes', 'string', 'max:255'],
            'tipo'                    => ['sometimes', 'string', 'in:305,310,350'],
            'ano_lanzamiento'         => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'ano_desarrollo'          => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'genero'                  => ['sometimes', 'nullable', 'string', 'in:masculino,femenino,unisex'],
            'familia_olfativa'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'nombre'                  => ['sometimes', 'nullable', 'string', 'max:255'],
            'salida'                  => ['sometimes', 'nullable', 'string'],
            'corazon'                 => ['sometimes', 'nullable', 'string'],
            'fondo'                   => ['sometimes', 'nullable', 'string'],
            'precio_coleccion'        => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'costo'                   => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'inventario_kg'           => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'precio_oferta'           => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'estado'                  => ['sometimes', 'nullable', 'string', 'in:activa,inactiva,novedad,saldo'],
            'foto_url'                => ['sometimes', 'nullable', 'string', 'max:255'],
            'observaciones'           => ['sometimes', 'nullable', 'string'],
            'activo'                  => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
