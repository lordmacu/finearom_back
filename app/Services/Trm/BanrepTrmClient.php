<?php

namespace App\Services\Trm;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente para la serie diaria de TRM del Banco de la República (Banrep).
 *
 * Fuente oficial (misma que publica la TRM):
 *   GET suameca.banrep.gov.co/.../consultaMenuXId?idMenu=1
 *
 * Devuelve toda la serie histórica diaria (con carry-forward de fines de semana
 * y festivos) en `SERIES[0].data` como pares [timestamp_ms, valor].
 *
 * ⚠️ Banrep bloquea peticiones sin User-Agent de navegador (responde 200 con
 *    body vacío). Por eso el header es obligatorio.
 */
class BanrepTrmClient
{
    private const URL = 'https://suameca.banrep.gov.co/estadisticas-economicas-back/rest/estadisticaEconomicaRestService/consultaMenuXId';

    private const ID_MENU_TRM = 1;

    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * Guarda mínima: la serie histórica tiene ~12.6k puntos. Si llega mucho
     * menos, la respuesta está incompleta y NO se debe reconstruir la tabla.
     */
    private const MIN_EXPECTED_ROWS = 5000;

    /**
     * Descarga la serie diaria completa de TRM.
     *
     * @return array{rows: array<int, array{date: string, value: float}>, latest: array{date: string, value: float}}
     *
     * @throws RuntimeException si la respuesta falla o viene incompleta.
     */
    public function fetchSeries(): array
    {
        // Banrep exige User-Agent de navegador Y Referer; sin el Referer responde
        // 200 con body vacío (0 bytes).
        $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/json, text/plain, */*',
                'Referer' => 'https://suameca.banrep.gov.co/estadisticas-economicas/',
            ])
            ->timeout(45)
            ->retry(2, 1000)
            ->get(self::URL, ['idMenu' => self::ID_MENU_TRM]);

        if (! $response->successful()) {
            throw new RuntimeException('Banrep TRM: HTTP ' . $response->status());
        }

        $data = $response->json('SERIES.0.data');

        if (! is_array($data) || count($data) < self::MIN_EXPECTED_ROWS) {
            $count = is_array($data) ? count($data) : 'null';
            throw new RuntimeException("Banrep TRM: serie vacía o incompleta ({$count} puntos)");
        }

        // Dedup por fecha (última gana), convirtiendo el timestamp ms a fecha Bogotá.
        $byDate = [];
        foreach ($data as $point) {
            if (! isset($point[0], $point[1])) {
                continue;
            }

            $value = (float) $point[1];
            if ($value <= 0) {
                continue;
            }

            $date = Carbon::createFromTimestampMs((int) $point[0], 'America/Bogota')->toDateString();
            $byDate[$date] = ['date' => $date, 'value' => $value];
        }

        if (count($byDate) < self::MIN_EXPECTED_ROWS) {
            throw new RuntimeException('Banrep TRM: filas válidas insuficientes (' . count($byDate) . ')');
        }

        ksort($byDate);
        $rows = array_values($byDate);
        $latest = end($rows);

        return ['rows' => $rows, 'latest' => $latest];
    }
}
