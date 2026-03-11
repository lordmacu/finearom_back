<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinearomEvaluation extends Model
{
    use HasFactory;

    protected $table = 'finearom_evaluations';

    protected $fillable = [
        'finearom_reference_id',
        'fecha_evaluacion',
        'benchmarks',
        'puntaje_agradabilidad',
        'puntaje_intensidad',
        'puntaje_promedio',
        'observaciones',
        'evaluado_por',
    ];

    protected $casts = [
        'fecha_evaluacion'      => 'date',
        'benchmarks'            => 'array',
        'puntaje_agradabilidad' => 'decimal:1',
        'puntaje_intensidad'    => 'decimal:1',
        'puntaje_promedio'      => 'decimal:1',
    ];

    public function finearomReference(): BelongsTo
    {
        return $this->belongsTo(FinearomReference::class, 'finearom_reference_id');
    }

    public function evaluadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluado_por');
    }
}
