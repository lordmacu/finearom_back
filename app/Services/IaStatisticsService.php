<?php

namespace App\Services;

/**
 * IaStatisticsService — Port of estadisticas.js
 *
 * Clasificación dinámica de escenario + técnicas de proyección:
 * HOLT_WINTERS | SES_SEASONAL | CROSTON_SBA | WMA
 */
class IaStatisticsService
{
    // ── Priors estacionalidad Navidad (único prior activo) ────────────────────
    private const SEASONAL_PRIOR = [
        1 => 1.00, 2 => 1.00, 3 => 1.00, 4 => 1.00,
        5 => 1.00, 6 => 1.00, 7 => 1.00, 8 => 1.00,
        9 => 1.00, 10 => 1.35, 11 => 1.40, 12 => 1.10,
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    public static function calcularMetricas(array $historial, ?int $mesesVida = null): ?array
    {
        $serie    = self::expandirHistorial($historial);
        $valores  = array_values(array_filter($serie, fn($v) => $v > 0));
        $mesesConCompra = count($valores);

        if ($mesesConCompra === 0) return null;

        // Promedios
        $prom12m = round(self::prom($serie), 1);
        $prom6m  = round(self::prom(array_slice($serie, -6)), 1);
        $prom3m  = round(self::prom(array_slice($serie, -3)), 1);

        // Tendencia
        $reg     = self::linearRegression($serie);
        $slope   = $reg['slope'];
        $bloque3 = self::prom(array_values(array_filter(array_slice($serie, -3), fn($v) => $v > 0)));
        $bloque6 = self::prom(array_values(array_filter(array_slice($serie, -6, 3), fn($v) => $v > 0)));
        $pct_cambio = $bloque6 > 0
            ? round((($bloque3 - $bloque6) / $bloque6) * 100, 1)
            : 0;

        // Dispersión — CV calculado solo sobre meses con compra (excluye ceros)
        // Usar $serie completo inflaría el CV artificialmente (meses sin pedido ≠ demanda cero)
        $mediaActivos = self::prom($valores);
        $sigmaActivos = count($valores) > 1
            ? sqrt(self::prom(array_map(fn($v) => ($v - $mediaActivos) ** 2, $valores)))
            : 0.0;
        $cv    = $mediaActivos > 0 ? (int) round(($sigmaActivos / $mediaActivos) * 100) : 100;
        $consistencia = (int) round(($mesesConCompra / 12) * 100);

        $meses_desde_ultima = self::mesesDesdeUltima($serie);

        // Índices estacionales blended
        $indices = self::calcularIndices($serie, $mesesConCompra);

        // Clasificar escenario
        $escenario = self::clasificarEscenario($serie, $mesesVida, $mesesConCompra, $cv);

        // Comparación semestral
        $s1 = array_values(array_filter(array_slice($serie, 0, 6), fn($v) => $v > 0));
        $s2 = array_values(array_filter(array_slice($serie, -6), fn($v) => $v > 0));
        $promS1nz = self::prom($s1);
        $promS2nz = self::prom($s2);
        $referenciaUltimoSemestre = $promS2nz > $promS1nz && $promS2nz > 0 && $promS1nz > 0;

        // Proyectar
        $idxMes = self::buildIdxMes($indices);
        ['tecnica' => $tecnica, 'proyeccion' => $proyeccion] =
            self::proyectar($escenario, $serie, $indices, $mesesConCompra);

        // Escalar al último semestre si corresponde
        $proyeccionBase = self::escalarSemestre($proyeccion, $referenciaUltimoSemestre, $serie);

        // Piso picos históricos
        $peakHistorico      = count($valores) ? max($valores) : 0;
        $promNonZeroGlobal  = self::prom($valores) ?: 1;
        // CICLO_MENSUAL_IRREGULAR usa SES_SEASONAL que ya captura picos via índices estacionales —
        // no necesita pisoPicos (lo amplificaría artificialmente).
        $ESCENARIOS_PICOS   = ['VOLATIL','ESTACIONAL_NAVIDAD','REACTIVADO','DORMIDO','NEW_WIN'];
        $tienePicosHistoricos =
            in_array($escenario, $ESCENARIOS_PICOS) &&
            $peakHistorico >= $promNonZeroGlobal * 1.5 &&
            count($valores) >= 3;

        $proyeccionFinal = self::aplicarPisoPicos($proyeccionBase, $tienePicosHistoricos, $peakHistorico);

        return [
            'prom12m'                 => $prom12m,
            'prom6m'                  => $prom6m,
            'prom3m'                  => $prom3m,
            'consistencia'            => $consistencia,
            'cv'                      => $cv,
            'mesesConCompra'          => $mesesConCompra,
            'max'                     => count($valores) ? max($valores) : 0,
            'min'                     => count($valores) ? min($valores) : 0,
            'pct_cambio'              => $pct_cambio,
            'pendienteKgMes'          => round($slope, 1),
            'tendencia'               => $pct_cambio > 15 ? '↑' : ($pct_cambio < -15 ? '↓' : '→'),
            'tipo'                    => $escenario,
            'escenario'               => $escenario,
            'tecnica'                 => $tecnica,
            'meses_desde_ultima'      => $meses_desde_ultima,
            'proyeccion4m'            => $proyeccionFinal,
            'referenciaUltimoSemestre'=> $referenciaUltimoSemestre,
            'promS1nz'                => round($promS1nz, 1),
            'promS2nz'                => round($promS2nz, 1),
            'tienePicosHistoricos'    => $tienePicosHistoricos,
            'peakHistorico'           => $peakHistorico,
            'indicesEstacionales'     => array_map(fn($v) => round($v, 2), $indices),
        ];
    }

    public static function agruparPorProducto(array $historialRows): array
    {
        $mapa = [];
        foreach ($historialRows as $r) {
            $mapa[$r->producto_id][] = ['mes' => $r->mes, 'kg' => (float) $r->kg];
        }
        return $mapa;
    }

    public static function analizarPatrones(array $historial, array $mesesObjetivo = []): array
    {
        $serie      = self::expandirHistorial($historial);
        $meses12    = self::mesesVentana();
        $historial12 = array_map(fn($mes, $kg) => ['mes' => $mes, 'kg' => round((float) $kg, 1)], $meses12, $serie);
        $activos    = array_values(array_filter($historial12, fn($row) => $row['kg'] > 0));
        $sinCompra  = array_values(array_filter($historial12, fn($row) => $row['kg'] <= 0));

        if (empty($activos)) {
            return [
                'resumen' => 'Sin compras en los últimos 12 meses.',
                'picos' => [],
                'valles' => [],
                'meses_sin_compra' => array_map(fn($row) => $row['mes'], $sinCompra),
                'volumen_activo_promedio_kg' => 0,
                'volumen_activo_mediana_kg' => 0,
                'volumen_activo_min_kg' => 0,
                'volumen_activo_max_kg' => 0,
                'concentracion_top2_pct' => 0,
                'racha_sin_compra' => null,
                'meses_objetivo' => [],
            ];
        }

        $promActivos = self::prom(array_column($activos, 'kg'));
        $medianaActivos = self::mediana(array_column($activos, 'kg'));
        $totalActivo = array_sum(array_column($activos, 'kg'));
        $ordenDesc   = $activos;
        usort($ordenDesc, fn($a, $b) => $b['kg'] <=> $a['kg']);
        $ordenAsc = $activos;
        usort($ordenAsc, fn($a, $b) => $a['kg'] <=> $b['kg']);

        $umbralPico  = $medianaActivos * 1.35;
        $umbralValle = $medianaActivos * 0.80;

        $picos = array_values(array_filter($ordenDesc, fn($row) => $row['kg'] >= $umbralPico));
        if (empty($picos)) {
            $picos = array_slice($ordenDesc, 0, min(2, count($ordenDesc)));
        }
        $picos = array_map(fn($row) => self::enriquecerMesPatron($row, $promActivos), array_slice($picos, 0, 3));

        $valles = array_values(array_filter($ordenAsc, fn($row) => $row['kg'] <= $umbralValle));
        if (empty($valles)) {
            $valles = array_slice($ordenAsc, 0, min(2, count($ordenAsc)));
        }
        $valles = array_map(fn($row) => self::enriquecerMesPatron($row, $promActivos), array_slice($valles, 0, 3));

        $concentracionTop2 = $totalActivo > 0
            ? round((array_sum(array_column(array_slice($ordenDesc, 0, 2), 'kg')) / $totalActivo) * 100)
            : 0;

        $rachaSinCompra = self::calcularRacha($historial12, fn($row) => $row['kg'] <= 0);
        $objetivos = array_map(
            fn($mes) => self::analizarMesObjetivo($mes, $historial12, $medianaActivos),
            $mesesObjetivo
        );

        return [
            'resumen' => self::construirResumenPatrones($activos, $sinCompra, $picos, $valles, $concentracionTop2, $rachaSinCompra),
            'picos' => $picos,
            'valles' => $valles,
            'meses_sin_compra' => array_map(fn($row) => $row['mes'], $sinCompra),
            'meses_activos' => array_map(fn($row) => $row['mes'], $activos),
            'volumen_activo_promedio_kg' => round($promActivos, 1),
            'volumen_activo_mediana_kg' => round($medianaActivos, 1),
            'volumen_activo_min_kg' => round(min(array_column($activos, 'kg')), 1),
            'volumen_activo_max_kg' => round(max(array_column($activos, 'kg')), 1),
            'concentracion_top2_pct' => $concentracionTop2,
            'racha_sin_compra' => $rachaSinCompra,
            'meses_objetivo' => $objetivos,
        ];
    }

    // ── Historial helpers ─────────────────────────────────────────────────────

    /**
     * Expande [{mes, kg}] → array de 12 valores (slot 0 = hace 11 meses)
     */
    private static function expandirHistorial(array $historial): array
    {
        if (empty($historial)) return array_fill(0, 12, 0);

        $mapa = [];
        foreach ($historial as $h) {
            $mapa[$h['mes']] = (float) $h['kg'];
        }

        $meses12 = [];
        for ($i = 0; $i < 12; $i++) {
            $ts = mktime(0, 0, 0, (int) date('n') - 11 + $i, 1, (int) date('Y'));
            $meses12[] = date('Y-m', $ts);
        }

        return array_map(fn($m) => $mapa[$m] ?? 0.0, $meses12);
    }

    private static function mesesVentana(): array
    {
        $meses12 = [];
        for ($i = 0; $i < 12; $i++) {
            $ts = mktime(0, 0, 0, (int) date('n') - 11 + $i, 1, (int) date('Y'));
            $meses12[] = date('Y-m', $ts);
        }

        return $meses12;
    }

    /** Mes calendario (1-12) del slot i (slot 0 = hace 11 meses) */
    private static function mesDeSlot(int $i): int
    {
        $ts = mktime(0, 0, 0, (int) date('n') - 11 + $i, 1, (int) date('Y'));
        return (int) date('n', $ts);
    }

    /** Mes calendario (1-12) del paso de proyección h (h=0 → próximo mes) */
    private static function mesProyeccion(int $h): int
    {
        $ts = mktime(0, 0, 0, (int) date('n') + 1 + $h, 1, (int) date('Y'));
        return (int) date('n', $ts);
    }

    // ── Índices estacionales ──────────────────────────────────────────────────

    private static function calcularIndices(array $serie12, int $mesesConCompra = 0): array
    {
        $nonZero = array_values(array_filter($serie12, fn($v) => $v > 0));
        $media   = count($nonZero) ? self::prom($nonZero) : 1.0;

        return array_map(function ($val, $i) use ($serie12, $media, $mesesConCompra) {
            $m     = self::mesDeSlot($i);
            $prior = self::SEASONAL_PRIOR[$m] ?? 1.0;

            if ($val > 0) {
                // Mes activo: blend 60% datos del cliente + 40% prior industria
                return ($val / $media) * 0.6 + $prior * 0.4;
            }

            // Mes sin compra histórica: floor según vecindad.
            // Si los meses adyacentes (±1) tienen compras, este mes está "dentro de temporada"
            // y merece un floor más alto que un mes completamente aislado.
            $prevActivo = $i > 0  && $serie12[$i - 1] > 0;
            $nextActivo = $i < 11 && $serie12[$i + 1] > 0;
            $enTemporada = $prevActivo || $nextActivo;

            // Floor base por consistencia global
            $floorBase = $mesesConCompra >= 6 ? 0.20 : ($mesesConCompra >= 3 ? 0.10 : 0.04);
            // Si está entre meses activos, floor más alto (cliente probablemente compra ese mes también)
            $floor = $enTemporada ? min($floorBase * 1.5, 0.35) : $floorBase;

            return $prior * $floor;
        }, $serie12, range(0, 11));
    }

    /** Convierte array de 12 índices estacionales a mapa mes→índice */
    private static function buildIdxMes(array $indices): array
    {
        $idxMes = [];
        foreach ($indices as $i => $idx) {
            $m = self::mesDeSlot($i);
            $idxMes[$m] = $idx;
        }
        return $idxMes;
    }

    // ── Detectores de patrón ──────────────────────────────────────────────────

    private static function mesesDesdeUltima(array $serie12): int
    {
        for ($i = 11; $i >= 0; $i--) {
            if ($serie12[$i] > 0) return 11 - $i;
        }
        return 13;
    }

    private static function detectarNavidad(array $serie12): bool
    {
        $octNovIdx = [];
        for ($i = 0; $i < 12; $i++) {
            $m = self::mesDeSlot($i);
            if ($m === 10 || $m === 11) $octNovIdx[] = $i;
        }
        if (empty($octNovIdx)) return false;

        $promOctNov = self::prom(array_map(fn($i) => $serie12[$i], $octNovIdx));
        $resto = array_filter(
            array_map(fn($i) => $serie12[$i], array_diff(range(0,11), $octNovIdx)),
            fn($v) => $v > 0
        );
        $promResto = self::prom(array_values($resto));
        return $promResto > 0 && $promOctNov > $promResto * 1.8;
    }

    private static function detectarReactivado(array $serie12): bool
    {
        $reciente = $serie12[10] > 0 || $serie12[11] > 0;
        if (!$reciente) return false;

        $inicioReciente = 11;
        while ($inicioReciente >= 0 && $serie12[$inicioReciente] > 0) $inicioReciente--;

        $antesGap = $inicioReciente;
        while ($antesGap >= 0 && $serie12[$antesGap] === 0.0) $antesGap--;

        $longGap = $inicioReciente - $antesGap;
        return $longGap >= 4 && $antesGap >= 0;
    }

    private static function detectarCampana(array $serie12, int $mesesConCompra): bool
    {
        if ($mesesConCompra > 2) return false;
        return self::mesesDesdeUltima($serie12) >= 3;
    }

    // ── Clasificador de escenario ─────────────────────────────────────────────

    private static function clasificarEscenario(
        array $serie12, ?int $mesesVida, int $mesesConCompra, int $cv
    ): string {
        $desdeUlt = self::mesesDesdeUltima($serie12);

        if ($mesesVida !== null && $mesesVida <= 6)             return 'NEW_WIN';
        if ($desdeUlt === 13)                                   return 'PERDIDO';
        if ($desdeUlt >= 6 && !self::detectarNavidad($serie12)) return 'POSIBLE_PERDIDA';
        if ($desdeUlt >= 3 && $mesesConCompra >= 3)             return 'DORMIDO';
        if (self::detectarCampana($serie12, $mesesConCompra))   return 'CAMPAÑA_PUNTUAL';
        if (self::detectarReactivado($serie12))                 return 'REACTIVADO';
        if (self::detectarNavidad($serie12))                    return 'ESTACIONAL_NAVIDAD';
        if ($mesesConCompra >= 9 && $cv <= 50)                  return 'ESTABLE';
        if ($cv > 80)                                           return 'VOLATIL';
        return 'CICLO_MENSUAL_IRREGULAR';
    }

    // ── Técnicas de proyección ────────────────────────────────────────────────

    private static function proyectar(
        string $escenario, array $serie12, array $indices, int $mesesConCompra
    ): array {
        $PASOS  = 4;
        $idxMes = self::buildIdxMes($indices);

        switch ($escenario) {
            case 'PERDIDO':
            case 'CAMPAÑA_PUNTUAL':
                return ['tecnica' => 'NINGUNA', 'proyeccion' => array_fill(0, $PASOS, 0)];

            case 'POSIBLE_PERDIDA': {
                $base = self::crostonSBA($serie12, 0.15, $PASOS, $idxMes);
                return [
                    'tecnica'    => 'CROSTON_SBA×0.3',
                    'proyeccion' => array_map(fn($v) => round($v * 0.3, 1), $base),
                ];
            }

            case 'DORMIDO': {
                $desdeUlt = self::mesesDesdeUltima($serie12);
                $preDorm  = array_slice($serie12, 0, max(1, 12 - $desdeUlt));
                return ['tecnica' => 'WMA_PRE_DORMANCIA', 'proyeccion' => self::wma($preDorm, $PASOS, $idxMes)];
            }

            case 'NEW_WIN':
            case 'REACTIVADO':
                return ['tecnica' => 'WMA', 'proyeccion' => self::wma($serie12, $PASOS, $idxMes)];

            case 'ESTACIONAL_NAVIDAD':
                return ['tecnica' => 'SES_SEASONAL', 'proyeccion' => self::sesSeasonal($serie12, $indices, 0.3, $PASOS)];

            case 'ESTABLE':
                if ($mesesConCompra >= 10)
                    return ['tecnica' => 'HOLT_WINTERS', 'proyeccion' => self::holtWinters($serie12, 0.3, 0.1, 0.15, $PASOS)];
                return ['tecnica' => 'SES_SEASONAL', 'proyeccion' => self::sesSeasonal($serie12, $indices, 0.3, $PASOS)];

            case 'CICLO_MENSUAL_IRREGULAR':
                // Demanda irregular pero con volúmenes significativos — SES captura mejor el nivel
                return ['tecnica' => 'SES_SEASONAL', 'proyeccion' => self::sesSeasonal($serie12, $indices, 0.4, $PASOS)];

            default: // VOLATIL — demanda verdaderamente esporádica (CV alto en meses activos)
                // Solo usamos CROSTON si la consistencia es baja (≤33%) — caso de demanda intermitente real
                if ($mesesConCompra <= 4) {
                    return ['tecnica' => 'CROSTON_SBA', 'proyeccion' => self::crostonSBA($serie12, 0.15, $PASOS, $idxMes)];
                }
                // Consistencia moderada-alta con volatilidad: SES con alpha más reactivo
                return ['tecnica' => 'SES_SEASONAL', 'proyeccion' => self::sesSeasonal($serie12, $indices, 0.5, $PASOS)];
        }
    }

    private static function holtWinters(
        array $serie, float $alpha = 0.3, float $beta = 0.1, float $gamma = 0.15, int $pasos = 4
    ): array {
        $m  = 12;
        $n  = count($serie);
        $sc = self::limpiarOutliers($serie);
        $L  = self::prom($sc) ?: 1.0;
        $B  = 0.0;
        $S  = array_map(fn($v) => $v > 0 ? $v / $L : 1.0, $sc);

        for ($t = $m; $t < $n; $t++) {
            $prevL = $L;
            $si    = $t % $m;
            $y     = $sc[$t] ?: 0.001;
            $L     = $alpha * ($y / ($S[$si] ?: 1)) + (1 - $alpha) * ($L + $B);
            $B     = $beta  * ($L - $prevL)           + (1 - $beta)  * $B;
            $S[$si]= $gamma * ($y / $L)               + (1 - $gamma) * $S[$si];
        }

        return array_map(
            fn($h) => max(0.0, ($L + ($h + 1) * $B) * $S[($n + $h) % $m]),
            range(0, $pasos - 1)
        );
    }

    private static function sesSeasonal(
        array $serie, array $indices, float $alpha = 0.3, int $pasos = 4
    ): array {
        $desest = array_map(fn($v, $i) => $indices[$i] > 0 ? $v / $indices[$i] : $v, $serie, range(0, 11));
        $nivel  = array_values(array_filter($desest, fn($v) => $v > 0))[0] ?? 1.0;
        foreach ($desest as $v) {
            if ($v > 0) $nivel = $alpha * $v + (1 - $alpha) * $nivel;
        }

        $nonZero3 = array_values(array_filter(array_slice($desest, -3), fn($v) => $v > 0));
        $nonZero6 = array_values(array_filter(array_slice($desest, -6), fn($v) => $v > 0));
        $p3 = self::prom($nonZero3);
        $p6 = self::prom($nonZero6);
        $tendFactor = $p6 > 0 ? $p3 / $p6 : 1.0;
        $n = count($serie);

        return array_map(function ($h) use ($n, $indices, $nivel, $tendFactor) {
            $si  = ($n + $h) % 12;
            $m   = self::mesProyeccion($h);
            $idx = $indices[$si] ?? (self::SEASONAL_PRIOR[$m] ?? 1.0);
            return max(0.0, $nivel * (($tendFactor) ** (($h + 1) / 3)) * $idx);
        }, range(0, $pasos - 1));
    }

    private static function crostonSBA(
        array $serie, float $alpha = 0.15, int $pasos = 4, array $idxMes = []
    ): array {
        $noZero = [];
        foreach ($serie as $i => $v) {
            if ($v > 0) $noZero[] = ['i' => $i, 'v' => $v];
        }

        if (empty($noZero)) return array_fill(0, $pasos, 0);

        if (count($noZero) === 1) {
            $base = $noZero[0]['v'] / count($serie);
            return array_map(function ($h) use ($base, $idxMes) {
                $m     = self::mesProyeccion($h);
                $nudge = $idxMes[$m] ?? (self::SEASONAL_PRIOR[$m] ?? 1.0);
                return max(0.0, round($base * $nudge, 1));
            }, range(0, $pasos - 1));
        }

        $intervalos = [];
        for ($k = 1; $k < count($noZero); $k++) {
            $intervalos[] = $noZero[$k]['i'] - $noZero[$k - 1]['i'];
        }

        $zHat = $noZero[0]['v'];
        $pHat = self::prom($intervalos) ?: 1.0;

        for ($k = 1; $k < count($noZero); $k++) {
            $zHat = $alpha * $noZero[$k]['v']    + (1 - $alpha) * $zHat;
            $pHat = $alpha * $intervalos[$k - 1] + (1 - $alpha) * $pHat;
        }

        $base = (1 - $alpha / 2) * ($zHat / $pHat);

        return array_map(function ($h) use ($base, $idxMes) {
            $m     = self::mesProyeccion($h);
            $nudge = $idxMes[$m] ?? (self::SEASONAL_PRIOR[$m] ?? 1.0);
            return max(0.0, round($base * $nudge, 1));
        }, range(0, $pasos - 1));
    }

    private static function wma(array $serie, int $pasos = 4, array $idxMes = []): array
    {
        $activos = array_values(array_filter($serie, fn($v) => $v > 0));
        if (empty($activos)) return array_fill(0, $pasos, 0);

        $weights = count($activos) >= 3 ? [0.5, 0.3, 0.2]
            : (count($activos) === 2    ? [0.6, 0.4]
            :                             [1.0]);

        $recent = array_slice($activos, -count($weights));
        $base   = array_sum(array_map(fn($v, $w) => $v * $w, $recent, $weights));

        return array_map(function ($h) use ($base, $idxMes) {
            $m     = self::mesProyeccion($h);
            $nudge = $idxMes[$m] ?? (self::SEASONAL_PRIOR[$m] ?? 1.0);
            return max(0.0, round($base * $nudge, 1));
        }, range(0, $pasos - 1));
    }

    // ── Post-procesamiento de proyección ─────────────────────────────────────

    private static function escalarSemestre(
        array $proyeccion, bool $referenciaUltimoSemestre, array $serie12
    ): array {
        if (!$referenciaUltimoSemestre) {
            return array_map(fn($v) => round($v, 1), $proyeccion);
        }
        $s2nz    = array_values(array_filter(array_slice($serie12, -6), fn($v) => $v > 0));
        $allnz   = array_values(array_filter($serie12, fn($v) => $v > 0));
        $promS2  = self::prom($s2nz) ?: 1;
        $promGen = self::prom($allnz) ?: 1;
        $factor  = min($promS2 / $promGen, 1.5);
        return array_map(fn($v) => round($v * $factor, 1), $proyeccion);
    }

    private static function aplicarPisoPicos(
        array $proyeccionBase, bool $tienePicos, float $peakHistorico
    ): array {
        if (!$tienePicos) return $proyeccionBase;
        $forecastMax = max($proyeccionBase);
        if ($forecastMax <= 0) return $proyeccionBase;
        $targetPeak  = $peakHistorico * 0.65;
        if ($forecastMax >= $targetPeak) return $proyeccionBase;
        $factor = min($targetPeak / $forecastMax, 3.0);
        return array_map(fn($v) => round($v * $factor, 1), $proyeccionBase);
    }

    // ── Math helpers ──────────────────────────────────────────────────────────

    private static function prom(array $arr): float
    {
        return count($arr) ? array_sum($arr) / count($arr) : 0.0;
    }

    private static function mediana(array $arr): float
    {
        if (empty($arr)) {
            return 0.0;
        }

        sort($arr, SORT_NUMERIC);
        $n = count($arr);
        $mid = intdiv($n, 2);

        if ($n % 2 === 0) {
            return ($arr[$mid - 1] + $arr[$mid]) / 2;
        }

        return (float) $arr[$mid];
    }

    private static function enriquecerMesPatron(array $row, float $promActivos): array
    {
        return [
            'mes' => $row['mes'],
            'kg' => round((float) $row['kg'], 1),
            'label' => self::labelMes($row['mes']),
            'ratio_vs_promedio' => $promActivos > 0 ? round($row['kg'] / $promActivos, 2) : null,
        ];
    }

    private static function analizarMesObjetivo(string $mes, array $historial12, float $medianaActivos): array
    {
        $historialMap = [];
        foreach ($historial12 as $row) {
            $historialMap[$row['mes']] = $row['kg'];
        }

        [$year, $month] = array_map('intval', explode('-', $mes));
        $mesReferencia = sprintf('%04d-%02d', $year - 1, $month);
        $idx = array_search($mesReferencia, array_column($historial12, 'mes'), true);
        $kgReferencia = (float) ($historialMap[$mesReferencia] ?? 0);
        $kgPrevio = $idx !== false && $idx > 0 ? (float) $historial12[$idx - 1]['kg'] : 0.0;
        $kgSiguiente = $idx !== false && $idx < count($historial12) - 1 ? (float) $historial12[$idx + 1]['kg'] : 0.0;
        $vecindadActiva = $kgPrevio > 0 || $kgSiguiente > 0;

        if ($kgReferencia <= 0) {
            $tipo = 'MES_SIN_COMPRA';
            $fuerza = $vecindadActiva ? 'BAJA' : 'BAJA';
            $mensaje = "El mismo mes del año pasado no tuvo compras; cualquier demanda proyectada aquí debe leerse con cautela.";
        } elseif ($medianaActivos > 0 && $kgReferencia >= $medianaActivos * 1.35) {
            $tipo = 'MES_PICO';
            $fuerza = $vecindadActiva ? 'MEDIA' : 'BAJA';
            $mensaje = $vecindadActiva
                ? "El mismo mes del año pasado fue alto y además estuvo acompañado por actividad cercana."
                : "El mismo mes del año pasado fue alto, pero es una referencia aislada y no una estacionalidad confirmada.";
        } elseif ($medianaActivos > 0 && $kgReferencia <= $medianaActivos * 0.80) {
            $tipo = 'MES_BAJO';
            $fuerza = 'MEDIA';
            $mensaje = "El mismo mes del año pasado estuvo por debajo del rango típico de meses activos.";
        } else {
            $tipo = 'MES_MEDIO';
            $fuerza = 'MEDIA';
            $mensaje = "El mismo mes del año pasado estuvo dentro del rango típico de meses activos.";
        }

        return [
            'mes' => $mes,
            'label' => self::labelMes($mes),
            'mes_referencia' => $mesReferencia,
            'label_referencia' => self::labelMes($mesReferencia),
            'kg_referencia' => round($kgReferencia, 1),
            'kg_previo' => round($kgPrevio, 1),
            'kg_siguiente' => round($kgSiguiente, 1),
            'tipo' => $tipo,
            'fuerza' => $fuerza,
            'mensaje' => $mensaje,
        ];
    }

    private static function construirResumenPatrones(
        array $activos,
        array $sinCompra,
        array $picos,
        array $valles,
        int $concentracionTop2,
        ?array $rachaSinCompra
    ): string {
        $partes = [];
        $partes[] = count($activos) . ' meses con compra y ' . count($sinCompra) . ' meses sin compra en los últimos 12 meses.';

        if (!empty($picos)) {
            $pico = $picos[0];
            $partes[] = "El mayor pico fue {$pico['label']} con {$pico['kg']} kg.";
        }

        if (!empty($valles)) {
            $valle = $valles[0];
            $partes[] = "El valle activo más bajo fue {$valle['label']} con {$valle['kg']} kg.";
        }

        if ($concentracionTop2 > 0) {
            $partes[] = "Los dos meses más fuertes concentran {$concentracionTop2}% del volumen comprado.";
        }

        if ($rachaSinCompra && ($rachaSinCompra['meses'] ?? 0) >= 2) {
            $partes[] = "La racha más larga sin compra fue de {$rachaSinCompra['meses']} meses entre {$rachaSinCompra['inicio_label']} y {$rachaSinCompra['fin_label']}.";
        }

        return implode(' ', $partes);
    }

    private static function calcularRacha(array $historial12, callable $fn): ?array
    {
        $mejor = null;
        $actual = null;

        foreach ($historial12 as $idx => $row) {
            if ($fn($row)) {
                if ($actual === null) {
                    $actual = ['inicio' => $row['mes'], 'inicio_idx' => $idx, 'meses' => 0];
                }
                $actual['fin'] = $row['mes'];
                $actual['fin_idx'] = $idx;
                $actual['meses']++;
            } else {
                if ($actual !== null && ($mejor === null || $actual['meses'] > $mejor['meses'])) {
                    $mejor = $actual;
                }
                $actual = null;
            }
        }

        if ($actual !== null && ($mejor === null || $actual['meses'] > $mejor['meses'])) {
            $mejor = $actual;
        }

        if ($mejor === null) {
            return null;
        }

        return [
            'inicio' => $mejor['inicio'],
            'fin' => $mejor['fin'],
            'inicio_label' => self::labelMes($mejor['inicio']),
            'fin_label' => self::labelMes($mejor['fin']),
            'meses' => $mejor['meses'],
        ];
    }

    private static function labelMes(string $mes): string
    {
        [$year, $month] = explode('-', $mes);
        $labels = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

        return ($labels[((int) $month) - 1] ?? $month) . ' ' . substr($year, 2);
    }

    private static function linearRegression(array $valores): array
    {
        $n  = count($valores);
        $xM = ($n - 1) / 2;
        $yM = self::prom($valores);
        $num = 0;
        $den = 0;
        foreach ($valores as $i => $v) {
            $num += ($i - $xM) * ($v - $yM);
            $den += ($i - $xM) ** 2;
        }
        $slope = $den ? $num / $den : 0;
        return ['slope' => $slope, 'intercept' => $yM - $slope * $xM];
    }

    private static function limpiarOutliers(array $valores): array
    {
        $media = self::prom($valores);
        $sigma = sqrt(self::prom(array_map(fn($v) => ($v - $media) ** 2, $valores)));
        return array_map(fn($v) => $v > $media + 2 * $sigma ? $media : $v, $valores);
    }
}
