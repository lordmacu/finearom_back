<?php

namespace App\Http\Requests\Project;

use App\Rules\ProductTypeBelongsToCategory;
use App\Support\ProjectOwnership;
use Illuminate\Foundation\Http\FormRequest;

class ProjectUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Regla de dueño: una comercial solo edita proyectos donde es la ejecutiva
        return ProjectOwnership::canManage($this->user(), $this->route('project'));
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $project = $this->route('project');
            $min = $this->has('rango_min') ? $this->input('rango_min') : $project?->rango_min;
            $max = $this->has('rango_max') ? $this->input('rango_max') : $project?->rango_max;
            if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min) {
                $v->errors()->add('rango_max', 'El rango máximo debe ser mayor o igual al mínimo.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        // tipo_homologacion solo aplica cuando la característica es Homologación.
        // Solo se fuerza a null si "homologacion" viene explícito en el request
        // (update parcial: si no viene, no se toca lo que ya había).
        if ($this->has('homologacion') && !$this->boolean('homologacion')) {
            $this->merge(['tipo_homologacion' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'nombre'           => 'nullable|string|max:300',
            'client_id'        => 'nullable|integer|exists:clients,id',
            'nombre_prospecto'  => 'nullable|string|max:300',
            'email_prospecto'   => 'nullable|email|max:200',
            'product_id'          => [
                'nullable', 'integer', 'exists:project_product_types,id',
                new ProductTypeBelongsToCategory($this->integer('product_category_id') ?: null),
            ],
            'product_category_id' => 'nullable|integer|exists:product_categories,id',
            'tipo'            => 'nullable|in:Colección,Desarrollo,Fine Fragances',
            'secciones_visibles'   => 'nullable|array',
            'secciones_visibles.*' => 'in:desarrollo,evaluaciones,regulatoria,marketing,comercial',
            'rango_min'       => 'nullable|numeric|min:0',
            // Se compara con el mínimo (el enviado o el guardado) en withValidator
            'rango_max'       => 'nullable|numeric|min:0',
            'volumen'         => 'nullable|numeric|min:0',
            // precio ya no se usa: el potencial USD se calcula con el rango máximo
            'dosis'           => 'nullable|numeric|min:0|max:100',
            'trm'             => 'nullable|numeric|min:0',
            'factor'                       => 'nullable|numeric|min:0',
            'costo_perfumacion_especifico' => 'nullable|numeric|min:0',
            'costo_perfumacion_tonelada'   => 'nullable|numeric|min:0',
            'tipo_etiquetado'              => 'nullable|in:Estandar,SGA',
            'envelope_type_ids'            => 'nullable|array',
            'envelope_type_ids.*'          => 'integer|exists:envelope_types,id',
            // max_variantes ya no se administra (sin límite de variantes)
            'base_cliente'    => 'nullable|boolean',
            'proactivo'       => 'nullable|boolean',
            'homologacion'    => 'nullable|boolean',
            'tipo_homologacion' => 'nullable|in:cromatografia,olfativa',
            'tipo_desarrollo' => 'nullable|in:desde_cero,ajuste_formula,piramides_olfativas',
            'area_aplicacion' => 'nullable|in:pesaje_aceites,aplicaciones_liquidas,aplicaciones_jabon,montaje_estabilidad',
            'seleccion_envase_aplicacion' => 'sometimes|boolean',
            'area_evaluaciones' => 'nullable|in:evaluacion_laundry,evaluacion_cabinas',
            'nuevo_tipo_producto' => 'nullable|string|max:200',
            'internacional'   => 'nullable|boolean',
            'fecha_requerida' => 'nullable|date',
            'fecha_creacion'  => 'nullable|date',
            'fecha_entrega'   => 'nullable|date',
            'tipo_producto'   => 'nullable|string|max:200',
            'ejecutivo_id'    => 'nullable|integer|exists:users,id',
            // desarrollador_id no va aquí: lo asigna un ingeniero (PATCH /projects/{id}/ingeniero)
            'ejecutivo'       => 'nullable|string|max:200',
            'obs_lab'         => 'nullable|string',
            'obs_des'         => 'nullable|string',
            'obs_mer'         => 'nullable|string',
            'obs_cal'         => 'nullable|string',
            'obs_esp'         => 'nullable|string',
            'obs_ext'         => 'nullable|string',
            'fecha_cierre_estimada'      => 'nullable|date',
            'potencial_anual_kg'         => 'nullable|numeric|min:0',
            'probabilidad_cierre'        => 'nullable|in:alto,medio,bajo',
            'frecuencia_compra_estimada' => 'nullable|integer|min:1|max:999',
        ];
    }
}
