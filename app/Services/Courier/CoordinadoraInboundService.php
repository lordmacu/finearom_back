<?php

namespace App\Services\Courier;

use App\Http\Middleware\VerifyCoordinadoraWebhook;
use App\Models\CourierWebhookLog;
use App\Models\ShipmentTracking;
use App\Models\ShipmentTrackingEvent;
use App\Services\Courier\Drivers\CoordinadoraDriver;
use DateTimeImmutable;
use DateTimeZone;
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
     * Tope de bytes del payload persistido en la bitácora (y, reutilizado en
     * boundedEventRaw(), del `raw` por evento). El endpoint es público, sin
     * autenticación propia del proveedor, y admite hasta 600 peticiones/min
     * (ver throttle:600,1 en routes/api.php): sin tope, un payload gigante o
     * repetido haría crecer `courier_webhook_logs` y
     * `shipment_tracking_events` sin control. Mismo criterio que
     * VerifyCoordinadoraWebhook::MAX_REJECTED_PAYLOAD_BYTES, algo más
     * generoso porque este camino sí necesita poder reprocesar el envío.
     */
    private const MAX_PAYLOAD_BYTES = 8192;

    /**
     * Formatos ISO 8601 estrictos aceptados para `fecha_hora` (NyS), con y
     * sin milisegundos. `\Z` es un literal (no un especificador de zona):
     * por eso `parseFechaHoraEstricta()` pasa UTC explícito como zona del
     * DateTimeImmutable resultante.
     */
    private const NYS_TIMESTAMP_FORMATS = [
        'Y-m-d\TH:i:s\Z',
        'Y-m-d\TH:i:s.v\Z',
    ];

    /**
     * Margen de desfase de reloj aceptado para el timestamp de un evento
     * (segundos). Sin este tope, un solo evento fechado en el futuro
     * congelaría la guía para siempre: el guard de orden de applyEvent()
     * trataría cualquier evento real posterior como "más viejo" y lo
     * descartaría — un ataque de un solo mensaje en un endpoint público sin
     * autenticación propia. Minutos, no días: es margen de reloj, no una
     * ventana para timestamps genuinamente futuros.
     */
    private const MAX_FUTURE_SKEW_SECONDS = 300;

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

        // Cuerpo para PROCESAR: el JSON crudo si decodificó bien; si no
        // —p. ej. un Content-Type distinto a JSON, como
        // x-www-form-urlencoded, que Laravel sí sabe interpretar— se cae a
        // la entrada ya interpretada por el framework. La documentación de
        // Coordinadora habla de JSON, así que esto es solo una red de
        // seguridad: NO cambia lo que se guarda en la bitácora (sigue
        // siendo el crudo acotado, ver boundedPayload() más abajo).
        $body = $decodeOk ? $decodedRaw : $request->all();

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
                // boundedPayload() recibe $decodedRaw (no $body): la
                // bitácora debe seguir guardando el cuerpo crudo, nunca la
                // entrada interpretada por el fallback de $request->all().
                'payload'          => $this->boundedPayload($rawContent, $decodeOk ? $decodedRaw : [], $decodeOk),
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
        // admite hasta 600 peticiones/min (ver throttle:600,1 en
        // routes/api.php), y envolver la columna en una función anula
        // cualquier índice sobre ella. El valor entrante ya
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

            // Aplica a los dos caminos (toEvent() de tracking, que ya
            // valida el FORMATO pero no qué tan lejos en el futuro cae, y
            // buildNysEvent() de NyS): sin este tope, un evento fechado en
            // el futuro congelaría la guía para siempre contra el guard de
            // orden de applyEvent().
            $this->assertNotUnreasonablyFuture($event->occurredAt);
        } catch (InvalidArgumentException) {
            return $this->reject($log, 400, 'fecha/hora del evento no arman un timestamp válido');
        }

        // Una guía puede pertenecer a varias órdenes (218 casos reales): el
        // evento se aplica a TODAS las filas de shipment_trackings de esa
        // guía, no solo a la primera que aparezca. Cada orden en su PROPIA
        // transacción: antes, una sola transacción envolvía el lote
        // completo, así que un fallo puntual en una orden (deadlock, fila
        // corrupta) revertía también las órdenes sanas y devolvía 500 para
        // todas. Aislar por orden evita que un problema puntual arrastre al
        // resto.
        $ordenesAplicadas = 0;

        foreach ($partialOrders as $partialRow) {
            try {
                DB::transaction(function () use ($partialRow, $trackingNumber, $endpoint, $payload, $event) {
                    $this->applyEvent($partialRow, $trackingNumber, $endpoint, $payload, $event);
                });
                $ordenesAplicadas++;
            } catch (Throwable $e) {
                Log::error("[Coordinadora] fallo aplicando el evento de la guía {$trackingNumber} "
                    . "a la orden {$partialRow->order_id}: {$e->getMessage()}");
            }
        }

        // Resultado global, de forma sensata: si al menos una orden recibió
        // el evento, se acepta (200) — las que fallaron quedaron en el log
        // de aplicación (arriba) para diagnosticar y reprocesar puntualmente,
        // sin bloquear el acuse al proveedor. Solo si TODAS fallaron es un
        // fallo real del servicio (500); el payload ya está en la bitácora
        // para reprocesar.
        if ($ordenesAplicadas === 0) {
            $this->safeUpdateLog($log, [
                'rejection_reason' => "fallo aplicando el evento a las {$partialOrders->count()} orden(es) de la guía",
            ]);

            return $this->response(500, 'error interno');
        }

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
        // Bloqueo de fila dentro de la transacción por-orden de
        // processProduction(): dos pushes simultáneos de la misma guía no
        // deben poder crear dos filas ni pisarse la creación (firstOrNew +
        // save por separado no es atómico). Bajo REPEATABLE READ (por defecto en InnoDB/MySQL),
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
                // Acotado igual que la bitácora (MAX_PAYLOAD_BYTES): este
                // mismo evento se guarda una vez POR CADA orden de la guía
                // (hasta 218 casos reales), así que un payload grande sin
                // tope se multiplica por cada una.
                'raw'         => $this->boundedEventRaw($event->raw),
            ]
        );
    }

    /**
     * Acota el `raw` de un evento a MAX_PAYLOAD_BYTES antes de guardarlo en
     * shipment_tracking_events. Mismo criterio que boundedPayload() para la
     * bitácora, pero aplicado por evento: sin esto, el `raw` completo de
     * NyS (que arrastra todo el payload original, incluido
     * `listado_soluciones[]`) se repite sin control multiplicado por cada
     * orden de la guía.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function boundedEventRaw(array $raw): array
    {
        $encoded = json_encode($raw);

        if ($encoded !== false && strlen($encoded) <= self::MAX_PAYLOAD_BYTES) {
            return $raw;
        }

        return [
            '_truncated'           => true,
            '_original_size_bytes' => $encoded === false ? null : strlen($encoded),
        ];
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
            // Mismo guard que soluciones, más abajo: el endpoint de
            // novedades siempre devuelve 'novedad' (mapNovedadStatus() no
            // distingue casos), así que sin este guard cualquier evento
            // posterior — p. ej. un "edicion reporte" con fecha después de
            // la entrega — reabre para siempre un despacho ya cerrado.
            // Coordinadora es push-only (fuera de pullKeys()): ningún job
            // lo corrige después.
            if ($currentStatus !== null && CourierStatus::isFinal($currentStatus)) {
                return $currentStatus;
            }

            return $this->parser->mapNovedadStatus($payload);
        }

        // soluciones: 'aprobacion' significa que la novedad quedó resuelta
        // y el envío continúa. El texto de Coordinadora no es de fiar
        // ("Aprobación", "APROBACION", con espacios alrededor...), así que
        // se compara normalizado con el mismo criterio que usa el parser
        // para comment/desc_estado, en vez de una comparación literal.
        $evento = self::campoTexto($payload, 'evento');
        $eventoNormalizado = $evento === null ? null : CoordinadoraPayloadParser::normalizar($evento);

        $estadoNatural = $eventoNormalizado === 'APROBACION'
            ? CourierStatus::EN_TRANSITO
            : CourierStatus::NOVEDAD; // 'rechazo' o cualquier evento no reconocido.

        // Ni 'aprobacion' ni 'rechazo' (ni un evento no reconocido) deben
        // reabrir un despacho ya cerrado: Coordinadora es push-only, fuera
        // de pullKeys(), así que ningún job lo va a corregir después — un
        // error acá queda mal para siempre.
        if ($currentStatus !== null && CourierStatus::isFinal($currentStatus)) {
            return $currentStatus;
        }

        return $estadoNatural;
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

        // Formato ESTRICTO, no el parseo libre de `new DateTimeImmutable()`:
        // ese constructor acepta cadenas relativas ("now", "+1 year") sin
        // que el payload tenga que parecerse a una fecha real. `fecha_hora`
        // llega en UTC ("Z"); el resto del sistema —incluida la fecha/hora
        // de tracking, que ya viene en hora local— trabaja en la zona
        // horaria de la aplicación, así que se convierte antes de usarla.
        $momento = self::parseFechaHoraEstricta($fechaHora);
        $momento = $momento->setTimezone(new DateTimeZone(config('app.timezone')));

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
     * `occurredAt` ya viene en hora local ('Y-m-d H:i:s', tanto de
     * toEvent() como de buildNysEvent()). Rechaza cualquier evento fechado
     * más adelante de lo que un desfase de reloj razonable explica — ver
     * MAX_FUTURE_SKEW_SECONDS.
     *
     * @throws InvalidArgumentException si el evento está en el futuro (más allá del margen) o el timestamp no es interpretable.
     */
    private function assertNotUnreasonablyFuture(string $occurredAt): void
    {
        $momento = \DateTime::createFromFormat('Y-m-d H:i:s', $occurredAt, new DateTimeZone(config('app.timezone')));

        if ($momento === false) {
            throw new InvalidArgumentException("Coordinadora: timestamp de evento no interpretable: \"{$occurredAt}\"");
        }

        if ($momento->getTimestamp() > time() + self::MAX_FUTURE_SKEW_SECONDS) {
            throw new InvalidArgumentException("Coordinadora: timestamp de evento está en el futuro: \"{$occurredAt}\"");
        }
    }

    /**
     * Parsea `fecha_hora` con un formato ISO 8601 fijo — nunca con el
     * constructor libre de DateTimeImmutable, que interpreta cadenas
     * relativas ("now", "+1 year") como si fueran fechas reales. Acepta
     * con o sin milisegundos.
     *
     * @throws InvalidArgumentException si no calza con ninguno de los dos formatos exactos.
     */
    private static function parseFechaHoraEstricta(string $fechaHora): DateTimeImmutable
    {
        foreach (self::NYS_TIMESTAMP_FORMATS as $formato) {
            $momento = DateTimeImmutable::createFromFormat($formato, $fechaHora, new DateTimeZone('UTC'));

            // Verificación de ida y vuelta: createFromFormat() es
            // permisivo con desbordes (p. ej. "2026-13-45" se acepta y se
            // normaliza); formatear de regreso y comparar contra el texto
            // original es lo que realmente garantiza el formato exacto.
            if ($momento !== false && $momento->format($formato) === $fechaHora) {
                return $momento;
            }
        }

        throw new InvalidArgumentException("Coordinadora: fecha_hora no tiene un formato ISO 8601 reconocido: \"{$fechaHora}\"");
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
