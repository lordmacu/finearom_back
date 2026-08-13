<?php

namespace App\Http\Middleware;

use App\Models\CourierWebhookLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Coordinadora empuja sus notificaciones sin ningún método de autenticación
 * propio (así lo dice su documentación, textual). Este middleware es la única
 * barrera de un endpoint que de otro modo quedaría abierto en internet.
 *
 * Orden de verificación:
 * 1. Token de ruta (segmento `{token}` de la URL) contra
 *    `config('custom.coordinadora_webhook_token')`, en tiempo constante.
 *    Si falla: 404 (no 401 — un 401 le confirma a quien está sondeando que
 *    la ruta existe).
 * 2. Lista blanca de IPs (`config('custom.coordinadora_ips')`). Vacía =
 *    filtro desactivado. Si falla: 403.
 *
 * Regla de oro: el token NUNCA se escribe en ningún log (ni el de Laravel ni
 * la bitácora `courier_webhook_logs`). No aparece en mensajes de excepción,
 * no aparece en `Log::`, no aparece en el `rejection_reason`.
 */
class VerifyCoordinadoraWebhook
{
    /**
     * Tope de bytes que se persiste como `payload` en un intento rechazado.
     *
     * El camino de rechazo por token inválido es la superficie más expuesta
     * del endpoint (no pasa por el filtro de IP), así que cualquiera puede
     * golpearlo repetidamente con cuerpos grandes. Sin este tope,
     * `courier_webhook_logs` crecería sin límite — un problema de
     * disponibilidad en un endpoint público sin autenticación. 2 KB alcanza
     * para conservar la forma del payload con fines de diagnóstico sin dejar
     * crecer la tabla sin control. No aplica a peticiones aceptadas: esas
     * necesitan el payload completo y las guarda la Tarea 5.
     */
    private const MAX_REJECTED_PAYLOAD_BYTES = 2048;

    /**
     * Ambiente (`prod`|`test`) según el segmento literal `/test/` de la URL,
     * NO según `APP_ENV`: las rutas de prueba de Coordinadora viven en el
     * mismo servidor de producción (no hay servidor de staging), distinguidas
     * solo por ese segmento:
     *
     *   .../{token}/tracking          → prod
     *   .../{token}/test/tracking     → test
     *
     * Posición **relativa al final**, no absoluta: el endpoint (`tracking`,
     * `novedades`, `soluciones`) es siempre el último segmento, y cuando hay
     * marcador de ambiente es el penúltimo. Un índice absoluto (p. ej.
     * "segmento 4") se rompe apenas cambia el prefijo de la ruta — ya pasó
     * una vez con el prefijo `api/` del `RouteServiceProvider` — y además ahí
     * caía justo sobre el token, no sobre 'test'.
     *
     * El penúltimo segmento puede ser el propio token (en la forma sin
     * `/test/`). Se compara contra la cadena `'test'` y se descarta: nunca se
     * devuelve, se registra ni se expone, solo se usa para decidir el
     * resultado `prod`/`test`.
     *
     * Público y estático a propósito: la Tarea 5 debe usar exactamente este
     * mismo criterio al guardar las filas aceptadas, para que la columna
     * `environment` sea comparable entre aceptadas y rechazadas.
     */
    public static function environmentFor(Request $request): string
    {
        return self::environmentForSegments($request->segments());
    }

    /**
     * @param  array<int, string>  $segments  Segmentos de la URL, en el mismo
     *         orden que devuelve `Request::segments()` (índice base 0).
     */
    private static function environmentForSegments(array $segments): string
    {
        $count = count($segments);

        // Ruta con menos segmentos de los esperados: no hay forma fiable de
        // ubicar el penúltimo. Cae a 'prod', el valor conservador — así
        // ninguna fila ambigua queda descartada como "ruido de pruebas".
        if ($count < 2) {
            return 'prod';
        }

        return $segments[$count - 2] === 'test' ? 'test' : 'prod';
    }

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = (string) config('custom.coordinadora_webhook_token', '');
        $providedToken   = (string) $request->route('token', '');

        // Fail-closed: sin token configurado no hay nada válido contra qué
        // comparar. No existe un modo "abierto" para este endpoint — el
        // proveedor no ofrece autenticación propia, así que un token vacío
        // en el entorno no puede tratarse como "cualquiera pasa". Se rechaza
        // todo hasta que alguien configure `COORDINADORA_WEBHOOK_TOKEN`.
        if ($configuredToken === '' || !hash_equals($configuredToken, $providedToken)) {
            $this->logRejected($request, 'token invalido');

            abort(404);
        }

        $allowedIps = config('custom.coordinadora_ips', []);

        if (!empty($allowedIps) && !in_array($request->ip(), $allowedIps, true)) {
            $this->logRejected($request, 'ip no autorizada');

            abort(403);
        }

        return $next($request);
    }

    /**
     * Registra el intento rechazado en la bitácora de auditoría, sin el
     * token en ningún campo. Si la escritura falla (p. ej. BD caída), no debe
     * tumbar la verificación de seguridad ni terminar en una excepción que
     * arrastre datos de la petición a los logs de Laravel: se traga el error
     * y deja constancia mínima, sin URL ni token, en el log de aplicación.
     */
    private function logRejected(Request $request, string $reason): void
    {
        try {
            $configuredToken = (string) config('custom.coordinadora_webhook_token', '');
            $providedToken   = (string) $request->route('token', '');

            $segments = $request->segments();
            $endpoint = (string) (end($segments) ?: '');

            // Defensa en profundidad: el patrón de ruta actual siempre trae un
            // segmento después del token (.../{token}/tracking), así que este
            // caso no debería darse. Pero si algún día una ruta terminara en
            // el token mismo, jamás debe quedar guardado en esta columna.
            if ($endpoint === '' || $endpoint === $providedToken || $endpoint === $configuredToken) {
                $endpoint = 'desconocido';
            }

            $payload = $this->truncatedPayload($request);

            CourierWebhookLog::create([
                'carrier'          => 'coordinadora',
                'endpoint'         => $endpoint,
                'environment'      => self::environmentForSegments($segments),
                'ip'               => (string) ($request->ip() ?? ''),
                'tracking_number'  => null,
                'payload'          => $payload,
                'accepted'         => false,
                'rejection_reason' => $reason,
                'processed_at'     => now(),
            ]);
        } catch (Throwable) {
            // Deliberadamente sin el mensaje de la excepción (podría traer
            // detalle de la fila que se intentó insertar) y sin datos de la
            // petición: solo una marca de que la auditoría falló.
            Log::warning('[Coordinadora] no se pudo registrar intento rechazado en la bitácora de webhooks');
        }
    }

    /**
     * Payload del intento rechazado, acotado a `MAX_REJECTED_PAYLOAD_BYTES`.
     * Mide sobre el JSON ya combinado (cuerpo + query string, lo mismo que
     * terminaría persistido) para que un query string inflado no esquive el
     * tope. Si excede el límite, no guarda el arreglo parseado sino un
     * resumen truncado que deja constancia explícita de que se recortó.
     */
    private function truncatedPayload(Request $request): array
    {
        $payload = $request->all();
        $encoded = json_encode($payload);
        $size    = $encoded === false ? PHP_INT_MAX : strlen($encoded);

        if ($size <= self::MAX_REJECTED_PAYLOAD_BYTES) {
            return $payload;
        }

        return [
            '_truncated'           => true,
            '_original_size_bytes' => $size,
            '_preview'             => substr((string) $encoded, 0, self::MAX_REJECTED_PAYLOAD_BYTES),
        ];
    }
}
