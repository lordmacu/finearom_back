<?php

namespace App\Http\Controllers;

use App\Models\Process;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $emails = Process::where('process_type', $processType)
            ->pluck('email')
            ->toArray();

        return response()->json($emails);
    }
}
