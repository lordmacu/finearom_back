<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$startDate = '2026-04-01';
$endDate = '2026-04-15';
$fromMes = Carbon::parse($startDate)->format('Y-m');
$toMes = Carbon::parse($endDate)->format('Y-m');

$avgTrmSiigo = (float) DB::table('trm_daily')
    ->whereBetween('date', [$startDate, $endDate])
    ->avg('value');
if ($avgTrmSiigo <= 0) {
    $avgTrmSiigo = 4000;
}

echo "=== CÁLCULO FACTURADO DESDE SIIGO (Query del Dashboard) ===\n";
echo "Rango fechas: {$startDate} a {$endDate}\n";
echo "Rango meses: {$fromMes} a {$toMes}\n";
echo "TRM promedio: {$avgTrmSiigo}\n\n";

$dispRows = DB::table('siigo_sales as s')
    ->leftJoin('clients as c', DB::raw('c.nit COLLATE utf8mb4_unicode_ci'), '=', DB::raw('s.nit COLLATE utf8mb4_unicode_ci'))
    ->whereBetween('s.mes', [$fromMes, $toMes])
    ->selectRaw("
        COALESCE(NULLIF(c.executive, ''), 'Sin ejecutiva') as executive,
        SUM(s.cantidad) as dispatched_kilos,
        SUM(s.valor) / {$avgTrmSiigo} as dispatched_usd,
        SUM(s.valor) as dispatched_cop
    ")
    ->groupBy('c.executive')
    ->get()
    ->keyBy('executive');

echo str_pad("Ejecutiva", 40) . str_pad("Kilos", 12) . str_pad("USD", 15) . "COP\n";
echo str_repeat("-", 85) . "\n";

$totalKilos = 0;
$totalUsd = 0;
$totalCop = 0;

foreach ($dispRows as $executive => $row) {
    echo str_pad($executive, 40);
    echo str_pad(number_format($row->dispatched_kilos, 0), 12);
    echo str_pad(number_format($row->dispatched_usd, 2), 15);
    echo number_format($row->dispatched_cop, 2) . "\n";
    $totalKilos += $row->dispatched_kilos;
    $totalUsd += $row->dispatched_usd;
    $totalCop += $row->dispatched_cop;
}

echo str_repeat("-", 85) . "\n";
echo str_pad("TOTAL", 40);
echo str_pad(number_format($totalKilos, 0), 12);
echo str_pad(number_format($totalUsd, 2), 15);
echo number_format($totalCop, 2) . "\n\n";

echo "=== SUMA DIRECTA DE SIIGO_SALES (sin JOIN) ===\n";
$directSum = DB::table('siigo_sales')
    ->whereBetween('mes', [$fromMes, $toMes])
    ->selectRaw("SUM(valor) as total, SUM(cantidad) as total_kilos")
    ->first();

echo "Total valor (COP): " . number_format($directSum->total, 2) . "\n";
echo "Total kilos: " . number_format($directSum->total_kilos, 0) . "\n\n";

echo "=== COMPARACIÓN ===\n";
echo "Suma query con JOIN (dispatched_cop): " . number_format($totalCop, 2) . "\n";
echo "Suma directa sin JOIN (total): " . number_format($directSum->total, 2) . "\n";
echo "Diferencia: " . number_format(abs($totalCop - $directSum->total), 2) . "\n";

if ($totalCop == $directSum->total) {
    echo "\n✓ COINCIDEN - Los cálculos están correctos\n";
} else {
    echo "\n✗ NO COINCIDEN - Hay un problema en el cálculo\n";
}
