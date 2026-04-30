<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CoordinadoraService
{
    private string $authUrl;
    private string $guiasUrl;
    private string $clientId;
    private string $clientSecret;
    private ?string $cachedToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct()
    {
        $this->authUrl      = rtrim(config('custom.coordinadora_auth_url', 'https://api.coordinadora.tech'), '/');
        $this->guiasUrl     = rtrim(config('custom.coordinadora_guias_url', 'https://guias-service.coordinadora.com'), '/');
        $this->clientId     = config('custom.coordinadora_client_id', '');
        $this->clientSecret = config('custom.coordinadora_client_secret', '');
    }

    /**
     * Obtiene token OAuth2 client_credentials para la API de Coordinadora.
     * Cachea el token hasta que expire.
     */
    private function getToken(): ?string
    {
        if ($this->cachedToken && $this->tokenExpiresAt > time()) {
            return $this->cachedToken;
        }

        if (empty($this->clientId) || empty($this->clientSecret)) {
            Log::warning('[Coordinadora] Credenciales OAuth2 no configuradas');
            return null;
        }

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->timeout(15)
                ->post("{$this->authUrl}/oauth/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if (!$response->successful()) {
                Log::error('[Coordinadora] Error token: ' . $response->body());
                return null;
            }

            $data = $response->json();
            $this->cachedToken    = $data['access_token'] ?? null;
            $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 3500) - 60;

            return $this->cachedToken;
        } catch (\Throwable $e) {
            Log::error('[Coordinadora] Exception token: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Consulta el rastreo de una guía por número (11 dígitos).
     * GET /guias/{numeroGuia}
     */
    public function trackShipment(string $trackingNumber): array
    {
        try {
            $token = $this->getToken();

            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Credenciales de Coordinadora no configuradas. Agregar COORDINADORA_CLIENT_ID y COORDINADORA_CLIENT_SECRET al .env',
                ];
            }

            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(15)
                ->get("{$this->guiasUrl}/guias/{$trackingNumber}");

            if ($response->status() === 404) {
                return ['success' => false, 'not_found' => true, 'message' => 'Guía no encontrada'];
            }

            if ($response->status() === 401 || $response->status() === 403) {
                $this->cachedToken = null;
                return ['success' => false, 'message' => 'Token expirado o inválido. Reintentar.'];
            }

            if (!$response->successful()) {
                Log::error('[Coordinadora] Error rastreo ' . $trackingNumber . ' — HTTP ' . $response->status());
                return ['success' => false, 'message' => 'Error HTTP ' . $response->status()];
            }

            $data = $response->json();

            if (!$data || (is_array($data) && empty($data))) {
                return ['success' => false, 'not_found' => true, 'message' => 'Guía no encontrada'];
            }

            return ['success' => true, 'data' => $data];
        } catch (\Throwable $e) {
            Log::error('[Coordinadora] Exception rastreo ' . $trackingNumber . ': ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Formatea la respuesta de rastreo en texto legible para el chat IA.
     */
    public function formatForChat(array $responseData): string
    {
        $data = $responseData['data'] ?? [];

        if (empty($data)) {
            return 'No se encontraron datos de seguimiento para esa guía.';
        }

        $lines = [];
        $guia = $data['guia'] ?? $data['codigoRemision'] ?? $data['numeroGuia'] ?? $data['codigo_remision'] ?? 'N/A';
        $lines[] = "Guía Coordinadora: {$guia}";

        $estado = $data['estado'] ?? $data['descripcionEstado'] ?? $data['estadoGuia'] ?? null;
        if ($estado) {
            $lines[] = "Estado: {$estado}";
        }

        $fechaEntrega = $data['fechaEntrega'] ?? $data['fecha_entrega'] ?? null;
        if ($fechaEntrega) {
            $lines[] = "Fecha de entrega: {$fechaEntrega}";
        }

        $origen = $data['origen'] ?? $data['ciudadOrigen'] ?? $data['nombreOrigen'] ?? null;
        if ($origen) {
            $lines[] = "Origen: {$origen}";
        }

        $destino = $data['destino'] ?? $data['ciudadDestino'] ?? $data['nombreDestino'] ?? null;
        if ($destino) {
            $lines[] = "Destino: {$destino}";
        }

        $destinatario = $data['destinatario'] ?? $data['nombreDestinatario'] ?? null;
        if ($destinatario) {
            $lines[] = "Destinatario: {$destinatario}";
        }

        $unidades = $data['unidades'] ?? $data['totalUnidades'] ?? null;
        if ($unidades) {
            $lines[] = "Unidades: {$unidades}";
        }

        $peso = $data['peso'] ?? $data['pesoTotal'] ?? null;
        if ($peso) {
            $lines[] = "Peso: {$peso} kg";
        }

        $eventos = $data['eventos'] ?? $data['historial'] ?? $data['novedades'] ?? [];
        if (!empty($eventos) && is_array($eventos)) {
            $lines[] = '';
            $lines[] = 'Historial de eventos:';
            foreach ($eventos as $evento) {
                $fecha = $evento['fecha'] ?? $evento['fechaEvento'] ?? '';
                $desc  = $evento['descripcion'] ?? $evento['observacion'] ?? $evento['novedad'] ?? '';
                $lines[] = "• [{$fecha}] {$desc}";
            }
        }

        return implode("\n", $lines);
    }
}
