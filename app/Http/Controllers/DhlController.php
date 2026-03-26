<?php

namespace App\Http\Controllers;

use App\Services\DhlService;
use Illuminate\Http\JsonResponse;

class DhlController extends Controller
{
    public function __construct(
        private readonly DhlService $dhlService
    ) {}

    /**
     * Consulta el estado de un envío DHL por número de guía.
     * GET /api/dhl/track/{trackingNumber}
     */
    public function track(string $trackingNumber): JsonResponse
    {
        $trackingNumber = preg_replace('/\D/', '', $trackingNumber);

        if (strlen($trackingNumber) < 10) {
            return response()->json(['message' => 'Número de guía inválido'], 422);
        }

        $result = $this->dhlService->trackShipment($trackingNumber);

        if (!$result['success']) {
            return response()->json(['message' => $result['error']], 502);
        }

        return response()->json(['data' => $result['data']]);
    }
}
