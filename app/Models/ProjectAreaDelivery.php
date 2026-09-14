<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notas de entrega de las áreas sin subentidad propia (regulatoria,
 * especiales). Una fila por proyecto y área.
 */
class ProjectAreaDelivery extends Model
{
    protected $table = 'project_area_deliveries';

    protected $fillable = [
        'project_id',
        'area',
        'notas_entrega',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
