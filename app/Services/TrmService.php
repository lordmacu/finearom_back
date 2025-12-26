<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client as HttpClient;
use Exception;

/**
 * Servicio para obtener la Tasa Representativa del Mercado (TRM)
 * Centraliza toda la lógica de obtención de TRM desde diferentes fuentes
 *
 * Sistema de caché multinivel:
 * 1. Caché en memoria (runtime) - Ultra rápido para requests múltiples
 * 2. Caché de Laravel (Redis/File) - Persistente entre requests con TTL inteligente
 * 3. APIs externas - Solo cuando no hay caché disponible
 */
class TrmService
{
    /**
     * @var TrmNormalizer
     */
    private $normalizer;

    /**
     * @var array Caché en memoria para el request actual
     */
    private $runtimeCache = [];

    /**
     * Constructor
     */
    public function __construct(TrmNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }
    /**
     * Obtiene la TRM para una fecha dada con sistema de caché multinivel.
     *
     * Sistema de caché en cascada:
     * 1. Caché en memoria (runtime) - Si ya se consultó en este request
     * 2. Caché de Laravel - Valores persistentes con TTL inteligente
     * 3. Servicio SOAP principal
     * 4. API alternativa (Exchange Rates)
     * 5. Último valor exitoso en caché de emergencia
     * 6. Valor por defecto (4000)
     *
     * @param string|null $custom_date La fecha en formato 'Y-m-d'. Si es nulo, usa la fecha actual.
     * @return float La TRM para la fecha especificada
     */
    public function getTrm(?string $custom_date = null): float
    {
        $date = $custom_date ?? date('Y-m-d');

        // Nivel 1: Caché en memoria (runtime)
        if (isset($this->runtimeCache[$date])) {
            Log::debug("TRM obtenido de caché en memoria para {$date}: {$this->runtimeCache[$date]}");
            return $this->runtimeCache[$date];
        }

        // Nivel 2: Caché de Laravel
        $cacheKey = "trm_{$date}";
        $cachedValue = Cache::get($cacheKey);

        if ($cachedValue !== null && $cachedValue > TrmNormalizer::MIN_VALID_TRM) {
            Log::debug("TRM obtenido de caché de Laravel para {$date}: {$cachedValue}");
            $this->runtimeCache[$date] = $cachedValue;
            return $cachedValue;
        }

        // Nivel 3: Intentar obtener de SOAP
        try {
            $trm_from_service = $this->fetchTrmFromSoapService($date);
            $trmValue = (float) ($trm_from_service["value"] ?? 0);

            if ($trmValue > TrmNormalizer::MIN_VALID_TRM) {
                $this->cacheTrmValue($date, $trmValue, 'soap');
                return $trmValue;
            }
        } catch (Exception $e) {
            Log::warning("Error obteniendo TRM del servicio SOAP para fecha {$date}: " . $e->getMessage());
        }

        // Nivel 4: Fallback a API alternativa
        try {
            $exchangeRate = $this->getExchangeRates($date);
            if ($exchangeRate && $exchangeRate > TrmNormalizer::MIN_VALID_TRM) {
                $this->cacheTrmValue($date, $exchangeRate, 'exchange_api');
                return (float) $exchangeRate;
            }
        } catch (Exception $e) {
            Log::warning("Error obteniendo TRM de Exchange API para fecha {$date}: " . $e->getMessage());
        }

        // Nivel 5: Último valor exitoso en caché de emergencia
        $lastSuccessful = $this->getLastSuccessfulTrm();
        if ($lastSuccessful) {
            Log::info("Usando último valor TRM exitoso del caché de emergencia: {$lastSuccessful['value']} (fecha: {$lastSuccessful['date']}, fuente: {$lastSuccessful['source']})");
            $trmValue = (float) $lastSuccessful['value'];
            $this->runtimeCache[$date] = $trmValue;
            return $trmValue;
        }

        // Nivel 6: Valor por defecto
        Log::error("No se pudo obtener TRM de ninguna fuente para fecha {$date}. Usando valor por defecto: 4000");
        $this->runtimeCache[$date] = 4000.0;
        return 4000.0;
    }

    /**
     * Cachea un valor de TRM en todos los niveles de caché
     * Usa TTL inteligente según la fecha
     *
     * @param string $date Fecha en formato Y-m-d
     * @param float $value Valor de TRM
     * @param string $source Fuente del valor (soap, exchange_api, etc.)
     * @return void
     */
    private function cacheTrmValue(string $date, float $value, string $source): void
    {
        // 1. Guardar en caché de memoria
        $this->runtimeCache[$date] = $value;

        // 2. Guardar en caché de Laravel con TTL inteligente
        $ttl = $this->calculateCacheTTL($date);
        $cacheKey = "trm_{$date}";
        Cache::put($cacheKey, $value, $ttl);

        // 3. Guardar como último exitoso para emergencias
        $this->saveLastSuccessfulTrm($value, $date, $source);

        Log::info("TRM cacheado para {$date}: {$value} (fuente: {$source}, TTL: {$ttl} segundos)");
    }

