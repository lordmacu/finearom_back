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
    ];

    protected $casts = [
        'marketing' => 'array',
        'calidad' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
