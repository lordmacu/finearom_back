<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectEvaluation extends Model
{
    use HasFactory;

    protected $table = 'project_evaluations';

    protected $fillable = [
        'project_id',
        'tipos',
        'benchmark_reference_id',
        'metodologia',
        'observacion',
    ];

    protected $casts = [
        'tipos' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function benchmarkReference(): BelongsTo
    {
        return $this->belongsTo(FinearomReference::class, 'benchmark_reference_id');
    }
}
