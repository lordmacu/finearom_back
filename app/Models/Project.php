<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'projects';

    protected $fillable = [
        'nombre',
        'client_id',
        'product_id',
        'tipo',
        'rango_min',
        'rango_max',
        'volumen',
        'base_cliente',
        'proactivo',
        'fecha_requerida',
        'fecha_creacion',
        'fecha_calculada',
        'fecha_entrega',
        'tipo_producto',
        'trm',
        'factor',
        'homologacion',
        'internacional',
        'ejecutivo',
        'estado_externo',
        'fecha_externo',
        'ejecutivo_externo',
        'estado_interno',
        'ejecutivo_interno',
        'estado_desarrollo',
        'fecha_desarrollo',
        'ejecutivo_desarrollo',
        'estado_laboratorio',
        'fecha_laboratorio',
        'ejecutivo_laboratorio',
        'estado_mercadeo',
        'fecha_mercadeo',
        'ejecutivo_mercadeo',
        'estado_calidad',
        'fecha_calidad',
        'ejecutivo_calidad',
        'estado_especiales',
        'fecha_especiales',
        'ejecutivo_especiales',
        'obs_lab',
        'obs_des',
        'obs_mer',
        'obs_cal',
        'obs_esp',
        'obs_ext',
        'actualizado',
    ];

    protected $casts = [
        'base_cliente' => 'boolean',
        'proactivo' => 'boolean',
        'homologacion' => 'boolean',
        'internacional' => 'boolean',
        'actualizado' => 'boolean',
        'estado_desarrollo' => 'boolean',
        'estado_laboratorio' => 'boolean',
        'estado_mercadeo' => 'boolean',
        'estado_calidad' => 'boolean',
        'estado_especiales' => 'boolean',
        'rango_min' => 'decimal:2',
        'rango_max' => 'decimal:2',
        'volumen' => 'decimal:2',
        'trm' => 'decimal:2',
        'factor' => 'decimal:4',
        'fecha_requerida' => 'date',
        'fecha_creacion' => 'date',
        'fecha_calculada' => 'date',
        'fecha_entrega' => 'date',
        'fecha_externo' => 'date',
        'fecha_desarrollo' => 'date',
        'fecha_laboratorio' => 'date',
        'fecha_mercadeo' => 'date',
        'fecha_calidad' => 'date',
        'fecha_especiales' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function sample(): HasOne
    {
        return $this->hasOne(ProjectSample::class, 'project_id');
    }

    public function application(): HasOne
    {
        return $this->hasOne(ProjectApplication::class, 'project_id');
    }

    public function evaluation(): HasOne
    {
        return $this->hasOne(ProjectEvaluation::class, 'project_id');
    }

    public function marketingYCalidad(): HasOne
    {
        return $this->hasOne(ProjectMarketing::class, 'project_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProjectVariant::class, 'project_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ProjectRequest::class, 'project_id');
    }

    public function fragrances(): HasMany
    {
        return $this->hasMany(ProjectFragrance::class, 'project_id');
    }
}
