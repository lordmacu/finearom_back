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
        'tipo_aplicacion',
        'packaging',
        'claims',
        'fecha_entrega_marketing',
        'cert_alergenos',
        'cert_biodegradabilidad',
        'cert_animal_testing',
        'cert_coa',
    ];

    protected $casts = [
        'marketing'               => 'array',
        'calidad'                 => 'array',
        'fecha_entrega_marketing' => 'date',
        'cert_alergenos'          => 'boolean',
        'cert_biodegradabilidad'  => 'boolean',
        'cert_animal_testing'     => 'boolean',
        'cert_coa'                => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
