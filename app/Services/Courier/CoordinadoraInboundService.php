<?php

namespace App\Services\Courier;

use App\Http\Middleware\VerifyCoordinadoraWebhook;
use App\Models\CourierWebhookLog;
use App\Models\ShipmentTracking;
use App\Models\ShipmentTrackingEvent;
use App\Services\Courier\Drivers\CoordinadoraDriver;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
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
 * ajenas, guías con el formato roto de `partials.transporter` (207 filas
 * en producción tienen el texto literal `'null'`), timestamps inválidos o
 * eventos fuera de orden (Pub/Sub no garantiza orden ni deduplica reintentos).
 *
 * Contrato de respuesta (ver task-5-brief.md):
 *   200 → el intento quedó registrado, haya o no novedad de negocio. Incluye
 *         el caso "la guía no es nuestra" (formato inválido o sin parcial
 *         vivo): no queremos que Coordinadora reintente indefinidamente algo
 *         que nunca va a coincidir.
 *   400 → el payload en sí está mal formado: no se pudo decodificar, no
 *         trae número de guía, o — una vez confirmado que la guía SÍ es
 *         nuestra — fecha/hora no arman un timestamp válido.
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
    /**
     * Tope de bytes del payload persistido en la bitácora. El endpoint es
     * público, sin autenticación propia del proveedor, y admite hasta 60
     * peticiones/min: sin tope, un payload gigante o repetido haría crecer
     * `courier_webhook_logs` sin control. Mismo criterio que
     * VerifyCoordinadoraWebhook::MAX_REJECTED_PAYLOAD_BYTES, algo más
     * generoso porque este camino sí necesita poder reprocesar el envío.
     */
    private const MAX_PAYLOAD_BYTES = 8192;

    public function __construct(
        private readonly CoordinadoraPayloadParser $parser,
        private readonly CoordinadoraDriver $coordinadoraDriver
    ) {}

    /**
     * @return array{status:int, body:array}
     */
    public function handle(string $endpoint, Request $request): array
    {
        $environment = VerifyCoordinadoraWebhook::environmentFor($request);
        $ip          = (string) ($request->ip() ?? '');

        // Cuerpo crudo, no $request->all(): con JSON mal formado o un
        // Content-Type que Laravel no reconoce como JSON, all() queda en
        // blanco — justo el caso que interesa poder reprocesar desde la
        // bitácora.
        $rawContent = (string) $request->getContent();
        $decodedRaw = json_decode($rawContent, true);
        $decodeOk   = json_last_error() === JSON_ERROR_NONE && is_array($decodedRaw);
        $body       = $decodeOk ? $decodedRaw : [];

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
                'payload'          => $this->boundedPayload($rawContent, $body, $decodeOk),
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
            $this->safeUpdateLog($log, ['accepted' => true]);

            return $this->response(200, 'ok');
        }

        try {
            return $this->processProduction($endpoint, $body, $log);
        } catch (Throwable $e) {
            Log::error("[Coordinadora] fallo procesando webhook de {$endpoint}: {$e->getMessage()}");

            $this->safeUpdateLog($log, ['rejection_reason' => 'error interno del servicio']);

            return $this->response(500, 'error interno');
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status:int, body:array}
     */
    private function processProduction(string $endpoint, array $body, CourierWebhookLog $log): array
    {
        // El endpoint de tracking llega envuelto en Pub/Sub; novedades y
        // soluciones son JSON plano ("NyS", ver CoordinadoraPayloadParser).
        $payload = $endpoint === 'tracking'
            ? $this->parser->decodeTrackingEnvelope($body)
            : $body;

        if ($payload === null) {
            return $this->reject($log, 400, 'no se pudo decodificar la envoltura del payload');
        }

        $trackingNumber = $this->parser->extractTrackingNumber($endpoint, $payload);

        if ($trackingNumber === null) {
            return $this->reject($log, 400, 'el payload no trae número de guía');
        }

        // Se deja constancia de la guía en la bitácora aunque el intento
        // termine rechazado más adelante.
        $log->tracking_number = $trackingNumber;

        // CRÍTICO — antes de tocar la base de datos: excluye vacíos, el
        // texto literal 'null' (207 parciales en producción lo tienen en
        // `tracking_number`) y cualquier guía que no tenga el formato de
        // Coordinadora (11 dígitos, CoordinadoraDriver::matches()). Sin
        // este filtro, un payload con "tracking_number": "null" cruzaría
        // con todos esos parciales sucios y tocaría órdenes ajenas.
        if (!$this->coordinadoraDriver->matches($trackingNumber)) {
            return $this->reject($log, 200, 'la guía no tiene el formato de coordinadora (11 dígitos)');
        }

        // La guía debe existir en un parcial vivo de Coordinadora. `type =
        // 'real'` porque un parcial 'temporal' no es un despacho real: así
        // es como ShipmentDiscoveryService decide qué pares (orden, guía)
        // son rastreables, y este flujo debe dejar los datos en el mismo
        // estado que dejaría ese flujo.
        //
        // La columna `tracking_number` va DESNUDA (sin envolver en TRIM) a
        // propósito: es un endpoint público sin autenticación propia que
        // admite hasta 60 peticiones/min, y envolver la columna en una
        // función anula cualquier índice sobre ella. El valor entrante ya
        // llega normalizado (trim) desde el parser; ver migración
        // add_tracking_number_index_to_partials_table.
        $partialOrders = DB::table('partials')
            ->whereNull('deleted_at')
            ->where('type', 'real')
            ->where('tracking_number', $trackingNumber)
            ->whereRaw("LOWER(TRIM(transporter)) = 'coordinadora'")
            ->groupBy('order_id')
            // orderBy: applyEvent() bloquea una fila por orden (lockForUpdate).
            // Un orden consistente evita deadlocks entre dos webhooks
            // concurrentes de la misma guía que, sin esto, podrían bloquear
            // las mismas órdenes en sentido contrario.
            ->orderBy('order_id')
            ->selectRaw('order_id, SUM(quantity) as total_kg, COUNT(*) as partials_count, MIN(dispatch_date) as dispatch_date')
            ->get();

        if ($partialOrders->isEmpty()) {
            // No es un 400: la guía puede ser de otro cliente de
            // Coordinadora. Un 200 evita que el proveedor reintente
            // indefinidamente algo que nunca va a coincidir.
            return $this->reject($log, 200, 'la guía no corresponde a ningún parcial vivo de coordinadora');
        }

        // El timestamp SOLO se valida después de confirmar que la guía es
        // nuestra: si se valida antes, una guía ajena con un timestamp raro
        // recibiría 400 y el proveedor reintentaría sin parar — justo lo
        // que el 200 de arriba existe para evitar.
        try {
            $event = $endpoint === 'tracking'
                ? $this->parser->toEvent($payload)
                : $this->buildNysEvent($endpoint, $payload);
        } catch (InvalidArgumentException) {
            return $this->reject($log, 400, 'fecha/hora del evento no arman un timestamp válido');
        }

        // Una guía puede pertenecer a varias órdenes (218 casos reales): el
        // evento se aplica a TODAS las filas de shipment_trackings de esa
        // guía, no solo a la primera que aparezca.
        DB::transaction(function () use ($partialOrders, $trackingNumber, $endpoint, $payload, $event) {
            foreach ($partialOrders as $partialRow) {
                $this->applyEvent($partialRow, $trackingNumber, $endpoint, $payload, $event);
            }
        });

        $this->safeUpdateLog($log, ['accepted' => true, 'tracking_number' => $trackingNumber]);

        return $this->response(200, 'ok');
    }

    /**
     * Ubica o crea la fila de shipment_trackings de (orden, guía) y aplica
     * el evento, sin mover el estado hacia atrás si llega desordenado.
     *
     * @param array<string, mixed> $payload
     */
    private function applyEvent(object $partialRow, string $trackingNumber, string $endpoint, array $payload, CourierEvent $event): void
    {
        // Bloqueo de fila dentro de la transacción de processProduction():
        // dos pushes simultáneos de la misma guía no deben poder crear dos
        // filas ni pisarse la creación (firstOrNew + save por separado no
        // es atómico). Bajo REPEATABLE READ (por defecto en InnoDB/MySQL),
        // un SELECT ... FOR UPDATE que no encuentra fila toma un gap lock
        // que serializa a cualquier otra transacción que intente el mismo
        // insert hasta que esta confirme.
        $tracking = ShipmentTracking::query()
            ->where('purchase_order_id', $partialRow->order_id)
            ->where('tracking_number', $trackingNumber)
            ->lockForUpdate()
            ->first() ?? new ShipmentTracking([
                'purchase_order_id' => $partialRow->order_id,
                'tracking_number'   => $trackingNumber,
            ]);

        // Igual que ShipmentDiscoveryService: estos datos se recalculan
        // siempre, existiera ya la fila o no.
        $tracking->carrier        = 'coordinadora';
        $tracking->total_kg       = $partialRow->total_kg;
        $tracking->partials_count = $partialRow->partials_count;
        $tracking->dispatch_date  = $partialRow->dispatch_date;

        $currentStatus      = $tracking->status;
        $currentLastEventAt = $tracking->last_event_at?->format('Y-m-d H:i:s');

        $status = $this->resolveStatus($endpoint, $payload, $currentStatus);

        // Pub/Sub no garantiza orden ni deduplica reintentos: un evento
        // con timestamp más viejo que el último registrado no debe mover
        // el estado hacia atrás (p. ej. un reintento tardío que llega
        // después de "ENTREGADA" no puede degradar el status). El evento
        // igual se guarda en el historial más abajo, aunque llegue tarde.
        if ($currentLastEventAt === null || $event->occurredAt >= $currentLastEventAt) {
            $tracking->status                 = $status;
            $tracking->last_event_at          = $event->occurredAt;
            $tracking->last_event_code        = $event->code;
            $tracking->last_event_description = $event->description;
            $tracking->last_event_location    = $event->location;
            $tracking->is_final               = CourierStatus::isFinal($status);
        }

        $tracking->checked_at = now();

        $tracking->save();

        // firstOrCreate respeta el índice único (shipment_tracking_id,
        // occurred_at, code): si Coordinadora reenvía el mismo evento no lo
        // duplica. `code` nunca es null (ver buildNysEvent()), así que esta
        // garantía también aplica a novedades y soluciones.
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
     * Estado "natural" del evento según el endpoint, sin considerar todavía
     * el orden temporal (eso lo aplica applyEvent comparando last_event_at).
     *
     * @param array<string, mixed> $payload
     */
    private function resolveStatus(string $endpoint, array $payload, ?string $currentStatus): string
    {
        if ($endpoint === 'tracking') {
            return $this->parser->mapTrackingStatus($payload);
        }

        if ($endpoint === 'novedades') {
            return $this->parser->mapNovedadStatus($payload);
        }

        // soluciones: 'aprobacion' significa que la novedad quedó resuelta
        // y el envío continúa — NUNCA debe reabrir un despacho ya cerrado
        // (Coordinadora es push-only, fuera de pullKeys(): ningún job la va
        // a corregir después, así que un error acá queda mal para siempre).
        // 'rechazo' (o cualquier evento no reconocido) sí es una novedad
        // real, igual que mapNovedadStatus().
        $evento = self::campoTexto($payload, 'evento');

        if ($evento === 'aprobacion') {
            return ($currentStatus !== null && CourierStatus::isFinal($currentStatus))
                ? $currentStatus
                : CourierStatus::EN_TRANSITO;
        }

        return CourierStatus::NOVEDAD;
    }

    /**
     * Arma el CourierEvent de un payload NyS (novedades o soluciones), que
     * no comparte los nombres de campo de tracking: la marca de tiempo
     * viaja en un único campo `fecha_hora` (ISO 8601 con `Z`, no `fecha` +
     * `hora` por separado) y no hay `codigo`/`comment`.
     *
     * Reusa `toEvent()` del parser (no se modifica su interfaz) armando un
     * arreglo sintético con las claves que sí espera, para no duplicar su
     * validación de timestamp. Garantiza `code` no nulo: el índice único
     * `(shipment_tracking_id, occurred_at, code)` no atrapa duplicados
     * cuando `code` es NULL (MySQL no compara NULLs como iguales entre sí).
     *
     * @param array<string, mixed> $payload
     * @throws InvalidArgumentException si `fecha_hora` falta o no es una fecha válida.
     */
    private function buildNysEvent(string $endpoint, array $payload): CourierEvent
    {
        $fechaHora = self::campoTexto($payload, 'fecha_hora');

        if ($fechaHora === null) {
            throw new InvalidArgumentException('Coordinadora: el payload NyS no trae fecha_hora');
        }

        try {
            // fecha_hora llega en UTC ("Z"); el resto del sistema —
            // incluida la fecha/hora de tracking, que ya viene en hora
            // local— trabaja en la zona horaria de la aplicación.
            $momento = (new DateTimeImmutable($fechaHora))->setTimezone(new DateTimeZone(config('app.timezone')));
        } catch (Exception) {
            throw new InvalidArgumentException("Coordinadora: fecha_hora inválida: \"{$fechaHora}\"");
        }

        $codigo = self::campoTexto($payload, 'id_registro_novedad')
            ?? self::campoTexto($payload, 'id_novedad')
            ?? self::campoTexto($payload, 'id_solucion')
            // Sin ID propio en el payload: se cae a un código fijo por
            // endpoint para que el índice único siga deduplicando reenvíos.
            ?? "{$endpoint}-sin-id";

        $descripcion = self::campoTexto($payload, 'descripcion_novedad')
            ?? self::campoTexto($payload, 'descripcion_solucion')
            ?? self::campoTexto($payload, 'observacion_novedad')
            ?? self::campoTexto($payload, 'observacion_rechazo');

        $sintetico = array_merge($payload, [
            'fecha' => $momento->format('Y-m-d'),
            'hora'  => $momento->format('H:i:s'),
            // shipment_tracking_events.code y last_event_code son
            // VARCHAR(20); id_registro_novedad es un uuid (36 caracteres) y
            // no cabe entero. description es VARCHAR(255).
            'codigo'  => mb_substr($codigo, 0, 20),
            'comment' => $descripcion === null ? null : mb_substr($descripcion, 0, 255),
        ]);

        return $this->parser->toEvent($sintetico);
    }

    /**
     * @return array{status:int, body:array}
     */
    private function reject(CourierWebhookLog $log, int $status, string $reason): array
    {
        $this->safeUpdateLog($log, [
            'accepted'         => false,
            'rejection_reason' => $reason,
        ]);

        // En el 200 "guía ajena" no se expone la razón interna en la
        // respuesta: para Coordinadora se ve como un acuse normal.
        return $this->response($status, $status === 200 ? 'ok' : $reason);
    }

    /**
     * Actualiza la bitácora sin dejar que un fallo aquí (p. ej. BD caída a
     * mitad de la petición) escape como excepción no controlada al
     * proveedor. El registro ya quedó creado en handle(): en el peor caso
     * la fila conserva su estado inicial en vez del final.
     */
    private function safeUpdateLog(CourierWebhookLog $log, array $attributes): void
    {
        try {
            $log->update($attributes);
        } catch (Throwable $e) {
            Log::warning("[Coordinadora] no se pudo actualizar la bitácora del webhook: {$e->getMessage()}");
        }
    }

    /**
     * Payload a persistir en la bitácora, acotado a MAX_PAYLOAD_BYTES.
     * Si el cuerpo decodificó como JSON y cabe en el tope, se guarda ya
     * interpretado (más útil para depurar). En cualquier otro caso —JSON
     * inválido, Content-Type no reconocido, o payload demasiado grande— se
     * conserva el cuerpo crudo en Base64, que nunca falla por bytes que no
     * sean UTF-8 válido (a diferencia de meter el string crudo directo en
     * una columna JSON), para poder reprocesarlo.
     *
     * @param array<string, mixed> $decodedBody
     * @return array<string, mixed>
     */
    private function boundedPayload(string $rawContent, array $decodedBody, bool $decodeOk): array
    {
        if ($decodeOk) {
            $encoded = json_encode($decodedBody);

            if ($encoded !== false && strlen($encoded) <= self::MAX_PAYLOAD_BYTES) {
                return $decodedBody;
            }
        }

        return [
            '_raw_body_base64'     => substr(base64_encode($rawContent), 0, self::MAX_PAYLOAD_BYTES),
            '_original_size_bytes' => strlen($rawContent),
        ];
    }

    /**
     * @return array{status:int, body:array}
     */
    private function response(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['message' => $message]];
    }

    /**
     * Igual criterio defensivo que CoordinadoraPayloadParser::campoEscalar():
     * el payload no es de fiar (endpoint público sin autenticación propia),
     * así que cualquier valor no escalar se trata como ausente.
     *
     * @param array<string, mixed> $payload
     */
    private static function campoTexto(array $payload, string $clave): ?string
    {
        $valor = $payload[$clave] ?? null;

        if ($valor === null || !is_scalar($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor !== '' ? $valor : null;
    }
}
