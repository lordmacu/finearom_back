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
        $candidatos = DB::table('partials as pt')
            ->join('purchase_orders as po', 'pt.order_id', '=', 'po.id')
            ->whereNull('pt.deleted_at')
            ->where('pt.type', 'real')
            ->whereNotIn('po.status', ['completed', 'cancelled'])
            ->whereNotNull('pt.tracking_number')
            ->where('pt.tracking_number', '!=', '')
            ->whereRaw("LOWER(pt.tracking_number) <> 'null'")
            ->groupBy('po.id', 'pt.tracking_number')
            ->selectRaw('po.id as purchase_order_id, pt.tracking_number,
                         MIN(LOWER(pt.transporter)) as carrier,
                         SUM(pt.quantity) as total_kg,
                         COUNT(*) as partials_count,
                         MIN(pt.dispatch_date) as dispatch_date')
            ->get();

        $tocados = 0;

        foreach ($candidatos as $c) {
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

        return $tocados;
    }
}
