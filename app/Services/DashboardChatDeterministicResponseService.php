<?php

namespace App\Services;

use Carbon\Carbon;

class DashboardChatDeterministicResponseService
{
    private const MONTHS = [
        1 => ['sql' => 'ENERO', 'label' => 'enero'],
        2 => ['sql' => 'FEBRERO', 'label' => 'febrero'],
        3 => ['sql' => 'MARZO', 'label' => 'marzo'],
        4 => ['sql' => 'ABRIL', 'label' => 'abril'],
        5 => ['sql' => 'MAYO', 'label' => 'mayo'],
        6 => ['sql' => 'JUNIO', 'label' => 'junio'],
        7 => ['sql' => 'JULIO', 'label' => 'julio'],
        8 => ['sql' => 'AGOSTO', 'label' => 'agosto'],
        9 => ['sql' => 'SEPTIEMBRE', 'label' => 'septiembre'],
        10 => ['sql' => 'OCTUBRE', 'label' => 'octubre'],
        11 => ['sql' => 'NOVIEMBRE', 'label' => 'noviembre'],
        12 => ['sql' => 'DICIEMBRE', 'label' => 'diciembre'],
    ];

    public function build(string $message, string $periodStart, string $periodEnd): ?string
    {
        if (! $this->isForecastByClientCopIntent($message)) {
            return null;
        }

        $months = $this->forecastMonths($periodStart, $periodEnd);
        if ($months === []) {
            return null;
        }

        $periodWhere = $this->periodWhereSql($months);
        $periodLabel = $this->periodLabel($months);

        $sql = <<<SQL
WITH product_ranked AS (
    SELECT
        p.client_id,
        p.code COLLATE utf8mb4_unicode_ci AS codigo,
        p.price,
        ROW_NUMBER() OVER (
            PARTITION BY p.client_id, p.code
            ORDER BY CASE WHEN p.price > 0 THEN 0 ELSE 1 END, p.updated_at DESC, p.id DESC
        ) AS rn
    FROM products p
),
product_prices AS (
    SELECT client_id, codigo, price
    FROM product_ranked
    WHERE rn = 1
),
trm AS (
    SELECT COALESCE(
        (SELECT value FROM trm_daily WHERE date <= CURDATE() ORDER BY date DESC LIMIT 1),
        4000
    ) AS trm_cop_usd
)
SELECT
    c.client_name AS cliente,
    c.nit AS nit,
    SUM(sf.cantidad_forecast) AS kilos_pronosticados,
    ROUND(SUM(sf.cantidad_forecast * COALESCE(NULLIF(pp.price, 0), 0)), 2) AS valor_usd_estimado,
    ROUND(SUM(sf.cantidad_forecast * COALESCE(NULLIF(pp.price, 0), 0) * trm.trm_cop_usd), 0) AS valor_cop_estimado,
    MAX(trm.trm_cop_usd) AS trm_cop_usd
FROM sales_forecasts sf
JOIN clients c ON c.nit = sf.nit
LEFT JOIN product_prices pp ON pp.client_id = c.id AND pp.codigo = sf.codigo
CROSS JOIN trm
WHERE sf.modelo = 'manual'
  AND {$periodWhere}
GROUP BY c.client_name, c.nit
ORDER BY valor_cop_estimado DESC
SQL;

        return json_encode([
            'html' => "<p>Total pronosticado manual para {$periodLabel}, agrupado por cliente y convertido a pesos colombianos con la TRM más reciente disponible.</p>",
            'sql' => $sql,
            'showing' => 'cliente, NIT, kilos pronosticados, valor USD estimado, valor COP estimado, TRM usada',
            'available' => 'desglose por referencia, ejecutiva, mes o modelo de forecast',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isForecastByClientCopIntent(string $message): bool
    {
        $text = $this->normalize($message);

        $hasForecast = $this->containsAny($text, ['pronostic', 'forecast', 'presupuesto', 'proyectad', 'planead']);
        $hasClient = str_contains($text, 'cliente');
        $hasTotal = str_contains($text, 'total') || str_contains($text, 'sum');
        $hasCurrency = $this->containsAny($text, ['peso', 'pesos', 'cop', 'valor', '$']);

        return $hasForecast && $hasClient && $hasTotal && $hasCurrency;
    }

    /**
     * @return array<int, array{year: string, month: string, label: string}>
     */
    private function forecastMonths(string $periodStart, string $periodEnd): array
    {
        try {
            $start = Carbon::parse($periodStart)->startOfMonth();
            $end = Carbon::parse($periodEnd)->startOfMonth();
        } catch (\Throwable) {
            return [];
        }

        if ($start->gt($end)) {
            return [];
        }

        $months = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $month = self::MONTHS[$cursor->month];
            $months[] = [
                'year' => (string) $cursor->year,
                'month' => $month['sql'],
                'label' => $month['label'] . ' ' . $cursor->year,
            ];
            $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * @param  array<int, array{year: string, month: string, label: string}>  $months
     */
    private function periodWhereSql(array $months): string
    {
        $parts = array_map(
            fn (array $month) => "(sf.año = '{$month['year']}' AND sf.mes = '{$month['month']}')",
            $months
        );

        if (count($parts) === 1) {
            return $parts[0];
        }

        return '(' . implode("\n      OR ", $parts) . ')';
    }

    /**
     * @param  array<int, array{year: string, month: string, label: string}>  $months
     */
    private function periodLabel(array $months): string
    {
        if (count($months) === 1) {
            return $months[0]['label'];
        }

        return $months[0]['label'] . ' a ' . $months[array_key_last($months)]['label'];
    }

    private function normalize(string $message): string
    {
        $text = mb_strtolower($message, 'UTF-8');

        return strtr($text, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ]);
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
