<?php

namespace App\Http\Controllers;

use App\Http\Requests\RawMaterial\RawMaterialStoreRequest;
use App\Http\Requests\RawMaterial\RawMaterialUpdateRequest;
use App\Models\CorazonFormulaLine;
use App\Models\FinearomPriceHistory;
use App\Models\FinearomReference;
use App\Models\RawMaterial;
use App\Models\RawMaterialPriceHistory;
use App\Models\RawMaterialStockMovement;
use App\Models\ReferenceFormulaLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RawMaterialController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:raw material list')->only(['index', 'show']);
        $this->middleware('can:raw material create')->only(['store']);
        $this->middleware('can:raw material edit')->only(['update', 'updateCost', 'addMovement']);
        $this->middleware('can:raw material delete')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = RawMaterial::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        $activo = $request->input('activo', 'true');
        if ($activo !== 'all') {
            $query->where('activo', filter_var($activo, FILTER_VALIDATE_BOOLEAN));
        }

        $query->withCount('priceHistory')
              ->with(['stockMovements' => function ($q) {
                  $q->orderByDesc('created_at')->limit(3);
              }])
              ->orderBy('nombre');

        $perPage = min((int) $request->input('per_page', 30), 1000);
        $materials = $query->paginate($perPage);

        return response()->json([
            'data'    => $materials,
            'message' => 'OK',
        ]);
    }

    public function store(RawMaterialStoreRequest $request): JsonResponse
    {
        $material = RawMaterial::create($request->validated());

        return response()->json([
            'data'    => $material,
            'message' => 'Materia prima creada correctamente.',
        ], 201);
    }

    public function show(RawMaterial $rawMaterial): JsonResponse
    {
        $rawMaterial->load([
            'priceHistory' => function ($q) {
                $q->with('changedBy:id,name')
                  ->orderByDesc('created_at')
                  ->limit(10);
            },
            'stockMovements' => function ($q) {
                $q->with('user:id,name')
                  ->orderByDesc('created_at')
                  ->limit(20);
            },
            'formulaLines.rawMaterial',
        ]);

        return response()->json([
            'data'    => $rawMaterial,
            'message' => 'OK',
        ]);
    }

    public function update(RawMaterialUpdateRequest $request, RawMaterial $rawMaterial): JsonResponse
    {
        $data = $request->validated();

        // costo_unitario no se actualiza por esta ruta; usar updateCost
        unset($data['costo_unitario']);

        $rawMaterial->update($data);

        return response()->json([
            'data'    => $rawMaterial->fresh(),
            'message' => 'Materia prima actualizada correctamente.',
        ]);
    }

    public function destroy(RawMaterial $rawMaterial): JsonResponse
    {
        $hasMovements = $rawMaterial->stockMovements()->exists();

        if ($hasMovements) {
            // Soft delete: marcar como inactivo
            $rawMaterial->update(['activo' => false]);

            return response()->json([
                'data'    => $rawMaterial->fresh(),
                'message' => 'La materia prima tiene movimientos de stock; se marcó como inactiva.',
            ]);
        }

        $rawMaterial->delete();

        return response()->json([
            'data'    => null,
            'message' => 'Materia prima eliminada correctamente.',
        ]);
    }

    public function updateCost(Request $request, RawMaterial $rawMaterial): JsonResponse
    {
        $request->validate([
            'costo_unitario' => ['required', 'numeric', 'min:0'],
        ]);

        $costoAnterior = (float) $rawMaterial->costo_unitario;
        $costoNuevo    = (float) $request->costo_unitario;

        RawMaterialPriceHistory::create([
            'raw_material_id' => $rawMaterial->id,
            'costo_anterior'  => $costoAnterior,
            'costo_nuevo'     => $costoNuevo,
            'changed_by'      => auth()->id(),
        ]);

        $rawMaterial->update(['costo_unitario' => $costoNuevo]);

        // Cascade: update corazones that use this material, then their dependent references
        $updatedCorazonIds = $this->recalculateCorazonCosts($rawMaterial);
        $updatedCount      = $this->recalculateReferencePrices($rawMaterial);

        // Also recalculate references that use the updated corazones
        foreach ($updatedCorazonIds as $corazonId) {
            $corazonMaterial = RawMaterial::find($corazonId);
            if ($corazonMaterial) {
                $updatedCount += $this->recalculateReferencePrices($corazonMaterial);
            }
        }

        $message = 'Costo actualizado correctamente.';
        if ($updatedCount > 0) {
            $message .= " Se recalcularon {$updatedCount} referencia(s) afectadas.";
        }

        return response()->json([
            'data'    => $rawMaterial->fresh()->load(['priceHistory' => function ($q) {
                $q->with('changedBy:id,name')->orderByDesc('created_at')->limit(10);
            }]),
            'message' => $message,
        ]);
    }

    /**
     * Recalculates the costo_unitario of all corazones that use the given raw material.
     * Returns the IDs of corazones whose cost changed.
     */
    private function recalculateCorazonCosts(RawMaterial $rawMaterial): array
    {
        $affectedCorazonIds = CorazonFormulaLine::where('raw_material_id', $rawMaterial->id)
            ->pluck('corazon_id')
            ->unique();

        $updatedIds = [];

        foreach ($affectedCorazonIds as $corazonId) {
            $corazon = RawMaterial::find($corazonId);
            if (!$corazon || $corazon->tipo !== 'corazon') {
                continue;
            }

            $lines = CorazonFormulaLine::where('corazon_id', $corazonId)
                ->with('rawMaterial')
                ->get();

            $costoTotal = 0.0;
            foreach ($lines as $line) {
                $rm = $line->rawMaterial;
                if (!$rm) {
                    continue;
                }
                $factor      = $this->toKgFactor($rm->unidad);
                $costoTotal += ((float) $line->porcentaje / 100) * ((float) $rm->costo_unitario / $factor);
            }

            $costoTotal = round($costoTotal, 4);

            if (abs((float) $corazon->costo_unitario - $costoTotal) >= 0.0001) {
                $corazon->update(['costo_unitario' => $costoTotal]);
                $updatedIds[] = $corazonId;
            }
        }

        return $updatedIds;
    }

    private function recalculateReferencePrices(RawMaterial $rawMaterial): int
    {
        $affectedReferenceIds = ReferenceFormulaLine::where('raw_material_id', $rawMaterial->id)
            ->pluck('finearom_reference_id')
            ->unique();

        $updated = 0;

        foreach ($affectedReferenceIds as $refId) {
            $reference = FinearomReference::find($refId);
            if (!$reference) {
                continue;
            }

            $lines = ReferenceFormulaLine::where('finearom_reference_id', $refId)
                ->with('rawMaterial')
                ->get();

            $costoTotal = 0.0;
            foreach ($lines as $line) {
                $rm = $line->rawMaterial;
                if (!$rm) {
                    continue;
                }
                $factor      = $this->toKgFactor($rm->unidad);
                $costoTotal += ((float) $line->porcentaje / 100) * ((float) $rm->costo_unitario / $factor);
            }

            $costoTotal     = round($costoTotal, 4);
            $precioAnterior = (float) $reference->precio;

            if (abs($precioAnterior - $costoTotal) < 0.0001) {
                continue;
            }

            FinearomPriceHistory::create([
                'finearom_reference_id' => $reference->id,
                'precio_anterior'       => $precioAnterior,
                'precio_nuevo'          => $costoTotal,
                'changed_by'            => auth()->id(),
            ]);

            $reference->update(['precio' => $costoTotal]);
            $updated++;
        }

        return $updated;
    }

    private function toKgFactor(string $unidad): float
    {
        return match ($unidad) {
            'g'  => 1 / 1000,
            'ml' => 1 / 1000,
            default => 1.0,
        };
    }

    public function addMovement(Request $request, RawMaterial $rawMaterial): JsonResponse
    {
        $request->validate([
            'tipo'     => ['required', 'in:entrada,salida,ajuste'],
            'cantidad' => ['required', 'numeric', 'min:0.0001'],
            'notas'    => ['nullable', 'string'],
            'fecha'    => ['required', 'date'],
        ]);

        $tipo     = $request->tipo;
        $cantidad = (float) $request->cantidad;
        $stock    = (float) $rawMaterial->stock_disponible;

        if ($tipo === 'entrada') {
            $nuevoStock = $stock + $cantidad;
        } elseif ($tipo === 'salida') {
            if ($stock - $cantidad < 0) {
                return response()->json([
                    'message' => 'Stock insuficiente. Stock actual: ' . $stock . ' ' . $rawMaterial->unidad,
                ], 422);
            }
            $nuevoStock = $stock - $cantidad;
        } else {
            // ajuste: la cantidad es el nuevo valor absoluto
            $nuevoStock = $cantidad;
        }

        $movement = RawMaterialStockMovement::create([
            'raw_material_id' => $rawMaterial->id,
            'tipo'            => $tipo,
            'cantidad'        => $cantidad,
            'notas'           => $request->notas,
            'user_id'         => auth()->id(),
            'fecha'           => $request->fecha,
        ]);

        $rawMaterial->update(['stock_disponible' => $nuevoStock]);

        return response()->json([
            'data' => [
                'raw_material' => $rawMaterial->fresh(),
                'movement'     => $movement->load('user:id,name'),
            ],
            'message' => 'Movimiento registrado correctamente.',
        ], 201);
    }
}
