<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CoordinadoraService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('custom.coordinadora_base_url', 'https://apiv2.coordinadora.com'), '/');
    }

    /**
     * Consulta el rastreo de una guía Coordinadora por número (11 dígitos).
     * Endpoint REST v2: GET /guias/cm-guias-consultas-ms/guia/{numero}
     */
    public function trackShipment(string $trackingNumber): array
    {
        try {
            $response = Http::withHeaders(['Accept' => 'application/json'])
                ->timeout(15)
                ->get("{$this->baseUrl}/guias/cm-guias-consultas-ms/guia/{$trackingNumber}");

            if ($response->status() === 404) {
                return ['success' => false, 'not_found' => true, 'message' => 'Guía no encontrada'];
            }

            if (!$response->successful()) {
                Log::error('[Coordinadora] Error rastreo ' . $trackingNumber . ' — HTTP ' . $response->status());
                return ['success' => false, 'message' => 'Error consultando Coordinadora: HTTP ' . $response->status()];
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
        $guia = $data['guia'] ?? $data['codigoRemision'] ?? $data['numeroGuia'] ?? 'N/A';
        $lines[] = "Guía Coordinadora: {$guia}";

        $estado = $data['estado'] ?? $data['descripcionEstado'] ?? null;
        if ($estado) {
            $lines[] = "Estado: {$estado}";
        }

        $fechaEntrega = $data['fechaEntrega'] ?? $data['fecha_entrega'] ?? null;
        if ($fechaEntrega) {
            $lines[] = "Fecha de entrega: {$fechaEntrega}";
        }

        $origen = $data['origen'] ?? $data['ciudadOrigen'] ?? null;
        if ($origen) {
            $lines[] = "Origen: {$origen}";
        }

        $destino = $data['destino'] ?? $data['ciudadDestino'] ?? null;
        if ($destino) {
            $lines[] = "Destino: {$destino}";
        }

        $destinatario = $data['destinatario'] ?? $data['nombreDestinatario'] ?? null;
        if ($destinatario) {
            $lines[] = "Destinatario: {$destinatario}";
        }

        $unidades = $data['unidades'] ?? null;
        if ($unidades) {
            $lines[] = "Unidades: {$unidades}";
        }

        $peso = $data['peso'] ?? null;
        if ($peso) {
            $lines[] = "Peso: {$peso} kg";
        }

        $eventos = $data['eventos'] ?? $data['historial'] ?? [];
        if (!empty($eventos) && is_array($eventos)) {
            $lines[] = '';
            $lines[] = 'Historial de eventos:';
            foreach ($eventos as $evento) {
                $fecha = $evento['fecha'] ?? '';
                $desc  = $evento['descripcion'] ?? $evento['observacion'] ?? '';
                $lines[] = "• [{$fecha}] {$desc}";
            }
        }

        return implode("\n", $lines);
    }
}
