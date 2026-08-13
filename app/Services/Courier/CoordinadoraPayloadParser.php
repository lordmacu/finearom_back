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
     *
     * Orden deliberado: devolución/cancelación y entrega-fallida se evalúan
     * ANTES que entrega-exitosa. La subcadena "ENTREGA" por sí sola aparece
     * tanto en "ENTREGADA" como en "NO SE PUDO ENTREGAR", "ENTREGA FALLIDA" o
     * "DEVOLUCIÓN POR NO ENTREGA" — si "entregado" se evaluara primero (como
     * antes), un despacho devuelto o con entrega fallida quedaba marcado como
     * entregado con is_final=true, para siempre: Coordinadora es push-only,
     * nada lo vuelve a corregir. Por eso la regla de éxito exige una forma
     * verbal concreta (ENTREGADA/ENTREGADO/ENTREGA EXITOSA) y nunca se evalúa
     * si antes se detectó una negación del verbo.
     */
    public function mapTrackingStatus(array $payload): string
    {
        $comment    = self::campoEscalar($payload, 'comment') ?? '';
        $descEstado = self::campoEscalar($payload, 'desc_estado') ?? '';
        $texto      = self::normalizar($comment . ' ' . $descEstado);

        if (str_contains($texto, 'DEVOLUC') || str_contains($texto, 'DEVUELT')) {
            return CourierStatus::DEVUELTO;
        }

        if (str_contains($texto, 'CANCELAD')) {
            return CourierStatus::NOVEDAD;
        }

        // Entrega fallida/negada: "NO SE PUDO ENTREGAR", "NO FUE POSIBLE
        // ENTREGAR" (NO + hasta 4 palabras + ENTREG) y "ENTREGA FALLIDA"
        // (ENTREG... seguido de FALLID...). Es una novedad real que alguien
        // debe perseguir, nunca "entregado".
        if (preg_match('/\bNO\b(?:\s+\S+){0,4}\s+ENTREG/u', $texto)
            || preg_match('/ENTREG\w*\s+FALLID/u', $texto)
        ) {
            return CourierStatus::NOVEDAD;
        }

        // Entrega exitosa: solo formas verbales concretas, no cualquier
        // subcadena "ENTREGA" (ver nota arriba).
        if (preg_match('/ENTREG(AD[AO]\b|A\s+EXITOS)/u', $texto)) {
            return CourierStatus::ENTREGADO;
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

        $codigo      = self::campoEscalar($payload, 'codigo');
        $descripcion = self::campoEscalar($payload, 'comment') ?? self::campoEscalar($payload, 'desc_estado');

        return new CourierEvent(
            occurredAt: $occurredAt,
            // shipment_tracking_events.code / shipment_trackings.last_event_code
            // son VARCHAR(20); *_description son VARCHAR(255). MySQL en modo
            // estricto lanza excepción ante un valor más largo que la columna
            // — sin este tope, un `comment` largo del proveedor produce un
            // 500 que Coordinadora reintenta indefinidamente sin que el
            // evento entre nunca. buildNysEvent() ya truncaba su lado antes
            // de llamar a este método; acá se acota también el camino de
            // tracking, que llegaba sin recortar.
            code:        $codigo === null ? null : mb_substr($codigo, 0, 20),
            description: $descripcion === null ? null : mb_substr($descripcion, 0, 255),
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

    /**
     * Mayúsculas y sin tildes, para comparar texto que llega en cualquier
     * formato. Pública a propósito: CoordinadoraInboundService la reusa
     * para comparar el campo `evento` de novedades/soluciones (`"Aprobación"`,
     * `"APROBACION"`, con o sin espacios) en vez de duplicar esta
     * normalización — el texto de este proveedor nunca es de fiar.
     */
    public static function normalizar(string $texto): string
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
