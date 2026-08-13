<?php

namespace App\Services\Courier;

use App\Http\Middleware\VerifyCoordinadoraWebhook;
use App\Models\CourierWebhookLog;
use App\Models\ShipmentTracking;
use App\Models\ShipmentTrackingEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Procesa lo que Coordinadora empuja a `/webhooks/coordinadora/{token}/*`.
 *
 * El middleware `VerifyCoordinadoraWebhook` ya validó token e IP antes de
 * llegar aquí, así que el ORIGEN de la petición es confiable. El CONTENIDO
 * del payload no lo es: Coordinadora puede mandar JSON mal formado, guías
 * ajenas o timestamps inválidos, y el endpoint es público.
 *
 * Contrato de respuesta (ver task-5-brief.md):
 *   200 → el intento quedó registrado, haya o no novedad de negocio. Incluye
 *         el caso "la guía no es nuestra": no queremos que Coordinadora
 *         reintente indefinidamente algo que nunca va a coincidir.
 *   400 → el payload en sí está mal formado: no se pudo decodificar, no
 *         trae número de guía, o fecha/hora no arman un timestamp válido.
 *   500 → falló el servicio de verdad (excepción no prevista). El payload
 *         ya quedó en la bitácora antes de este punto, así que se puede
 *         reprocesar manualmente.
 *
 * Las rutas `/test/*` (mismo `$endpoint`, ambiente `test` derivado de la
 * URL) solo registran el intento: nunca tocan `shipment_trackings` ni
 * `shipment_tracking_events`.
 */
class CoordinadoraInboundService
{
    public function __construct(
        private readonly CoordinadoraPayloadParser $parser
    ) {}

    /**
     * @return array{status:int, body:array}
     */
    public function handle(string $endpoint, Request $request): array
    {
        $environment = VerifyCoordinadoraWebhook::environmentFor($request);
        $rawBody     = $request->all();
        $ip          = (string) ($request->ip() ?? '');

        // Requisito 1: la bitácora se guarda SIEMPRE, antes de procesar,
        // pase lo que pase después. Si esto mismo falla no hay nada que
        // reprocesar: es un fallo real del servicio → 500.
        try {
            $log = CourierWebhookLog::create([
                'carrier'          => 'coordinadora',
                'endpoint'         => $endpoint,
                'environment'      => $environment,
                'ip'               => $ip,
                'tracking_number'  => null,
                'payload'          => $rawBody,
                'accepted'         => false,
                'rejection_reason' => null,
                'processed_at'     => now(),
            ]);
        } catch (Throwable $e) {
            Log::error("[Coordinadora] no se pudo guardar la bitácora del webhook de {$endpoint}: {$e->getMessage()}");

            return $this->response(500, 'error interno');
        }

        // Requisito 2: las rutas test solo registran.
        if ($environment === 'test') {
            $log->update(['accepted' => true]);

            return $this->response(200, 'ok');
        }

        try {
            return $this->processProduction($endpoint, $rawBody, $log);
        } catch (Throwable $e) {
            Log::error("[Coordinadora] fallo procesando webhook de {$endpoint}: {$e->getMessage()}");

            $log->update(['rejection_reason' => 'error interno del servicio']);

            return $this->response(500, 'error interno');
        }
    }

