<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Plan de despachos de una referencia seleccionada (Potencial a la vista).
 *
 * Los Kg/año se reparten en despachos iguales según la frecuencia de compra
 * (trimestral = 4 al año = Kg/año ÷ 4 cada uno). Desde el mes del primer
 * despacho se repite cada N meses, también en los años siguientes; el USD de
 * cada mes es Kg × precio. "Una vez" despacha todo en el mes del primero.
 */
class PotentialDispatchPlan
{
    /** Meses entre despachos; null = un solo despacho. */
    public const INTERVALOS = [
        'mensual'       => 1,
        'bimensual'     => 2,
        'trimestral'    => 3,
        'cuatrimestral' => 4,
        'semestral'     => 6,
        'anual'         => 12,
        'una_vez'       => null,
    ];

    /**
     * @return array{anio:int, despachos:int, kg_por_despacho:float, meses:array<int, array{mes:int, kg:float, usd:?float}>, total_kg:float, total_usd:?float}|null
     */
    public static function for(
        float|string|null $kgAnio,
        float|string|null $precio,
        ?string $frecuencia,
        DateTimeInterface|string|null $primerDespacho,
        int $anio,
    ): ?array {
        if ($kgAnio === null || $primerDespacho === null || !array_key_exists((string) $frecuencia, self::INTERVALOS)) {
            return null;
        }

        $intervalo     = self::INTERVALOS[$frecuencia];
        $kgAnio        = (float) $kgAnio;
        $precio        = $precio === null ? null : (float) $precio;
        $primero       = Carbon::parse($primerDespacho);
        $kgPorDespacho = $intervalo === null ? $kgAnio : $kgAnio * $intervalo / 12;

        $meses     = [];
        $despachos = 0;
        foreach (range(1, 12) as $mes) {
            $desdePrimero = ($anio - $primero->year) * 12 + ($mes - $primero->month);
            $toca = $intervalo === null
                ? $desdePrimero === 0
                : $desdePrimero >= 0 && $desdePrimero % $intervalo === 0;

            $kg = $toca ? round($kgPorDespacho, 2) : 0.0;
            $despachos += $toca ? 1 : 0;

            $meses[] = [
                'mes' => $mes,
                'kg'  => $kg,
                'usd' => $precio === null ? null : round($kg * $precio, 2),
            ];
        }

        $totalKg = round(array_sum(array_column($meses, 'kg')), 2);

        return [
            'anio'            => $anio,
            'despachos'       => $despachos,
            'kg_por_despacho' => round($kgPorDespacho, 2),
            'meses'           => $meses,
            'total_kg'        => $totalKg,
            'total_usd'       => $precio === null ? null : round($totalKg * $precio, 2),
        ];
    }
}
