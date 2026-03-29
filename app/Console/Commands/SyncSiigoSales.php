<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\SiigoSale;
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

    private string $baseUrl = 'https://plates-cds-hosted-maintain.trycloudflare.com/api';

    public function handle(): int
    {
        $now = Carbon::now();
        $desde = $this->option('desde') ?? $now->copy()->startOfYear()->format('Y-m');
        $hasta = $this->option('hasta') ?? $now->format('Y-m');

        $this->info("Sincronizando ventas desde {$desde} hasta {$hasta}");

        // 1. Login
        $token = $this->login();
        if (! $token) {
            $this->error('No se pudo autenticar con la API de Siigo.');
            return self::FAILURE;
        }
        $this->info('Autenticación exitosa.');

        // 2. Get clients
        $nitFilter = $this->option('nit');
        if ($nitFilter) {
            $nits = collect([$nitFilter]);
        } else {
            $nits = Client::whereNotNull('nit')
                ->where('nit', '!=', '')
                ->pluck('nit')
                ->unique();
        }

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

    private function login(): ?string
    {
        try {
            $response = Http::post("{$this->baseUrl}/login", [
                'username' => 'lordmacu',
                'password' => 'lordmacu',
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

            foreach ($valoresMes as $mes => $valor) {
                $cantidad = $cantidadesMes[$mes] ?? 0;

                SiigoSale::updateOrCreate(
                    [
                        'nit' => $venta['nit'],
                        'producto' => $venta['producto'],
                        'empresa' => $venta['empresa'] ?? null,
                        'cuenta' => $venta['cuenta'] ?? null,
                        'mes' => $mes,
                    ],
                    [
                        'descripcion' => $venta['descripcion'] ?? null,
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
