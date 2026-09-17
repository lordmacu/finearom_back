<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una entrega de un área del proyecto (bitácora): parcial, final o una
 * actualización posterior a la final. Solo lectura una vez creada.
 */
class ProjectAreaDeliveryLog extends Model
{
    public const PARCIAL = 'parcial';
    public const FINAL = 'final';
    public const ACTUALIZACION = 'actualizacion';

    protected $fillable = [
        'project_id',
        'area',
        'tipo',
        'notas',
        'ejecutivo',
        'user_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class, 'delivery_log_id');
    }
}
