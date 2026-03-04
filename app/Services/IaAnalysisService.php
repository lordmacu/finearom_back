<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * IaAnalysisService — Port of analizar_cliente.js
 *
 * Extrae datos, calcula estadísticas y llama al middleware AI
 * para generar el plan de compras de un producto específico.
 */
class IaAnalysisService
{
    private string $middlewareUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->middlewareUrl = config('services.ia_middleware.url', 'http://localhost:54321');
        $this->apiKey        = config('services.ia_middleware.key', 'finearom-ai-2025');
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Analiza un producto para un cliente y guarda el resultado en ia_plan_compras.
     * Retorna los datos del plan guardado.
     */
    public function analyze(int $clientId, int $productoId): array
    {
        $hoy       = now()->toDateString();
        $mesActual = now()->format('Y-m');

        // ── Próximos 4 meses ──────────────────────────────────────────────────
        $mesesForecast = [];
        for ($i = 1; $i <= 4; $i++) {
            $mesesForecast[] = now()->addMonths($i)->format('Y-m');
        }

        // ── FASE 1: Extracción ────────────────────────────────────────────────
        $producto  = $this->getProducto($clientId, $productoId);
        $perfil    = $this->getPerfilCliente($clientId);
        $timing    = $this->getTimingPedidos($clientId);
        $historial = $this->getHistorialMensual($clientId, $productoId);
        $transito  = $this->getEnTransito($clientId, $productoId);
        $newWin    = $this->getNewWin($clientId, $productoId);

        if (!$producto || empty($historial)) {
            throw new \RuntimeException("Sin datos para cliente {$clientId} producto {$productoId}");
        }

        // ── FASE 2: Estadísticas ──────────────────────────────────────────────
        $mesesVida = $newWin ? (int) $newWin->meses_vida : null;
        $metricas  = IaStatisticsService::calcularMetricas($historial, $mesesVida);

        if (!$metricas) {
            throw new \RuntimeException("No se pudieron calcular métricas para el producto {$productoId}");
        }

        if ($newWin && $mesesVida <= 6) {
            $metricas['tipo']     = 'NEW_WIN';
            $metricas['escenario']= 'NEW_WIN';
        }

        $tendencia = $metricas['pct_cambio'] > 15 ? '↑'
                   : ($metricas['pct_cambio'] < -15 ? '↓' : '→');

        $enTransito     = $transito ? (float) $transito->kg_transito : 0;
        $proximaEntrega = $transito?->proxima_entrega;
        $maxAllowed     = max($metricas['max'] * 1.3, $metricas['prom12m'] * 3, 5);
        [$minFactor, $maxFactor] = $this->resolverRangoAjuste($metricas);

        // ── Contexto del sistema ──────────────────────────────────────────────
        $semanasPico = collect($timing)
            ->sortByDesc('num_pedidos')
            ->map(fn($t) => "semana {$t->semana_del_mes}: {$t->num_pedidos} pedidos")
            ->implode(', ') ?: 'sin datos';

        $diasRestantes = now()->daysInMonth - now()->day;

        $CONTEXTO_SISTEMA = "Eres experto en planificación de compras de materias primas para Finearom, empresa de fragancias colombiana (B2B).\n"
            . "FECHA HOY: {$hoy} | CLIENTE: " . ($perfil->client_name ?? $clientId) . " | TIMING: {$semanasPico}\n"
            . "ESTACIONALIDAD NAVIDAD (único prior de industria activo): Oct:1.35x Nov:1.40x Dic:1.10x — resto del año basado en datos históricos del cliente.\n"
            . "TÉCNICAS: HOLT_WINTERS(≥10m) | SES_SEASONAL(5-9m) | CROSTON_SBA(intermitente) | WMA(nuevo/reactivado)\n"
            . "REGLA CLAVE: la proyección estadística es la base. Solo puedes ajustarla dentro del rango permitido y nunca inventar demanda fuera del histórico reciente sin evidencia fuerte.";

        // ── Contexto del producto ─────────────────────────────────────────────
        $filasMes = collect($historial)
            ->map(fn($h) => "  {$h['mes']}: {$h['kg']} kg")
            ->implode("\n");

        $proyStr = $metricas['proyeccion4m']
            ? collect($metricas['proyeccion4m'])
                ->map(fn($kg, $i) => "  {$mesesForecast[$i]}: {$kg} kg")
                ->implode("\n")
            : '  (no disponible)';

        $refSemestre = $metricas['referenciaUltimoSemestre']
            ? " [REF ÚLTIMO SEMESTRE: S2={$metricas['promS2nz']}kg > S1={$metricas['promS1nz']}kg]"
            : '';
        $refPicos = $metricas['tienePicosHistoricos']
            ? " [PICOS: peak={$metricas['peakHistorico']}kg]"
            : '';

        $contextoProducto = "PRODUCTO: {$producto->codigo} | {$producto->producto}\n"
            . "ESCENARIO: {$metricas['escenario']} | TENDENCIA: {$tendencia} ("
            . ($metricas['pct_cambio'] >= 0 ? '+' : '') . "{$metricas['pct_cambio']}%)\n"
            . "TÉCNICA: {$metricas['tecnica']}{$refSemestre}{$refPicos}\n"
            . "PROM: 3m={$metricas['prom3m']}kg | 6m={$metricas['prom6m']}kg | 12m={$metricas['prom12m']}kg\n"
            . "CONSISTENCIA: {$metricas['consistencia']}% ({$metricas['mesesConCompra']}/12 meses) | "
            . "CV: {$metricas['cv']}% | PENDIENTE: {$metricas['pendienteKgMes']}kg/mes\n"
            . "EN TRÁNSITO: {$enTransito} kg" . ($proximaEntrega ? " (entrega: {$proximaEntrega})" : '') . "\n"
            . "RESTRICCIÓN: ningún mes puede superar {$maxAllowed} kg (1.3× pico histórico={$metricas['max']}kg)\n"
            . "RANGO DE AJUSTE IA PERMITIDO: {$minFactor}x a {$maxFactor}x sobre la proyección estadística mensual\n"
            . "PROYECCIÓN ESTADÍSTICA:\n{$proyStr}\n"
            . "HISTORIAL (últimos 12 meses):\n{$filasMes}";

        $convId = "ia_{$clientId}_{$producto->codigo}_{$mesActual}";

        // ── FASE 3: IA como validador y ajustador acotado ────────────────────
        $prompt1 = "{$CONTEXTO_SISTEMA}\n\n{$contextoProducto}\n\n"
            . "TAREA:\n"
            . "1. Evalúa si la proyección estadística necesita ajuste.\n"
            . "2. Ajusta SOLO con factores multiplicadores por mes dentro del rango permitido.\n"
            . "3. No cambies la aritmética de compra: el backend calculará tránsito y compra.\n"
            . "4. Si un mes base es 0, mantén 0 salvo evidencia histórica muy fuerte; en escenarios DORMIDO, POSIBLE_PERDIDA, PERDIDO o CAMPAÑA_PUNTUAL no reactives meses apagados.\n"
            . "5. Usa motivos cortos y concretos basados solo en el historial dado.\n\n"
            . "Responde SOLO con JSON (objeto, no array):\n"
            . "{\n"
            . "  \"evaluacion_tendencia\": \"REALISTA|OPTIMISTA|CONSERVADOR\",\n"
            . "  \"clasificacion\": \"...\",\n"
            . "  \"tendencia\": \"↑|↓|→\",\n"
            . "  \"factor_global\": 1.0,\n"
            . "  \"confianza_global\": \"ALTA|MEDIA|BAJA\",\n"
            . "  \"hay_estacionalidad\": true,\n"
            . "  \"nota_estacionalidad\": \"...\",\n"
            . "  \"justificacion\": \"...\",\n"
            . "  \"factores_mensuales\": [\n"
            . "    { \"mes\": \"{$mesesForecast[0]}\", \"factor\": 1.0, \"confianza\": \"ALTA|MEDIA|BAJA\", \"motivo\": \"...\" },\n"
            . "    { \"mes\": \"{$mesesForecast[1]}\", \"factor\": 1.0, \"confianza\": \"ALTA|MEDIA|BAJA\", \"motivo\": \"...\" },\n"
            . "    { \"mes\": \"{$mesesForecast[2]}\", \"factor\": 1.0, \"confianza\": \"ALTA|MEDIA|BAJA\", \"motivo\": \"...\" },\n"
            . "    { \"mes\": \"{$mesesForecast[3]}\", \"factor\": 1.0, \"confianza\": \"ALTA|MEDIA|BAJA\", \"motivo\": \"...\" }\n"
            . "  ],\n"
            . "  \"alertas\": [\n"
            . "    { \"tipo\": \"EN_RIESGO|CRECIENDO|SIN_STOCK|OPORTUNIDAD\", \"mensaje\": \"...\" }\n"
            . "  ]\n"
            . "}";

        $r1        = $this->llamarMiddleware($prompt1, $convId, true);
        $analisisAi = $this->extraerJSON($r1);

        $planProd = $this->construirPlanFinal(
            $analisisAi,
            $metricas,
            $mesesForecast,
            $enTransito,
            $diasRestantes,
            $maxAllowed
        );

        $planProd = $this->auditarPlanConIa(
            $planProd,
            $metricas,
            $mesesForecast,
            $convId,
            $newChat = false
        );

        // ── FASE 4: Guardar ────────────────────────────────────────────────────
        $this->guardarResultado($clientId, $productoId, $producto, $metricas, $planProd);

        return [
            'cliente_id'  => $clientId,
            'producto_id' => $productoId,
            'codigo'      => $producto->codigo,
            'producto'    => $producto->producto,
            'clasificacion' => $planProd['clasificacion'] ?? null,
            'tendencia'   => $planProd['tendencia'] ?? $tendencia,
            'metricas'    => $metricas,
            'forecast'    => $planProd['meses'] ?? [],
            'alertas'     => $planProd['alertas'] ?? [],
        ];
    }

