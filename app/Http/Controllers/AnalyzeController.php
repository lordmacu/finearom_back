<?php

namespace App\Http\Controllers;

use App\Http\Requests\Analyze\AnalyzeClientPartialsRequest;
use App\Http\Requests\Analyze\AnalyzeClientsRequest;
use App\Http\Requests\Analyze\UpdatePartialRequest;
use App\Models\Partial;
use App\Queries\Analyze\AnalyzeQuery;
use App\Services\Trm\TrmDailyWarmup;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class AnalyzeController extends Controller
{
    public function __construct(
        private readonly AnalyzeQuery $analyzeQuery,
        private readonly TrmDailyWarmup $trmDailyWarmup
    )
    {
        $this->middleware('can:analysis view')->only(['clients', 'clientPartials']);
        $this->middleware('can:partial edit')->only(['updatePartial']);
        $this->middleware('can:partial delete')->only(['deletePartial']);
    }

    public function clients(AnalyzeClientsRequest $request): JsonResponse
    {
        [$from, $to] = $this->analyzeQuery->resolveDateRange($request->validated());
        [$status, $type] = $this->analyzeQuery->resolveStatusAndType($request->validated());

        $paginateRaw = $request->validated()['paginate'] ?? null;
        $paginateParsed = $paginateRaw === null
            ? null
            : filter_var($paginateRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $paginate = $paginateParsed ?? true;
        $perPage = (int) ($request->validated()['per_page'] ?? 10);
        $perPage = max(1, min(5000, $perPage));

        // Generar clave de caché única
        $cacheKey = $this->generateAnalyzeClientsCacheKey($from, $to, $type, $status, $paginate, $perPage, $request->query('page', 1));
        $cacheTimestampKey = "{$cacheKey}.timestamp";

        // Verificar si se forzó recarga del caché
        $cachedTimestamp = Cache::get($cacheTimestampKey);
        $forceReloadAfter = Cache::get('analyze.clients.force_reload_after', 0);

        if ($cachedTimestamp && $cachedTimestamp < $forceReloadAfter) {
            // El usuario forzó recarga, eliminar caché
            Cache::forget($cacheKey);
            Cache::forget($cacheTimestampKey);
        }

        // Caché de 4 horas (14400 segundos)
        $data = Cache::remember($cacheKey, 14400, function () use ($from, $to, $type, $status, $paginate, $perPage) {
            $this->trmDailyWarmup->warmForAnalyze($from, $to, $type, $status);

            $totals = $this->analyzeQuery->totals($from, $to, $type, $status);
            if ($paginate) {
                $clients = $this->analyzeQuery->paginateClients($from, $to, $type, $status, $perPage);

                return [
                    'success' => true,
                    'data' => $clients->items(),
                    'meta' => [
                        'current_page' => $clients->currentPage(),
                        'per_page' => $clients->perPage(),
                        'total' => $clients->total(),
                        'last_page' => $clients->lastPage(),
                        'from' => $from->toDateString(),
                        'to' => $to->toDateString(),
                        'paginate' => true,
                    ],
                    'totals' => [
                        'total_cop' => (float) ($totals->total_cop ?? 0),
                        'total_usd' => (float) ($totals->total_usd ?? 0),
                    ],
                ];
            }

            $clients = $this->analyzeQuery->allClients($from, $to, $type, $status, $perPage);

            return [
                'success' => true,
                'data' => $clients,
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => $clients->count(),
                    'last_page' => 1,
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'paginate' => false,
                ],
                'totals' => [
                    'total_cop' => (float) ($totals->total_cop ?? 0),
                    'total_usd' => (float) ($totals->total_usd ?? 0),
                ],
            ];
        });

        // Guardar timestamp del caché si es la primera vez
        if (!Cache::has($cacheTimestampKey)) {
            Cache::put($cacheTimestampKey, now()->timestamp, 14400);
        }

        return response()->json($data);
    }

    public function clientPartials(AnalyzeClientPartialsRequest $request, int $clientId): JsonResponse
    {
        [$from, $to] = $this->analyzeQuery->resolveDateRange($request->validated());
        [$status, $type] = $this->analyzeQuery->resolveStatusAndType($request->validated());

        $this->trmDailyWarmup->warmForAnalyze($from, $to, $type, $status, $clientId);
        $rows = $this->analyzeQuery->clientPartials($from, $to, $type, $status, $clientId);

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    public function updatePartial(UpdatePartialRequest $request, int $partialId): JsonResponse
    {
        $partial = Partial::query()->findOrFail($partialId);

        $data = $request->validated();
        if (! empty($data['dispatch_date'])) {
            $partial->dispatch_date = $data['dispatch_date'];
        }

        $partial->quantity = $data['quantity'];
        $partial->trm = $data['trm'];
        $partial->touch();
        $partial->save();

        // Invalidar caché de análisis
        $this->clearAllAnalyzeClientsCache();

        return response()->json([
            'success' => true,
            'message' => 'Parcial actualizado',
            'data' => [
                'id' => $partial->id,
                'quantity' => $partial->quantity,
                'trm' => $partial->trm,
                'dispatch_date' => $partial->dispatch_date,
                'updated_at' => $partial->updated_at,
            ],
        ]);
    }

    public function deletePartial(int $partialId): JsonResponse
    {
        $partial = Partial::query()->findOrFail($partialId);
        $partial->delete();

        // Invalidar caché de análisis
        $this->clearAllAnalyzeClientsCache();

        return response()->json([
            'success' => true,
            'message' => 'Parcial eliminado',
        ]);
    }

    /**
     * Forzar recarga del caché de análisis de clientes
     * Este endpoint limpia el caché para permitir una recarga fresca de los datos
     */
    public function clearAnalyzeCache(): JsonResponse
    {
        // Limpiar todas las claves de caché que empiecen con 'analyze.clients.'
        $this->clearAllAnalyzeClientsCache();

        return response()->json([
            'success' => true,
            'message' => 'Caché de análisis limpiado exitosamente',
        ]);
    }

    /**
     * Generar clave de caché única para el análisis de clientes
     */
    private function generateAnalyzeClientsCacheKey($from, $to, $type, $status, $paginate, $perPage, $page): string
    {
        $params = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'type' => $type,
            'status' => $status,
            'paginate' => $paginate,
            'per_page' => $perPage,
            'page' => $page,
        ];

        return 'analyze.clients.' . md5(json_encode($params));
    }

    /**
     * Limpiar todas las claves de caché relacionadas con análisis de clientes
     */
    private function clearAllAnalyzeClientsCache(): void
    {
        // Nota: Con file cache, no podemos borrar por patrón fácilmente.
        // Una alternativa es usar un timestamp de invalidación, pero como este
        // endpoint tiene expiración de 4 horas, simplemente forzamos flush
        // solo para las claves que conocemos o usamos Cache tags si migramos a Redis.

        // Por ahora, guardamos un timestamp de invalidación
        Cache::forever('analyze.clients.force_reload_after', now()->timestamp);
    }
}
