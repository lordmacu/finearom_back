<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMarketing extends Model
{
    use HasFactory;

    protected $table = 'project_marketing';

    protected $fillable = [
        'project_id',
        'marketing',
        'calidad',
        'obs_marketing',
        'obs_calidad',
        'marca',
        'logo_marca',
        'tipo_envase',
        'descripcion_detallada',
        'fecha_entrega_marketing',
    ];

    protected $casts = [
        'marketing'               => 'array',
        'calidad'                 => 'array',
        'logo_marca'              => 'array',
        'benchmark_examples'      => 'array',
        'catalog_etiquetas'       => 'array',
        'catalog_piramides'        => 'array',
        'lista_presentaciones'    => 'array',
        'fecha_entrega_marketing' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
