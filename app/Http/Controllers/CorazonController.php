<?php

namespace App\Http\Controllers;

use App\Http\Requests\Corazon\CorazonStoreRequest;
use App\Http\Requests\Corazon\CorazonUpdateRequest;
use App\Models\RawMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorazonController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:raw material list')->only(['index', 'show']);
        $this->middleware('can:raw material create')->only(['store']);
        $this->middleware('can:raw material edit')->only(['update', 'activate', 'deactivate']);
        $this->middleware('can:raw material delete')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = RawMaterial::where('tipo', 'corazon');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%");
            });
        }

        $activo = $request->input('activo', 'all');
        if ($activo !== 'all') {
            $query->where('activo', filter_var($activo, FILTER_VALIDATE_BOOLEAN));
        }

        $query->withSum('corazonComponents', 'porcentaje')->orderByRaw('CAST(codigo AS UNSIGNED), codigo');

        $perPage = min((int) $request->input('per_page', 30), 1000);
        $corazones = $query->paginate($perPage);

        $corazones->getCollection()->transform(function (RawMaterial $corazon) {
            $corazon->setAttribute(
                'suma_porcentajes',
                round((float) $corazon->corazon_components_sum_porcentaje, 4)
            );
            return $corazon;
        });

        return response()->json([
            'data'    => $corazones,
            'message' => 'OK',
        ]);
    }

    public function store(CorazonStoreRequest $request): JsonResponse
    {
        $corazon = RawMaterial::create([
            ...$request->validated(),
            'tipo'   => 'corazon',
            'unidad' => 'kg',
            'activo' => false,
        ]);

        return response()->json([
            'data'    => $corazon,
            'message' => 'Corazón creado correctamente.',
        ], 201);
    }

    public function show(RawMaterial $corazon): JsonResponse
    {
        abort_if($corazon->tipo !== 'corazon', 404);

        $corazon->load('corazonComponents.rawMaterial');
        $corazon->setAttribute(
            'suma_porcentajes',
            round((float) $corazon->corazonComponents()->sum('porcentaje'), 4)
        );

        return response()->json([
            'data'    => $corazon,
            'message' => 'OK',
        ]);
    }

    public function update(CorazonUpdateRequest $request, RawMaterial $corazon): JsonResponse
    {
        abort_if($corazon->tipo !== 'corazon', 404);

        $corazon->update($request->validated());

        return response()->json([
            'data'    => $corazon->fresh(),
            'message' => 'Corazón actualizado correctamente.',
        ]);
    }

    public function activate(RawMaterial $corazon): JsonResponse
    {
        abort_if($corazon->tipo !== 'corazon', 404);

        $suma = (float) $corazon->corazonComponents()->sum('porcentaje');

        if (abs($suma - 100) >= 0.01) {
            return response()->json([
                'message' => "La suma de porcentajes debe ser 100% para activar este corazón. Actual: " . round($suma, 2) . "%.",
            ], 422);
        }

        $corazon->update(['activo' => true]);

        return response()->json([
            'data'    => $corazon->fresh(),
            'message' => 'Corazón activado correctamente.',
        ]);
    }

    public function deactivate(RawMaterial $corazon): JsonResponse
    {
        abort_if($corazon->tipo !== 'corazon', 404);

        $corazon->update(['activo' => false]);

        return response()->json([
            'data'    => $corazon->fresh(),
            'message' => 'Corazón pasado a borrador.',
        ]);
    }

    public function destroy(RawMaterial $corazon): JsonResponse
    {
        abort_if($corazon->tipo !== 'corazon', 404);

        $usedElsewhere = $corazon->usedInCorazones()->exists()
            || $corazon->formulaLines()->exists()
            || $corazon->usedInProductosTerminados()->exists();

        if ($usedElsewhere) {
            return response()->json([
                'message' => 'No se puede eliminar: este corazón se usa como ingrediente en otra fórmula.',
            ], 422);
        }

        $hasMovements = $corazon->stockMovements()->exists();

        if ($hasMovements) {
            $corazon->update(['activo' => false]);

            return response()->json([
                'data'    => $corazon->fresh(),
                'message' => 'El corazón tiene movimientos de stock; se marcó como inactivo.',
            ]);
        }

        $corazon->delete();

        return response()->json([
            'data'    => null,
            'message' => 'Corazón eliminado correctamente.',
        ]);
    }
}
