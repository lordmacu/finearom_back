<?php

namespace App\Console\Commands;

use App\Models\TrmDaily;
use App\Services\Trm\BanrepTrmClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sincroniza la TRM desde la serie oficial del Banco de la República.
 *
 * Modo por defecto (horario): solo hace upsert del último día publicado por
 * Banrep. Las TRMs pasadas no cambian, así que no tiene sentido reescribirlas.
 *
 * Modo `--rebuild`: reconstruye toda la tabla desde 2023 (borrar + reinsertar
 * en una transacción). Útil para el primer poblado o para rellenar huecos.
 *
 * En ambos casos la descarga se valida ANTES de tocar la BD; si Banrep falla o
 * responde incompleto, la tabla no se modifica.
 */
class SyncBanrepTrm extends Command
{
    protected $signature = 'trm:sync-banrep
                            {--rebuild : Reconstruye toda la tabla desde 2023 (borrar + reinsertar)}
                            {--dry-run : Descarga y valida, pero no escribe en la BD}';

    protected $description = 'Sincroniza la TRM del Banco de la República (día actual; --rebuild para toda la serie desde 2023)';

    /** En modo --rebuild, solo se insertan fechas desde este día en adelante. */
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

            return $this->option('rebuild')
                ? $this->rebuild($series['rows'], $latest)
                : $this->upsertLatest($latest);
        } catch (Throwable $e) {
            Log::error('TRM Banrep sync FAILED: ' . $e->getMessage());
            $this->error('❌ ' . $e->getMessage());
            $this->warn('La tabla trm_daily NO fue modificada.');

            return self::FAILURE;
        }
    }

    /**
     * Modo horario: upsert del último día publicado por Banrep.
     *
     * @param array{date: string, value: float} $latest
     */
    private function upsertLatest(array $latest): int
    {
        $this->info(sprintf('✅ Último dato: %s = %s', $latest['date'], number_format($latest['value'], 2)));

        if ($this->option('dry-run')) {
            $this->warn('Dry-run: la tabla trm_daily NO fue modificada.');
            return self::SUCCESS;
        }

        $record = TrmDaily::updateOrCreate(
            ['date' => $latest['date']],
            [
                'value' => $latest['value'],
                'source' => 'banrep',
                'is_weekend' => Carbon::parse($latest['date'])->isWeekend(),
                'is_holiday' => false,
            ]
        );

        $action = $record->wasRecentlyCreated ? 'insertado' : 'actualizado';

        Log::info("TRM Banrep día {$action}", [
            'date' => $latest['date'],
            'value' => $latest['value'],
        ]);

        $this->info(sprintf('✅ Día %s %s: %s', $latest['date'], $action, number_format($latest['value'], 2)));

        return self::SUCCESS;
    }

    /**
     * Modo --rebuild: borra y reinserta toda la serie desde FROM_DATE.
     *
     * @param array<int, array{date: string, value: float}> $rows
     * @param array{date: string, value: float} $latest
     */
    private function rebuild(array $rows, array $latest): int
    {
        // Solo desde FROM_DATE (comparación lexicográfica válida sobre 'Y-m-d').
        $rows = array_values(array_filter(
            $rows,
            fn (array $r): bool => $r['date'] >= self::FROM_DATE
        ));

        $this->info(sprintf(
            '✅ %d días desde %s (último %s = %s)',
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
        $records = array_map(fn (array $r): array => [
            'date' => $r['date'],
            'value' => $r['value'],
            'source' => 'banrep',
            'metadata' => null,
            'is_weekend' => Carbon::parse($r['date'])->isWeekend(),
            'is_holiday' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        $prevCount = DB::table('trm_daily')->count();

        DB::transaction(function () use ($records): void {
            // DELETE (no TRUNCATE) para que sea reversible dentro de la transacción.
            DB::table('trm_daily')->delete();

            foreach (array_chunk($records, 1000) as $chunk) {
                DB::table('trm_daily')->insert($chunk);
            }
        });

        $newCount = DB::table('trm_daily')->count();

        Log::info('TRM Banrep rebuild OK', [
            'prev_rows' => $prevCount,
            'new_rows' => $newCount,
            'latest_date' => $latest['date'],
            'latest_value' => $latest['value'],
        ]);

        $this->info(sprintf('✅ trm_daily reconstruida: %d → %d filas.', $prevCount, $newCount));

        return self::SUCCESS;
    }
}