    /**
     * Calcula el TTL apropiado para el caché según la fecha
     *
     * - Fechas pasadas: 30 días (son históricas, no cambian)
     * - Fecha actual: Hasta medianoche (puede actualizarse durante el día)
     * - Fechas futuras: 1 hora (son estimaciones)
     *
     * @param string $date Fecha en formato Y-m-d
     * @return int TTL en segundos
     */
    private function calculateCacheTTL(string $date): int
    {
        $targetDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        $today = Carbon::today();

        if ($targetDate->lt($today)) {
            // Fecha pasada: TRM histórico, cachear por 30 días
            return 60 * 60 * 24 * 30; // 30 días
        }

        if ($targetDate->eq($today)) {
            // Fecha actual: cachear hasta medianoche
            $midnight = Carbon::tomorrow();
            return $midnight->diffInSeconds(Carbon::now());
        }

        // Fecha futura: cachear por 1 hora (son estimaciones)
        return 60 * 60; // 1 hora
    }

    /**
     * Obtiene la TRM usando Carbon date
     *
     * @param Carbon|null $date
     * @return float
     */
    public function getTrmByCarbon(?Carbon $date = null): float
    {
        $dateString = $date ? $date->format('Y-m-d') : null;
        return $this->getTrm($dateString);
    }

    /**
     * Normaliza un valor de TRM (convierte a float y valida)
     *
     * @param mixed $trmValue
     * @return float
     */
    public function normalizeTrm($trmValue): float
    {
        return $this->normalizer->normalize($trmValue);
    }

    /**
     * Obtiene la TRM efectiva para un parcial (lógica de analyzeClientsByStatus)
     *
     * @param mixed $partialTrm TRM del parcial
     * @param string|null $dispatchDate Fecha de despacho
     * @return array ['trm' => float, 'is_default' => bool]
     */
public function getEffectiveTrm($partialTrm, ?string $dispatchDate = null): array
{
    $normalizationResult = $this->normalizer->normalizeWithFlags($partialTrm);
    $trm = $normalizationResult['value'];
    $isDefault = $normalizationResult['is_default'];

    // Si la TRM es inválida o por defecto, usar TRM de la fecha
    if ($isDefault || $trm == 0) {
        $trm = $this->getTrm($dispatchDate);
        $isDefault = true;
    }

    // ✅ AGREGAR ESTA VALIDACIÓN CRÍTICA (igual que en analyzeClientsByStatus):
    if ($trm < TrmNormalizer::MIN_VALID_TRM) {  // 3800
        $isDefault = true;
        $trm = $this->getTrm($dispatchDate);  // ← ⚠️ RECALCULAR TRM POR DEFECTO
    }

    return [
        'trm' => $trm,
        'is_default' => $isDefault,
        'original_value' => $partialTrm,
        'normalized_original' => $normalizationResult['value']
    ];
}

