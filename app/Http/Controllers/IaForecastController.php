<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\IaAnalysisService;

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
                MAX(h.codigo) AS codigo,
                p.description AS producto,
                COUNT(h.mes) AS meses_historico,
                SUM(h.kg_real) AS kg_total,
                CASE WHEN ia.producto_id IS NOT NULL THEN 1 ELSE 0 END AS tiene_forecast,
                ia.escenario,
                ia.tendencia,
                ia.analizado_en
            FROM (
                SELECT cliente_id, producto_id, codigo, mes, kg_real
                FROM ia_historial_mensual
                WHERE cliente_id = ?
            ) h
            JOIN products p ON p.id = h.producto_id
            LEFT JOIN ia_plan_compras ia
                ON ia.cliente_id = h.cliente_id
               AND ia.producto_id = h.producto_id
            GROUP BY h.producto_id, p.description, ia.producto_id, ia.escenario, ia.tendencia, ia.analizado_en
            ORDER BY kg_total DESC
        ", [$clientId]);

        return response()->json([
            'success' => true,
            'data'    => $products,
        ]);
    }

    /**
     * Historial 12m + forecast 4m (si existe) para un cliente × producto.
     * Siempre responde 200 — forecast y metricas son null si no fue analizado.
     */
    public function show(int $clientId, int $productoId)
    {
        $historial = DB::select("
            SELECT mes, SUM(kg_real) AS kg_real
            FROM ia_historial_mensual
            WHERE cliente_id = ? AND producto_id = ?
            GROUP BY mes
            ORDER BY mes ASC
            LIMIT 12
        ", [$clientId, $productoId]);

        if (empty($historial)) {
            return response()->json(['success' => false, 'message' => 'Sin datos para este producto.'], 404);
        }

        $productoInfo = DB::selectOne("
            SELECT h.producto_id, MAX(h.codigo) AS codigo, p.description AS producto,
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
                'historial'      => array_map(fn($h) => [
                    'mes'     => $h->mes,
                    'kg_real' => (float) $h->kg_real,
                ], $historial),
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
}