    private function construirPlanFinal(
        array $analisisAi,
        array $metricas,
        array $mesesForecast,
        float $enTransito,
        int $diasRestantes,
        float $maxAllowed
    ): array {
        [$minFactor, $maxFactor] = $this->resolverRangoAjuste($metricas);
        $factoresMensuales = collect($analisisAi['factores_mensuales'] ?? [])
            ->filter(fn($row) => is_array($row) && !empty($row['mes']))
            ->keyBy('mes');

        $factorGlobal = $this->clamp(
            $this->toFloat($analisisAi['factor_global'] ?? 1.0, 1.0),
            $minFactor,
            $maxFactor
        );

        $proyecciones = [];
        foreach ($mesesForecast as $i => $mes) {
            $base = (float) ($metricas['proyeccion4m'][$i] ?? 0);
            $row  = $factoresMensuales->get($mes, []);

            $factor = $this->clamp(
                $this->toFloat($row['factor'] ?? $factorGlobal, $factorGlobal),
                $minFactor,
                $maxFactor
            );

            $proyectado = $base > 0
                ? min($base * $factor, $maxAllowed)
                : 0.0;

            $proyecciones[] = [
                'mes'           => $mes,
                'kg_proyectados'=> $this->round5($proyectado),
                'confianza'     => $this->sanitizeEnum(
                    $row['confianza'] ?? ($analisisAi['confianza_global'] ?? null),
                    ['ALTA', 'MEDIA', 'BAJA'],
                    $this->confianzaPorMetricas($metricas)
                ),
                'motivo'        => $this->normalizarMotivo(
                    $row['motivo'] ?? null,
                    $analisisAi['justificacion'] ?? null,
                    $metricas,
                    $base
                ),
            ];
        }

        $meses = $this->distribuirCompraYTransito($proyecciones, $enTransito, $diasRestantes);
        $alertas = $this->normalizarAlertas($analisisAi['alertas'] ?? null, $metricas, $meses, $enTransito);

        return [
            'clasificacion' => $this->normalizarClasificacion($analisisAi['clasificacion'] ?? null, $metricas),
            'tendencia'     => $this->sanitizeEnum($analisisAi['tendencia'] ?? null, ['↑', '↓', '→'], $metricas['tendencia'] ?? '→'),
            'meses'         => $meses,
            'alertas'       => $alertas,
        ];
    }

