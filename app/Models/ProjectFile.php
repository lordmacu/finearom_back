<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectFile extends Model
{
    protected $table = 'project_files';

    protected $fillable = [
        'project_id',
        'nombre_original',
        'nombre_storage',
        'path',
        'mime_type',
        'size',
        'categoria',
        'ejecutivo',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
