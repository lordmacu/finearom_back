<?php

namespace App\Http\Controllers;

use App\Http\Requests\RawMaterial\RawMaterialStoreRequest;
use App\Http\Requests\RawMaterial\RawMaterialUpdateRequest;
use App\Models\RawMaterial;
use App\Models\RawMaterialPriceHistory;
use App\Models\RawMaterialStockMovement;
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

        $materials = $query->paginate(30);

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

        return response()->json([
            'data'    => $rawMaterial->fresh()->load(['priceHistory' => function ($q) {
                $q->with('changedBy:id,name')->orderByDesc('created_at')->limit(10);
            }]),
            'message' => 'Costo actualizado correctamente.',
        ]);
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