    private function auditarPlanConIa(
        array $planProd,
        array $metricas,
        array $mesesForecast,
        string $convId,
        bool $newChat = false
    ): array {
        $proyeccionFinal = collect($planProd['meses'] ?? [])
            ->map(function ($mes) {
                $fecha = $mes['fecha_compra_recomendada'] ?? 'null';
                return sprintf(
                    "%s: proyectado=%skg | transito=%skg | comprar=%skg | urgencia=%s | fecha=%s",
                    $mes['mes'] ?? 'N/A',
                    $mes['kg_proyectados'] ?? 0,
                    $mes['kg_en_transito'] ?? 0,
                    $mes['kg_a_comprar'] ?? 0,
                    $mes['urgencia'] ?? 'N/A',
                    $fecha
                );
            })
            ->implode("\n");

        $prompt2 = "Audita el siguiente plan de compra ya calculado. No puedes cambiar kilos, tránsito, compra, urgencia ni fechas; solo validar coherencia y mejorar la explicación.\n\n"
            . "ESCENARIO: {$metricas['escenario']}\n"
            . "TÉCNICA BASE: {$metricas['tecnica']}\n"
            . "TENDENCIA BASE: {$metricas['tendencia']}\n"
            . "CONSISTENCIA: {$metricas['consistencia']}%\n"
            . "PROYECCIÓN FINAL:\n{$proyeccionFinal}\n\n"
            . "REGLAS:\n"
            . "1. No alteres números.\n"
            . "2. Si detectas riesgo de sobrecompra o subcompra, exprésalo en alertas.\n"
            . "3. Da motivos cortos por mes, alineados con el histórico y el escenario.\n"
            . "4. Mantén la salida en JSON estricto.\n\n"
            . "Responde SOLO con JSON:\n"
            . "{\n"
            . "  \"clasificacion\": \"...\",\n"
            . "  \"tendencia\": \"↑|↓|→\",\n"
            . "  \"meses\": [\n"
            . "    { \"mes\": \"{$mesesForecast[0]}\", \"motivo\": \"...\", \"confianza\": \"ALTA|MEDIA|BAJA\" },\n"
            . "    { \"mes\": \"{$mesesForecast[1]}\", \"motivo\": \"...\", \"confianza\": \"ALTA|MEDIA|BAJA\" },\n"
            . "    { \"mes\": \"{$mesesForecast[2]}\", \"motivo\": \"...\", \"confianza\": \"ALTA|MEDIA|BAJA\" },\n"
            . "    { \"mes\": \"{$mesesForecast[3]}\", \"motivo\": \"...\", \"confianza\": \"ALTA|MEDIA|BAJA\" }\n"
            . "  ],\n"
            . "  \"alertas\": [\n"
            . "    { \"tipo\": \"EN_RIESGO|CRECIENDO|SIN_STOCK|OPORTUNIDAD\", \"mensaje\": \"...\" }\n"
            . "  ]\n"
            . "}";

        try {
            $r2 = $this->llamarMiddleware($prompt2, $convId, $newChat);
            $auditoria = $this->extraerJSON($r2);
        } catch (\Throwable $e) {
            return $planProd;
        }

        $motivos = collect($auditoria['meses'] ?? [])
            ->filter(fn($row) => is_array($row) && !empty($row['mes']))
            ->keyBy('mes');

        foreach ($planProd['meses'] ?? [] as &$mes) {
            $row = $motivos->get($mes['mes'] ?? '', []);

            if (!empty($row['motivo'])) {
                $mes['motivo'] = substr(trim((string) $row['motivo']), 0, 240);
            }

            $mes['confianza'] = $this->sanitizeEnum(
                $row['confianza'] ?? ($mes['confianza'] ?? null),
                ['ALTA', 'MEDIA', 'BAJA'],
                $mes['confianza'] ?? $this->confianzaPorMetricas($metricas)
            );
        }
        unset($mes);

        $planProd['clasificacion'] = $this->normalizarClasificacion(
            $auditoria['clasificacion'] ?? ($planProd['clasificacion'] ?? null),
            $metricas
        );

        $planProd['tendencia'] = $this->sanitizeEnum(
            $auditoria['tendencia'] ?? ($planProd['tendencia'] ?? null),
            ['↑', '↓', '→'],
            $planProd['tendencia'] ?? ($metricas['tendencia'] ?? '→')
        );

        $planProd['alertas'] = $this->normalizarAlertas(
            $auditoria['alertas'] ?? ($planProd['alertas'] ?? []),
            $metricas,
            $planProd['meses'] ?? [],
            array_sum(array_map(fn($mes) => (float) ($mes['kg_en_transito'] ?? 0), $planProd['meses'] ?? []))
        );

        return $planProd;
    }

