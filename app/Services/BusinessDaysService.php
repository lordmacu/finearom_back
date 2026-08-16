<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Días hábiles en Colombia: excluye sábados, domingos y los 18 festivos legales.
 *
 * Los festivos colombianos son de tres clases:
 *  - Fijos: caen siempre en la misma fecha.
 *  - Ley Emiliani: se trasladan al lunes siguiente si no caen en lunes.
 *  - Móviles: dependen de la Pascua; unos fijos a ella y otros trasladados a lunes.
 *
 * Sin descontar festivos, una orden que cruza un puente aparece con más días
 * hábiles de los que la operación realmente tuvo.
 */
class BusinessDaysService
{
    /** Festivos fijos: [mes, día] */
    private const FIJOS = [
        [1, 1],   // Año Nuevo
        [5, 1],   // Día del Trabajo
        [7, 20],  // Independencia
        [8, 7],   // Batalla de Boyacá
        [12, 8],  // Inmaculada Concepción
        [12, 25], // Navidad
    ];

    /** Festivos que la Ley Emiliani traslada al lunes siguiente: [mes, día] */
    private const EMILIANI = [
        [1, 6],   // Reyes Magos
        [3, 19],  // San José
        [6, 29],  // San Pedro y San Pablo
        [8, 15],  // Asunción de la Virgen
        [10, 12], // Día de la Raza
        [11, 1],  // Todos los Santos
        [11, 11], // Independencia de Cartagena
    ];

    /** Días respecto al Domingo de Pascua, sin traslado */
    private const PASCUA_FIJOS = [-3, -2]; // Jueves y Viernes Santo

    /** Días respecto al Domingo de Pascua, trasladados a lunes */
    private const PASCUA_EMILIANI = [43, 64, 71]; // Ascensión, Corpus Christi, Sagrado Corazón

    /** @var array<int, array<string, true>> festivos por año, ya calculados */
    private array $cache = [];

    /**
     * Días hábiles transcurridos entre dos fechas, sin contar la de inicio.
     * Un despacho el mismo día de creación da 0.
     */
    public function between(string|Carbon $desde, string|Carbon $hasta): int
    {
        $inicio = $desde instanceof Carbon ? $desde->copy()->startOfDay() : Carbon::parse($desde)->startOfDay();
        $fin    = $hasta instanceof Carbon ? $hasta->copy()->startOfDay() : Carbon::parse($hasta)->startOfDay();

        if ($fin->lessThanOrEqualTo($inicio)) {
            return 0;
        }

        $dias = 0;
        $cursor = $inicio->copy();

        while ($cursor->lessThan($fin)) {
            $cursor->addDay();
            if ($this->esHabil($cursor)) {
                $dias++;
            }
        }

        return $dias;
    }

    public function esHabil(Carbon $fecha): bool
    {
        if (in_array($fecha->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY], true)) {
            return false;
        }

        return ! isset($this->festivos((int) $fecha->year)[$fecha->toDateString()]);
    }

    /**
     * Festivos de un año, indexados por fecha 'Y-m-d'.
     *
     * @return array<string, true>
     */
    public function festivos(int $anio): array
    {
        if (isset($this->cache[$anio])) {
            return $this->cache[$anio];
        }

        $fechas = [];

        foreach (self::FIJOS as [$mes, $dia]) {
            $fechas[] = Carbon::create($anio, $mes, $dia);
        }

        foreach (self::EMILIANI as [$mes, $dia]) {
            $fechas[] = $this->alLunesSiguiente(Carbon::create($anio, $mes, $dia));
        }

        $pascua = $this->domingoDePascua($anio);

        foreach (self::PASCUA_FIJOS as $offset) {
            $fechas[] = $pascua->copy()->addDays($offset);
        }

        foreach (self::PASCUA_EMILIANI as $offset) {
            $fechas[] = $this->alLunesSiguiente($pascua->copy()->addDays($offset));
        }

        $indice = [];
        foreach ($fechas as $f) {
            $indice[$f->toDateString()] = true;
        }

        return $this->cache[$anio] = $indice;
    }

    private function alLunesSiguiente(Carbon $fecha): Carbon
    {
        return $fecha->dayOfWeek === Carbon::MONDAY
            ? $fecha
            : $fecha->next(Carbon::MONDAY);
    }

    /**
     * Domingo de Pascua por el algoritmo de Meeus/Jones/Butcher, para no depender
     * de la extensión `calendar` de PHP.
     */
    private function domingoDePascua(int $anio): Carbon
    {
        $a = $anio % 19;
        $b = intdiv($anio, 100);
        $c = $anio % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($anio, $mes, $dia);
    }
}
