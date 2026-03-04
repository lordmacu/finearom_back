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
        $patrones  = IaStatisticsService::analizarPatrones($historial, $mesesForecast);

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

        $CONTEXTO_SISTEMA = "Eres experto en planificación de compras B2B de materias primas para Finearom.\n"
            . "FECHA HOY: {$hoy} | CLIENTE: " . ($perfil->client_name ?? $clientId) . " | TIMING: {$semanasPico}\n"
            . "ESTACIONALIDAD DE INDUSTRIA ACTIVA: Navidad (Oct 1.35x, Nov 1.40x, Dic 1.10x). Fuera de eso manda el histórico del cliente.\n"
            . "TÉCNICAS BASE DISPONIBLES: HOLT_WINTERS(≥10m) | SES_SEASONAL(5-9m) | CROSTON_SBA(intermitente) | WMA(nuevo/reactivado).\n"
            . "REGLA MAESTRA: la estadística produce la base numérica; la IA solo interpreta evidencia y ajusta dentro de límites.";

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
            . "LECTURA DEL HISTORIAL: " . ($patrones['resumen'] ?? 'sin lectura disponible') . "\n"
            . "PICOS HISTÓRICOS: " . $this->resumirMesesPatron($patrones['picos'] ?? []) . "\n"
            . "VALLES ACTIVOS: " . $this->resumirMesesPatron($patrones['valles'] ?? []) . "\n"
            . "MESES SIN COMPRA: " . $this->resumirMesesApagados($patrones['meses_sin_compra'] ?? []) . "\n"
            . "EVIDENCIA PRÓXIMOS 4 MESES:\n" . $this->resumirMesesObjetivo($patrones['meses_objetivo'] ?? []) . "\n"
            . "EN TRÁNSITO: {$enTransito} kg" . ($proximaEntrega ? " (entrega: {$proximaEntrega})" : '') . "\n"
            . "RESTRICCIÓN: ningún mes puede superar {$maxAllowed} kg (1.3× pico histórico={$metricas['max']}kg)\n"
            . "RANGO DE AJUSTE IA PERMITIDO: {$minFactor}x a {$maxFactor}x sobre la proyección estadística mensual\n"
            . "PROYECCIÓN ESTADÍSTICA:\n{$proyStr}\n"
            . "HISTORIAL (últimos 12 meses):\n{$filasMes}";

        $payloadAnalisis = $this->buildPromptPayload(
            $clientId,
            $producto,
            $perfil,
            $metricas,
            $patrones,
            $historial,
            $mesesForecast,
            $enTransito,
            $proximaEntrega,
            $diasRestantes,
            $minFactor,
            $maxFactor,
            $maxAllowed,
            $semanasPico
        );

        $convId = "ia_{$clientId}_{$producto->codigo}_{$mesActual}";

        // ── FASE 3: IA como validador y ajustador acotado ────────────────────
        $prompt1 = $this->buildAnalysisPrompt($CONTEXTO_SISTEMA, $contextoProducto, $payloadAnalisis, $mesesForecast);

        $r1        = $this->llamarMiddleware($prompt1, $convId, true);
        $analisisAi = $this->extraerJSON($r1);

        $planProd = $this->construirPlanFinal(
            $analisisAi,
            $metricas,
            $patrones,
            $mesesForecast,
            $enTransito,
            $diasRestantes,
            $maxAllowed
        );

        $planProd = $this->enriquecerOportunidadCompra(
            $planProd,
            $metricas,
            $patrones,
            $mesesForecast,
            $maxAllowed,
            $convId
        );

        $planProd = $this->auditarPlanConIa(
            $planProd,
            $metricas,
            $patrones,
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
            'insights'    => $patrones,
            'forecast'    => $planProd['meses'] ?? [],
            'alertas'     => $planProd['alertas'] ?? [],
        ];
    }

    private function buildPromptPayload(
        int $clientId,
        object $producto,
        ?object $perfil,
        array $metricas,
        array $patrones,
        array $historial,
        array $mesesForecast,
        float $enTransito,
        ?string $proximaEntrega,
        int $diasRestantes,
        float $minFactor,
        float $maxFactor,
        float $maxAllowed,
        string $semanasPico
    ): array {
        $historialNormalizado = array_map(fn($row) => [
            'mes' => $row['mes'],
            'kg' => round((float) $row['kg'], 1),
        ], $historial);

        $forecastBase = array_map(function ($mes, $kg) use ($patrones) {
            $patron = $this->buscarPatronMes($patrones, $mes);

            return [
                'mes' => $mes,
                'kg_base' => round((float) $kg, 1),
                'evidencia' => $patron ? [
                    'tipo' => $patron['tipo'] ?? null,
                    'fuerza' => $patron['fuerza'] ?? null,
                    'kg_mismo_mes_anio_anterior' => $patron['kg_referencia'] ?? null,
                    'mensaje' => $patron['mensaje'] ?? null,
                ] : null,
            ];
        }, $mesesForecast, $metricas['proyeccion4m'] ?? array_fill(0, count($mesesForecast), 0));

        return [
            'cliente' => [
                'id' => $clientId,
                'nombre' => $perfil->client_name ?? null,
                'frecuencia_compra' => $perfil->purchase_frequency ?? null,
                'plazo_credito' => $perfil->credit_term ?? null,
                'timing_pedidos' => $semanasPico,
            ],
            'producto' => [
                'codigo' => $producto->codigo,
                'nombre' => $producto->producto,
            ],
            'metricas' => [
                'escenario' => $metricas['escenario'] ?? null,
                'tecnica' => $metricas['tecnica'] ?? null,
                'tendencia' => $metricas['tendencia'] ?? null,
                'consistencia_pct' => $metricas['consistencia'] ?? null,
                'meses_con_compra' => $metricas['mesesConCompra'] ?? null,
                'cv_pct' => $metricas['cv'] ?? null,
                'prom_3m_kg' => $metricas['prom3m'] ?? null,
                'prom_6m_kg' => $metricas['prom6m'] ?? null,
                'prom_12m_kg' => $metricas['prom12m'] ?? null,
                'peak_historico_kg' => $metricas['peakHistorico'] ?? ($metricas['max'] ?? null),
                'meses_desde_ultima_compra' => $metricas['meses_desde_ultima'] ?? null,
                'referencia_ultimo_semestre' => $metricas['referenciaUltimoSemestre'] ?? false,
            ],
            'patrones' => [
                'resumen' => $patrones['resumen'] ?? null,
                'picos' => $patrones['picos'] ?? [],
                'valles' => $patrones['valles'] ?? [],
                'meses_sin_compra' => $patrones['meses_sin_compra'] ?? [],
                'concentracion_top2_pct' => $patrones['concentracion_top2_pct'] ?? null,
                'racha_sin_compra' => $patrones['racha_sin_compra'] ?? null,
                'meses_objetivo' => $patrones['meses_objetivo'] ?? [],
            ],
            'operacion' => [
                'kg_en_transito_total' => round($enTransito, 1),
                'proxima_entrega' => $proximaEntrega,
                'dias_restantes_mes_actual' => $diasRestantes,
            ],
            'restricciones' => [
                'factor_min' => $minFactor,
                'factor_max' => $maxFactor,
                'maximo_kg_mes' => round($maxAllowed, 1),
                'no_inventar_demanda_sin_evidencia' => true,
                'no_reactivar_meses_apagados_en_escenarios_dormidos' => true,
                'una_sola_referencia_anual_no_confirma_estacionalidad' => true,
            ],
            'historial_12m' => $historialNormalizado,
            'forecast_base_4m' => $forecastBase,
        ];
    }

    private function buildAnalysisPrompt(string $contextoSistema, string $contextoProducto, array $payload, array $mesesForecast): string
    {
        return "{$contextoSistema}\n\n{$contextoProducto}\n\n"
            . "DATOS ESTRUCTURADOS (JSON):\n"
            . $this->jsonForPrompt($payload) . "\n\n"
            . "MODO DE TRABAJO:\n"
            . "1. Razona internamente con prioridad en evidencia histórica real, no en intuición.\n"
            . "2. Distingue entre patrón robusto y coincidencia aislada.\n"
            . "3. Penaliza con menor confianza los meses cuyo soporte sea un solo pico anual o un mes previamente apagado.\n"
            . "4. Si el mismo mes del año pasado fue 0, no proyectes crecimiento agresivo salvo evidencia reciente muy fuerte.\n"
            . "5. Si la concentración top 2 es alta, asume que la demanda está concentrada y no extiendas picos a meses vecinos sin soporte.\n"
            . "6. Usa la proyección estadística como base y solo devuelve factores dentro del rango permitido.\n"
            . "7. Piensa paso a paso de forma privada y devuelve solo el JSON final.\n\n"
            . "OBJETIVO:\n"
            . "Evaluar si el forecast base de 4 meses debe ajustarse y con qué intensidad, manteniendo una lógica estricta frente a picos, valles y meses sin compra.\n\n"
            . "SALIDA OBLIGATORIA. Responde SOLO con JSON válido:\n"
            . "{\n"
            . "  \"evaluacion_tendencia\": \"REALISTA|OPTIMISTA|CONSERVADOR\",\n"
            . "  \"clasificacion\": \"...\",\n"
            . "  \"tendencia\": \"↑|↓|→\",\n"
            . "  \"factor_global\": 1.0,\n"
            . "  \"confianza_global\": \"ALTA|MEDIA|BAJA\",\n"
            . "  \"hay_estacionalidad\": true,\n"
            . "  \"nota_estacionalidad\": \"máximo 180 caracteres\",\n"
            . "  \"justificacion\": \"máximo 220 caracteres; menciona evidencia concreta\",\n"
            . "  \"factores_mensuales\": [\n"
            . "    { \"mes\": \"{$mesesForecast[0]}\", \"factor\": 1.0, \"confianza\": \"ALTA|MEDIA|BAJA\", \"motivo\": \"máximo 180 caracteres\" },\n"
            . "    { \"mes\": \"{$mesesForecast[1]}\", \"factor\": 1.0, \"confianza\": \"ALTA|MEDIA|BAJA\", \"motivo\": \"máximo 180 caracteres\" },\n"
            . "    { \"mes\": \"{$mesesForecast[2]}\", \"factor\": 1.0, \"confianza\": \"ALTA|MEDIA|BAJA\", \"motivo\": \"máximo 180 caracteres\" },\n"
            . "    { \"mes\": \"{$mesesForecast[3]}\", \"factor\": 1.0, \"confianza\": \"ALTA|MEDIA|BAJA\", \"motivo\": \"máximo 180 caracteres\" }\n"
            . "  ],\n"
            . "  \"alertas\": [\n"
            . "    { \"tipo\": \"EN_RIESGO|CRECIENDO|SIN_STOCK|OPORTUNIDAD\", \"mensaje\": \"máximo 180 caracteres\" }\n"
            . "  ]\n"
            . "}";
    }

    private function buildAuditPrompt(array $metricas, array $patrones, array $mesesForecast, string $proyeccionFinal): string
    {
        $payload = [
            'metricas' => [
                'escenario' => $metricas['escenario'] ?? null,
                'tecnica' => $metricas['tecnica'] ?? null,
                'tendencia' => $metricas['tendencia'] ?? null,
                'consistencia_pct' => $metricas['consistencia'] ?? null,
            ],
            'patrones' => [
                'resumen' => $patrones['resumen'] ?? null,
                'meses_objetivo' => $patrones['meses_objetivo'] ?? [],
                'concentracion_top2_pct' => $patrones['concentracion_top2_pct'] ?? null,
                'meses_sin_compra' => $patrones['meses_sin_compra'] ?? [],
            ],
            'plan_cerrado' => $proyeccionFinal,
        ];

        return "Audita el plan de compra ya calculado. No puedes cambiar kilos, tránsito, compra, urgencia ni fechas.\n\n"
            . "DATOS ESTRUCTURADOS (JSON):\n"
            . $this->jsonForPrompt($payload) . "\n\n"
            . "MODO DE TRABAJO:\n"
            . "1. Revisa coherencia con el histórico y la evidencia por mes objetivo.\n"
            . "2. Si un mes alto depende de una sola referencia anual, exprésalo como riesgo.\n"
            . "3. Si un mes viene de un histórico apagado, exprésalo como oportunidad o cautela según el caso.\n"
            . "4. Da explicaciones breves, concretas y accionables.\n"
            . "5. Piensa internamente y devuelve solo el JSON final.\n\n"
            . "SALIDA OBLIGATORIA. Responde SOLO con JSON válido:\n"
            . "{\n"
            . "  \"resumen_ejecutivo\": \"máximo 220 caracteres\",\n"
            . "  \"clasificacion\": \"...\",\n"
            . "  \"tendencia\": \"↑|↓|→\",\n"
            . "  \"meses\": [\n"
            . "    { \"mes\": \"{$mesesForecast[0]}\", \"motivo\": \"máximo 180 caracteres\", \"confianza\": \"ALTA|MEDIA|BAJA\" },\n"
            . "    { \"mes\": \"{$mesesForecast[1]}\", \"motivo\": \"máximo 180 caracteres\", \"confianza\": \"ALTA|MEDIA|BAJA\" },\n"
            . "    { \"mes\": \"{$mesesForecast[2]}\", \"motivo\": \"máximo 180 caracteres\", \"confianza\": \"ALTA|MEDIA|BAJA\" },\n"
            . "    { \"mes\": \"{$mesesForecast[3]}\", \"motivo\": \"máximo 180 caracteres\", \"confianza\": \"ALTA|MEDIA|BAJA\" }\n"
            . "  ],\n"
            . "  \"alertas\": [\n"
            . "    { \"tipo\": \"EN_RIESGO|CRECIENDO|SIN_STOCK|OPORTUNIDAD\", \"mensaje\": \"máximo 180 caracteres\" }\n"
            . "  ]\n"
            . "}";
    }

    private function buildOpportunityPrompt(array $metricas, array $patrones, array $planProd, array $mesesForecast, float $maxAllowed): string
    {
        $payload = [
            'metricas' => [
                'escenario' => $metricas['escenario'] ?? null,
                'tecnica' => $metricas['tecnica'] ?? null,
                'consistencia_pct' => $metricas['consistencia'] ?? null,
            ],
            'patrones' => [
                'resumen' => $patrones['resumen'] ?? null,
                'volumen_activo_promedio_kg' => $patrones['volumen_activo_promedio_kg'] ?? null,
                'volumen_activo_mediana_kg' => $patrones['volumen_activo_mediana_kg'] ?? null,
                'volumen_activo_min_kg' => $patrones['volumen_activo_min_kg'] ?? null,
                'volumen_activo_max_kg' => $patrones['volumen_activo_max_kg'] ?? null,
                'meses_objetivo' => $patrones['meses_objetivo'] ?? [],
            ],
            'forecast_esperado' => array_map(fn($mes) => [
                'mes' => $mes['mes'] ?? null,
                'kg_esperados' => $mes['kg_proyectados'] ?? 0,
                'kg_si_compra_base' => $mes['kg_si_compra'] ?? 0,
                'probabilidad_base_pct' => $mes['probabilidad_compra_pct'] ?? 0,
                'lectura_base' => $mes['lectura_compra'] ?? null,
            ], $planProd['meses'] ?? []),
            'restricciones' => [
                'maximo_kg_mes' => round($maxAllowed, 1),
                'no_cambiar_kg_esperados' => true,
                'expresar_baja_probabilidad_si_el_esperado_es_bajo_pero_la_compra_tipica_es_alta' => true,
            ],
        ];

        return "Analiza la oportunidad de compra por mes. No cambies los kg esperados del forecast; solo estima el tamaño típico de compra si el cliente sí compra ese mes.\n\n"
            . "DATOS ESTRUCTURADOS (JSON):\n"
            . $this->jsonForPrompt($payload) . "\n\n"
            . "MODO DE TRABAJO:\n"
            . "1. Separa demanda esperada de tamaño de compra si ocurre.\n"
            . "2. Si el producto suele comprar en bloques altos (300kg o más), evita interpretar 70kg esperados como una compra pequeña; léelo como baja probabilidad de un bloque alto.\n"
            . "3. Usa picos, valles, mismo mes del año anterior y volumen activo típico para estimar kg_si_compra.\n"
            . "4. No superes el máximo mensual y no inventes mini-compras si el patrón histórico es por bloques altos.\n"
            . "5. Piensa internamente y devuelve solo JSON.\n\n"
            . "SALIDA OBLIGATORIA. Responde SOLO con JSON válido:\n"
            . "{\n"
            . "  \"resumen_oportunidad\": \"máximo 220 caracteres\",\n"
            . "  \"meses\": [\n"
            . "    { \"mes\": \"{$mesesForecast[0]}\", \"kg_si_compra\": 0, \"confianza_compra\": \"ALTA|MEDIA|BAJA\", \"motivo_compra\": \"máximo 180 caracteres\" },\n"
            . "    { \"mes\": \"{$mesesForecast[1]}\", \"kg_si_compra\": 0, \"confianza_compra\": \"ALTA|MEDIA|BAJA\", \"motivo_compra\": \"máximo 180 caracteres\" },\n"
            . "    { \"mes\": \"{$mesesForecast[2]}\", \"kg_si_compra\": 0, \"confianza_compra\": \"ALTA|MEDIA|BAJA\", \"motivo_compra\": \"máximo 180 caracteres\" },\n"
            . "    { \"mes\": \"{$mesesForecast[3]}\", \"kg_si_compra\": 0, \"confianza_compra\": \"ALTA|MEDIA|BAJA\", \"motivo_compra\": \"máximo 180 caracteres\" }\n"
            . "  ]\n"
            . "}";
    }

    private function jsonForPrompt(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: '{}';
    }

    private function construirPlanFinal(
        array $analisisAi,
        array $metricas,
        array $patrones,
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
            $patronMes = $this->buscarPatronMes($patrones, $mes);
            [$minFactorMes, $maxFactorMes] = $this->resolverRangoPorPatron($patronMes, $metricas);
            [$factorMinFinal, $factorMaxFinal] = $this->intersectarRangos($minFactor, $maxFactor, $minFactorMes, $maxFactorMes);

            $factor = $this->clamp(
                $this->toFloat($row['factor'] ?? $factorGlobal, $factorGlobal),
                $factorMinFinal,
                $factorMaxFinal
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
                    $this->confianzaPorPatron($patronMes, $metricas)
                ),
                'motivo'        => $this->normalizarMotivo(
                    $row['motivo'] ?? null,
                    $analisisAi['justificacion'] ?? null,
                    $metricas,
                    $base,
                    $patronMes
                ),
            ];
        }

        $meses = $this->distribuirCompraYTransito($proyecciones, $enTransito, $diasRestantes);
        $alertas = $this->normalizarAlertas($analisisAi['alertas'] ?? null, $metricas, $patrones, $meses, $enTransito);

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
        array $patrones,
        array $mesesForecast,
        string $convId,
        bool $newChat = false
    ): array {
        $proyeccionFinal = collect($planProd['meses'] ?? [])
            ->map(function ($mes) {
                $fecha = $mes['fecha_compra_recomendada'] ?? 'null';
                return sprintf(
                    "%s: proyectado=%skg | prob_compra=%s%% | si_compra=%skg | transito=%skg | comprar=%skg | urgencia=%s | fecha=%s",
                    $mes['mes'] ?? 'N/A',
                    $mes['kg_proyectados'] ?? 0,
                    $mes['probabilidad_compra_pct'] ?? 0,
                    $mes['kg_si_compra'] ?? 0,
                    $mes['kg_en_transito'] ?? 0,
                    $mes['kg_a_comprar'] ?? 0,
                    $mes['urgencia'] ?? 'N/A',
                    $fecha
                );
            })
            ->implode("\n");

        $prompt2 = $this->buildAuditPrompt($metricas, $patrones, $mesesForecast, $proyeccionFinal);

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
            $patrones,
            $planProd['meses'] ?? [],
            array_sum(array_map(fn($mes) => (float) ($mes['kg_en_transito'] ?? 0), $planProd['meses'] ?? []))
        );

        return $planProd;
    }

    private function enriquecerOportunidadCompra(
        array $planProd,
        array $metricas,
        array $patrones,
        array $mesesForecast,
        float $maxAllowed,
        string $convId
    ): array {
        $meses = $this->construirOportunidadBase($planProd['meses'] ?? [], $metricas, $patrones, $maxAllowed);
        $planProd['meses'] = $meses;

        $prompt = $this->buildOpportunityPrompt($metricas, $patrones, $planProd, $mesesForecast, $maxAllowed);

        try {
            $response = $this->llamarMiddleware($prompt, $convId, false);
            $oportunidadAi = $this->extraerJSON($response);
        } catch (\Throwable $e) {
            return $planProd;
        }

        $mesesAi = collect($oportunidadAi['meses'] ?? [])
            ->filter(fn($row) => is_array($row) && !empty($row['mes']))
            ->keyBy('mes');

        foreach ($planProd['meses'] ?? [] as &$mes) {
            $row = $mesesAi->get($mes['mes'] ?? '', []);
            $esperado = max(0, (float) ($mes['kg_proyectados'] ?? 0));
            $baseSiCompra = max($esperado, (float) ($mes['kg_si_compra'] ?? 0));
            $kgSiCompraAi = $this->toFloat($row['kg_si_compra'] ?? null, $baseSiCompra);
            $kgSiCompraFinal = $this->clamp(
                $kgSiCompraAi,
                max($esperado, $baseSiCompra * 0.85),
                min($maxAllowed, max($esperado, $baseSiCompra * 1.15))
            );

            $mes['kg_si_compra'] = $this->round5($kgSiCompraFinal);
            $mes['probabilidad_compra_pct'] = $mes['kg_si_compra'] > 0
                ? (int) round(min(95, max(0, ($esperado / $mes['kg_si_compra']) * 100)))
                : 0;
            $mes['escenario_probabilidad'] = $this->labelProbabilidad($mes['probabilidad_compra_pct']);
            $mes['confianza_compra'] = $this->sanitizeEnum(
                $row['confianza_compra'] ?? null,
                ['ALTA', 'MEDIA', 'BAJA'],
                $mes['confianza']
            );

            if (!empty($row['motivo_compra'])) {
                $mes['motivo_compra'] = substr(trim((string) $row['motivo_compra']), 0, 180);
            }

            $mes['lectura_compra'] = $this->buildLecturaCompra($mes);
        }
        unset($mes);

        return $planProd;
    }

    private function construirOportunidadBase(array $meses, array $metricas, array $patrones, float $maxAllowed): array
    {
        $medianaActiva = max(0.0, (float) ($patrones['volumen_activo_mediana_kg'] ?? 0));
        $promActiva = max(0.0, (float) ($patrones['volumen_activo_promedio_kg'] ?? 0));
        $minActiva = max(0.0, (float) ($patrones['volumen_activo_min_kg'] ?? 0));

        foreach ($meses as &$mes) {
            $esperado = max(0, (float) ($mes['kg_proyectados'] ?? 0));
            $patronMes = $this->buscarPatronMes($patrones, $mes['mes'] ?? '');
            $referencia = max(0.0, (float) ($patronMes['kg_referencia'] ?? 0));

            $kgSiCompra = match ($patronMes['tipo'] ?? null) {
                'MES_PICO' => max($referencia, $medianaActiva > 0 ? $medianaActiva : $promActiva, $esperado),
                'MES_BAJO' => max($referencia, $medianaActiva * 0.9, $esperado),
                'MES_SIN_COMPRA' => max($medianaActiva, $promActiva, $esperado),
                default => max($referencia > 0 ? (($referencia + max($medianaActiva, $promActiva)) / 2) : max($medianaActiva, $promActiva), $esperado),
            };

            if ($kgSiCompra <= 0) {
                $kgSiCompra = max($esperado, $medianaActiva, $promActiva, $minActiva, 0);
            }

            $kgSiCompra = min(max($kgSiCompra, $esperado), $maxAllowed);
            $probabilidad = $kgSiCompra > 0 ? (int) round(min(95, max(0, ($esperado / $kgSiCompra) * 100))) : 0;

            $mes['kg_si_compra'] = $this->round5($kgSiCompra);
            $mes['probabilidad_compra_pct'] = $probabilidad;
            $mes['escenario_probabilidad'] = $this->labelProbabilidad($probabilidad);
            $mes['confianza_compra'] = $mes['confianza'] ?? $this->confianzaPorMetricas($metricas);
            $mes['motivo_compra'] = $this->motivoCompraBase($patronMes, $mes['kg_si_compra']);
            $mes['lectura_compra'] = $this->buildLecturaCompra($mes);
        }
        unset($mes);

        return $meses;
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

    private function confianzaPorPatron(?array $patronMes, array $metricas): string
    {
        $base = $this->confianzaPorMetricas($metricas);

        if (!$patronMes) {
            return $base;
        }

        return match ($patronMes['fuerza'] ?? null) {
            'ALTA' => $base === 'BAJA' ? 'MEDIA' : $base,
            'MEDIA' => $base,
            default => $base === 'ALTA' ? 'MEDIA' : $base,
        };
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

    private function normalizarMotivo(?string $motivoMes, ?string $justificacion, array $metricas, float $base, ?array $patronMes = null): string
    {
        $motivo = trim((string) ($motivoMes ?: $justificacion ?: ''));
        if ($motivo !== '') {
            return substr($motivo, 0, 240);
        }

        if ($base <= 0) {
            return 'Sin demanda proyectada en la base estadistica del periodo.';
        }

        if ($patronMes) {
            return substr("{$patronMes['label']}: {$patronMes['mensaje']}", 0, 240);
        }

        return "Escenario {$metricas['escenario']} con base estadistica de {$this->round5($base)} kg.";
    }

    private function normalizarAlertas($alertasAi, array $metricas, array $patrones, array $meses, float $enTransito): array
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
            return array_values(array_slice(array_merge($alertas, $this->alertasPorPatron($patrones, $meses)), 0, 3));
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

        return array_slice(array_merge($fallback, $this->alertasPorPatron($patrones, $meses)), 0, 3);
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

    private function intersectarRangos(float $minA, float $maxA, float $minB, float $maxB): array
    {
        $min = max($minA, $minB);
        $max = min($maxA, $maxB);

        if ($min <= $max) {
            return [$min, $max];
        }

        return [$max, $max];
    }

    private function buscarPatronMes(array $patrones, string $mes): ?array
    {
        foreach (($patrones['meses_objetivo'] ?? []) as $row) {
            if (($row['mes'] ?? null) === $mes) {
                return $row;
            }
        }

        return null;
    }

    private function resolverRangoPorPatron(?array $patronMes, array $metricas): array
    {
        if (!$patronMes) {
            return [0.75, 1.25];
        }

        return match ($patronMes['tipo'] ?? null) {
            'MES_SIN_COMPRA' => in_array($metricas['escenario'] ?? '', ['DORMIDO', 'POSIBLE_PERDIDA', 'PERDIDO', 'CAMPAÑA_PUNTUAL'], true)
                ? [0.0, 0.45]
                : [0.65, 0.95],
            'MES_BAJO' => [0.75, 1.0],
            'MES_PICO' => ($patronMes['fuerza'] ?? null) === 'MEDIA'
                ? [0.9, 1.15]
                : [0.85, 1.05],
            default => [0.85, 1.1],
        };
    }

    private function alertasPorPatron(array $patrones, array $meses): array
    {
        $alertas = [];
        $mesesMap = [];
        foreach ($meses as $mes) {
            $mesesMap[$mes['mes'] ?? ''] = $mes;
        }

        foreach (($patrones['meses_objetivo'] ?? []) as $patronMes) {
            $mes = $mesesMap[$patronMes['mes'] ?? ''] ?? null;
            if (!$mes) {
                continue;
            }

            if (($patronMes['tipo'] ?? null) === 'MES_PICO' && ($patronMes['fuerza'] ?? null) === 'BAJA' && ($mes['kg_proyectados'] ?? 0) > 0) {
                $alertas[] = [
                    'tipo' => 'EN_RIESGO',
                    'mensaje' => "{$patronMes['label']} hereda un pico aislado del año pasado; validar con comercial antes de sobrecomprar.",
                ];
                break;
            }

            if (($patronMes['tipo'] ?? null) === 'MES_SIN_COMPRA' && ($mes['kg_proyectados'] ?? 0) > 0) {
                $alertas[] = [
                    'tipo' => 'OPORTUNIDAD',
                    'mensaje' => "{$patronMes['label']} no tuvo compras el año pasado; si este forecast se confirma, sería una recuperación frente a una base apagada.",
                ];
                break;
            }
        }

        return $alertas;
    }

    private function labelProbabilidad(int $probabilidad): string
    {
        if ($probabilidad >= 70) {
            return 'ALTA';
        }

        if ($probabilidad >= 35) {
            return 'MEDIA';
        }

        return 'BAJA';
    }

    private function buildLecturaCompra(array $mes): string
    {
        $esperado = (int) ($mes['kg_proyectados'] ?? 0);
        $prob = (int) ($mes['probabilidad_compra_pct'] ?? 0);
        $siCompra = (int) ($mes['kg_si_compra'] ?? 0);

        if ($esperado <= 0 || $siCompra <= 0 || $prob <= 0) {
            return 'Sin oportunidad clara de compra en este mes.';
        }

        return "Esperado {$esperado} kg = ~{$prob}% de probabilidad de compra y ~{$siCompra} kg si la compra ocurre.";
    }

    private function motivoCompraBase(?array $patronMes, int $kgSiCompra): string
    {
        if ($kgSiCompra <= 0) {
            return 'Sin señal de compra relevante para este mes.';
        }

        if (!$patronMes) {
            return "Si compra, el tamaño probable del pedido estaría alrededor de {$kgSiCompra} kg.";
        }

        return match ($patronMes['tipo'] ?? null) {
            'MES_PICO' => "{$patronMes['label']} toma como referencia un mes históricamente alto; si compra, el bloque probable ronda {$kgSiCompra} kg.",
            'MES_SIN_COMPRA' => "{$patronMes['label']} no tuvo compra el año pasado; si reaparece demanda, el bloque probable seguiría la escala típica del producto (~{$kgSiCompra} kg).",
            'MES_BAJO' => "{$patronMes['label']} fue bajo frente al promedio activo, pero si compra el producto suele moverse en bloques cercanos a {$kgSiCompra} kg.",
            default => "{$patronMes['label']} se proyecta con una compra probable cercana a {$kgSiCompra} kg si el pedido se materializa.",
        };
    }

    private function resumirMesesPatron(array $rows): string
    {
        if (empty($rows)) {
            return 'sin datos relevantes';
        }

        return collect($rows)
            ->map(fn($row) => "{$row['label']}={$row['kg']}kg")
            ->implode(' | ');
    }

    private function resumirMesesApagados(array $meses): string
    {
        if (empty($meses)) {
            return 'ninguno';
        }

        return collect(array_slice($meses, 0, 6))
            ->map(fn($mes) => $this->labelMes($mes))
            ->implode(', ');
    }

    private function resumirMesesObjetivo(array $rows): string
    {
        if (empty($rows)) {
            return '  sin referencias';
        }

        return collect($rows)
            ->map(fn($row) => "  {$row['label']}: ref {$row['label_referencia']}={$row['kg_referencia']}kg | {$row['tipo']} | fuerza {$row['fuerza']} | {$row['mensaje']}")
            ->implode("\n");
    }

    private function labelMes(string $mes): string
    {
        [$year, $month] = explode('-', $mes);
        $labels = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

        return ($labels[((int) $month) - 1] ?? $month) . ' ' . substr($year, 2);
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
