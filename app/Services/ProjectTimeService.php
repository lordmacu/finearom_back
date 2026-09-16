<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Project;
use Carbon\Carbon;

/**
 * Cálculo de fecha_calculada de proyectos. Reemplaza por completo la lógica
 * vieja basada en `grupo`/potencial/tipo_cliente y las tablas time_* (esa
 * lógica se eliminó; las tablas quedan solo como catálogo administrable).
 *
 * Lógica: pasos secuenciales (desarrollo → aplicación → evaluación), donde
 * cada uno suma sus días hábiles a la fecha de creación. Regulatoria y
 * marketing corren en simultáneo al final, así que no se suman entre sí:
 * se toma el mayor de los dos. Dentro de cada checklist (calidad, marketing)
 * tampoco se suma por ítem marcado — cada uno tiene un tramo básico y uno
 * elevado, y si hay ítems de ambos se toma el tramo de mayor valor.
 */
class ProjectTimeService
{
    private const DIAS_HOMOLOGACION = ['cromatografia' => 15, 'olfativa' => 3];
    private const DIAS_DESARROLLO = ['desde_cero' => 3, 'ajuste_formula' => 2, 'piramides_olfativas' => 1];
    private const DIAS_AREA_APLICACION = ['pesaje_aceites' => 2, 'aplicaciones_liquidas' => 2, 'aplicaciones_jabon' => 8, 'montaje_estabilidad' => 20];
    private const DIAS_AREA_EVALUACIONES = ['evaluacion_laundry' => 3, 'evaluacion_cabinas' => 2];

    private const CALIDAD_BASICO = ['MSDS', 'FDS', 'IFRA', 'Ficha Técnica', 'Certificados Alergenos', 'Certificado de análisis'];
    private const CALIDAD_ESPECIALES = ['CARB', 'Libre Alérgenos', 'Reglamento Europeo', 'PSA Essity'];
    private const DIAS_CALIDAD_BASICO = 5;
    private const DIAS_CALIDAD_ESPECIALES = 15;

    private const MARKETING_BASICO = ['Descripción Olfativa', 'Pirámide Olfativa', 'Caja', 'Presentación', 'Dummie Digital', 'Dummie Fisico', 'Investigación De Mercado'];
    private const MARKETING_PRESENTACION_CERO = 'Presentación Cero';
    private const DIAS_MARKETING_BASICO = 5;
    private const DIAS_MARKETING_PRESENTACION_CERO = 15;

    public function calculate(Project $project): Carbon
    {
        $project->loadMissing('marketingYCalidad');

        $diasDesarrollo = $project->homologacion
            ? (self::DIAS_HOMOLOGACION[$project->tipo_homologacion ?? ''] ?? 0)
            : (self::DIAS_DESARROLLO[$project->tipo_desarrollo ?? ''] ?? 0);

        $diasAplicacion = self::DIAS_AREA_APLICACION[$project->area_aplicacion ?? ''] ?? 0;
        $diasEvaluacion = self::DIAS_AREA_EVALUACIONES[$project->area_evaluaciones ?? ''] ?? 0;

        $diasRegulatoria = $this->diasCalidad($project->marketingYCalidad?->calidad);
        $diasMarketing = $this->diasMarketing($project->marketingYCalidad?->marketing);

        $totalDias = $diasDesarrollo + $diasAplicacion + $diasEvaluacion + max($diasRegulatoria, $diasMarketing);

        $fechaBase = $project->fecha_creacion ? Carbon::parse($project->fecha_creacion) : now();

        return $totalDias > 0 ? $this->addBusinessDays($fechaBase, $totalDias) : $fechaBase->copy();
    }

    private function diasCalidad(mixed $calidad): int
    {
        $items = $this->parseArray($calidad);
        if ($this->algunoMarcado($items, self::CALIDAD_ESPECIALES)) {
            return self::DIAS_CALIDAD_ESPECIALES;
        }
        if ($this->algunoMarcado($items, self::CALIDAD_BASICO)) {
            return self::DIAS_CALIDAD_BASICO;
        }
        return 0;
    }

    private function diasMarketing(mixed $marketing): int
    {
        $items = $this->parseArray($marketing);
        if (in_array(self::MARKETING_PRESENTACION_CERO, $items, true)) {
            return self::DIAS_MARKETING_PRESENTACION_CERO;
        }
        if ($this->algunoMarcado($items, self::MARKETING_BASICO)) {
            return self::DIAS_MARKETING_BASICO;
        }
        return 0;
    }

    private function algunoMarcado(array $items, array $opciones): bool
    {
        return count(array_intersect($items, $opciones)) > 0;
    }

    private function parseArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_filter($value, fn($v) => trim((string) $v) !== '');
        }
        return array_filter(explode(',', (string) $value), fn($v) => trim($v) !== '');
    }

    /**
     * Suma $days días hábiles (excluye sábados, domingos y feriados de la
     * tabla `holidays`).
     */
    private function addBusinessDays(Carbon $date, int $days): Carbon
    {
        $result = $date->copy();
        $holidays = $this->holidaySet();
        $added = 0;

        while ($added < $days) {
            $result->addDay();
            $dayOfWeek = (int) $result->format('w');
            $esFeriado = in_array($result->toDateString(), $holidays, true);
            if ($dayOfWeek !== 0 && $dayOfWeek !== 6 && !$esFeriado) {
                $added++;
            }
        }

        return $result;
    }

    private function holidaySet(): array
    {
        return Holiday::query()->pluck('date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->all();
    }
}
