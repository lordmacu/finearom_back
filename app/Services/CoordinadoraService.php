<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CoordinadoraService
{
    private string $baseUrl;
    private string $authUrl;
    private string $clientId;
    private string $clientSecret;
    private ?string $cachedToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct()
    {
        $this->baseUrl      = rtrim(config('custom.coordinadora_base_url', 'https://api.coordinadora.tech'), '/');
        $this->authUrl      = rtrim(config('custom.coordinadora_auth_url', 'https://api.coordinadora.tech'), '/');
        $this->clientId     = config('custom.coordinadora_client_id', '');
        $this->clientSecret = config('custom.coordinadora_client_secret', '');
    }

    /**
     * Obtiene un token OAuth2 client_credentials de Coordinadora.
     * Cachea el token hasta que expire (1 hora).
     */
    private function getToken(): ?string
    {
        if ($this->cachedToken && $this->tokenExpiresAt > time()) {
            return $this->cachedToken;
        }

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->timeout(15)
                ->post("{$this->authUrl}/oauth/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if (!$response->successful()) {
                Log::error('[Coordinadora] Error obteniendo token: ' . $response->body());
                return null;
            }

            $data = $response->json();
            $this->cachedToken   = $data['access_token'] ?? null;
            $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 3500) - 60;

            return $this->cachedToken;
        } catch (\Throwable $e) {
            Log::error('[Coordinadora] Exception obteniendo token: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Consulta el tracking de un envío Coordinadora por número de guía.
     *
     * Si no hay endpoint de API disponible, usa la URL pública de rastreo web.
     * Cuando Coordinadora proporcione el endpoint de tracking, actualizar este método.
     */
    public function trackShipment(string $trackingNumber): array
    {
        try {
            $token = $this->getToken();

            if (!$token) {
                return $this->trackViaPublicUrl($trackingNumber);
            }

            // TODO: Actualizar cuando Coordinadora proporcione el endpoint de tracking
            // Ejemplo esperado:
            // $response = Http::withToken($token)
            //     ->timeout(15)
            //     ->get("{$this->baseUrl}/guias/{$trackingNumber}/seguimiento");
            // if ($response->successful()) {
            //     return ['success' => true, 'data' => $response->json()];
            // }

            return $this->trackViaPublicUrl($trackingNumber);
        } catch (\Throwable $e) {
            Log::error('[Coordinadora] Exception tracking ' . $trackingNumber . ': ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fallback: devuelve la URL pública de rastreo en la web de Coordinadora.
     * Las guías Coordinadora tienen 11 dígitos.
     */
    private function trackViaPublicUrl(string $trackingNumber): array
    {
        $url = "https://www.coordinadora.com/rastreo/?guia=" . $trackingNumber;

        return [
            'success'   => false,
            'not_found' => false,
            'public_url' => $url,
            'message'   => 'El rastreo por API aún no está disponible. Podés consultar la guía en: ' . $url,
        ];
    }

    /**
     * Formatea la respuesta de tracking en texto legible para inyectar al chat IA.
     */
    public function formatForChat(array $responseData): string
    {
        // Si es la respuesta de fallback (solo tiene URL pública)
        if (!empty($responseData['public_url'])) {
            return 'El seguimiento de Coordinadora por API aún no está disponible. Consultá la guía en: ' . $responseData['public_url'];
        }

        // Formato cuando el endpoint de API esté disponible
        $guia = $responseData['guia'] ?? $responseData['tracking_number'] ?? 'N/A';
        $lines = ["Guía Coordinadora: {$guia}"];

        $estado = $responseData['estado'] ?? $responseData['status'] ?? null;
        if ($estado) {
            $lines[] = "Estado: {$estado}";
        }

        if (!empty($responseData['fecha_entrega'])) {
            $lines[] = "Fecha de entrega: {$responseData['fecha_entrega']}";
        }

        if (!empty($responseData['eventos'])) {
            $lines[] = '';
            $lines[] = 'Historial de eventos:';
            foreach ($responseData['eventos'] as $evento) {
                $fecha = $evento['fecha'] ?? $evento['timestamp'] ?? '';
                $desc  = $evento['descripcion'] ?? $evento['description'] ?? '';
                $lines[] = "• [{$fecha}] {$desc}";
            }
        }

        return implode("\n", $lines);
    }
}
