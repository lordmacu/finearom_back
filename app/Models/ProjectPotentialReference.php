<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referencia de un proyecto que el cliente seleccionó, con su potencial
 * comercial (Potencial a la vista). El precio no vive aquí: es el que
 * Desarrollo asignó a la referencia.
 */
class ProjectPotentialReference extends Model
{
    public const ESTADOS = ['abierto', 'ganado', 'perdido', 'cancelado'];
    public const PROBABILIDADES = ['alta', 'media', 'baja'];
    public const FRECUENCIAS = ['una_vez', 'mensual', 'bimensual', 'trimestral', 'cuatrimestral', 'semestral', 'anual'];

    protected $fillable = [
        'project_id',
        'reference_id',
        'kg_anio',
        'fecha_primer_despacho',
        'venta_anio_usd',
        'frecuencia_compra',
        'seguimiento',
        'estado',
        'probabilidad',
    ];

    protected $casts = [
        'kg_anio'               => 'decimal:2',
        'venta_anio_usd'        => 'decimal:2',
        'fecha_primer_despacho' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(ProjectMarketingVariantReference::class, 'reference_id');
    }
}
