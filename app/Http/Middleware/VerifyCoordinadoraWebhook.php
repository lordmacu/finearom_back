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

            $payload = $request->all();

            CourierWebhookLog::create([
                'carrier'          => 'coordinadora',
                'endpoint'         => $endpoint,
                'environment'      => app()->isProduction() ? 'prod' : 'test',
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
}
