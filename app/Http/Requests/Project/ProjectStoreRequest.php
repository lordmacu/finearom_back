<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (!$this->client_id && !$this->nombre_prospecto) {
                $v->errors()->add('client_id', 'Ingresa un cliente del sistema o el nombre del prospecto.');
            }
        });
    }

    public function rules(): array
    {
        return [
            'nombre'            => 'required|string|max:300',
            'client_id'         => 'nullable|integer|exists:clients,id',
            'nombre_prospecto'  => 'nullable|string|max:300',
            'email_prospecto'   => 'nullable|email|max:200',
            'product_id'          => 'nullable|integer|exists:project_product_types,id',
            'product_category_id' => 'nullable|integer|exists:product_categories,id',
            'tipo'            => 'required|in:Colección,Desarrollo,Fine Fragances',
            'rango_min'       => 'nullable|numeric|min:0',
            'rango_max'       => 'nullable|numeric|min:0|gte:rango_min',
            'volumen'         => 'nullable|numeric|min:0',
            'trm'             => 'nullable|numeric|min:0',
            'factor'                       => 'nullable|numeric|min:0',
            'costo_perfumacion_especifico' => 'nullable|numeric|min:0',
            'max_variantes'                => 'nullable|integer|min:1|max:50',
            'base_cliente'    => 'nullable|boolean',
            'proactivo'       => 'nullable|boolean',
            'homologacion'    => 'nullable|boolean',
            'internacional'   => 'nullable|boolean',
            'fecha_requerida' => 'nullable|date',
            'fecha_creacion'  => 'nullable|date',
            'tipo_producto'   => 'nullable|string|max:200',
            'ejecutivo_id'    => 'nullable|integer|exists:users,id',
            'ejecutivo'       => 'nullable|string|max:200',
            'fecha_cierre_estimada'      => 'nullable|date',
            'potencial_anual_usd'        => 'nullable|numeric|min:0',
            'potencial_anual_kg'         => 'nullable|numeric|min:0',
            'probabilidad_cierre'        => 'nullable|in:alto,medio,bajo',
            'frecuencia_compra_estimada' => 'nullable|integer|min:1|max:999',
        ];
    }
}
