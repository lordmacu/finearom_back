<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TrmService;
use App\Models\TrmDaily;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Exception;

class FetchDailyTrm extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trm:fetch-daily 
                            {date? : Fecha en formato Y-m-d (opcional, por defecto hoy)}
                            {--force : Forzar actualización si ya existe}
                            {--range= : Rango de fechas desde hoy hacia atrás (ej: --range=7 para 7 días)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Obtiene y guarda la TRM para una fecha específica o la fecha actual';

    /**
     * @var TrmService
     */
    private $trmService;

    /**
     * Create a new command instance.
     */
    public function __construct(TrmService $trmService)
    {
        parent::__construct();
        $this->trmService = $trmService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Verificar si se solicita un rango de fechas
            if ($range = $this->option('range')) {
                return $this->handleDateRange((int) $range);
            }

            // Procesar fecha única
            $date = $this->argument('date') ? 
                Carbon::createFromFormat('Y-m-d', $this->argument('date')) : 
                Carbon::now();

            $this->info("🔍 Obteniendo TRM para: " . $date->format('Y-m-d'));

            $result = $this->processSingleDate($date);

            if ($result['success']) {
                $this->info("✅ " . $result['message']);
                $this->displayTrmInfo($result['data']);
            } else {
                $this->error("❌ " . $result['message']);
                return 1;
            }

            return 0;

        } catch (Exception $e) {
            $this->error("💥 Error general: " . $e->getMessage());
            Log::error("FetchDailyTrm Error: " . $e->getMessage(), [
                'date' => $this->argument('date'),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Procesa un rango de fechas
     */
    private function handleDateRange(int $days): int
    {
        $this->info("📅 Procesando TRM para los últimos {$days} días...");
        
        $startDate = Carbon::now()->subDays($days - 1);
        $endDate = Carbon::now();
        $processed = 0;
        $errors = 0;

        $progressBar = $this->output->createProgressBar($days);
        $progressBar->start();

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $result = $this->processSingleDate($date, false); // Sin verbose
            
            if ($result['success']) {
                $processed++;
            } else {
                $errors++;
            }
            
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("✅ Procesadas: {$processed} fechas");
        if ($errors > 0) {
            $this->warn("⚠️  Errores: {$errors} fechas");
        }

        return $errors > 0 ? 1 : 0;
    }

    /**
     * Procesa una fecha única
     */
    private function processSingleDate(Carbon $date, bool $verbose = true): array
    {
        $dateString = $date->format('Y-m-d');

        // Verificar si ya existe y no se fuerza la actualización
        if (!$this->option('force') && TrmDaily::existsForDate($dateString)) {
            $existing = TrmDaily::getTrmByDate($dateString);
            return [
                'success' => true,
                'message' => "TRM ya existe para {$dateString}: {$existing}",
                'data' => ['date' => $dateString, 'value' => $existing, 'existed' => true]
            ];
        }

        try {
            // Obtener TRM del servicio
            $trmValue = $this->trmService->getTrm($dateString);

            if ($trmValue <= 0) {
                return [
                    'success' => false,
                    'message' => "TRM inválida obtenida para {$dateString}: {$trmValue}"
                ];
            }

            // Determinar características de la fecha
            $isWeekend = $date->isWeekend();
            $isHoliday = $this->isColombianHoliday($date); // Implementar según necesidades

            // Preparar metadata
            $metadata = [
                'fetched_at' => Carbon::now()->toISOString(),
                'day_of_week' => $date->dayOfWeek,
                'day_name' => $date->dayName,
                'is_business_day' => !$isWeekend && !$isHoliday,
            ];

            // Guardar o actualizar en base de datos
            $trmRecord = TrmDaily::updateOrCreate(
                ['date' => $dateString],
                [
                    'value' => $trmValue,
                    'source' => 'soap', // Ajustar según la fuente real
                    'metadata' => $metadata,
                    'is_weekend' => $isWeekend,
                    'is_holiday' => $isHoliday,
                ]
            );

            if ($verbose) {
                $action = $trmRecord->wasRecentlyCreated ? 'Guardada' : 'Actualizada';
                Log::info("TRM {$action}", [
                    'date' => $dateString,
                    'value' => $trmValue,
                    'is_weekend' => $isWeekend,
                    'is_holiday' => $isHoliday
                ]);
            }

            return [
                'success' => true,
                'message' => "TRM procesada correctamente para {$dateString}",
                'data' => [
                    'date' => $dateString,
                    'value' => $trmValue,
                    'is_weekend' => $isWeekend,
                    'is_holiday' => $isHoliday,
                    'was_created' => $trmRecord->wasRecentlyCreated
                ]
            ];

        } catch (Exception $e) {
            Log::error("Error procesando TRM para {$dateString}: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error obteniendo TRM para {$dateString}: " . $e->getMessage()
            ];
        }
    }

    /**
     * Muestra información detallada de la TRM
     */
    private function displayTrmInfo(array $data): void
    {
        $this->newLine();
        $this->line("📊 <info>Detalles de la TRM:</info>");
        $this->line("   Fecha: <comment>{$data['date']}</comment>");
        $this->line("   Valor: <comment>$" . number_format($data['value'], 2) . "</comment>");
        
        if (isset($data['is_weekend']) && $data['is_weekend']) {
            $this->line("   <fg=yellow>🎯 Fin de semana</>");
        }
        
        if (isset($data['is_holiday']) && $data['is_holiday']) {
            $this->line("   <fg=yellow>🎉 Día festivo</>");
        }

        if (isset($data['existed']) && $data['existed']) {
            $this->line("   <fg=cyan>ℹ️  Registro existente</>");
        } elseif (isset($data['was_created'])) {
            $status = $data['was_created'] ? 'Nuevo registro' : 'Registro actualizado';
            $this->line("   <fg=green>✨ {$status}</>");
        }
    }

    /**
     * Verifica si una fecha es festivo en Colombia
     * Esta es una implementación básica - puedes expandirla según tus necesidades
     */
    private function isColombianHoliday(Carbon $date): bool
    {
        // Festivos fijos básicos
        $fixedHolidays = [
            '01-01', // Año Nuevo
            '05-01', // Día del Trabajo
            '07-20', // Día de la Independencia
            '08-07', // Batalla de Boyacá
            '12-08', // Inmaculada Concepción
            '12-25', // Navidad
        ];

        $monthDay = $date->format('m-d');
        return in_array($monthDay, $fixedHolidays);
        
        // TODO: Implementar cálculo de festivos móviles (Semana Santa, etc.)
        // Para una implementación completa, considera usar una librería como
        // yasumi/yasumi o implementar la lógica completa de festivos colombianos
    }
}