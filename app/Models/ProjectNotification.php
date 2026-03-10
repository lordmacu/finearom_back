<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectNotification extends Model
{
    protected $table = 'project_notifications';

    protected $fillable = [
        'user_id',
        'project_id',
        'tipo',
        'titulo',
        'mensaje',
        'data',
        'leida_at',
    ];

    protected $casts = [
        'data'     => 'array',
        'leida_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('leida_at');
    }

    // -------------------------------------------------------------------------
    // Static helper
    // -------------------------------------------------------------------------

    public static function notify(
        int $userId,
        string $tipo,
        string $titulo,
        string $mensaje,
        array $data = [],
        ?int $projectId = null
    ): self {
        return static::create([
            'user_id'    => $userId,
            'project_id' => $projectId,
            'tipo'       => $tipo,
            'titulo'     => $titulo,
            'mensaje'    => $mensaje,
            'data'       => $data ?: null,
        ]);
    }
}
