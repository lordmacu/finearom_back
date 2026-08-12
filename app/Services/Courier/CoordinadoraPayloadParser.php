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
        $comment    = self::campoEscalar($payload, 'comment') ?? '';
        $descEstado = self::campoEscalar($payload, 'desc_estado') ?? '';
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

        $codigo      = self::campoEscalar($payload, 'codigo') ?? 'sin código';
        $descripcion = trim($comment . ' ' . $descEstado);
        Log::info("[Coordinadora] estado no mapeado: código={$codigo}, descripcion=\"{$descripcion}\"");

        return CourierStatus::EN_TRANSITO;
    }

    /**
     * Arma el evento normalizado de un payload de tracking. La marca de
     * tiempo se compone de `fecha` + `hora`; si `hora` trae microsegundos
     * (`13:51:43.456818`) se recortan al formato `Y-m-d H:i:s`.
     *
     * Todo campo que llegue con un tipo no escalar (arreglo, objeto) se trata
     * como ausente en vez de castearse a ciegas — el endpoint es público y
     * sin autenticación, así que el payload no es de fiar.
     *
     * @throws \InvalidArgumentException si `fecha`/`hora` no arman una marca
     *         de tiempo válida en formato `Y-m-d H:i:s`. El llamador debe
     *         capturarla y descartar el evento (p. ej. registrar el rechazo
     *         en la bitácora) en vez de dejar pasar un `occurredAt` inválido.
     */
    public function toEvent(array $payload): CourierEvent
    {
        $fecha = trim(self::campoEscalar($payload, 'fecha') ?? '');
        $hora  = trim(self::campoEscalar($payload, 'hora') ?? '');

        // Recorta microsegundos: "13:51:43.456818" -> "13:51:43"
        $puntoPos = strpos($hora, '.');
        if ($puntoPos !== false) {
            $hora = substr($hora, 0, $puntoPos);
        }

        $occurredAt = trim($fecha . ' ' . $hora);

        if (!self::esTimestampValido($occurredAt)) {
            throw new \InvalidArgumentException(
                "Coordinadora: no se pudo armar una marca de tiempo válida a partir de fecha=\"{$fecha}\" hora=\"{$hora}\""
            );
        }

        return new CourierEvent(
            occurredAt:  $occurredAt,
            code:        self::campoEscalar($payload, 'codigo'),
            description: self::campoEscalar($payload, 'comment') ?? self::campoEscalar($payload, 'desc_estado'),
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

    /**
     * Lee `$payload[$clave]` como texto, tratando cualquier valor no escalar
     * (arreglo, objeto) o ausente/`null` como si el campo no existiera. Evita
     * castear a ciegas datos de un payload no confiable (endpoint público sin
     * autenticación): un `(string)` sobre un arreglo emite un aviso de PHP y
     * produce el texto inútil `"Array"`.
     */
    private static function campoEscalar(array $payload, string $clave): ?string
    {
        $valor = $payload[$clave] ?? null;

        if ($valor === null || !is_scalar($valor)) {
            return null;
        }

        return (string) $valor;
    }

    /** `true` solo si `$valor` es exactamente una marca de tiempo `Y-m-d H:i:s` real. */
    private static function esTimestampValido(string $valor): bool
    {
        $fecha = \DateTime::createFromFormat('Y-m-d H:i:s', $valor);

        return $fecha !== false && $fecha->format('Y-m-d H:i:s') === $valor;
    }
}
