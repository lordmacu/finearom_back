<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        // ── Contexto del sistema ──────────────────────────────────────────────
        $semanasPico = collect($timing)
            ->sortByDesc('num_pedidos')
            ->map(fn($t) => "semana {$t->semana_del_mes}: {$t->num_pedidos} pedidos")
            ->implode(', ') ?: 'sin datos';

        $diasRestantes = now()->daysInMonth - now()->day;

        $CONTEXTO_SISTEMA = "Eres experto en planificación de compras de materias primas para Finearom, empresa de fragancias colombiana (B2B).\n"
            . "FECHA HOY: {$hoy} | CLIENTE: " . ($perfil->client_name ?? $clientId) . " | TIMING: {$semanasPico}\n"
            . "ESTACIONALIDAD NAVIDAD (único prior de industria activo): Oct:1.35x Nov:1.40x Dic:1.10x — resto del año basado en datos históricos del cliente.\n"
            . "TÉCNICAS: HOLT_WINTERS(≥10m) | SES_SEASONAL(5-9m) | CROSTON_SBA(intermitente) | WMA(nuevo/reactivado)";

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
            . "PROYECCIÓN ESTADÍSTICA:\n{$proyStr}\n"
            . "HISTORIAL (últimos 12 meses):\n{$filasMes}";

        $convId = "ia_{$clientId}_{$producto->codigo}_{$mesActual}";

        // ── FASE 3: Call 1 — validar tendencia + mes 1 ───────────────────────
        $prompt1 = "{$CONTEXTO_SISTEMA}\n\n{$contextoProducto}\n\n"
            . "TAREA: Evalúa este producto:\n"
            . "1. ¿La proyección estadística es razonable dado el historial?\n"
            . "2. ¿Hay cambios de comportamiento que el modelo no captura?\n"
            . "3. Proyección ajustada para {$mesesForecast[0]}\n\n"
            . "Responde SOLO con JSON (objeto, no array):\n"
            . "{\n"
            . "  \"evaluacion_tendencia\": \"REALISTA|OPTIMISTA|CONSERVADOR\",\n"
            . "  \"proyeccion_mes1_kg\": 0,\n"
            . "  \"factor_ajuste\": 1.0,\n"
            . "  \"hay_estacionalidad\": true,\n"
            . "  \"nota_estacionalidad\": \"...\",\n"
            . "  \"confianza\": \"ALTA|MEDIA|BAJA\",\n"
            . "  \"justificacion\": \"...\"\n"
            . "}";

        $r1   = $this->llamarMiddleware($prompt1, $convId, true);
        $mes1 = $this->extraerJSON($r1);

        // ── Call 2 — meses 2-4 ────────────────────────────────────────────────
        $proyRef = $metricas['proyeccion4m']
            ? collect($metricas['proyeccion4m'])
                ->map(fn($kg, $i) => "{$mesesForecast[$i]}={$kg}kg")
                ->implode(' | ')
            : 'no disponible';

        $prompt2 = "Basándote en el análisis anterior de este producto, proyecta los siguientes meses:\n"
            . "- {$mesesForecast[1]} (mes 2)\n"
            . "- {$mesesForecast[2]} (mes 3)\n"
            . "- {$mesesForecast[3]} (mes 4)\n\n"
            . "Referencia estadística completa: {$proyRef}\n"
            . "Propaga el factor de ajuste del mes 1 si aplica.\n\n"
            . "Responde SOLO con JSON:\n"
            . "{\n"
            . "  \"mes2_kg\": 0, \"mes2_confianza\": \"ALTA|MEDIA|BAJA\",\n"
            . "  \"mes3_kg\": 0, \"mes3_confianza\": \"ALTA|MEDIA|BAJA\",\n"
            . "  \"mes4_kg\": 0, \"mes4_confianza\": \"ALTA|MEDIA|BAJA\",\n"
            . "  \"nota\": \"...\"\n"
            . "}";

        $r2      = $this->llamarMiddleware($prompt2, $convId, false);
        $meses234 = $this->extraerJSON($r2);

        // ── Call 3 — plan de compra ────────────────────────────────────────────
        $prompt3 = "Con base en el análisis completo de este producto, genera el plan de compra.\n\n"
            . "DÍAS RESTANTES DEL MES: {$diasRestantes} | LEAD TIME: ~5-10 días\n"
            . "EN TRÁNSITO: {$enTransito} kg\n\n"
            . "Proyecciones acordadas: "
            . "{$mesesForecast[0]}={$mes1['proyeccion_mes1_kg']}kg | "
            . "{$mesesForecast[1]}={$meses234['mes2_kg']}kg | "
            . "{$mesesForecast[2]}={$meses234['mes3_kg']}kg | "
            . "{$mesesForecast[3]}={$meses234['mes4_kg']}kg\n\n"
            . "Responde SOLO con JSON:\n"
            . "{\n"
            . "  \"clasificacion\": \"...\",\n"
            . "  \"tendencia\": \"↑|↓|→\",\n"
            . "  \"meses\": [\n"
            . "    {\n"
            . "      \"mes\": \"YYYY-MM\",\n"
            . "      \"kg_proyectados\": 0,\n"
            . "      \"kg_en_transito\": 0,\n"
            . "      \"kg_a_comprar\": 0,\n"
            . "      \"urgencia\": \"ALTA|MEDIA|BAJA\",\n"
            . "      \"fecha_compra_recomendada\": \"YYYY-MM-DD\",\n"
            . "      \"confianza\": \"ALTA|MEDIA|BAJA\",\n"
            . "      \"motivo\": \"...\"\n"
            . "    }\n"
            . "  ],\n"
            . "  \"alertas\": [\n"
            . "    { \"tipo\": \"EN_RIESGO|CRECIENDO|SIN_STOCK|OPORTUNIDAD\", \"mensaje\": \"...\" }\n"
            . "  ]\n"
            . "}";

        $r3      = $this->llamarMiddleware($prompt3, $convId, false);
        $planProd = $this->extraerJSON($r3);

        // ── Redondear a múltiplos de 5 ────────────────────────────────────────
        foreach ($planProd['meses'] ?? [] as &$mes) {
            $mes['kg_proyectados'] = $this->round5($mes['kg_proyectados'] ?? 0);
            $mes['kg_en_transito'] = $this->round5($mes['kg_en_transito'] ?? 0);
            $mes['kg_a_comprar']   = $this->round5($mes['kg_a_comprar']   ?? 0);
        }
        unset($mes);

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

    // ── DB queries ────────────────────────────────────────────────────────────

    private function getProducto(int $clientId, int $productoId): ?object
    {
        return DB::selectOne("
            SELECT p.id, p.code AS codigo, p.description AS producto
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