    private function distribuirCompraYTransito(array $proyecciones, float $enTransito, int $diasRestantes): array
    {
        $transitoRestante = max(0, $this->round5($enTransito));

        foreach ($proyecciones as $i => &$mes) {
            $proyectado = max(0, (int) ($mes['kg_proyectados'] ?? 0));
            $aplicado   = min($transitoRestante, $proyectado);
            $aComprar   = max(0, $proyectado - $aplicado);
            $urgencia   = $this->calcularUrgencia($i, $proyectado, $aplicado, $aComprar, $diasRestantes);

            $mes['kg_en_transito']            = $aplicado;
            $mes['kg_a_comprar']              = $aComprar;
            $mes['urgencia']                  = $urgencia;
            $mes['fecha_compra_recomendada']  = $this->fechaCompraRecomendada($mes['mes'], $urgencia, $i, $aComprar);

            $transitoRestante -= $aplicado;
        }
        unset($mes);

        return $proyecciones;
    }

    private function resolverRangoAjuste(array $metricas): array
    {
        $map = [
            'PERDIDO'                => [0.0, 0.2],
            'CAMPAÑA_PUNTUAL'        => [0.0, 0.6],
            'POSIBLE_PERDIDA'        => [0.25, 0.9],
            'DORMIDO'                => [0.55, 1.1],
            'NEW_WIN'                => [0.8, 1.35],
            'REACTIVADO'             => [0.75, 1.35],
            'ESTACIONAL_NAVIDAD'     => [0.8, 1.25],
            'ESTABLE'                => [0.9, 1.15],
            'VOLATIL'                => [0.65, 1.35],
            'CICLO_MENSUAL_IRREGULAR'=> [0.75, 1.3],
        ];

        return $map[$metricas['escenario'] ?? 'CICLO_MENSUAL_IRREGULAR'] ?? [0.75, 1.25];
    }

