<?php

namespace App\Services;

use App\Models\IaForecastClientRun;
use App\Models\IaForecastClientRunItem;
use App\Jobs\ProcessIaForecastClientProduct;
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

        return [
            'run' => $this->buildRunPayload($run, $rows),
            'products' => $rows,
            'has_active_run' => $run ? $run->isActive() : false,
        ];
    }

    public function buildEventPayload(int $clientId, ?IaForecastClientRun $run = null, ?int $itemId = null): array
    {
        $run = $run ?: IaForecastClientRun::query()
            ->where('cliente_id', $clientId)
            ->latest('id')
            ->first();

        return [
            'client_id' => $clientId,
            'run' => $this->buildRunPayload($run),
            'row' => $itemId ? $this->buildItemPayload($itemId, $run?->id) : null,
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

    public function getClientProductsToProcess(int $clientId, bool $force = true): array
    {
        $products = collect($this->getClientProductsForProcessing($clientId));

        if ($force) {
            return $products->values()->all();
        }

        return $products
            ->filter(fn($product) => !(bool) $product->tiene_forecast)
            ->values()
            ->all();
    }

    public function getClientProductsWithErrorsFromLatestRun(int $clientId): array
    {
        $latestRunId = IaForecastClientRun::query()
            ->where('cliente_id', $clientId)
            ->max('id');

        if (!$latestRunId) {
            return [];
        }

        $errorProductIds = IaForecastClientRunItem::query()
            ->where('run_id', $latestRunId)
            ->where('status', IaForecastClientRunItem::STATUS_ERROR)
            ->pluck('producto_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        if ($errorProductIds->isEmpty()) {
            return [];
        }

        return collect($this->getClientProductsForProcessing($clientId))
            ->filter(fn($product) => $errorProductIds->contains((int) $product->producto_id))
            ->values()
            ->all();
    }

    public function createRun(int $clientId, array $products, ?int $createdBy = null, ?int $batchRunId = null): IaForecastClientRun
    {
        $run = IaForecastClientRun::query()->create([
            'cliente_id' => $clientId,
            'batch_run_id' => $batchRunId,
            'status' => IaForecastClientRun::STATUS_QUEUED,
            'total_productos' => count($products),
            'pendientes' => count($products),
            'creado_por' => $createdBy,
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

        return $run->fresh();
    }

    private function buildRunPayload(?IaForecastClientRun $run, ?Collection $rows = null): ?array
    {
        if (!$run) {
            return null;
        }

        $rows = $rows ?: IaForecastClientRunItem::query()
            ->where('run_id', $run->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn(IaForecastClientRunItem $item) => $this->mapItemToPayload($item));

        $summary = [
            'total_productos' => (int) ($run->total_productos ?? $rows->count()),
            'procesados' => (int) ($run->procesados ?? $rows->whereIn('status', [IaForecastClientRunItem::STATUS_COMPLETED, IaForecastClientRunItem::STATUS_ERROR])->count()),
            'completados' => (int) ($run->completados ?? $rows->where('status', IaForecastClientRunItem::STATUS_COMPLETED)->count()),
            'errores' => (int) ($run->errores ?? $rows->where('status', IaForecastClientRunItem::STATUS_ERROR)->count()),
            'pendientes' => (int) ($run->pendientes ?? $rows->whereIn('status', [IaForecastClientRunItem::STATUS_PENDING, IaForecastClientRunItem::STATUS_PROCESSING])->count()),
        ];

        return [
            'id' => $run->id,
            'status' => $run->status,
            'started_at' => optional($run->started_at)->toDateTimeString(),
            'finished_at' => optional($run->finished_at)->toDateTimeString(),
            'error_message' => $run->error_message,
            'summary' => $summary,
            'current_item' => $rows->firstWhere('status', IaForecastClientRunItem::STATUS_PROCESSING),
        ];
    }

    private function buildItemPayload(int $itemId, ?int $runId = null): ?array
    {
        $query = IaForecastClientRunItem::query()->whereKey($itemId);
        if ($runId) {
            $query->where('run_id', $runId);
        }

        $item = $query->first();

        return $item ? $this->mapItemToPayload($item) : null;
    }

    private function mapItemToPayload(IaForecastClientRunItem $item): array
    {
        return [
            'producto_id' => (int) $item->producto_id,
            'codigo' => $item->codigo,
            'producto' => $item->producto,
            'kg_total' => (float) $item->kg_total,
            'analizado_en' => $item->analizado_en?->toDateTimeString(),
            'status' => $item->status,
            'attempts' => (int) ($item->attempts ?? 0),
            'error_message' => $item->error_message,
            'started_at' => $item->started_at?->toDateTimeString(),
            'finished_at' => $item->finished_at?->toDateTimeString(),
        ];
    }
}