    /**
     * @param  array<string, mixed>  $rawBody
     * @return array{status:int, body:array}
     */
    private function processProduction(string $endpoint, array $rawBody, CourierWebhookLog $log): array
    {
        // El endpoint de tracking llega envuelto en Pub/Sub; novedades y
        // soluciones son JSON plano ("NyS", ver CoordinadoraPayloadParser).
        $payload = $endpoint === 'tracking'
            ? $this->parser->decodeTrackingEnvelope($rawBody)
            : $rawBody;

        if ($payload === null) {
            return $this->reject($log, 400, 'no se pudo decodificar la envoltura del payload');
        }

        $trackingNumber = $this->parser->extractTrackingNumber($endpoint, $payload);

        if ($trackingNumber === null) {
            return $this->reject($log, 400, 'el payload no trae número de guía');
        }

        // Se deja constancia de la guía en la bitácora aunque el intento
        // termine rechazado más adelante (timestamp inválido, guía ajena).
        $log->tracking_number = $trackingNumber;

        try {
            $event = $this->parser->toEvent($payload);
        } catch (InvalidArgumentException) {
            return $this->reject($log, 400, 'fecha/hora del evento no arman un timestamp válido');
        }

        // El endpoint de novedades no tiene estados propios y soluciones
        // comparte el mismo formato "NyS" (ver doc de mapNovedadStatus): no
        // hay un mapeador propio para soluciones, así que usa el mismo.
        $status = $endpoint === 'tracking'
            ? $this->parser->mapTrackingStatus($payload)
            : $this->parser->mapNovedadStatus($payload);

        // La guía debe existir en un parcial vivo de Coordinadora. `type =
        // 'real'` porque un parcial 'temporal' no es un despacho real: así
        // es como ShipmentDiscoveryService decide qué pares (orden, guía)
        // son rastreables, y este flujo debe dejar los datos en el mismo
        // estado que dejaría ese flujo.
        $partialOrders = DB::table('partials')
            ->whereNull('deleted_at')
            ->where('type', 'real')
            ->whereRaw('TRIM(tracking_number) = ?', [$trackingNumber])
            ->whereRaw("LOWER(TRIM(transporter)) = 'coordinadora'")
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(quantity) as total_kg, COUNT(*) as partials_count, MIN(dispatch_date) as dispatch_date')
            ->get();

        if ($partialOrders->isEmpty()) {
            // No es un 400: la guía puede ser de otro cliente de
            // Coordinadora. Un 200 evita que el proveedor reintente
            // indefinidamente algo que nunca va a coincidir.
            return $this->reject($log, 200, 'la guía no corresponde a ningún parcial vivo de coordinadora');
        }

        // Una guía puede pertenecer a varias órdenes (218 casos reales): el
        // evento se aplica a TODAS las filas de shipment_trackings de esa
        // guía, no solo a la primera que aparezca.
        DB::transaction(function () use ($partialOrders, $trackingNumber, $status, $event) {
            foreach ($partialOrders as $partialRow) {
                $this->applyEvent($partialRow, $trackingNumber, $status, $event);
            }
        });

        $log->update(['accepted' => true, 'tracking_number' => $trackingNumber]);

        return $this->response(200, 'ok');
    }

    /** Ubica o crea la fila de shipment_trackings de (orden, guía) y aplica el evento. */
    private function applyEvent(object $partialRow, string $trackingNumber, string $status, CourierEvent $event): void
    {
        $tracking = ShipmentTracking::firstOrNew([
            'purchase_order_id' => $partialRow->order_id,
            'tracking_number'   => $trackingNumber,
        ]);

        // Igual que ShipmentDiscoveryService: estos datos se recalculan
        // siempre, existiera ya la fila o no.
        $tracking->carrier        = 'coordinadora';
        $tracking->total_kg       = $partialRow->total_kg;
        $tracking->partials_count = $partialRow->partials_count;
        $tracking->dispatch_date  = $partialRow->dispatch_date;

        // Igual que ShipmentTrackingSyncService::syncOne(): status, último
        // evento, checked_at y el cierre por estado final.
        $tracking->status                 = $status;
        $tracking->last_event_at          = $event->occurredAt;
        $tracking->last_event_code        = $event->code;
        $tracking->last_event_description = $event->description;
        $tracking->last_event_location    = $event->location;
        $tracking->checked_at             = now();
        $tracking->is_final               = CourierStatus::isFinal($status);

        $tracking->save();

        // firstOrCreate respeta el índice único (shipment_tracking_id,
        // occurred_at, code): si Coordinadora reenvía el mismo evento no lo
        // duplica.
        ShipmentTrackingEvent::firstOrCreate(
            [
                'shipment_tracking_id' => $tracking->id,
                'occurred_at'          => $event->occurredAt,
                'code'                 => $event->code,
            ],
            [
                'description' => $event->description,
                'location'    => $event->location,
                'raw'         => $event->raw,
            ]
        );
    }

    /**
     * @return array{status:int, body:array}
     */
    private function reject(CourierWebhookLog $log, int $status, string $reason): array
    {
        $log->update([
            'accepted'         => false,
            'rejection_reason' => $reason,
        ]);

        // En el 200 "guía ajena" no se expone la razón interna en la
        // respuesta: para Coordinadora se ve como un acuse normal.
        return $this->response($status, $status === 200 ? 'ok' : $reason);
    }

    /**
     * @return array{status:int, body:array}
     */
    private function response(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['message' => $message]];
    }
}