    private function confianzaPorMetricas(array $metricas): string
    {
        $consistencia = (int) ($metricas['consistencia'] ?? 0);

        if ($consistencia >= 75) {
            return 'ALTA';
        }

        if ($consistencia >= 40) {
            return 'MEDIA';
        }

        return 'BAJA';
    }

    private function normalizarClasificacion(?string $clasificacion, array $metricas): string
    {
        $value = trim((string) $clasificacion);
        if ($value !== '') {
            return substr($value, 0, 80);
        }

        return match ($metricas['escenario'] ?? null) {
            'ESTABLE' => 'Compra recurrente estable',
            'ESTACIONAL_NAVIDAD' => 'Compra estacional',
            'REACTIVADO' => 'Reactivacion reciente',
            'NEW_WIN' => 'Nuevo negocio',
            'DORMIDO' => 'Consumo dormido',
            'POSIBLE_PERDIDA' => 'Riesgo de perdida',
            default => 'Compra variable',
        };
    }

    private function normalizarMotivo(?string $motivoMes, ?string $justificacion, array $metricas, float $base): string
    {
        $motivo = trim((string) ($motivoMes ?: $justificacion ?: ''));
        if ($motivo !== '') {
            return substr($motivo, 0, 240);
        }

        if ($base <= 0) {
            return 'Sin demanda proyectada en la base estadistica del periodo.';
        }

        return "Escenario {$metricas['escenario']} con base estadistica de {$this->round5($base)} kg.";
    }

    private function normalizarAlertas($alertasAi, array $metricas, array $meses, float $enTransito): array
    {
        $allowed = ['EN_RIESGO', 'CRECIENDO', 'SIN_STOCK', 'OPORTUNIDAD'];
        $alertas = [];

        foreach ((array) $alertasAi as $alerta) {
            if (!is_array($alerta)) {
                continue;
            }

            $tipo = $this->sanitizeEnum($alerta['tipo'] ?? null, $allowed, null);
            $mensaje = trim((string) ($alerta['mensaje'] ?? ''));

            if (!$tipo || $mensaje === '') {
                continue;
            }

            $alertas[] = [
                'tipo' => $tipo,
                'mensaje' => substr($mensaje, 0, 240),
            ];
        }

        if (!empty($alertas)) {
            return array_values(array_slice($alertas, 0, 3));
        }

        $fallback = [];
        $primero = $meses[0] ?? null;
        $compraTotal = array_sum(array_map(fn($mes) => (int) ($mes['kg_a_comprar'] ?? 0), $meses));

        if (($metricas['tendencia'] ?? '→') === '↑' && $compraTotal > 0) {
            $fallback[] = [
                'tipo' => 'CRECIENDO',
                'mensaje' => 'El consumo reciente esta por encima del semestre anterior y requiere seguimiento.',
            ];
        }

        if ($primero && ($primero['kg_a_comprar'] ?? 0) > 0 && $enTransito <= 0) {
            $fallback[] = [
                'tipo' => 'SIN_STOCK',
                'mensaje' => 'El primer mes proyectado no tiene cobertura en transito y exige compra anticipada.',
            ];
        }

        if (in_array($metricas['escenario'] ?? '', ['DORMIDO', 'POSIBLE_PERDIDA'], true)) {
            $fallback[] = [
                'tipo' => 'EN_RIESGO',
                'mensaje' => 'El patron reciente es discontinuo; conviene validar con comercial antes de sobrecomprar.',
            ];
        }

        if (empty($fallback) && $compraTotal > 0) {
            $fallback[] = [
                'tipo' => 'OPORTUNIDAD',
                'mensaje' => 'Hay demanda proyectada para los proximos meses y se puede planear compra escalonada.',
            ];
        }

        return array_slice($fallback, 0, 3);
    }

