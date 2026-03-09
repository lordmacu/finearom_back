<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectRequest extends Model
{
    use HasFactory;

    protected $table = 'project_requests';

    protected $fillable = [
        'project_id',
        'fragrance_id',
        'tipo',
        'porcentaje',
        'nombre_asociado',
    ];

    protected $casts = [
        'porcentaje' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function fragrance(): BelongsTo
    {
        return $this->belongsTo(Fragrance::class, 'fragrance_id');
    }
}
