<?php

namespace App\Http\Controllers;

use App\Models\ProductoFormulaLine;
use App\Models\ProductoTerminado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductoFormulaController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:producto terminado edit');
    }

    private function toKgFactor(string $unidad): float
    {
        return match ($unidad) {
            'g'  => 1 / 1000,
            'ml' => 1 / 1000,
            default => 1.0,
        };
    }

    public function index(ProductoTerminado $productoTerminado): JsonResponse
    {
        $lines = $productoTerminado->formulaLines()->with('rawMaterial')->get();

        $costoTotalKg = 0.0;
        $lines = $lines->map(function (ProductoFormulaLine $line) use (&$costoTotalKg) {
            $rm       = $line->rawMaterial;
            $fraccion = (float) $line->porcentaje / 100;

            if (!$rm) {
                $line->setAttribute('costo_aporte', 0);
                return $line;
            }

            $factor      = $this->toKgFactor($rm->unidad);
            $costoUnitKg = (float) $rm->costo_unitario / $factor;
            $aporte      = $fraccion * $costoUnitKg;

            $line->setAttribute('costo_aporte', round($aporte, 4));
            $costoTotalKg += $aporte;

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
            'raw_material_id' => ['required', 'integer', 'exists:raw_materials,id'],
            'porcentaje'      => ['required', 'numeric', 'min:0.0001', 'max:100'],
            'notas'           => ['nullable', 'string'],
        ]);

        $line = ProductoFormulaLine::updateOrCreate(
            [
                'producto_terminado_id' => $productoTerminado->id,
                'raw_material_id'       => $validated['raw_material_id'],
            ],
            [
                'porcentaje' => $validated['porcentaje'],
                'notas'      => $validated['notas'] ?? null,
            ]
        );

        $line->load('rawMaterial');

        $this->updateProductoCosto($productoTerminado);

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

        $this->updateProductoCosto($productoTerminado);

        return response()->json(['message' => 'Ingrediente eliminado correctamente.']);
    }

    private function updateProductoCosto(ProductoTerminado $producto): void
    {
        $lines = $producto->formulaLines()->with('rawMaterial')->get();

        $costoTotal = 0.0;
        foreach ($lines as $line) {
            $rm = $line->rawMaterial;
            if (!$rm) {
                continue;
            }
            $factor      = $this->toKgFactor($rm->unidad);
            $costoTotal += ((float) $line->porcentaje / 100) * ((float) $rm->costo_unitario / $factor);
        }

        $producto->update(['costo_unitario' => round($costoTotal, 4)]);
    }
}
