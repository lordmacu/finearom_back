<?php

namespace App\Services;

use App\Events\IaForecastBatchProcessingUpdated;
use App\Models\IaForecastBatchRun;
use App\Models\IaForecastBatchRunItem;
use App\Models\IaForecastClientRun;
use App\Models\IaForecastClientRunItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IaForecastBatchProcessingService
{
    public function __construct(
        private readonly IaForecastClientProcessingService $clientProcessingService
    ) {
    }

    public function buildPayload(?IaForecastBatchRun $run = null): array
    {
        $run = $run ?: IaForecastBatchRun::query()->latest('id')->first();

        $items = $run
            ? IaForecastBatchRunItem::query()
                ->where('batch_run_id', $run->id)
                ->orderBy('id')
                ->get()
            : collect();

        $rows = $items->map(fn(IaForecastBatchRunItem $item) => [
            'cliente_id' => (int) $item->cliente_id,
            'client_name' => $item->client_name,
            'total_productos' => (int) $item->total_productos,
            'status' => $item->status,
            'attempts' => (int) $item->attempts,
            'started_at' => optional($item->started_at)->toDateTimeString(),
            'finished_at' => optional($item->finished_at)->toDateTimeString(),
            'error_message' => $item->error_message,
            'current_run_id' => $item->current_run_id ? (int) $item->current_run_id : null,
        ])->values();

        $summary = [
            'total_clientes' => (int) ($run->total_clientes ?? $rows->count()),
            'total_productos' => (int) ($run->total_productos ?? $rows->sum('total_productos')),
            'procesados' => (int) ($run->procesados ?? $rows->whereIn('status', [IaForecastBatchRunItem::STATUS_COMPLETED, IaForecastBatchRunItem::STATUS_ERROR])->count()),
            'completados' => (int) ($run->completados ?? $rows->where('status', IaForecastBatchRunItem::STATUS_COMPLETED)->count()),
            'errores' => (int) ($run->errores ?? $rows->where('status', IaForecastBatchRunItem::STATUS_ERROR)->count()),
            'pendientes' => (int) ($run->pendientes ?? $rows->whereIn('status', [IaForecastBatchRunItem::STATUS_PENDING, IaForecastBatchRunItem::STATUS_PROCESSING])->count()),
        ];

        $currentItem = $rows->firstWhere('status', IaForecastBatchRunItem::STATUS_PROCESSING);

        return [
            'run' => $run ? [
                'id' => $run->id,
                'mode' => $run->mode,
                'status' => $run->status,
                'started_at' => optional($run->started_at)->toDateTimeString(),
                'finished_at' => optional($run->finished_at)->toDateTimeString(),
                'error_message' => $run->error_message,
                'summary' => $summary,
                'current_item' => $currentItem,
            ] : null,
            'clients' => $rows,
            'has_active_run' => $run ? $run->isActive() : false,
        ];
    }

    public function getClientsToProcess(bool $force = false): Collection
    {
        $clients = collect(DB::select("
            SELECT
                c.id,
                c.client_name,
                COUNT(DISTINCT h.producto_id) AS total_productos,
                COUNT(DISTINCT CASE WHEN ia.producto_id IS NULL THEN h.producto_id END) AS productos_pendientes
            FROM ia_historial_mensual h
            JOIN clients c ON c.id = h.cliente_id
            LEFT JOIN ia_plan_compras ia
                ON ia.cliente_id = h.cliente_id
               AND ia.producto_id = h.producto_id
            GROUP BY c.id, c.client_name
            ORDER BY c.client_name
        "));

        return $clients
            ->map(function ($client) use ($force) {
                $productos = $force
                    ? (int) $client->total_productos
                    : (int) $client->productos_pendientes;

                return (object) [
                    'id' => (int) $client->id,
                    'client_name' => $client->client_name,
                    'total_productos' => (int) $client->total_productos,
                    'productos_a_procesar' => $productos,
                ];
            })
            ->filter(fn($client) => $client->productos_a_procesar > 0)
            ->values();
    }

    public function getClientsWithErrorsToProcess(): Collection
    {
        $clients = collect(DB::select("
            SELECT
                c.id,
                c.client_name,
                COUNT(i.id) AS productos_error
            FROM (
                SELECT cliente_id, MAX(id) AS latest_run_id
                FROM ia_forecast_client_runs
                GROUP BY cliente_id
            ) lr
            JOIN ia_forecast_client_run_items i
                ON i.run_id = lr.latest_run_id
               AND i.status = 'ERROR'
            JOIN clients c ON c.id = lr.cliente_id
            GROUP BY c.id, c.client_name
            ORDER BY c.client_name
        "));

        return $clients
            ->map(fn($client) => (object) [
                'id' => (int) $client->id,
                'client_name' => $client->client_name,
                'total_productos' => (int) $client->productos_error,
                'productos_a_procesar' => (int) $client->productos_error,
            ])
            ->filter(fn($client) => $client->productos_a_procesar > 0)
            ->values();
    }

    public function startBatch(bool $force = false, ?int $createdBy = null): array
    {
        return $this->startBatchByMode(
            $force ? IaForecastBatchRun::MODE_FORCE_RESTART : IaForecastBatchRun::MODE_PROCESS_ALL,
            $force,
            $createdBy,
        );
    }

    public function startRetryErrors(?int $createdBy = null): array
    {
        return $this->startBatchByMode(IaForecastBatchRun::MODE_RETRY_ERRORS, false, $createdBy);
    }

    private function startBatchByMode(string $mode, bool $force, ?int $createdBy): array
    {
        $activeRun = IaForecastBatchRun::query()
            ->whereIn('status', [IaForecastBatchRun::STATUS_QUEUED, IaForecastBatchRun::STATUS_PROCESSING])
            ->latest('id')
            ->first();

        if ($activeRun) {
            if ($force) {
                $this->cancelActiveRuns('Reinicio global forzado manualmente.');
            } else {
                return $this->buildPayload($activeRun);
            }
        }

        $activeClientRun = IaForecastClientRun::query()
            ->whereIn('status', [IaForecastClientRun::STATUS_QUEUED, IaForecastClientRun::STATUS_PROCESSING])
            ->latest('id')
            ->first();

        if ($activeClientRun) {
            if ($force) {
                $this->cancelActiveRuns('Reinicio global forzado manualmente.');
            } else {
                throw new \RuntimeException('Ya existe un procesamiento individual en curso. Espera a que termine antes de lanzar el lote global.');
            }
        }

        $clients = match ($mode) {
            IaForecastBatchRun::MODE_FORCE_RESTART => $this->getClientsToProcess(true),
            IaForecastBatchRun::MODE_RETRY_ERRORS => $this->getClientsWithErrorsToProcess(),
            default => $this->getClientsToProcess(false),
        };

        if ($clients->isEmpty()) {
            $message = $mode === IaForecastBatchRun::MODE_RETRY_ERRORS
                ? 'No hay productos con error para reprocesar.'
                : 'No hay clientes con productos pendientes para procesar.';
            throw new \RuntimeException($message);
        }

        $run = IaForecastBatchRun::query()->create([
            'mode' => $mode,
            'status' => IaForecastBatchRun::STATUS_QUEUED,
            'total_clientes' => $clients->count(),
            'total_productos' => (int) $clients->sum('productos_a_procesar'),
            'pendientes' => $clients->count(),
            'creado_por' => $createdBy,
        ]);

        $items = $clients->values()->map(fn($client) => [
            'batch_run_id' => $run->id,
            'cliente_id' => $client->id,
            'client_name' => $client->client_name,
            'total_productos' => $client->productos_a_procesar,
            'status' => IaForecastBatchRunItem::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        IaForecastBatchRunItem::query()->insert($items);

        $this->dispatchNextClient($run->fresh());

        return $this->buildPayload($run->fresh());
    }

    private function cancelActiveRuns(string $reason): void
    {
        $timestamp = now();

        $activeBatchRuns = IaForecastBatchRun::query()
            ->whereIn('status', [IaForecastBatchRun::STATUS_QUEUED, IaForecastBatchRun::STATUS_PROCESSING])
            ->get();

        foreach ($activeBatchRuns as $batchRun) {
            IaForecastBatchRunItem::query()
                ->where('batch_run_id', $batchRun->id)
                ->whereIn('status', [IaForecastBatchRunItem::STATUS_PENDING, IaForecastBatchRunItem::STATUS_PROCESSING])
                ->update([
                    'status' => IaForecastBatchRunItem::STATUS_ERROR,
                    'finished_at' => $timestamp,
                    'error_message' => $reason,
                    'updated_at' => $timestamp,
                ]);

            $completed = IaForecastBatchRunItem::query()
                ->where('batch_run_id', $batchRun->id)
                ->where('status', IaForecastBatchRunItem::STATUS_COMPLETED)
                ->count();

            $errors = IaForecastBatchRunItem::query()
                ->where('batch_run_id', $batchRun->id)
                ->where('status', IaForecastBatchRunItem::STATUS_ERROR)
                ->count();

            $batchRun->update([
                'status' => $completed > 0 ? IaForecastBatchRun::STATUS_COMPLETED_WITH_ERRORS : IaForecastBatchRun::STATUS_FAILED,
                'procesados' => $completed + $errors,
                'completados' => $completed,
                'errores' => $errors,
                'pendientes' => 0,
                'finished_at' => $timestamp,
                'error_message' => $reason,
            ]);

            $this->broadcastUpdate($batchRun->id);
        }

        $activeClientRuns = IaForecastClientRun::query()
            ->whereIn('status', [IaForecastClientRun::STATUS_QUEUED, IaForecastClientRun::STATUS_PROCESSING])
            ->get();

        foreach ($activeClientRuns as $clientRun) {
            IaForecastClientRunItem::query()
                ->where('run_id', $clientRun->id)
                ->whereIn('status', [IaForecastClientRunItem::STATUS_PENDING, IaForecastClientRunItem::STATUS_PROCESSING])
                ->update([
                    'status' => IaForecastClientRunItem::STATUS_ERROR,
                    'finished_at' => $timestamp,
                    'error_message' => $reason,
                    'updated_at' => $timestamp,
                ]);

            $completed = IaForecastClientRunItem::query()
                ->where('run_id', $clientRun->id)
                ->where('status', IaForecastClientRunItem::STATUS_COMPLETED)
                ->count();

            $errors = IaForecastClientRunItem::query()
                ->where('run_id', $clientRun->id)
                ->where('status', IaForecastClientRunItem::STATUS_ERROR)
                ->count();

            $clientRun->update([
                'status' => $completed > 0 ? IaForecastClientRun::STATUS_COMPLETED_WITH_ERRORS : IaForecastClientRun::STATUS_FAILED,
                'procesados' => $completed + $errors,
                'completados' => $completed,
                'errores' => $errors,
                'pendientes' => 0,
                'finished_at' => $timestamp,
                'error_message' => $reason,
            ]);

            event(new \App\Events\IaForecastClientProcessingUpdated(
                (int) $clientRun->cliente_id,
                [
                    'run_id' => (int) $clientRun->id,
                    'status' => $clientRun->status,
                ]
            ));
        }
    }

    public function dispatchNextClient(IaForecastBatchRun $run): void
    {
        $run = IaForecastBatchRun::query()->find($run->id);
        if (!$run || !$run->isActive() && $run->status !== IaForecastBatchRun::STATUS_QUEUED) {
            return;
        }

        $nextItem = null;

        DB::transaction(function () use ($run, &$nextItem) {
            $lockedRun = IaForecastBatchRun::query()->lockForUpdate()->find($run->id);
            if (!$lockedRun) {
                return;
            }

            $nextItem = IaForecastBatchRunItem::query()
                ->where('batch_run_id', $lockedRun->id)
                ->where('status', IaForecastBatchRunItem::STATUS_PENDING)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$nextItem) {
                return;
            }

            $lockedRun->update([
                'status' => IaForecastBatchRun::STATUS_PROCESSING,
                'started_at' => $lockedRun->started_at ?? now(),
                'error_message' => null,
            ]);

            $nextItem->update([
                'status' => IaForecastBatchRunItem::STATUS_PROCESSING,
                'attempts' => (int) $nextItem->attempts + 1,
                'started_at' => now(),
                'finished_at' => null,
                'error_message' => null,
            ]);

            $nextItem = $nextItem->fresh();
        });

        if (!$nextItem) {
            $this->refreshRunState($run->fresh());
            $this->broadcastUpdate($run->id);
            return;
        }

        $products = match ($run->mode) {
            IaForecastBatchRun::MODE_FORCE_RESTART => $this->clientProcessingService->getClientProductsToProcess((int) $nextItem->cliente_id, true),
            IaForecastBatchRun::MODE_RETRY_ERRORS => $this->clientProcessingService->getClientProductsWithErrorsFromLatestRun((int) $nextItem->cliente_id),
            default => $this->clientProcessingService->getClientProductsToProcess((int) $nextItem->cliente_id, false),
        };

        if (empty($products)) {
            $nextItem->update([
                'status' => IaForecastBatchRunItem::STATUS_COMPLETED,
                'finished_at' => now(),
                'error_message' => null,
            ]);

            $this->refreshRunState($run->fresh());
            $this->broadcastUpdate($run->id);
            $this->dispatchNextClient($run->fresh());
            return;
        }

        $clientRun = $this->clientProcessingService->createRun(
            (int) $nextItem->cliente_id,
            $products,
            $run->creado_por,
            $run->id,
        );

        $nextItem->update([
            'current_run_id' => $clientRun->id,
            'total_productos' => $clientRun->total_productos,
        ]);

        $this->broadcastUpdate($run->id);
    }

    public function onClientRunFinished(IaForecastClientRun $clientRun): void
    {
        if (!$clientRun->batch_run_id) {
            return;
        }

        $batchRun = IaForecastBatchRun::query()->find($clientRun->batch_run_id);
        if (!$batchRun) {
            return;
        }

        $item = IaForecastBatchRunItem::query()
            ->where('batch_run_id', $batchRun->id)
            ->where('cliente_id', $clientRun->cliente_id)
            ->first();

        if (!$item) {
            return;
        }

        $itemStatus = in_array($clientRun->status, [IaForecastClientRun::STATUS_FAILED, IaForecastClientRun::STATUS_COMPLETED_WITH_ERRORS], true)
            ? IaForecastBatchRunItem::STATUS_ERROR
            : IaForecastBatchRunItem::STATUS_COMPLETED;

        $item->update([
            'status' => $itemStatus,
            'finished_at' => now(),
            'error_message' => $clientRun->error_message,
            'current_run_id' => $clientRun->id,
        ]);

        $this->refreshRunState($batchRun->fresh());
        $this->broadcastUpdate($batchRun->id);
        $this->dispatchNextClient($batchRun->fresh());
    }

    public function refreshRunState(IaForecastBatchRun $run): void
    {
        $items = IaForecastBatchRunItem::query()
            ->selectRaw('status, COUNT(*) as total')
            ->where('batch_run_id', $run->id)
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending = (int) ($items[IaForecastBatchRunItem::STATUS_PENDING] ?? 0);
        $processing = (int) ($items[IaForecastBatchRunItem::STATUS_PROCESSING] ?? 0);
        $completed = (int) ($items[IaForecastBatchRunItem::STATUS_COMPLETED] ?? 0);
        $errors = (int) ($items[IaForecastBatchRunItem::STATUS_ERROR] ?? 0);
        $processed = $completed + $errors;

        $status = $processing > 0 || $pending > 0
            ? IaForecastBatchRun::STATUS_PROCESSING
            : ($errors > 0
                ? ($completed > 0 ? IaForecastBatchRun::STATUS_COMPLETED_WITH_ERRORS : IaForecastBatchRun::STATUS_FAILED)
                : IaForecastBatchRun::STATUS_COMPLETED);

        $run->update([
            'status' => $status,
            'procesados' => $processed,
            'completados' => $completed,
            'errores' => $errors,
            'pendientes' => $pending + $processing,
            'started_at' => $run->started_at ?? now(),
            'finished_at' => $pending === 0 && $processing === 0 ? now() : null,
        ]);
    }

    public function broadcastUpdate(int $runId): void
    {
        $run = IaForecastBatchRun::query()->find($runId);
        if (!$run) {
            return;
        }

        event(new IaForecastBatchProcessingUpdated([
            'run_id' => (int) $run->id,
            'status' => $run->status,
            'mode' => $run->mode,
        ]));
    }
}
