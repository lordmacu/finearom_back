<?php

namespace App\Console\Commands;

use App\Services\Courier\ShipmentDiscoveryService;
use App\Services\Courier\ShipmentTrackingSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Actualiza el estado de los despachos que todavía no están entregados.
 *
 * Corre a las 6:00 am. Primero descubre despachos nuevos a partir de los
 * parciales de las OCs abiertas, después consulta a cada transportadora los que
 * siguen vivos.
 */
class SyncShipmentTrackings extends Command
{
    protected $signature = 'shipments:sync-trackings
                            {--dry-run : Sólo descubre y lista, sin consultar transportadoras}
                            {--limit= : Máximo de guías a consultar en esta corrida}';

    protected $description = 'Actualiza el estado de los despachos no entregados consultando a las transportadoras';

    public function __construct(
        private readonly ShipmentDiscoveryService $discovery,
        private readonly ShipmentTrackingSyncService $sync,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🔎 Descubriendo despachos de OCs abiertas...');
        $descubiertos = $this->discovery->discover();
        $this->line("   {$descubiertos} despachos rastreables");

        $pendientes = $this->sync->pending();
        $limit = $this->option('limit');
        if ($limit !== null) {
            $pendientes = $pendientes->take((int) $limit);
        }

        $this->line("   {$pendientes->count()} por consultar");

        if ($this->option('dry-run')) {
            $this->table(
                ['guía', 'transp.', 'OC', 'kg', 'estado', 'intentos'],
                $pendientes->map(fn ($t) => [
                    $t->tracking_number, $t->carrier, $t->purchase_order_id,
                    $t->total_kg, $t->status, $t->check_attempts,
                ])->all()
            );
            $this->warn('dry-run: no se consultó ninguna transportadora');
            return self::SUCCESS;
        }

        $resumen = [];
        $errores = 0;

        foreach ($pendientes as $tracking) {
            try {
                $estado = $this->sync->syncOne($tracking);
                $resumen[$estado] = ($resumen[$estado] ?? 0) + 1;
                $this->line("   {$tracking->tracking_number} → {$estado}");
            } catch (\Throwable $e) {
                $errores++;
                Log::error("[Courier] Falló {$tracking->tracking_number}: " . $e->getMessage());
                $this->error("   {$tracking->tracking_number}: {$e->getMessage()}");
            }
        }

        foreach ($resumen as $estado => $n) {
            $this->info("   {$estado}: {$n}");
        }

        if ($errores > 0) {
            $this->warn("   {$errores} guías con error (ver log)");
        }

        Log::info('[Courier] Sync de despachos', [
            'descubiertos' => $descubiertos,
            'consultados'  => $pendientes->count(),
            'resumen'      => $resumen,
            'errores'      => $errores,
        ]);

        return self::SUCCESS;
    }
}
