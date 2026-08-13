<?php

namespace App\Http\Controllers;

use App\Services\Courier\CoordinadoraInboundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recibe lo que Coordinadora empuja a `/webhooks/coordinadora/{token}/*`.
 *
 * El middleware `coordinadora.webhook` (VerifyCoordinadoraWebhook) ya
 * validó token e IP antes de que la petición llegue aquí. Las rutas
 * `/test/*` comparten el mismo método que las de producción: el ambiente
 * (`test`|`prod`) se deriva de la URL dentro del servicio, con el mismo
 * criterio que usa el middleware, para que ninguna fila quede clasificada
 * de forma distinta a como la clasificó el filtro de seguridad.
 *
 * Toda la lógica de procesamiento vive en CoordinadoraInboundService: este
 * controller solo traduce su resultado a una respuesta JSON tipada.
 */
class CoordinadoraWebhookController extends Controller
{
    public function __construct(
        private readonly CoordinadoraInboundService $service
    ) {}

    public function tracking(Request $request): JsonResponse
    {
        return $this->toJsonResponse($this->service->handle('tracking', $request));
    }

    public function novedades(Request $request): JsonResponse
    {
        return $this->toJsonResponse($this->service->handle('novedades', $request));
    }

    public function soluciones(Request $request): JsonResponse
    {
        return $this->toJsonResponse($this->service->handle('soluciones', $request));
    }

    /**
     * @param array{status:int, body:array} $result
     */
    private function toJsonResponse(array $result): JsonResponse
    {
        return response()->json($result['body'], $result['status']);
    }
}
