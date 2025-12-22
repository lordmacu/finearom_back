<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client as HttpClient;
use Exception;

/**
 * Servicio para obtener la Tasa Representativa del Mercado (TRM)
 * Centraliza toda la lógica de obtención de TRM desde diferentes fuentes
 */
class TrmService
{
    /**
     * @var TrmNormalizer
     */
    private $normalizer;

    /**
     * Constructor
     */
    public function __construct(TrmNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }
    /**
     * Obtiene la TRM para una fecha dada, usando caché.
     *
     * @param string|null $custom_date La fecha en formato 'Y-m-d'. Si es nulo, usa la fecha actual.
     * @return float La TRM para la fecha especificada
     */
    public function getTrm(?string $custom_date = null): float
    {
        $date = $custom_date ?? date('Y-m-d');

        try {
            $trm_from_service = $this->fetchTrmFromSoapService($date);

            return (float) ($trm_from_service["value"] ?? 0);
        } catch (Exception $e) {
            // Si hay cualquier error, retorna el valor de la API alternativa
            Log::warning("Error obteniendo TRM del servicio SOAP para fecha {$date}: " . $e->getMessage());
            return (float) ($this->getExchangeRates($date) ?? 0);
        }
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
                return (float) $cachedData['exchangeRate'];
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

            // Guardar en caché
            Storage::disk('local')->put($cacheFile, json_encode([
                'exchangeRate' => $exchangeRate,
                'date' => $currentDate
            ]));

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
     * Limpia el caché de TRM y exchange rates
     *
     * @return bool
     */
    public function clearCache(): bool
    {
        try {
            $files = Storage::disk('local')->files();
            $deleted = 0;

            foreach ($files as $file) {
                if (str_starts_with($file, 'exchange_rate_')) {
                    Storage::disk('local')->delete($file);
                    $deleted++;
                }
            }

            Log::info("TRM Cache cleared. {$deleted} files deleted.");
            return true;
        } catch (Exception $e) {
            Log::error("Error clearing TRM cache: " . $e->getMessage());
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