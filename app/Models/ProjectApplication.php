<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectApplication extends Model
{
    use HasFactory;

    protected $table = 'project_applications';

    protected $fillable = [
        'project_id',
        'dosis',
        'cantidad_aplicacion',
        'observaciones',
    ];

    protected $casts = [
        'dosis' => 'decimal:2',
        'cantidad_aplicacion' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
