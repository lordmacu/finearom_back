<?php

namespace App\Console\Commands;

use App\Models\Recaudo;
use App\Queries\Cartera\CarteraQuery;
use App\Services\SiigoBridgeUrl;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncSiigoRecaudos extends Command
{
    protected $signature = 'siigo:sync-recaudos
                            {--desde= : Mes inicio formato YYYY-MM (default: primer día del año actual)}
                            {--hasta= : Mes fin formato YYYY-MM (default: mes actual)}
                            {--nit= : Sincronizar solo un NIT específico}
                            {--fecha-desde= : Fecha exacta inicio YYYY-MM-DD}
                            {--fecha-hasta= : Fecha exacta fin YYYY-MM-DD}';

    protected $description = 'Sincroniza recaudos desde la API de Siigo (reemplaza import manual de Excel)';

    private string $baseUrl;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Obtener URL dinamica del bridge (empujada automaticamente por el bridge)
        $this->baseUrl = SiigoBridgeUrl::get();
        if (empty($this->baseUrl)) {
            $this->error('No hay URL del Siigo Bridge registrada. El bridge debe estar corriendo y haberla empujado.');
            return self::FAILURE;
        }
        $this->info("Usando bridge en: {$this->baseUrl}");

        $now = Carbon::now();
        $desde = $this->option('desde') ?? $now->copy()->startOfMonth()->format('Y-m');
        $hasta = $this->option('hasta') ?? $now->format('Y-m');

        $this->info("Sincronizando recaudos desde {$desde} hasta {$hasta}");

        // 1. Login
        $token = $this->login();
        if (!$token) {
            $this->error('No se pudo autenticar con la API de Siigo.');
            return self::FAILURE;
        }
        $this->info('Autenticación exitosa.');

        // 2. Fetch recaudos from API
        $params = [];
        if ($this->option('fecha-desde') || $this->option('fecha-hasta')) {
            if ($this->option('fecha-desde')) {
                $params['fecha_desde'] = $this->option('fecha-desde');
            }
            if ($this->option('fecha-hasta')) {
                $params['fecha_hasta'] = $this->option('fecha-hasta');
            }
        } else {
            $params['desde'] = $desde;
            $params['hasta'] = $hasta;
        }

        $nit = $this->option('nit') ?? 'all';
        $url = "{$this->baseUrl}/recaudo/{$nit}";

        $this->info("Consultando: {$url}");

        try {
            $response = Http::withToken($token)
                ->timeout(120)
                ->get($url, $params);

            if (!$response->successful()) {
                $this->error("Error API: HTTP {$response->status()}");
                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error("Error de conexión: {$e->getMessage()}");
            return self::FAILURE;
        }

        $data = $response->json();
        $recaudos = $data['recaudo'] ?? [];

        if (empty($recaudos)) {
            $this->warn('No se encontraron recaudos en el rango especificado.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Recibidos: %d registros, %d clientes, $%s',
            $data['total_registros'] ?? count($recaudos),
            $data['total_clientes'] ?? 0,
            number_format($data['total_valor'] ?? 0, 2)
        ));

        // 3. Upsert to recaudos table
        $bar = $this->output->createProgressBar(count($recaudos));
        $bar->start();

        $inserted = 0;
        $updated = 0;
        $errors = 0;

        DB::beginTransaction();

        try {
            foreach ($recaudos as $rec) {
                try {
                    $numeroRecibo = sprintf('R-001-%011d', $rec['num_recibo']);
                    $numeroFactura = sprintf('F-003-%011d', $rec['num_factura']);

                    $recaudo = Recaudo::query()->updateOrCreate(
                        [
                            'numero_recibo' => $numeroRecibo,
                            'numero_factura' => $numeroFactura,
                        ],
                        [
                            'fecha_recaudo' => $rec['fecha_recibo'] ?? null,
                            'fecha_vencimiento' => !empty($rec['fecha_vencimiento']) ? $rec['fecha_vencimiento'] : null,
                            'nit' => $rec['nit'] ?? null,
                            'cliente' => $rec['nombre_cliente'] ?? null,
                            'dias' => $rec['dias'] ?? 0,
                            'valor_cancelado' => $rec['valor_cancelado'] ?? 0,
                            'observaciones' => $rec['tipo_pago'] ?? null,
                        ]
                    );

                    if ($recaudo->wasRecentlyCreated) {
                        $inserted++;
                    } else {
                        $updated++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning("SyncSiigoRecaudos: Error rec {$rec['num_recibo']}: {$e->getMessage()}");
                }

                $bar->advance();
            }

            DB::commit();

            // Clear customers cache after import
            try {
                app(CarteraQuery::class)->clearCustomersCache();
            } catch (\Throwable $e) {
                // Cache clear is not critical
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("Error: {$e->getMessage()}");
            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine();
        $this->info("Sincronización completada: {$inserted} nuevos, {$updated} actualizados, {$errors} errores.");

        return self::SUCCESS;
    }

    private function login(): ?string
    {
        try {
            $response = Http::post("{$this->baseUrl}/login", [
                'username' => config('custom.siigo_proxy_username'),
                'password' => config('custom.siigo_proxy_password'),
            ]);

            if ($response->successful()) {
                return $response->json('token');
            }

            $this->error('Login fallido: ' . $response->status());
            return null;
        } catch (\Throwable $e) {
            $this->error('Login error: ' . $e->getMessage());
            return null;
        }
    }
}