    /**
     * Obtiene múltiples TRMs para un rango de fechas
     *
     * @param string $startDate
     * @param string $endDate
     * @return array Arreglo con fechas como claves y TRMs como valores
     */
    public function getTrmRange(string $startDate, string $endDate): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $startDate);
        $end = Carbon::createFromFormat('Y-m-d', $endDate);
        $trms = [];

        while ($start->lte($end)) {
            $dateString = $start->format('Y-m-d');
            $trms[$dateString] = $this->getTrm($dateString);
            $start->addDay();
        }

        return $trms;
    }

    /**
     * Obtiene el tipo de cambio usando API alternativa
     *
     * @param string|null $date
     * @return float|null
     */
    private function getExchangeRates(?string $date = null): ?float
    {
        $target_date = $date ?? Carbon::now()->format('Y-m-d');
        $currentDate = Carbon::now()->format('Y-m-d');
        $cacheFile = "exchange_rate_{$currentDate}.json";

        // Verifica si el valor ya está en el almacenamiento
        if (Storage::disk('local')->exists($cacheFile)) {
            $cachedData = json_decode(Storage::disk('local')->get($cacheFile), true);
            if ($cachedData && isset($cachedData['date']) && $cachedData['date'] === $currentDate) {
                $cachedValue = (float) $cachedData['exchangeRate'];
                // Guardar como último exitoso si es válido
                if ($cachedValue > TrmNormalizer::MIN_VALID_TRM) {
                    $this->saveLastSuccessfulTrm($cachedValue, $currentDate, 'exchange_api_cache');
                }
                return $cachedValue;
            }
        }

        // Si no existe en el almacenamiento, haz la solicitud a la API
        $client = new HttpClient(['timeout' => 10]);
        $url = "https://openexchangerates.org/api/time-series.json?app_id=f2884227901c482194f0fbfe7fa77c63&start={$target_date}&end={$target_date}&symbols=USD&base=COP";

        try {
            $response = $client->request('GET', $url);
            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['rates'][$currentDate]['USD'])) {
                Log::warning("Exchange rate API did not return expected data for date: {$target_date}");
                return null;
            }

            $exchangeRate = (float) $data['rates'][$currentDate]['USD'];

            // Guardar en caché diario
            Storage::disk('local')->put($cacheFile, json_encode([
                'exchangeRate' => $exchangeRate,
                'date' => $currentDate
            ]));

            // Guardar como último exitoso si es válido
            if ($exchangeRate > TrmNormalizer::MIN_VALID_TRM) {
                $this->saveLastSuccessfulTrm($exchangeRate, $currentDate, 'exchange_api');
            }

            return $exchangeRate;
        } catch (Exception $e) {
            Log::error("Error obteniendo exchange rate: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Realiza la llamada directa al servicio SOAP de la Superfinanciera.
     * Esta función solo se ejecuta si el valor no está en caché.
     *
     * @param string $date
     * @return array|null
     */
    private function fetchTrmFromSoapService(string $date): ?array
    {
        $soapUrl = "https://www.superfinanciera.gov.co/SuperfinancieraWebServiceTRM/TCRMServicesWebService/TCRMServicesWebService";

        $xmlPostString = <<<XML
        <Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/">
            <Body>
                <queryTCRM xmlns="http://action.trm.services.generic.action.superfinanciera.nexura.sc.com.co/">
                    <tcrmQueryAssociatedDate xmlns="">{$date}</tcrmQueryAssociatedDate>
                </queryTCRM>
            </Body>
        </Envelope>
        XML;

        $headers = [
            "Content-type: text/xml; charset=\"utf-8\"",
            "Accept: text/xml",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "SOAPAction: \"\"",
            "Content-length: " . strlen($xmlPostString),
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_URL, $soapUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlPostString);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);

        if (curl_errno($ch) || $response === false) {
            $error_msg = curl_error($ch);
            Log::error("TRM cURL Error: " . $error_msg);
            curl_close($ch);
            throw new Exception("Error en conexión SOAP: " . $error_msg);
        }

        curl_close($ch);

        try {
            $response = str_replace(['soap:', 'ns2:'], '', $response);
            $xml = new \SimpleXMLElement($response);
            $returnNode = $xml->Body->queryTCRMResponse->return;

            if (!$returnNode || !isset($returnNode->success) || $returnNode->success == 'false') {
                Log::warning("TRM SOAP Service did not return a successful response for date: {$date}");
                throw new Exception("Respuesta no exitosa del servicio SOAP para fecha: {$date}");
            }

            return [
                'value'      => (float) $returnNode->value,
                'unit'       => (string) $returnNode->unit,
                'valid_from' => (string) $returnNode->validityFrom,
                'valid_to'   => (string) $returnNode->validityTo,
                'success'    => filter_var((string) $returnNode->success, FILTER_VALIDATE_BOOLEAN),
            ];
        } catch (Exception $e) {
            Log::error("TRM XML Parse Error: " . $e->getMessage());
            throw new Exception("Error parseando respuesta XML: " . $e->getMessage());
        }
    }

    /**
     * Guarda el último valor exitoso de TRM en caché persistente
     *
     * @param float $value
     * @param string $date
     * @param string $source Fuente del valor (soap, exchange_api, etc.)
     * @return void
     */
    private function saveLastSuccessfulTrm(float $value, string $date, string $source): void
    {
        try {
            $cacheData = [
                'value' => $value,
                'date' => $date,
                'source' => $source,
                'saved_at' => Carbon::now()->toDateTimeString(),
            ];

            Storage::disk('local')->put('trm_last_successful.json', json_encode($cacheData));

            Log::debug("TRM exitoso guardado en caché: {$value} (fecha: {$date}, fuente: {$source})");
        } catch (Exception $e) {
            Log::error("Error guardando último TRM exitoso: " . $e->getMessage());
        }
    }

    /**
     * Obtiene el último valor exitoso de TRM desde el caché persistente
     *
     * @return array|null ['value' => float, 'date' => string, 'source' => string, 'saved_at' => string]
     */
    private function getLastSuccessfulTrm(): ?array
    {
        try {
            $cacheFile = 'trm_last_successful.json';

            if (!Storage::disk('local')->exists($cacheFile)) {
                return null;
            }

            $cachedData = json_decode(Storage::disk('local')->get($cacheFile), true);

            if (!$cachedData || !isset($cachedData['value'])) {
                return null;
            }

            // Verificar que el valor sea válido
            if ($cachedData['value'] < TrmNormalizer::MIN_VALID_TRM) {
                Log::warning("Último TRM en caché es inválido: {$cachedData['value']}");
                return null;
            }

            return $cachedData;
        } catch (Exception $e) {
            Log::error("Error leyendo último TRM exitoso del caché: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Limpia todos los cachés de TRM (memoria, Laravel Cache y archivos)
     *
     * @param bool $includeLast Si es true, también elimina el último valor exitoso
     * @return bool
     */
    public function clearCache(bool $includeLast = false): bool
    {
        try {
            $deleted = 0;

            // 1. Limpiar caché en memoria
            $memoryCount = count($this->runtimeCache);
            $this->runtimeCache = [];
            Log::debug("Caché en memoria limpiado: {$memoryCount} entradas eliminadas");

            // 2. Limpiar caché de Laravel (todos los TRMs)
            $laravelCacheCleared = $this->clearLaravelCache();
            if ($laravelCacheCleared) {
                Log::info("Caché de Laravel limpiado exitosamente");
            }

            // 3. Limpiar archivos de exchange rates
            $files = Storage::disk('local')->files();
            foreach ($files as $file) {
                if (str_starts_with($file, 'exchange_rate_')) {
                    Storage::disk('local')->delete($file);
                    $deleted++;
                }
            }

            // 4. Opcionalmente eliminar el último valor exitoso
            if ($includeLast && Storage::disk('local')->exists('trm_last_successful.json')) {
                Storage::disk('local')->delete('trm_last_successful.json');
                $deleted++;
                Log::info("Último valor TRM exitoso eliminado del caché.");
            }

            Log::info("TRM Cache cleared completamente. {$deleted} archivos eliminados.");
            return true;
        } catch (Exception $e) {
            Log::error("Error clearing TRM cache: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Limpia solo el caché de Laravel (keys con prefijo trm_)
     *
     * @return bool
     */
    private function clearLaravelCache(): bool
    {
        try {
            // Si estás usando Redis, puedes hacer un pattern delete
            // Si usas file cache, necesitamos una estrategia diferente

            // Por simplicidad, vamos a limpiar todo el caché
            // En producción, considera usar tags de caché si usas Redis
            Cache::flush();

            return true;
        } catch (Exception $e) {
            Log::error("Error limpiando caché de Laravel: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Limpia el caché de una fecha específica
     *
     * @param string $date Fecha en formato Y-m-d
     * @return bool
     */
    public function clearCacheForDate(string $date): bool
    {
        try {
            // Limpiar de memoria
            unset($this->runtimeCache[$date]);

            // Limpiar de Laravel Cache
            $cacheKey = "trm_{$date}";
            Cache::forget($cacheKey);

            Log::info("Caché limpiado para fecha: {$date}");
            return true;
        } catch (Exception $e) {
            Log::error("Error limpiando caché para fecha {$date}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene estadísticas de uso de TRM (útil para debugging)
     *
     * @return array
     */
    public function getTrmStats(): array
    {
        return [
            'current_trm' => $this->getTrm(),
            'runtime_cache_entries' => count($this->runtimeCache),
            'runtime_cache_dates' => array_keys($this->runtimeCache),
            'last_successful_trm' => $this->getLastSuccessfulTrm(),
            'cache_files' => $this->getCacheFiles(),
            'service_status' => $this->testTrmService(),
            'normalizer_config' => $this->normalizer->getConfig(),
        ];
    }

    /**
     * Analiza la calidad de un conjunto de TRMs
     *
     * @param array $trmValues
     * @return array
     */
    public function analyzeTrmQuality(array $trmValues): array
    {
        return [
            'statistics' => $this->normalizer->getStatistics($trmValues),
            'consistency' => $this->normalizer->validateConsistency($trmValues),
        ];
    }

    /**
     * Lista archivos de caché actuales
     *
     * @return array
     */
    private function getCacheFiles(): array
    {
        try {
            $files = Storage::disk('local')->files();
            return array_filter($files, fn($file) => str_starts_with($file, 'exchange_rate_'));
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Prueba si el servicio de TRM está funcionando
     *
     * @return bool
     */
    private function testTrmService(): bool
    {
        try {
            $trm = $this->getTrm();
            return $trm > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}