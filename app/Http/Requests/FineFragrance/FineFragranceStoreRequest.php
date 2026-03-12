<?php

namespace App\Http\Requests\FineFragrance;

use Illuminate\Foundation\Http\FormRequest;

class FineFragranceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fine_fragrance_house_id' => ['required', 'integer', 'exists:fine_fragrance_houses,id'],
            'contratipo'              => ['required', 'string', 'max:255'],
            'inspiracion'             => ['nullable', 'string', 'max:255'],
            'tipo'                    => ['required', 'string', 'in:305,310,350'],
            'ano_lanzamiento'         => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'ano_desarrollo'          => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'genero'                  => ['nullable', 'string', 'in:masculino,femenino,unisex'],
            'familia_olfativa'        => ['nullable', 'string', 'max:255'],
            'nombre'                  => ['nullable', 'string', 'max:255'],
            'salida'                  => ['nullable', 'string'],
            'corazon'                 => ['nullable', 'string'],
            'fondo'                   => ['nullable', 'string'],
            'precio_coleccion'        => ['nullable', 'numeric', 'min:0'],
            'costo'                   => ['nullable', 'numeric', 'min:0'],
            'inventario_kg'           => ['nullable', 'numeric', 'min:0'],
            'precio_oferta'           => ['nullable', 'numeric', 'min:0'],
            'estado'                  => ['nullable', 'string', 'in:activa,inactiva,novedad,saldo'],
            'foto_url'                => ['nullable', 'string', 'max:255'],
            'observaciones'           => ['nullable', 'string'],
            'activo'                  => ['nullable', 'boolean'],
        ];
    }
}
