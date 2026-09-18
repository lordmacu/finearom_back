<?php

namespace App\Http\Requests\Project;

use App\Rules\ProductTypeBelongsToCategory;
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

    protected function prepareForValidation(): void
    {
        // tipo_homologacion solo aplica cuando la característica es Homologación.
        if (!$this->boolean('homologacion')) {
            $this->merge(['tipo_homologacion' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'nombre'            => 'required|string|max:300',
            'client_id'         => 'nullable|integer|exists:clients,id',
            'nombre_prospecto'  => 'nullable|string|max:300',
            'email_prospecto'   => 'nullable|email|max:200',
            'product_id'          => [
                'nullable', 'integer', 'exists:project_product_types,id',
                new ProductTypeBelongsToCategory($this->integer('product_category_id') ?: null),
            ],
            'product_category_id' => 'nullable|integer|exists:product_categories,id',
            'tipo'            => 'required|in:Colección,Desarrollo,Fine Fragances',
            'secciones_visibles'   => 'nullable|array',
            'secciones_visibles.*' => 'in:desarrollo,evaluaciones,regulatoria,marketing,comercial',
            'rango_min'       => 'nullable|numeric|min:0',
            'rango_max'       => 'nullable|numeric|min:0|gte:rango_min',
            'volumen'         => 'nullable|numeric|min:0',
            'precio'          => 'nullable|numeric|min:0',
            'dosis'           => 'nullable|numeric|min:0|max:100',
            'trm'             => 'nullable|numeric|min:0',
            'factor'                       => 'nullable|numeric|min:0',
            'costo_perfumacion_especifico' => 'nullable|numeric|min:0',
            'costo_perfumacion_tonelada'   => 'nullable|numeric|min:0',
            'tipo_etiquetado'              => 'nullable|in:Estandar,SGA',
            'envelope_type_ids'            => 'nullable|array',
            'envelope_type_ids.*'          => 'integer|exists:envelope_types,id',
            'max_variantes'                => 'nullable|integer|min:1|max:50',
            'base_cliente'    => 'nullable|boolean',
            'proactivo'       => 'nullable|boolean',
            'homologacion'    => 'nullable|boolean',
            'tipo_homologacion' => 'nullable|in:cromatografia,olfativa',
            'tipo_desarrollo' => 'nullable|in:desde_cero,ajuste_formula,piramides_olfativas',
            'area_aplicacion' => 'nullable|in:pesaje_aceites,aplicaciones_liquidas,aplicaciones_jabon,montaje_estabilidad',
            'area_evaluaciones' => 'nullable|in:evaluacion_laundry,evaluacion_cabinas',
            'nuevo_tipo_producto' => 'nullable|string|max:200',
            'internacional'   => 'nullable|boolean',
            'fecha_requerida' => 'nullable|date',
            'fecha_creacion'  => 'nullable|date',
            'tipo_producto'   => 'nullable|string|max:200',
            'ejecutivo_id'    => 'nullable|integer|exists:users,id',
            // desarrollador_id no va al crear: el ingeniero se asigna al editar
            'ejecutivo'       => 'nullable|string|max:200',
            'fecha_cierre_estimada'      => 'nullable|date',
            'potencial_anual_usd'        => 'nullable|numeric|min:0',
            'potencial_anual_kg'         => 'nullable|numeric|min:0',
            'probabilidad_cierre'        => 'nullable|in:alto,medio,bajo',
            'frecuencia_compra_estimada' => 'nullable|integer|min:1|max:999',
        ];
    }
}
