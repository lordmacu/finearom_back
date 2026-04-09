<?php

namespace App\Console\Commands;

use App\Models\Cartera;
use App\Models\Client;
use App\Services\SiigoBridgeUrl;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncSiigoCartera extends Command
{
    protected $signature = 'siigo:sync-cartera
                            {--nit= : Sincronizar solo un NIT especifico}
                            {--dias-mora=-270 : Dias minimos de mora (filtro)}
                            {--dias-cobro=10 : Dias maximos de cobro (filtro)}
                            {--fecha-cartera= : Fecha del snapshot (default: hoy)}
                            {--fecha-from= : Fecha from del rango (default: hoy)}
                            {--fecha-to= : Fecha to del rango (default: hoy)}';

    protected $description = 'Sincroniza cartera desde el Siigo Bridge (reemplaza import manual Excel)';

    private string $baseUrl;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // 1. URL dinamica del bridge (empujada automaticamente)
        $this->baseUrl = SiigoBridgeUrl::get();
        if (empty($this->baseUrl)) {
            $this->error('No hay URL del Siigo Bridge registrada. El bridge debe estar corriendo.');
            return self::FAILURE;
        }
        $this->info("Usando bridge en: {$this->baseUrl}");

        // 2. Login
        $token = $this->login();
        if (! $token) {
            $this->error('No se pudo autenticar con el bridge.');
            return self::FAILURE;
        }
        $this->info('Autenticacion exitosa.');

        // 3. Parametros
        $nit = $this->option('nit') ?? 'all';
        $diasMora = $this->option('dias-mora');
        $diasCobro = $this->option('dias-cobro');
        $fechaCartera = $this->option('fecha-cartera') ?? Carbon::now()->toDateString();
        $fechaFrom = $this->option('fecha-from') ?? $fechaCartera;
        $fechaTo = $this->option('fecha-to') ?? $fechaCartera;

        // 4. Fetch
        $url = "{$this->baseUrl}/cartera-cliente/{$nit}";
        $this->info("Consultando: {$url}");

        try {
            $response = Http::withToken($token)
                ->timeout(120)
                ->get($url, [
                    'dias_mora' => $diasMora,
                    'dias_cobro' => $diasCobro,
                ]);

            if (! $response->successful()) {
                $this->error("Error API: HTTP {$response->status()}");
                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error("Error de conexion: {$e->getMessage()}");
            return self::FAILURE;
        }

        $data = $response->json();
        $cartera = $data['cartera'] ?? [];

        if (empty($cartera)) {
            $this->warn('No se encontraron registros de cartera.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Recibidos: %d documentos, total: $%s, vencidos: $%s',
            $data['total_documentos'] ?? count($cartera),
            number_format($data['total_por_vencer'] ?? 0, 2),
            number_format($data['total_vencidos'] ?? 0, 2)
        ));

        // 5. Cache de clientes para enriquecer ciudad + vendedor
        $clientsCache = Client::query()
            ->whereIn('nit', collect($cartera)->pluck('nit')->unique()->toArray())
            ->get()
            ->keyBy('nit');

        // 6. Transaction: eliminar snapshot del dia y volver a insertar
        $bar = $this->output->createProgressBar(count($cartera));
        $bar->start();
        $inserted = 0;
        $errors = 0;
        $now = Carbon::now();

        DB::transaction(function () use ($cartera, $fechaCartera, $fechaFrom, $fechaTo, $now, $clientsCache, &$inserted, &$errors, $bar) {
            Cartera::query()->where('fecha_cartera', $fechaCartera)->delete();

            $payload = [];
            foreach ($cartera as $row) {
                $nitRow = $row['nit'] ?? '';
                $client = $clientsCache->get($nitRow);

                $payload[] = [
                    'nit' => $nitRow,
                    'ciudad' => ($client->city ?? $client->registration_city ?? $row['ciudad'] ?? 'N/A'),
                    'vendedor' => ($client->executive ?? $row['vendedor'] ?? '0001'),
                    'nombre_vendedor' => ($client->executive ?? $row['nombre_vendedor'] ?? 'N/A'),
                    'cuenta' => $row['cuenta'] ?? '13050500',
                    'descripcion_cuenta' => $row['descripcion_cuenta'] ?? 'DEUDORES NACIONALES',
                    'documento' => $row['documento'] ?? '',
                    'fecha' => $row['fecha'] ?? null,
                    'fecha_from' => $fechaFrom,
                    'fecha_to' => $fechaTo,
                    'fecha_cartera' => $fechaCartera,
                    'vence' => $row['vence'] ?? null,
                    'dias' => (int) ($row['dias'] ?? 0),
                    'saldo_contable' => $row['saldo_contable'] ?? 0,
                    'vencido' => $row['vencido'] ?? 0,
                    'saldo_vencido' => $row['saldo_vencido'] ?? 0,
                    'nombre_empresa' => $row['nombre_empresa'] ?? '',
                    'catera_type' => $row['catera_type'] ?? 'nacional',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $bar->advance();
            }

            foreach (array_chunk($payload, 500) as $chunk) {
                try {
                    Cartera::query()->insert($chunk);
                    $inserted += count($chunk);
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning("SyncSiigoCartera: chunk failed: {$e->getMessage()}");
                }
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Sincronizacion completada: {$inserted} registros insertados, {$errors} errores.");
        $this->info("Snapshot guardado con fecha_cartera = {$fechaCartera}");

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
