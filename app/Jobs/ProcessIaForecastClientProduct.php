<?php

namespace App\Jobs;

use App\Models\IaForecastClientRun;
use App\Models\IaForecastClientRunItem;
use App\Services\IaAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessIaForecastClientProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(
        public int $runId,
        public int $itemId
    ) {
        $this->onQueue('ia-forecast');
    }

    public function handle(IaAnalysisService $analysisService): void
    {
        $item = null;

        try {
            $item = DB::transaction(function () {
                $run = IaForecastClientRun::query()->lockForUpdate()->find($this->runId);
                $item = IaForecastClientRunItem::query()
                    ->where('run_id', $this->runId)
                    ->lockForUpdate()
                    ->find($this->itemId);

                if (
                    !$run ||
                    !$item ||
                    !in_array($run->status, [
                        IaForecastClientRun::STATUS_QUEUED,
                        IaForecastClientRun::STATUS_PROCESSING,
                    ], true)
                ) {
                    return null;
                }

                if (!in_array($item->status, [IaForecastClientRunItem::STATUS_PENDING, IaForecastClientRunItem::STATUS_ERROR], true)) {
                    return null;
                }

                $run->update([
                    'status' => IaForecastClientRun::STATUS_PROCESSING,
                    'started_at' => $run->started_at ?? now(),
                    'error_message' => null,
                ]);

                $item->update([
                    'status' => IaForecastClientRunItem::STATUS_PROCESSING,
                    'attempts' => (int) $item->attempts + 1,
                    'started_at' => now(),
                    'finished_at' => null,
                    'error_message' => null,
                ]);

                return $item->fresh();
            });

            if (!$item) {
                return;
            }

            $analysisService->analyze((int) $item->cliente_id, (int) $item->producto_id);

            IaForecastClientRunItem::query()
                ->whereKey($item->id)
                ->update([
                    'status' => IaForecastClientRunItem::STATUS_COMPLETED,
                    'finished_at' => now(),
                    'analizado_en' => now(),
                    'error_message' => null,
                ]);
        } catch (\Throwable $e) {
            Log::error('IA client run item failed', [
                'run_id' => $this->runId,
                'item_id' => $this->itemId,
                'message' => $e->getMessage(),
            ]);

            IaForecastClientRunItem::query()
                ->whereKey($this->itemId)
                ->update([
                    'status' => IaForecastClientRunItem::STATUS_ERROR,
                    'finished_at' => now(),
                    'error_message' => mb_substr($e->getMessage(), 0, 1000),
                ]);
        }

        $this->refreshRunState();
        $this->dispatchNextItem();
    }

    private function refreshRunState(): void
    {
        $run = IaForecastClientRun::query()->find($this->runId);
        if (!$run) {
            return;
        }

        $items = IaForecastClientRunItem::query()
            ->selectRaw('status, COUNT(*) as total')
            ->where('run_id', $run->id)
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending = (int) ($items[IaForecastClientRunItem::STATUS_PENDING] ?? 0);
        $processing = (int) ($items[IaForecastClientRunItem::STATUS_PROCESSING] ?? 0);
        $completed = (int) ($items[IaForecastClientRunItem::STATUS_COMPLETED] ?? 0);
        $errors = (int) ($items[IaForecastClientRunItem::STATUS_ERROR] ?? 0);
        $processed = $completed + $errors;

        $status = $processing > 0 || $pending > 0
            ? IaForecastClientRun::STATUS_PROCESSING
            : ($errors > 0
                ? ($completed > 0 ? IaForecastClientRun::STATUS_COMPLETED_WITH_ERRORS : IaForecastClientRun::STATUS_FAILED)
                : IaForecastClientRun::STATUS_COMPLETED);

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

    private function dispatchNextItem(): void
    {
        $run = IaForecastClientRun::query()->find($this->runId);
        if (!$run || !$run->isActive()) {
            return;
        }

        $nextItem = IaForecastClientRunItem::query()
            ->where('run_id', $run->id)
            ->where('status', IaForecastClientRunItem::STATUS_PENDING)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if (!$nextItem) {
            $this->refreshRunState();
            return;
        }

        self::dispatch($run->id, $nextItem->id)->onQueue('ia-forecast');
    }
}
