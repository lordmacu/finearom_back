<?php

namespace App\Http\Controllers;

use App\Models\ProductoFormulaLine;
use App\Models\ProductoTerminado;
use App\Services\ProductoCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductoFormulaController extends Controller
{
    public function __construct(
        private readonly ProductoCostService $costos
    ) {
        $this->middleware('can:producto terminado edit');
    }

    public function index(ProductoTerminado $productoTerminado): JsonResponse
    {
        $lines = $productoTerminado->formulaLines()->with(['rawMaterial', 'ingredienteProducto'])->get();

        $costoTotalKg = 0.0;
        $lines = $lines->map(function (ProductoFormulaLine $line) use (&$costoTotalKg) {
            $aporte = ((float) $line->porcentaje / 100) * $this->costos->costoKgLinea($line);
            $line->setAttribute('costo_aporte', round($aporte, 4));
            $costoTotalKg += $aporte;

            // Datos del ingrediente ya normalizados, sea materia prima, corazón o producto
            if ($line->ingredienteProducto) {
                $line->setAttribute('tipo_ingrediente', 'producto_terminado');
                $line->setAttribute('ingrediente_codigo', $line->ingredienteProducto->codigo);
                $line->setAttribute('ingrediente_nombre', $line->ingredienteProducto->nombre);
            } else {
                $line->setAttribute('tipo_ingrediente', $line->rawMaterial?->tipo);
                $line->setAttribute('ingrediente_codigo', $line->rawMaterial?->codigo);
                $line->setAttribute('ingrediente_nombre', $line->rawMaterial?->nombre);
            }

            return $line;
        });

        return response()->json([
            'data' => [
                'lines'            => $lines,
                'costo_total_kg'   => round($costoTotalKg, 4),
                'suma_porcentajes' => round($lines->sum(fn($l) => (float) $l->porcentaje), 4),
            ],
            'message' => 'OK',
        ]);
    }

    public function store(Request $request, ProductoTerminado $productoTerminado): JsonResponse
    {
        $validated = $request->validate([
            'raw_material_id'         => ['required_without:ingrediente_producto_id', 'nullable', 'integer', 'exists:raw_materials,id'],
            'ingrediente_producto_id' => ['required_without:raw_material_id', 'nullable', 'integer', 'exists:productos_terminados,id'],
            'porcentaje'              => ['required', 'numeric', 'min:0.0001', 'max:100'],
            'notas'                   => ['nullable', 'string'],
        ]);

        if (!empty($validated['raw_material_id']) && !empty($validated['ingrediente_producto_id'])) {
            return response()->json(['message' => 'Indica una materia prima/corazón o un producto terminado, no los dos.'], 422);
        }

        $ingredienteProductoId = $validated['ingrediente_producto_id'] ?? null;
        if ($ingredienteProductoId) {
            if ((int) $ingredienteProductoId === $productoTerminado->id) {
                return response()->json(['message' => 'Un producto terminado no puede ser ingrediente de sí mismo.'], 422);
            }
            if ($this->costos->crearCiclo($productoTerminado->id, (int) $ingredienteProductoId)) {
                return response()->json(['message' => 'Ese producto ya contiene a este (directa o indirectamente): agregarlo crearía un ciclo.'], 422);
            }
        }

        $line = ProductoFormulaLine::updateOrCreate(
            [
                'producto_terminado_id'   => $productoTerminado->id,
                'raw_material_id'         => $ingredienteProductoId ? null : $validated['raw_material_id'],
                'ingrediente_producto_id' => $ingredienteProductoId,
            ],
            [
                'porcentaje' => $validated['porcentaje'],
                'notas'      => $validated['notas'] ?? null,
            ]
        );

        $line->load(['rawMaterial', 'ingredienteProducto']);

        $this->costos->recalcular($productoTerminado);

        return response()->json([
            'data'    => $line,
            'message' => $line->wasRecentlyCreated
                ? 'Ingrediente agregado correctamente.'
                : 'Ingrediente actualizado correctamente.',
        ], $line->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(ProductoTerminado $productoTerminado, ProductoFormulaLine $productoFormulaLine): JsonResponse
    {
        if ($productoFormulaLine->producto_terminado_id !== $productoTerminado->id) {
            return response()->json(['message' => 'El ingrediente no pertenece a este producto terminado.'], 422);
        }

        $productoFormulaLine->delete();

        $this->costos->recalcular($productoTerminado);

        return response()->json(['message' => 'Ingrediente eliminado correctamente.']);
    }
}
