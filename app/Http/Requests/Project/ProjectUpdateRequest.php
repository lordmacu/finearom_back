<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre'           => 'nullable|string|max:300',
            'client_id'        => 'nullable|integer|exists:clients,id',
            'nombre_prospecto'  => 'nullable|string|max:300',
            'email_prospecto'   => 'nullable|email|max:200',
            'product_id'       => 'nullable|integer|exists:project_product_types,id',
            'tipo'            => 'nullable|in:Colección,Desarrollo,Fine Fragances',
            'rango_min'       => 'nullable|numeric|min:0',
            'rango_max'       => 'nullable|numeric|min:0|gte:rango_min',
            'volumen'         => 'nullable|numeric|min:0',
            'trm'             => 'nullable|numeric|min:0',
            'factor'          => 'nullable|numeric|min:0',
            'base_cliente'    => 'nullable|boolean',
            'proactivo'       => 'nullable|boolean',
            'homologacion'    => 'nullable|boolean',
            'internacional'   => 'nullable|boolean',
            'fecha_requerida' => 'nullable|date',
            'fecha_creacion'  => 'nullable|date',
            'tipo_producto'   => 'nullable|string|max:200',
            'ejecutivo_id'    => 'nullable|integer|exists:users,id',
            'ejecutivo'       => 'nullable|string|max:200',
            'obs_lab'         => 'nullable|string',
            'obs_des'         => 'nullable|string',
            'obs_mer'         => 'nullable|string',
            'obs_cal'         => 'nullable|string',
            'obs_esp'         => 'nullable|string',
            'obs_ext'         => 'nullable|string',
        ];
    }
}
