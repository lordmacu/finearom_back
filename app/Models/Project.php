<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\ProductCategory;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'projects';

    protected $fillable = [
        'nombre',
        'client_id',
        'prospect_id',
        'legacy_id',
        'nombre_prospecto',
        'email_prospecto',
        'product_id',
        'product_category_id',
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
        'costo_perfumacion_especifico',
        'max_variantes',
        'homologacion',
        'internacional',
        'ejecutivo',
        'ejecutivo_id',
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
        'drive_folder_link',
        'razon_perdida',
        'dias_diferencia',
        'fecha_cierre_estimada',
        'potencial_anual_usd',
        'potencial_anual_kg',
        'probabilidad_cierre',
        'frecuencia_compra_estimada',
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
        'costo_perfumacion_especifico' => 'decimal:2',
        'max_variantes' => 'integer',
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
        'fecha_cierre_estimada'       => 'date',
        'potencial_anual_usd'         => 'decimal:2',
        'potencial_anual_kg'          => 'decimal:2',
        'frecuencia_compra_estimada'  => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    public function ejecutivoUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ejecutivo_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProjectProductType::class, 'product_id');
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

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'project_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(\App\Models\ProjectStatusHistory::class, 'project_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class, 'project_id');
    }

    public function quotationLogs(): HasMany
    {
        return $this->hasMany(ProjectQuotationLog::class, 'project_id');
    }

    public function googleTaskConfigs(): HasMany
    {
        return $this->hasMany(ProjectGoogleTaskConfig::class, 'project_id');
    }

    /**
     * Nombre de visualización del cliente: cliente real o nombre de prospecto.
     */
    public function getClientDisplayNameAttribute(): string
    {
        return $this->client?->client_name ?? $this->nombre_prospecto ?? '(Sin cliente)';
    }
}
