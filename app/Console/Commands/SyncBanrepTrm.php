<?php

namespace App\Console\Commands;

use App\Services\Trm\BanrepTrmClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconstruye la tabla `trm_daily` con la serie oficial completa del Banco de
 * la República. Corre cada hora (ver App\Console\Kernel).
 *
 * Estrategia: borrar + reinsertar toda la serie DENTRO de una transacción,
 * pero solo después de que Banrep respondió una serie sana. Si Banrep falla o
 * viene incompleta, la tabla NO se toca (la descarga y su validación ocurren
 * antes de abrir la transacción, y el DELETE hace rollback si el insert falla).
 */
class SyncBanrepTrm extends Command
{
    protected $signature = 'trm:sync-banrep {--dry-run : Descarga y valida, pero no escribe en la BD}';

    protected $description = 'Descarga la serie TRM del Banco de la República y reconstruye trm_daily (desde 2023)';

    /** Solo se insertan fechas desde este día en adelante. */
    private const FROM_DATE = '2023-01-01';

    public function __construct(private readonly BanrepTrmClient $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->info('🔄 Descargando serie TRM del Banco de la República...');

            $series = $this->client->fetchSeries();
            $latest = $series['latest'];

            // Solo insertamos desde FROM_DATE en adelante (comparación lexicográfica
            // válida sobre fechas 'Y-m-d').
            $rows = array_values(array_filter(
                $series['rows'],
                fn (array $r): bool => $r['date'] >= self::FROM_DATE
            ));

            $this->info(sprintf(
                '✅ Serie recibida: %d días totales; %d desde %s (último %s = %s)',
                count($series['rows']),
                count($rows),
                self::FROM_DATE,
                $latest['date'],
                number_format($latest['value'], 2)
            ));

            if ($this->option('dry-run')) {
                $this->warn('Dry-run: la tabla trm_daily NO fue modificada.');
                return self::SUCCESS;
            }

            $now = Carbon::now();
            $records = array_map(function (array $r) use ($now): array {
                return [
                    'date' => $r['date'],
                    'value' => $r['value'],
                    'source' => 'banrep',
                    'metadata' => null,
                    'is_weekend' => Carbon::parse($r['date'])->isWeekend(),
                    'is_holiday' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $rows);

            $prevCount = DB::table('trm_daily')->count();

            DB::transaction(function () use ($records): void {
                // DELETE (no TRUNCATE) para que sea reversible dentro de la transacción.
                DB::table('trm_daily')->delete();

                foreach (array_chunk($records, 1000) as $chunk) {
                    DB::table('trm_daily')->insert($chunk);
                }
            });

            $newCount = DB::table('trm_daily')->count();

            Log::info('TRM Banrep sync OK', [
                'prev_rows' => $prevCount,
                'new_rows' => $newCount,
                'latest_date' => $latest['date'],
                'latest_value' => $latest['value'],
            ]);

            $this->info(sprintf('✅ trm_daily reconstruida: %d → %d filas.', $prevCount, $newCount));

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('TRM Banrep sync FAILED: ' . $e->getMessage());
            $this->error('❌ ' . $e->getMessage());
            $this->warn('La tabla trm_daily NO fue modificada.');

            return self::FAILURE;
        }
    }
}
