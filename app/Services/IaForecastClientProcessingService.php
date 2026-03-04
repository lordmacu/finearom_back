<?php

namespace App\Services;

use App\Models\IaForecastClientRun;
use App\Models\IaForecastClientRunItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IaForecastClientProcessingService
{
    public function buildPayload(int $clientId, ?IaForecastClientRun $run = null): array
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

    public function getClientProductsForProcessing(int $clientId): array
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
