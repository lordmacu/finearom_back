<?php

namespace App\Services\Courier;

use Illuminate\Support\Facades\Log;

/**
 * Coordinadora — traductor de los payloads que el proveedor empuja (push) al
 * modelo interno de seguimiento.
 *
 * Dos formatos distintos:
 * - Push Tracking v3: llega envuelto en Google Pub/Sub, con el JSON real
 *   codificado en Base64 dentro de `message.data`.
 * - Push NyS (novedades / soluciones): JSON plano, sin envoltura.
 *
 * Todos los métodos son puros: sin Eloquent, sin HTTP, para poder probarlos
 * sin base de datos.
 */
class CoordinadoraPayloadParser
{
    /** Acentos frecuentes en los textos que manda el proveedor. */
    private const ACENTOS = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ü' => 'U',
        'Ñ' => 'N',
    ];

    /**
     * Decodifica la envoltura Pub/Sub (`{ "message": { "data": "<base64>" } }`)
     * del endpoint de tracking. Devuelve el JSON ya decodificado como arreglo,
     * o `null` si la envoltura no tiene la forma esperada o el Base64 no
     * decodifica a un JSON válido. Nunca lanza excepciones ante basura.
     */
    public function decodeTrackingEnvelope(array $body): ?array
    {
        $message = $body['message'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        $data = $message['data'] ?? null;
        if (!is_string($data) || $data === '') {
            return null;
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return null;
        }

        $json = json_decode($decoded, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
            return null;
        }

        return $json;
    }

    /**
     * Estado normalizado para el endpoint de tracking, a partir del texto de
     * `comment` o `desc_estado`. Insensible a mayúsculas y a tildes. Cuando no
     * reconoce nada registra en el log el código y la descripción para poder
     * ampliar el mapa con datos reales.
     */
    public function mapTrackingStatus(array $payload): string
    {
        $comment    = (string) ($payload['comment'] ?? '');
        $descEstado = (string) ($payload['desc_estado'] ?? '');
        $texto      = self::normalizar($comment . ' ' . $descEstado);

        if (str_contains($texto, 'ENTREGA')) {
            return CourierStatus::ENTREGADO;
        }

        if (str_contains($texto, 'DEVOLUC') || str_contains($texto, 'DEVUELT')) {
            return CourierStatus::DEVUELTO;
        }

        if (str_contains($texto, 'CANCELAD')) {
            return CourierStatus::NOVEDAD;
        }

        $codigo = $payload['codigo'] ?? 'sin código';
        Log::info("[Coordinadora] estado no mapeado: código={$codigo}, descripcion=\"{$comment}{$descEstado}\"");

        return CourierStatus::EN_TRANSITO;
    }

    /**
     * Arma el evento normalizado de un payload de tracking. La marca de
     * tiempo se compone de `fecha` + `hora`; si `hora` trae microsegundos
     * (`13:51:43.456818`) se recortan al formato `Y-m-d H:i:s`.
     */
    public function toEvent(array $payload): CourierEvent
    {
        $fecha = trim((string) ($payload['fecha'] ?? ''));
        $hora  = trim((string) ($payload['hora'] ?? ''));

        // Recorta microsegundos: "13:51:43.456818" -> "13:51:43"
        $puntoPos = strpos($hora, '.');
        if ($puntoPos !== false) {
            $hora = substr($hora, 0, $puntoPos);
        }

        $occurredAt = trim($fecha . ' ' . $hora);

        return new CourierEvent(
            occurredAt:  $occurredAt,
            code:        isset($payload['codigo']) ? (string) $payload['codigo'] : null,
            description: $payload['comment'] ?? $payload['desc_estado'] ?? null,
            location:    null,
            raw:         $payload,
        );
    }

    /** El endpoint de novedades no trae estados propios: siempre es `novedad`. */
    public function mapNovedadStatus(array $payload): string
    {
        return CourierStatus::NOVEDAD;
    }

    /**
     * Extrae el número de guía según el endpoint: `tracking_number` en
     * tracking, `numero_guia` en NyS (novedades / soluciones).
     */
    public function extractTrackingNumber(string $endpoint, array $payload): ?string
    {
        $clave = $endpoint === 'tracking' ? 'tracking_number' : 'numero_guia';
        $valor = $payload[$clave] ?? null;

        if (!is_string($valor) && !is_numeric($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor !== '' ? $valor : null;
    }

    /** Mayúsculas y sin tildes, para comparar texto que llega en cualquier formato. */
    private static function normalizar(string $texto): string
    {
        return strtr(mb_strtoupper($texto, 'UTF-8'), self::ACENTOS);
    }
}