    private function calcularUrgencia(int $index, int $proyectado, int $aplicado, int $aComprar, int $diasRestantes): string
    {
        if ($aComprar <= 0 || $proyectado <= 0) {
            return 'BAJA';
        }

        $cobertura = $proyectado > 0 ? $aplicado / $proyectado : 1.0;

        if ($index === 0 && ($diasRestantes <= 10 || $cobertura < 0.35)) {
            return 'ALTA';
        }

        if ($index <= 1 && ($diasRestantes <= 20 || $cobertura < 0.6)) {
            return 'MEDIA';
        }

        return $index === 0 ? 'MEDIA' : 'BAJA';
    }

    private function fechaCompraRecomendada(string $mes, string $urgencia, int $index, int $aComprar): ?string
    {
        if ($aComprar <= 0) {
            return null;
        }

        $hoy = now()->startOfDay();

        if ($index === 0) {
            $dias = match ($urgencia) {
                'ALTA' => 1,
                'MEDIA' => 3,
                default => 7,
            };

            return $hoy->copy()->addDays($dias)->toDateString();
        }

        $inicioMes = Carbon::createFromFormat('Y-m-d', "{$mes}-01")->startOfMonth();
        $leadDays = match ($urgencia) {
            'ALTA' => 10,
            'MEDIA' => 7,
            default => 5,
        };

        $fecha = $inicioMes->copy()->subDays($leadDays);
        if ($fecha->lt($hoy)) {
            $fecha = $hoy->copy()->addDay();
        }

        return $fecha->toDateString();
    }

    private function sanitizeEnum(mixed $value, array $allowed, mixed $default): mixed
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function toFloat(mixed $value, float $default = 0.0): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    // ── DB queries ────────────────────────────────────────────────────────────

