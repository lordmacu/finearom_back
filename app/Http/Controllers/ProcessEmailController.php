<?php

namespace App\Http\Controllers;

use App\Models\Process;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProcessEmailController extends Controller
{
    /**
     * Obtener emails de proceso por tipo
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getEmailsByType(Request $request): JsonResponse
    {
        $processType = $request->query('process_type');

        if (!$processType) {
            return response()->json([]);
        }

        $cacheKey = "process.emails.{$processType}";
        $cacheTimestampKey = "{$cacheKey}.timestamp";

        // Verificar si el caché es válido comparando timestamps
        $cachedTimestamp = Cache::get($cacheTimestampKey);
        $lastModified = Cache::get('process.last_modified', 0);

        if ($cachedTimestamp && $cachedTimestamp < $lastModified) {
            // El caché está desactualizado, eliminarlo
            Cache::forget($cacheKey);
            Cache::forget($cacheTimestampKey);
        }

        // Caché permanente (solo se invalida manualmente en CRUD)
        $emails = Cache::rememberForever($cacheKey, function () use ($processType) {
            return Process::where('process_type', $processType)
                ->pluck('email')
                ->toArray();
        });

        // Guardar timestamp del caché si es la primera vez (permanente)
        if (!Cache::has($cacheTimestampKey)) {
            Cache::forever($cacheTimestampKey, now()->timestamp);
        }

        return response()->json($emails);
    }

    /**
     * Invalidar caché de emails de proceso
     * Este método debe ser llamado cuando se actualizan los procesos
     */
    public static function clearProcessEmailsCache(): void
    {
        Cache::forever('process.last_modified', now()->timestamp);
    }
}
