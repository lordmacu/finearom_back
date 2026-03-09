<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectFragrance extends Model
{
    use HasFactory;

    protected $table = 'project_fragrances';

    protected $fillable = [
        'project_id',
        'fine_fragrance_id',
        'gramos',
    ];

    protected $casts = [
        'gramos' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function fineFragrance(): BelongsTo
    {
        return $this->belongsTo(FineFragrance::class, 'fine_fragrance_id');
    }
}
