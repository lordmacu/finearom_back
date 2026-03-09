<?php

namespace App\Services;

use App\Models\GroupClassification;
use App\Models\Project;
use App\Models\TimeApplication;
use App\Models\TimeEvaluation;
use App\Models\TimeFine;
use App\Models\TimeHomologation;
use App\Models\TimeMarketing;
use App\Models\TimeQuality;
use App\Models\TimeResponse;
use App\Models\TimeSample;
use Carbon\Carbon;

class ProjectTimeService
{
    /**
     * Calcula la fecha_calculada de un proyecto sumando días hábiles
     * desde fecha_creacion según las tablas de tiempos.
     *
     * Retorna null si faltan datos mínimos (rango_min, rango_max o volumen).
     */
    public function calculate(Project $project): ?Carbon
    {
        if (is_null($project->rango_min) || is_null($project->rango_max) || is_null($project->volumen)) {
            return null;
        }

        $project->loadMissing([
            'client',
            'application',
            'evaluation',
            'marketingYCalidad',
            'variants',
            'fragrances',
        ]);

        $potencial = ((float) $project->rango_min + (float) $project->rango_max) / 2 * (float) $project->volumen / 1000;
        $tipoCliente = $project->client->client_type;
        $grupo = $this->lookupGrupo($potencial, $tipoCliente);

        $dias = 0;

        // Paso 5: tiempo de aplicación si tiene observaciones no vacías
        if ($project->application && !empty(trim((string) $project->application->observaciones))) {
            $dias += $this->lookupApplication($potencial, $tipoCliente, $project->product_id);
        }

        // Paso 6: tiempos de evaluación
        if ($project->evaluation && !empty($project->evaluation->tipos)) {
            $tipos = $this->parseArray($project->evaluation->tipos);
            foreach ($tipos as $tipo) {
                $dias += $this->lookupEvaluation($tipo, $grupo);
            }
        }

        // Paso 7: tiempos de marketing
        if ($project->marketingYCalidad && !empty($project->marketingYCalidad->marketing)) {
            $marketingItems = $this->parseArray($project->marketingYCalidad->marketing);
            foreach ($marketingItems as $item) {
                $dias += $this->lookupMarketing($item, $grupo);
            }
        }

        // Paso 8: tiempos de calidad
        if ($project->marketingYCalidad && !empty($project->marketingYCalidad->calidad)) {
            $calidadItems = $this->parseArray($project->marketingYCalidad->calidad);
            foreach ($calidadItems as $item) {
                $dias += $this->lookupQuality($item, $grupo);
            }
        }

        // Paso 9: tiempos según tipo de proyecto
        switch ($project->tipo) {
            case 'Fine Fragances':
                $numFragancias = $project->fragrances()->count();
                $dias += $this->lookupFine($numFragancias, $tipoCliente);
                break;

            case 'Desarrollo':
                $dias += $this->lookupSample($potencial, $tipoCliente);
                $numVariantes = $project->variants()->count();
                if ($project->homologacion) {
                    $dias += $this->lookupHomologation($numVariantes, $grupo);
                } else {
                    $dias += $this->lookupResponse($numVariantes, $grupo);
                }
                break;

            case 'Colección':
                $dias += $this->lookupSample($potencial, $tipoCliente);
                break;
        }

        return $this->addBusinessDays(
            Carbon::parse($project->fecha_creacion),
            $dias
        );
    }

    /**
     * Suma $days días hábiles (excluye sábados y domingos) a $date.
     * Si el resultado final cae en domingo, retrocede 2 días.
     */
    private function addBusinessDays(Carbon $date, int $days): Carbon
    {
        $result = $date->copy();
        $added = 0;

        while ($added < $days) {
            $result->addDay();
            $dayOfWeek = (int) $result->format('w'); // 0=domingo, 6=sábado
            if ($dayOfWeek !== 0 && $dayOfWeek !== 6) {
                $added++;
            }
        }

        // Si cae en domingo, retroceder 2 días
        if ((int) $result->format('w') === 0) {
            $result->subDays(2);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Lookups — devuelven 0 si no encuentran registro
    // -------------------------------------------------------------------------

    private function lookupGrupo(float $potencial, string $tipoCliente): int
    {
        $row = GroupClassification::where('tipo_cliente', $tipoCliente)
            ->where('rango_min', '<=', $potencial)
            ->where('rango_max', '>=', $potencial)
            ->first();

        return $row ? (int) $row->valor : 0;
    }

    private function lookupSample(float $potencial, string $tipoCliente): int
    {
        $row = TimeSample::where('tipo_cliente', $tipoCliente)
            ->where('rango_min', '<=', $potencial)
            ->where('rango_max', '>=', $potencial)
            ->first();

        return $row ? (int) $row->valor : 0;
    }

    private function lookupApplication(float $potencial, string $tipoCliente, int $productId): int
    {
        $row = TimeApplication::where('tipo_cliente', $tipoCliente)
            ->where('rango_min', '<=', $potencial)
            ->where('rango_max', '>=', $potencial)
            ->where('product_id', $productId)
            ->first();

        return $row ? (int) $row->valor : 0;
    }

    private function lookupEvaluation(string $solicitud, int $grupo): int
    {
        $row = TimeEvaluation::where('solicitud', trim($solicitud))
            ->where('grupo', $grupo)
            ->first();

        return $row ? (int) $row->valor : 0;
    }

    private function lookupMarketing(string $solicitud, int $grupo): int
    {
        $row = TimeMarketing::where('solicitud', trim($solicitud))
            ->where('grupo', $grupo)
            ->first();

        return $row ? (int) $row->valor : 0;
    }

    private function lookupQuality(string $solicitud, int $grupo): int
    {
        $row = TimeQuality::where('solicitud', trim($solicitud))
            ->where('grupo', $grupo)
            ->first();

        return $row ? (int) $row->valor : 0;
    }

    private function lookupFine(int $numFragancias, string $tipoCliente): int
    {
        $row = TimeFine::where('tipo_cliente', $tipoCliente)
            ->where('num_fragrances_min', '<=', $numFragancias)
            ->where('num_fragrances_max', '>=', $numFragancias)
            ->first();

        return $row ? (int) $row->valor : 0;
    }

    private function lookupHomologation(int $numVariantes, int $grupo): int
    {
        $row = TimeHomologation::where('grupo', $grupo)
            ->where('num_variantes_min', '<=', $numVariantes)
            ->where('num_variantes_max', '>=', $numVariantes)
            ->first();

        return $row ? (int) $row->valor : 0;
    }

    private function lookupResponse(int $numVariantes, int $grupo): int
    {
        $row = TimeResponse::where('grupo', $grupo)
            ->where('num_variantes_min', '<=', $numVariantes)
            ->where('num_variantes_max', '>=', $numVariantes)
            ->first();

        return $row ? (int) $row->valor : 0;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Convierte un campo almacenado como string separado por comas
     * (o ya como array) en un array de strings no vacíos.
     */
    private function parseArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_filter($value, fn($v) => trim((string) $v) !== '');
        }

        return array_filter(
            explode(',', (string) $value),
            fn($v) => trim($v) !== ''
        );
    }
}
