<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContributionMargin extends Model
{
    protected $fillable = [
        'tipo_cliente',
        'volumen_min',
        'volumen_max',
        'factor',
        'descripcion',
        'activo',
    ];

    protected $casts = [
        'activo'      => 'boolean',
        'factor'      => 'float',
        'volumen_min' => 'integer',
        'volumen_max' => 'integer',
    ];

    /// Busca el factor activo para un tipo de cliente y un volumen dado (Kg/año).
    /// Retorna el factor como float, o null si ningún rango cubre ese volumen.
    public static function getFactorFor(string $tipoCliente, int $volumenKg): ?float
    {
        $margin = static::where('tipo_cliente', $tipoCliente)
            ->where('activo', true)
            ->where('volumen_min', '<=', $volumenKg)
            ->where(function ($q) use ($volumenKg) {
                $q->whereNull('volumen_max')
                  ->orWhere('volumen_max', '>=', $volumenKg);
            })
            ->orderBy('volumen_min', 'desc')
            ->first();

        return $margin?->factor;
    }
}
