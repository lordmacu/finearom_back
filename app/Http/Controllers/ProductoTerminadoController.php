<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductoTerminado\ProductoTerminadoStoreRequest;
use App\Http\Requests\ProductoTerminado\ProductoTerminadoUpdateRequest;
use App\Models\ProductoTerminado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductoTerminadoController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:producto terminado list')->only(['index', 'show']);
        $this->middleware('can:producto terminado create')->only(['store']);
        $this->middleware('can:producto terminado edit')->only(['update', 'activate', 'deactivate']);
        $this->middleware('can:producto terminado delete')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ProductoTerminado::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%")
                  ->orWhere('consecutivo', 'like', "%{$search}%");
            });
        }

        $activo = $request->input('activo', 'all');
        if ($activo !== 'all') {
            $query->where('activo', filter_var($activo, FILTER_VALIDATE_BOOLEAN));
        }

        // Productos que usan materias primas provisionales (320/330) a cambiar por su equivalente 300
        if ($request->boolean('pendientes')) {
            $query->whereHas('formulaLines.rawMaterial', fn ($q) => $q->where('pendiente_equivalencia', true));
        }

        $query->withSum('formulaLines', 'porcentaje')
              ->withCount(['formulaLines as pendientes_count' => fn ($q) => $q->whereHas('rawMaterial', fn ($r) => $r->where('pendiente_equivalencia', true))])
              ->orderByRaw('CAST(codigo AS UNSIGNED), codigo');

        $perPage = min((int) $request->input('per_page', 30), 1000);
        $productos = $query->paginate($perPage);

        $productos->getCollection()->transform(function (ProductoTerminado $producto) {
            $producto->setAttribute(
                'suma_porcentajes',
                round((float) $producto->formula_lines_sum_porcentaje, 4)
            );
            return $producto;
        });

        return response()->json([
            'data'    => $productos,
            'message' => 'OK',
        ]);
    }

    public function store(ProductoTerminadoStoreRequest $request): JsonResponse
    {
        $producto = ProductoTerminado::create([
            ...$request->validated(),
            'activo' => false,
        ]);

        $producto->update(['consecutivo' => 'PT-' . str_pad($producto->id, 4, '0', STR_PAD_LEFT)]);

        return response()->json([
            'data'    => $producto,
            'message' => 'Producto terminado creado correctamente.',
        ], 201);
    }

    public function show(ProductoTerminado $productoTerminado): JsonResponse
    {
        $productoTerminado->load('formulaLines.rawMaterial');
        $productoTerminado->setAttribute(
            'suma_porcentajes',
            round((float) $productoTerminado->formulaLines()->sum('porcentaje'), 4)
        );

        return response()->json([
            'data'    => $productoTerminado,
            'message' => 'OK',
        ]);
    }

    public function update(ProductoTerminadoUpdateRequest $request, ProductoTerminado $productoTerminado): JsonResponse
    {
        $productoTerminado->update($request->validated());

        return response()->json([
            'data'    => $productoTerminado->fresh(),
            'message' => 'Producto terminado actualizado correctamente.',
        ]);
    }

    public function activate(ProductoTerminado $productoTerminado): JsonResponse
    {
        $suma = (float) $productoTerminado->formulaLines()->sum('porcentaje');

        if (abs($suma - 100) >= 0.01) {
            return response()->json([
                'message' => "La suma de porcentajes debe ser 100% para activar este producto terminado. Actual: " . round($suma, 2) . "%.",
            ], 422);
        }

        $productoTerminado->update(['activo' => true]);

        return response()->json([
            'data'    => $productoTerminado->fresh(),
            'message' => 'Producto terminado activado correctamente.',
        ]);
    }

    public function deactivate(ProductoTerminado $productoTerminado): JsonResponse
    {
        $productoTerminado->update(['activo' => false]);

        return response()->json([
            'data'    => $productoTerminado->fresh(),
            'message' => 'Producto terminado pasado a borrador.',
        ]);
    }

    public function destroy(ProductoTerminado $productoTerminado): JsonResponse
    {
        $productoTerminado->delete();

        return response()->json([
            'data'    => null,
            'message' => 'Producto terminado eliminado correctamente.',
        ]);
    }
}
