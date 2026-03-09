<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSample extends Model
{
    use HasFactory;

    protected $table = 'project_samples';

    protected $fillable = [
        'project_id',
        'cantidad',
        'observaciones',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