    private function getProducto(int $clientId, int $productoId): ?object
    {
        return DB::selectOne("
            SELECT p.id, p.code AS codigo, p.product_name AS producto
            FROM products p
            WHERE p.id = ?
        ", [$productoId]);
    }

    private function getPerfilCliente(int $clientId): ?object
    {
        return DB::selectOne("
            SELECT client_name, purchase_frequency, credit_term
            FROM clients WHERE id = ?
        ", [$clientId]);
    }

    private function getTimingPedidos(int $clientId): array
    {
        return DB::select("
            SELECT
                WEEK(order_creation_date, 1) - WEEK(DATE_FORMAT(order_creation_date,'%Y-%m-01'), 1) + 1 AS semana_del_mes,
                COUNT(*) AS num_pedidos
            FROM purchase_orders
            WHERE client_id = ?
              AND status != 'cancelled'
              AND order_creation_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY semana_del_mes
            ORDER BY semana_del_mes
        ", [$clientId]);
    }

    private function getHistorialMensual(int $clientId, int $productoId): array
    {
        $rows = DB::select("
            SELECT mes, SUM(kg_real) AS kg
            FROM ia_historial_mensual
            WHERE cliente_id = ? AND producto_id = ?
            GROUP BY mes
            ORDER BY mes ASC
        ", [$clientId, $productoId]);

        return array_map(fn($r) => ['mes' => $r->mes, 'kg' => (float) $r->kg], $rows);
    }

    private function getEnTransito(int $clientId, int $productoId): ?object
    {
        return DB::selectOne("
            SELECT
                p.id AS producto_id,
                ROUND(SUM(pa.quantity), 2) AS kg_transito,
                MIN(pa.dispatch_date) AS proxima_entrega
            FROM partials pa
            JOIN purchase_order_product pop ON pop.id = pa.product_order_id
            JOIN products p                 ON p.id   = pop.product_id
            JOIN purchase_orders po         ON po.id  = pa.order_id
            WHERE po.client_id = ?
              AND pa.type      = 'temporal'
              AND p.id         = ?
              AND po.status   IN ('processing','parcial_status','pending')
              AND pop.muestra  = 0
              AND pa.deleted_at IS NULL
            GROUP BY p.id
        ", [$clientId, $productoId]);
    }

    private function getNewWin(int $clientId, int $productoId): ?object
    {
        return DB::selectOne("
            SELECT
                p.id AS producto_id,
                MIN(pa.dispatch_date) AS primera_entrega_real,
                TIMESTAMPDIFF(MONTH, MIN(pa.dispatch_date), NOW()) AS meses_vida
            FROM partials pa
            JOIN purchase_order_product pop ON pop.id = pa.product_order_id
            JOIN products p                 ON p.id   = pop.product_id
            JOIN purchase_orders po         ON po.id  = pa.order_id
            WHERE po.client_id = ?
              AND pa.type      = 'real'
              AND p.id         = ?
              AND pa.deleted_at IS NULL
            GROUP BY p.id
        ", [$clientId, $productoId]);
    }

    // ── Middleware HTTP calls ─────────────────────────────────────────────────

    private function llamarMiddleware(string $prompt, string $convId, bool $newChat): string
    {
        $response = Http::withHeaders(['X-Api-Key' => $this->apiKey])
            ->timeout(120)
            ->post("{$this->middlewareUrl}/api/prompt/set", [
                'prompt'  => $prompt,
                'id'      => $convId,
                'newChat' => $newChat,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Middleware error {$response->status()}: " . $response->body()
            );
        }

        $data     = $response->json();
        $messages = $data['result']['messages'] ?? [];

        // Último mensaje del assistant
        $lastAssistant = null;
        foreach (array_reverse($messages) as $msg) {
            if (($msg['role'] ?? '') === 'assistant') {
                $lastAssistant = $msg;
                break;
            }
        }

        if (!$lastAssistant) {
            throw new \RuntimeException(
                "Sin respuesta del assistant. Conv: " . json_encode(array_slice($messages, -2))
            );
        }

        $text = $lastAssistant['text'] ?? '';
        return is_string($text) ? $text : json_encode($text);
    }

    private function extraerJSON(string $texto): array
    {
        // Bloque ```json ... ```
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $texto, $m)) {
            return json_decode($m[1], true)
                ?? throw new \RuntimeException("JSON inválido en bloque code:\n" . substr($texto, 0, 300));
        }

        // Objeto o array raw
        if (preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])/', $texto, $m)) {
            return json_decode($m[1], true)
                ?? throw new \RuntimeException("JSON inválido en texto:\n" . substr($texto, 0, 300));
        }

        throw new \RuntimeException("No se encontró JSON en:\n" . substr($texto, 0, 300));
    }

    private function guardarResultado(
        int $clientId, int $productoId, object $producto,
        array $metricas, array $planProd
    ): void {
        $mesesDB = json_encode($planProd['meses'] ?? []);
        $alertas = json_encode($planProd['alertas'] ?? []);

        DB::statement("
            INSERT INTO ia_plan_compras
                (cliente_id, producto_id, codigo, producto, escenario, tendencia, tecnica,
                 prom_3m, prom_6m, prom_12m, consistencia, meses, alertas)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                codigo        = VALUES(codigo),
                producto      = VALUES(producto),
                escenario     = VALUES(escenario),
                tendencia     = VALUES(tendencia),
                tecnica       = VALUES(tecnica),
                prom_3m       = VALUES(prom_3m),
                prom_6m       = VALUES(prom_6m),
                prom_12m      = VALUES(prom_12m),
                consistencia  = VALUES(consistencia),
                meses         = VALUES(meses),
                alertas       = VALUES(alertas),
                analizado_en  = CURRENT_TIMESTAMP
        ", [
            $clientId,
            $productoId,
            $producto->codigo,
            $producto->producto,
            $metricas['escenario'],
            $planProd['tendencia'] ?? '→',
            $metricas['tecnica'],
            $metricas['prom3m'],
            $metricas['prom6m'],
            $metricas['prom12m'],
            $metricas['consistencia'],
            $mesesDB,
            $alertas,
        ]);
    }

    private function round5(float $v): int
    {
        return (int) (round($v / 5) * 5);
    }
}
