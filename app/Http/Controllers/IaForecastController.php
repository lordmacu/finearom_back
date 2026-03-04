<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIaForecastClientProduct;
use App\Models\IaForecastClientRun;
use App\Models\IaForecastClientRunItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\IaAnalysisService;
use App\Services\IaStatisticsService;

class IaForecastController extends Controller
{
    /**
     * Todos los clientes que tienen historial en ia_historial_mensual
     */
    public function clients()
    {
        $clients = DB::select("
            SELECT c.id, c.client_name, c.nit,
                   COUNT(DISTINCT h.producto_id) AS total_productos,
                   COUNT(DISTINCT ia.producto_id) AS productos_con_forecast
            FROM ia_historial_mensual h
            JOIN clients c ON c.id = h.cliente_id
            LEFT JOIN ia_plan_compras ia ON ia.cliente_id = h.cliente_id
            GROUP BY c.id, c.client_name, c.nit
            ORDER BY c.client_name
        ");

        return response()->json([
            'success' => true,
            'data'    => $clients,
        ]);
    }

    /**
     * Todos los productos de un cliente con historial,
     * con flag tiene_forecast si ya fueron analizados por IA
     */
    public function products(int $clientId)
    {
        $products = DB::select("
            SELECT
                h.producto_id,
                p.code AS codigo,
                p.product_name AS producto,
                COUNT(h.mes) AS meses_historico,
                SUM(h.kg_real) AS kg_total,
                CASE WHEN ia.producto_id IS NOT NULL THEN 1 ELSE 0 END AS tiene_forecast,
                ia.escenario,
                ia.tendencia,
                ia.analizado_en
            FROM ia_historial_mensual h
            JOIN products p ON p.id = h.producto_id
            LEFT JOIN ia_plan_compras ia
                ON ia.cliente_id = h.cliente_id
               AND ia.producto_id = h.producto_id
            WHERE h.cliente_id = ?
            GROUP BY h.producto_id, p.code, p.product_name, ia.producto_id, ia.escenario, ia.tendencia, ia.analizado_en
            ORDER BY kg_total DESC
        ", [$clientId]);

        return response()->json([
            'success' => true,
            'data'    => $products,
        ]);
    }

    public function processing(int $clientId)
    {
        return response()->json([
            'success' => true,
            'data' => $this->buildProcessingPayload($clientId),
        ]);
    }

    /**
     * Historial 12m + forecast 4m (si existe) para un cliente × producto.
     * Siempre responde 200 — forecast y metricas son null si no fue analizado.
     */
    public function show(int $clientId, int $productoId)
    {
        $historial = DB::select("
            SELECT mes, kg_real
            FROM (
                SELECT mes, SUM(kg_real) AS kg_real
                FROM ia_historial_mensual
                WHERE cliente_id = ? AND producto_id = ?
                GROUP BY mes
                ORDER BY mes DESC
                LIMIT 12
            ) historial_12
            ORDER BY mes ASC
        ", [$clientId, $productoId]);

        if (empty($historial)) {
            return response()->json(['success' => false, 'message' => 'Sin datos para este producto.'], 404);
        }

        $productoInfo = DB::selectOne("
            SELECT h.producto_id, p.code AS codigo, p.product_name AS producto,
                   c.id AS cliente_id, c.client_name, c.nit
            FROM ia_historial_mensual h
            JOIN products p ON p.id = h.producto_id
            JOIN clients c ON c.id = h.cliente_id
            WHERE h.cliente_id = ? AND h.producto_id = ?
            LIMIT 1
        ", [$clientId, $productoId]);

        $plan = DB::selectOne("
            SELECT * FROM ia_plan_compras
            WHERE cliente_id = ? AND producto_id = ?
        ", [$clientId, $productoId]);

        $metricas = null;
        $forecast  = [];
        $alertas   = [];

        if ($plan) {
            $metricas = [
                'escenario'   => $plan->escenario,
                'tendencia'   => $plan->tendencia,
                'tecnica'     => $plan->tecnica,
                'prom_3m'     => (float) $plan->prom_3m,
                'prom_6m'     => (float) $plan->prom_6m,
                'prom_12m'    => (float) $plan->prom_12m,
                'consistencia'=> (int)   $plan->consistencia,
                'analizado_en'=> $plan->analizado_en,
            ];
            $forecast = json_decode($plan->meses,  true) ?? [];
            $alertas  = json_decode($plan->alertas, true) ?? [];
        }

        $historialData = array_map(fn($h) => [
            'mes'     => $h->mes,
            'kg_real' => (float) $h->kg_real,
        ], $historial);

        $historialParaPatrones = array_map(fn($h) => [
            'mes' => $h['mes'],
            'kg'  => $h['kg_real'],
        ], $historialData);

        $insights = IaStatisticsService::analizarPatrones(
            $historialParaPatrones,
            array_map(fn($row) => $row['mes'] ?? null, array_filter($forecast, fn($row) => !empty($row['mes'])))
        );

        return response()->json([
            'success' => true,
            'data' => [
                'cliente' => [
                    'id'          => $productoInfo->cliente_id,
                    'client_name' => $productoInfo->client_name,
                    'nit'         => $productoInfo->nit,
                ],
                'producto' => [
                    'id'       => $productoInfo->producto_id,
                    'codigo'   => $productoInfo->codigo,
                    'producto' => $productoInfo->producto,
                ],
                'tiene_forecast' => $plan !== null,
                'metricas'       => $metricas,
                'historial'      => $historialData,
                'insights'       => $insights,
                'forecast' => $forecast,
                'alertas'  => $alertas,
            ],
        ]);
    }

    /**
     * Dispara el análisis IA para un cliente × producto.
     * Bloquea hasta completar (3 calls al middleware + save ~30-60s).
     */
    public function analyze(int $clientId, int $productoId)
    {
        try {
            $service = new IaAnalysisService();
            $result  = $service->analyze($clientId, $productoId);

            return response()->json([
                'success' => true,
                'message' => 'Análisis completado',
                'data'    => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function analyzeClient(Request $request, int $clientId)
    {
        $activeRun = IaForecastClientRun::query()
            ->where('cliente_id', $clientId)
            ->whereIn('status', [IaForecastClientRun::STATUS_QUEUED, IaForecastClientRun::STATUS_PROCESSING])
            ->latest('id')
            ->first();

        if ($activeRun) {
            return response()->json([
                'success' => true,
                'message' => 'Ya existe un procesamiento activo para este cliente.',
                'data' => $this->buildProcessingPayload($clientId, $activeRun),
            ]);
        }

        $products = $this->getClientProductsForProcessing($clientId);
        if (empty($products)) {
            return response()->json([
                'success' => false,
                'message' => 'El cliente no tiene productos con historial para procesar.',
            ], 404);
        }

        $run = IaForecastClientRun::query()->create([
            'cliente_id' => $clientId,
            'status' => IaForecastClientRun::STATUS_QUEUED,
            'total_productos' => count($products),
            'pendientes' => count($products),
            'creado_por' => $request->user()?->id,
        ]);

        $items = [];
        foreach ($products as $index => $product) {
            $items[] = [
                'run_id' => $run->id,
                'cliente_id' => $clientId,
                'producto_id' => $product->producto_id,
                'sort_order' => $index + 1,
                'codigo' => $product->codigo,
                'producto' => $product->producto,
                'kg_total' => (float) $product->kg_total,
                'status' => IaForecastClientRunItem::STATUS_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        IaForecastClientRunItem::query()->insert($items);

        $firstItem = IaForecastClientRunItem::query()
            ->where('run_id', $run->id)
            ->orderBy('sort_order')
            ->first();

        if ($firstItem) {
            ProcessIaForecastClientProduct::dispatch($run->id, $firstItem->id)->onQueue('ia-forecast');
        }

        return response()->json([
            'success' => true,
            'message' => 'Procesamiento IA por cliente iniciado.',
            'data' => $this->buildProcessingPayload($clientId, $run->fresh()),
        ]);
    }

    private function buildProcessingPayload(int $clientId, ?IaForecastClientRun $run = null): array
    {
        $run = $run ?: IaForecastClientRun::query()
            ->where('cliente_id', $clientId)
            ->latest('id')
            ->first();

        $products = $this->getClientProductsForProcessing($clientId);
        $items = $run
            ? IaForecastClientRunItem::query()
                ->where('run_id', $run->id)
                ->orderBy('sort_order')
                ->get()
                ->keyBy('producto_id')
            : collect();

        $rows = collect($products)->map(function ($product) use ($items) {
            $item = $items->get($product->producto_id);

            return [
                'producto_id' => (int) $product->producto_id,
                'codigo' => $product->codigo,
                'producto' => $product->producto,
                'kg_total' => (float) $product->kg_total,
                'tiene_forecast' => (bool) $product->tiene_forecast,
                'analizado_en' => $item?->analizado_en?->toDateTimeString() ?? $product->analizado_en,
                'status' => $item?->status ?? ((bool) $product->tiene_forecast ? IaForecastClientRunItem::STATUS_COMPLETED : IaForecastClientRunItem::STATUS_PENDING),
                'attempts' => (int) ($item->attempts ?? 0),
                'error_message' => $item->error_message ?? null,
                'started_at' => $item?->started_at?->toDateTimeString(),
                'finished_at' => $item?->finished_at?->toDateTimeString(),
            ];
        })->values();

        $summary = [
            'total_productos' => (int) ($run->total_productos ?? $rows->count()),
            'procesados' => (int) ($run->procesados ?? $rows->whereIn('status', [IaForecastClientRunItem::STATUS_COMPLETED, IaForecastClientRunItem::STATUS_ERROR])->count()),
            'completados' => (int) ($run->completados ?? $rows->where('status', IaForecastClientRunItem::STATUS_COMPLETED)->count()),
            'errores' => (int) ($run->errores ?? $rows->where('status', IaForecastClientRunItem::STATUS_ERROR)->count()),
            'pendientes' => (int) ($run->pendientes ?? $rows->whereIn('status', [IaForecastClientRunItem::STATUS_PENDING, IaForecastClientRunItem::STATUS_PROCESSING])->count()),
        ];

        $currentItem = $rows->firstWhere('status', IaForecastClientRunItem::STATUS_PROCESSING);

        return [
            'run' => $run ? [
                'id' => $run->id,
                'status' => $run->status,
                'started_at' => optional($run->started_at)->toDateTimeString(),
                'finished_at' => optional($run->finished_at)->toDateTimeString(),
                'error_message' => $run->error_message,
                'summary' => $summary,
                'current_item' => $currentItem,
            ] : null,
            'products' => $rows,
            'has_active_run' => $run ? $run->isActive() : false,
        ];
    }

    private function getClientProductsForProcessing(int $clientId): array
    {
        return DB::select("
            SELECT
                h.producto_id,
                p.code AS codigo,
                p.product_name AS producto,
                SUM(h.kg_real) AS kg_total,
                CASE WHEN ia.producto_id IS NOT NULL THEN 1 ELSE 0 END AS tiene_forecast,
                ia.analizado_en
            FROM ia_historial_mensual h
            JOIN products p ON p.id = h.producto_id
            LEFT JOIN ia_plan_compras ia
                ON ia.cliente_id = h.cliente_id
               AND ia.producto_id = h.producto_id
            WHERE h.cliente_id = ?
            GROUP BY h.producto_id, p.code, p.product_name, ia.producto_id, ia.analizado_en
            ORDER BY kg_total DESC, p.product_name ASC
        ", [$clientId]);
    }
}
