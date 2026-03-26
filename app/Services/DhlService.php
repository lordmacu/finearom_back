<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DhlService
{
    private string $baseUrl;
    private string $username;
    private string $password;

    public function __construct()
    {
        $this->baseUrl  = config('custom.dhl_base_url', 'https://express.api.dhl.com/mydhlapi');
        $this->username = config('custom.dhl_username', '');
        $this->password = config('custom.dhl_password', '');
    }

    /**
     * Consulta el tracking de un envío DHL por número de guía (waybill).
     * Retorna ['success' => true, 'data' => [...]] o ['success' => false, 'error' => '...'].
     */
    public function trackShipment(string $trackingNumber): array
    {
        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->timeout(15)
                ->get("{$this->baseUrl}/shipments/{$trackingNumber}/tracking", [
                    'trackingView' => 'all-checkpoints',
                    'levelOfDetail' => 'all',
                ]);

            if ($response->successful()) {
                $data = $response->json();

                // El endpoint de prueba de DHL devuelve un esquema de documentación
                // (no JSON válido) en lugar de datos reales. Si json() devuelve null,
                // la cuenta aún no tiene acceso a producción → informar al usuario.
                if ($data === null) {
                    Log::warning('[DHL] Respuesta no es JSON válido para ' . $trackingNumber . '. ¿Usando endpoint de prueba con mock?');
                    return [
                        'success' => false,
                        'error'   => 'La cuenta DHL está en modo de prueba. Para tracking real, contactar a DHL para activar el acceso a producción.',
                    ];
                }

                return ['success' => true, 'data' => $data];
            }

            Log::warning('[DHL] Tracking error for ' . $trackingNumber . ' — HTTP ' . $response->status() . ': ' . $response->body());

            return [
                'success' => false,
                'error'   => 'DHL respondió con código ' . $response->status(),
                'body'    => $response->body(),
            ];
        } catch (\Throwable $e) {
            Log::error('[DHL] Exception tracking ' . $trackingNumber . ': ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Formatea la respuesta de tracking en texto legible para inyectar al chat IA.
     */
    public function formatForChat(array $responseData): string
    {
        $shipments = $responseData['shipments'] ?? [];
        if (empty($shipments)) {
            return 'No se encontraron datos de seguimiento para esa guía.';
        }

        $lines = [];

        foreach ($shipments as $shipment) {
            $id = $shipment['id'] ?? 'N/A';
            $lines[] = "Guía: {$id}";

            if (!empty($shipment['service'])) {
                $lines[] = "Servicio: {$shipment['service']}";
            }

            // Estado actual
            $status = $shipment['status'] ?? null;
            if ($status) {
                $statusCode = $status['status'] ?? '';
                $desc       = $status['description'] ?? '';
                $timestamp  = $status['timestamp'] ?? '';
                $locality   = $status['location']['address']['addressLocality'] ?? '';
                $country    = $status['location']['address']['countryCode'] ?? '';
                $lines[] = "Estado actual: {$statusCode} — {$desc}";
                $lines[] = "Última actualización: {$timestamp}";
                if ($locality) {
                    $lines[] = "Ubicación actual: {$locality}, {$country}";
                }
            }

            // Origen / destino
            if (isset($shipment['origin']['address'])) {
                $o = $shipment['origin']['address'];
                $city    = $o['addressLocality'] ?? '';
                $country = $o['countryCode'] ?? '';
                $lines[] = "Origen: {$city}, {$country}";
            }
            if (isset($shipment['destination']['address'])) {
                $d = $shipment['destination']['address'];
                $city    = $d['addressLocality'] ?? '';
                $country = $d['countryCode'] ?? '';
                $lines[] = "Destino: {$city}, {$country}";
            }

            // Peso
            if (isset($shipment['totalWeight']['value'])) {
                $w    = $shipment['totalWeight']['value'];
                $unit = $shipment['totalWeight']['unitOfMeasurement'] ?? '';
                $lines[] = "Peso total: {$w} {$unit}";
            }

            // Últimos 8 eventos (el más reciente primero en la API)
            if (!empty($shipment['events'])) {
                $lines[] = '';
                $lines[] = 'Historial de eventos (más recientes primero):';
                foreach (array_slice($shipment['events'], 0, 8) as $event) {
                    $time    = $event['timestamp'] ?? '';
                    $desc    = $event['description'] ?? '';
                    $city    = $event['location']['address']['addressLocality'] ?? '';
                    $country = $event['location']['address']['countryCode'] ?? '';
                    $loc     = $city ? " ({$city}, {$country})" : '';
                    $lines[] = "• [{$time}] {$desc}{$loc}";
                }
            }
        }

        return implode("\n", $lines);
    }
}
