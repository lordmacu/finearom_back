<?php

namespace App\Services\Courier\Drivers;

use App\Services\Courier\CourierDriver;
use App\Services\Courier\CourierEvent;
use App\Services\Courier\CourierResult;
use App\Services\Courier\CourierStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DHL Express — MyDHL API.
 *
 * OJO con el esquema: la API de producción devuelve `shipmentTrackingNumber` y
 * eventos con `date` + `time` + `typeCode`. NO devuelve el esquema unificado de
 * Shipment Tracking (`id`, `status.description`, `events[].timestamp`).
 */
class DhlDriver implements CourierDriver
{
    private const ENTREGADO = 'OK';
    private const DEVUELTO  = 'RT';

    /** Códigos ya observados en producción: no se loguean como desconocidos. */
    private const CODIGOS_CONOCIDOS = ['PU', 'PL', 'DF', 'RT', 'OK'];

    public function key(): string
    {
        return 'dhl';
    }

    public function matches(string $trackingNumber): bool
    {
        return (bool) preg_match('/^\d{10}$/', trim($trackingNumber));
    }

    public function isPushOnly(): bool
    {
        return false;
    }

    public function track(string $trackingNumber): CourierResult
    {
        $baseUrl  = rtrim((string) config('custom.dhl_base_url'), '/');
        $username = (string) config('custom.dhl_username');
        $password = (string) config('custom.dhl_password');

        try {
            $response = Http::withBasicAuth($username, $password)
                ->timeout(20)
                ->get("{$baseUrl}/shipments/{$trackingNumber}/tracking", [
                    'trackingView'  => 'all-checkpoints',
                    'levelOfDetail' => 'all',
                ]);

            if ($response->status() === 404) {
                return CourierResult::notFound();
            }

            if (!$response->successful()) {
                return CourierResult::error("DHL respondió HTTP {$response->status()}");
            }

            $json = $response->json();
            if (!is_array($json)) {
                return CourierResult::error('DHL devolvió una respuesta que no es JSON (¿endpoint de prueba?)');
            }

            return $this->parse($json);
        } catch (\Throwable $e) {
            Log::warning("[DHL] Error consultando {$trackingNumber}: " . $e->getMessage());
            return CourierResult::error($e->getMessage());
        }
    }

    /** Traduce el JSON de DHL a estado + eventos normalizados. */
    public function parse(array $json): CourierResult
    {
        $shipment = $json['shipments'][0] ?? null;
        if (!$shipment || empty($shipment['events'])) {
            return CourierResult::notFound();
        }

        $events = [];
        foreach ($shipment['events'] as $raw) {
            $fecha = trim(($raw['date'] ?? '') . ' ' . ($raw['time'] ?? '00:00:00'));
            if ($fecha === '') {
                continue;
            }

            $events[] = new CourierEvent(
                occurredAt:  $fecha,
                code:        $raw['typeCode'] ?? null,
                description: $raw['description'] ?? null,
                location:    $raw['serviceArea'][0]['description'] ?? null,
                raw:         $raw,
            );
        }

        if (empty($events)) {
            return CourierResult::notFound();
        }

        // DHL entrega los eventos del más viejo al más reciente: el estado es el último.
        $ultimo = $events[count($events) - 1];

        return CourierResult::found($this->mapStatus($ultimo->code), $events);
    }

    /** typeCode de DHL → estado normalizado de Finearom. */
    public function mapStatus(?string $typeCode): string
    {
        if ($typeCode === null || $typeCode === '') {
            return CourierStatus::EN_TRANSITO;
        }

        $codigo = strtoupper($typeCode);

        if ($codigo === self::ENTREGADO) {
            return CourierStatus::ENTREGADO;
        }

        if ($codigo === self::DEVUELTO) {
            return CourierStatus::DEVUELTO;
        }

        $excepciones = array_map('strtoupper', (array) config('custom.dhl_exception_codes', []));
        if (in_array($codigo, $excepciones, true)) {
            return CourierStatus::NOVEDAD;
        }

        // Códigos no vistos todavía: se tratan como tránsito, pero se dejan en el
        // log para poder ampliar el mapa con datos reales en vez de adivinando.
        if (!in_array($codigo, self::CODIGOS_CONOCIDOS, true)) {
            Log::info("[DHL] typeCode no mapeado: {$codigo}");
        }

        return CourierStatus::EN_TRANSITO;
    }
}
