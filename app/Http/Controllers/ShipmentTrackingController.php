<?php

namespace App\Http\Controllers;

use App\Models\ShipmentTracking;
use App\Services\Courier\CourierRegistry;
use App\Services\Courier\ShipmentTrackingSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentTrackingController extends Controller
{
    public function __construct(
        private readonly ShipmentTrackingSyncService $sync,
        private readonly CourierRegistry $registry
    ) {
        $this->middleware('can:purchase_order list');
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'            => ['nullable', 'string', 'max:20'],
            'carrier'           => ['nullable', 'string', 'max:30'],
            'client_id'         => ['nullable', 'integer'],
            'purchase_order_id' => ['nullable', 'integer'],
            'per_page'          => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = ShipmentTracking::query()
            ->with(['purchaseOrder:id,order_consecutive,client_id,status',
                    'purchaseOrder.client:id,client_name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('carrier'), fn ($q) => $q->where('carrier', $request->carrier))
            ->when($request->filled('purchase_order_id'),
                fn ($q) => $q->where('purchase_order_id', $request->purchase_order_id))
            ->when($request->filled('client_id'), fn ($q) => $q->whereHas(
                'purchaseOrder', fn ($p) => $p->where('client_id', $request->client_id)
            ))
            ->orderByRaw('is_final ASC')
            ->orderByDesc('dispatch_date');

        return response()->json($query->paginate($request->input('per_page', 50)));
    }

    public function events(int $id): JsonResponse
    {
        $tracking = ShipmentTracking::with('events')->findOrFail($id);

        return response()->json(['data' => $tracking->events]);
    }

    public function refresh(int $id): JsonResponse
    {
        $tracking = ShipmentTracking::findOrFail($id);

        // Coordinadora (y cualquier otra transportadora push-only) no se
        // consulta: nadie le pregunta, ella empuja. Antes, este botón
        // llamaba syncOne() igual para cualquier fila: sobre Coordinadora
        // eso grababa un error_message de "no se consulta", movía
        // checked_at y el frontend mostraba éxito. Se rechaza explícito con
        // un mensaje claro en vez de tocar la fila.
        $driver = $this->registry->driverFor($tracking->carrier);

        if ($driver !== null && $driver->isPushOnly()) {
            return response()->json([
                'message' => "{$tracking->carrier} no se consulta: es push-only, solo recibe notificaciones del proveedor.",
            ], 422);
        }

        $estado = $this->sync->syncOne($tracking);

        return response()->json([
            'data'    => $tracking->fresh(),
            'message' => "Estado actualizado: {$estado}",
        ]);
    }
}
