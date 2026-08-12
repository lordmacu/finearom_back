<?php

namespace App\Services\Courier;

use App\Models\ShipmentTracking;
use Illuminate\Support\Facades\DB;

/**
 * Convierte los parciales despachados en despachos rastreables.
 *
 * Un despacho es la pareja (orden, guía): en toda la base no existe un caso de
 * (OC, mismo día) con dos guías distintas, así que la guía identifica el envío
 * completo, con todos los productos que van en él.
 *
 * Se descarta:
 *   - guía vacía o con el texto 'null' (207 parciales la tienen)
 *   - transportadoras sin driver (aldia, reymor, conductores)
 *   - guías cuyo formato no corresponde a su transportadora
 */
class ShipmentDiscoveryService
{
    public function __construct(
        private readonly CourierRegistry $registry
    ) {}

    public function discover(): int
    {
        // Paso 1: qué órdenes entran al descubrimiento. La ventana de 30 días
        // sólo se usa aquí, para decidir si una OC completed sigue vigente
        // (despacho reciente que aún no puede esperar a la corrida de mañana).
        // No debe usarse más adelante para recortar los parciales de una orden
        // ya incluida: eso rompía la agregación y el cálculo de huérfanas.
        $ordenesElegibles = DB::table('purchase_orders as po')
            ->where('po.status', '!=', 'cancelled')
            ->where(function ($query) {
                $query->where('po.status', '!=', 'completed')
                    ->orWhereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('partials as pt')
                            ->whereColumn('pt.order_id', 'po.id')
                            ->whereNull('pt.deleted_at')
                            ->where('pt.type', 'real')
                            ->where('pt.dispatch_date', '>=', now()->subDays(30)->toDateString())
                            ->whereNotNull('pt.tracking_number')
                            ->where('pt.tracking_number', '!=', '')
                            ->whereRaw("LOWER(pt.tracking_number) <> 'null'");
                    });
            })
            ->pluck('po.id');

        if ($ordenesElegibles->isEmpty()) {
            return 0;
        }

        // Paso 2: pares (orden, guía) vivos de esas órdenes, sin predicado de
        // fecha — una vez que la orden entró, se consideran TODOS sus
        // parciales vigentes, no sólo los recientes. Esta misma consulta
        // alimenta tanto la actualización de shipment_trackings como el
        // conjunto de pares vivos que decide el cierre por orfandad.
        $candidatos = DB::table('partials as pt')
            ->whereIn('pt.order_id', $ordenesElegibles)
            ->whereNull('pt.deleted_at')
            ->where('pt.type', 'real')
            ->whereNotNull('pt.tracking_number')
            ->where('pt.tracking_number', '!=', '')
            ->whereRaw("LOWER(pt.tracking_number) <> 'null'")
            ->groupBy('pt.order_id', 'pt.tracking_number')
            ->selectRaw('pt.order_id as purchase_order_id, pt.tracking_number,
                         MIN(LOWER(TRIM(pt.transporter))) as carrier,
                         SUM(pt.quantity) as total_kg,
                         COUNT(*) as partials_count,
                         MIN(pt.dispatch_date) as dispatch_date')
            ->get();

        $tocados        = 0;
        $ordenesTocadas = [];
        $paresVivos     = [];

        foreach ($candidatos as $c) {
            $ordenesTocadas[$c->purchase_order_id] = true;
            $paresVivos[$c->purchase_order_id . '|' . $c->tracking_number] = true;

            if (!$this->registry->canTrack($c->carrier, $c->tracking_number)) {
                continue;
            }

            $tracking = ShipmentTracking::firstOrNew([
                'purchase_order_id' => $c->purchase_order_id,
                'tracking_number'   => $c->tracking_number,
            ]);

            // Los datos del despacho se recalculan siempre: pueden agregarse
            // parciales a la misma guía después de creado el registro.
            $tracking->carrier        = $c->carrier;
            $tracking->total_kg       = $c->total_kg;
            $tracking->partials_count = $c->partials_count;
            $tracking->dispatch_date  = $c->dispatch_date;

            if (!$tracking->exists) {
                $tracking->status = CourierStatus::PENDIENTE;
            }

            $tracking->save();
            $tocados++;
        }

        $this->closeOrphanedTrackings($ordenesTocadas, $paresVivos);

        return $tocados;
    }

    /**
     * Cierra las filas de shipment_trackings cuya guía ya no aparece entre los
     * parciales vivos de la orden: pasa cuando alguien corrige una guía mal
     * digitada (soft-delete + recreación de parciales). No se borran: el
     * historial de eventos sirve como registro. Sólo mira órdenes que
     * aparecieron en el descubrimiento de esta corrida.
     *
     * @param array<int|string, bool> $ordenesTocadas
     * @param array<string, bool> $paresVivos pares (orden, guía) vivos, sin
     *        filtrar por fecha de despacho — ver paso 2 de discover().
     */
    private function closeOrphanedTrackings(array $ordenesTocadas, array $paresVivos): void
    {
        if (empty($ordenesTocadas)) {
            return;
        }

        ShipmentTracking::query()
            ->whereIn('purchase_order_id', array_keys($ordenesTocadas))
            ->where('is_final', false)
            ->get()
            ->each(function (ShipmentTracking $tracking) use ($paresVivos) {
                $clave = $tracking->purchase_order_id . '|' . $tracking->tracking_number;

                if (isset($paresVivos[$clave])) {
                    return;
                }

                $tracking->is_final      = true;
                $tracking->error_message = 'La guía ya no está en los parciales de la orden (fue corregida o eliminada)';
                $tracking->save();
            });
    }
}
