<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\SiigoSale;
use App\Services\SiigoBridgeUrl;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncSiigoSales extends Command
{
    protected $signature = 'siigo:sync-sales
                            {--desde= : Mes inicio formato YYYY-MM (default: primer día del mes actual)}
                            {--hasta= : Mes fin formato YYYY-MM (default: mes actual)}
                            {--nit= : Sincronizar solo un NIT específico}';

    protected $description = 'Sincroniza ventas desde la API de Siigo para todos los clientes';

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

        $this->info("Sincronizando ventas desde {$desde} hasta {$hasta}");

        // 1. Login
        $token = $this->login();
        if (! $token) {
            $this->error('No se pudo autenticar con la API de Siigo.');
            return self::FAILURE;
        }
        $this->info('Autenticación exitosa.');

        // 2. Si piden un NIT específico → endpoint individual directo
        $nitFilter = $this->option('nit');
        if ($nitFilter) {
            $this->info("Procesando solo NIT {$nitFilter}...");
            try {
                $inserted = $this->syncClientSales($token, $nitFilter, $desde, $hasta);
                $this->info("Sincronización completada: {$inserted} registros.");
                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error("Error NIT {$nitFilter}: {$e->getMessage()}");
                Log::error("SyncSiigoSales: Error NIT {$nitFilter}: {$e->getMessage()}");
                return self::FAILURE;
            }
        }

        // 3. Sin filtro → intentar primero el endpoint masivo /ventas/all (1 llamada)
        $this->info('Intentando descarga masiva vía /ventas/all...');
        $bulkResult = $this->syncAll($token, $desde, $hasta);

        if ($bulkResult !== null) {
            $this->info("Sincronización masiva completada: {$bulkResult} registros insertados/actualizados.");
            return self::SUCCESS;
        }

        // 4. Fallback: /ventas/all falló, volver al método por NIT
        $this->warn('Endpoint /ventas/all no disponible o con error. Usando modo cliente-por-cliente.');

        $nits = Client::whereNotNull('nit')
            ->where('nit', '!=', '')
            ->pluck('nit')
            ->unique();

        $this->info("Procesando {$nits->count()} clientes...");
        $bar = $this->output->createProgressBar($nits->count());
        $bar->start();

        $totalInserted = 0;
        $errors = 0;

        foreach ($nits as $nit) {
            try {
                $inserted = $this->syncClientSales($token, $nit, $desde, $hasta);
                $totalInserted += $inserted;
            } catch (\Throwable $e) {
                $errors++;
                Log::warning("SyncSiigoSales: Error NIT {$nit}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Sincronización completada: {$totalInserted} registros insertados/actualizados, {$errors} errores.");

        return self::SUCCESS;
    }

    /**
     * Intenta descargar TODAS las ventas en una sola llamada via /ventas/all.
     * Devuelve el # de registros persistidos o NULL si el endpoint falla
     * (para que el caller haga fallback al modo por NIT).
     */
    private function syncAll(string $token, string $desde, string $hasta): ?int
    {
        try {
            $response = Http::withToken($token)
                ->timeout(300)  // 5 min para respuestas grandes
                ->get("{$this->baseUrl}/ventas/all", [
                    'desde' => $desde,
                    'hasta' => $hasta,
                ]);

            if (! $response->successful()) {
                Log::info("SyncSiigoSales: /ventas/all status={$response->status()} — " . substr($response->body(), 0, 200));
                return null;
            }

            $ventas = $response->json('ventas');
            if (! is_array($ventas)) {
                Log::info('SyncSiigoSales: /ventas/all respondió sin key "ventas".');
                return null;
            }

            $this->info('Recibidas ' . count($ventas) . ' líneas de venta. Persistiendo...');
            $bar = $this->output->createProgressBar(count($ventas));
            $bar->start();

            $count = 0;
            foreach ($ventas as $venta) {
                $count += $this->persistVenta($venta);
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();

            return $count;
        } catch (\Throwable $e) {
            Log::info('SyncSiigoSales: /ventas/all exception — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Persiste una venta del formato del bridge en sales_siigo.
     * Extrae product_code de la descripción ("Producto - CODE").
     */
    private function persistVenta(array $venta): int
    {
        $valoresMes    = $venta['valores_mes'] ?? [];
        $cantidadesMes = $venta['cantidades_mes'] ?? [];
        $descripcion   = $venta['descripcion'] ?? null;
        $productCode   = ($descripcion && str_contains($descripcion, ' - '))
            ? trim(substr($descripcion, strrpos($descripcion, ' - ') + 3))
            : null;

        $count = 0;
        foreach ($valoresMes as $mes => $valor) {
            SiigoSale::updateOrCreate(
                [
                    'nit'          => $venta['nit'],
                    'product_code' => $productCode,
                    'cuenta'       => $venta['cuenta'] ?? '',
                    'mes'          => $mes,
                ],
                [
                    'orden_compra'    => $venta['orden_compra'] ?? null,
                    'lote'            => $venta['lote'] ?? null,
                    'precio_unitario' => $venta['precio_unitario'] ?? 0,
                    'valor'           => $valor,
                    'cantidad'        => $cantidadesMes[$mes] ?? 0,
                ]
            );
            $count++;
        }
        return $count;
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

    private function syncClientSales(string $token, string $nit, string $desde, string $hasta): int
    {
        $response = Http::withToken($token)
            ->timeout(30)
            ->get("{$this->baseUrl}/ventas/{$nit}", [
                'desde' => $desde,
                'hasta' => $hasta,
            ]);

        if (! $response->successful()) {
            return 0;
        }

        $data = $response->json();
        $ventas = $data['ventas'] ?? [];
        $count = 0;

        foreach ($ventas as $venta) {
            $valoresMes = $venta['valores_mes'] ?? [];
            $cantidadesMes = $venta['cantidades_mes'] ?? [];

            $descripcion = $venta['descripcion'] ?? null;
            $productCode = $descripcion && str_contains($descripcion, ' - ')
                ? trim(substr($descripcion, strrpos($descripcion, ' - ') + 3))
                : null;

            foreach ($valoresMes as $mes => $valor) {
                $cantidad = $cantidadesMes[$mes] ?? 0;

                SiigoSale::updateOrCreate(
                    [
                        'nit' => $venta['nit'],
                        'product_code' => $productCode,
                        'cuenta' => $venta['cuenta'] ?? '',
                        'mes' => $mes,
                    ],
                    [
                        'orden_compra' => $venta['orden_compra'] ?? null,
                        'lote' => $venta['lote'] ?? null,
                        'precio_unitario' => $venta['precio_unitario'] ?? 0,
                        'valor' => $valor,
                        'cantidad' => $cantidad,
                    ]
                );
                $count++;
            }
        }

        return $count;
    }
}
