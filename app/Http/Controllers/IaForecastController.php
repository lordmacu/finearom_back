<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IaForecastController extends Controller
{
    /**
     * Clientes que tienen datos en ia_plan_compras
     */
    public function clients()
    {
        $clients = DB::select("
            SELECT DISTINCT c.id, c.client_name, c.nit
            FROM ia_plan_compras ia
            JOIN clients c ON c.id = ia.cliente_id
            ORDER BY c.client_name
        ");

        return response()->json([
            'success' => true,
            'data'    => $clients,
        ]);
    }

    /**
     * Productos de un cliente con datos en ia_plan_compras
     */
    public function products(int $clientId)
    {
        $products = DB::select("
            SELECT producto_id, codigo, producto, escenario, tendencia,
                   tecnica, consistencia, prom_3m, prom_6m, prom_12m, analizado_en
            FROM ia_plan_compras
            WHERE cliente_id = ?
            ORDER BY producto
        ", [$clientId]);

        return response()->json([
            'success' => true,
            'data'    => $products,
        ]);
    }

    /**
     * Historial 12m + forecast 4m completo para un cliente × producto
     */
    public function show(int $clientId, int $productoId)
    {
        // ── Plan y métricas ───────────────────────────────────────────────────
        $plan = DB::selectOne("
            SELECT ia.*, c.client_name, c.nit
            FROM ia_plan_compras ia
            JOIN clients c ON c.id = ia.cliente_id
            WHERE ia.cliente_id = ? AND ia.producto_id = ?
        ", [$clientId, $productoId]);

        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'No hay forecast para este cliente/producto.'], 404);
        }

        // ── Historial mensual (últimos 12 meses) ─────────────────────────────
        $historial = DB::select("
            SELECT mes, kg_real
            FROM ia_historial_mensual
            WHERE cliente_id = ? AND producto_id = ?
            ORDER BY mes ASC
            LIMIT 12
        ", [$clientId, $productoId]);

        // ── Respuesta estructurada ────────────────────────────────────────────
        $meses   = json_decode($plan->meses,  true) ?? [];
        $alertas = json_decode($plan->alertas, true) ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'cliente' => [
                    'id'          => $plan->cliente_id,
                    'client_name' => $plan->client_name,
                    'nit'         => $plan->nit,
                ],
                'producto' => [
                    'id'      => $plan->producto_id,
                    'codigo'  => $plan->codigo,
                    'producto'=> $plan->producto,
                ],
                'metricas' => [
                    'escenario'   => $plan->escenario,
                    'tendencia'   => $plan->tendencia,
                    'tecnica'     => $plan->tecnica,
                    'prom_3m'     => (float) $plan->prom_3m,
                    'prom_6m'     => (float) $plan->prom_6m,
                    'prom_12m'    => (float) $plan->prom_12m,
                    'consistencia'=> (int)   $plan->consistencia,
                    'analizado_en'=> $plan->analizado_en,
                ],
                'historial' => array_map(fn($h) => [
                    'mes'     => $h->mes,
                    'kg_real' => (float) $h->kg_real,
                ], $historial),
                'forecast' => $meses,
                'alertas'  => $alertas,
            ],
        ]);
    }
}
