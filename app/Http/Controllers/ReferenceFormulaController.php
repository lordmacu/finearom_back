<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReferenceFormulaLine\ReferenceFormulaLineRequest;
use App\Models\FinearomReference;
use App\Models\ReferenceFormulaLine;
use Illuminate\Http\JsonResponse;

class ReferenceFormulaController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:raw material edit');
    }

    /**
     * Factores de conversión a kg.
     * Usados para normalizar stock_disponible y costo_unitario al calcular
     * cuánto de cada materia prima (expresada en su unidad) se necesita por kg de referencia.
     */
    private function toKgFactor(string $unidad): float
    {
        return match ($unidad) {
            'g'  => 1 / 1000,
            'ml' => 1 / 1000,
            default => 1.0, // kg, L, un
        };
    }

    public function index(FinearomReference $finearomReference): JsonResponse
    {
        $lines = $finearomReference->formulaLines()
            ->with('rawMaterial')
            ->get();

        $costoTotalKg = 0.0;
        $disponibilidades = [];

        $lines = $lines->map(function (ReferenceFormulaLine $line) use (&$costoTotalKg, &$disponibilidades) {
            $rm         = $line->rawMaterial;
            $porcentaje = (float) $line->porcentaje;
            $fraccion   = $porcentaje / 100;

            if (!$rm) {
                $line->setAttribute('costo_por_kg', 0);
                return $line;
            }

            $factor       = $this->toKgFactor($rm->unidad);
            $costoUnitKg  = (float) $rm->costo_unitario / $factor; // costo por kg equivalente
            $costoPorKg   = $fraccion * $costoUnitKg;

            $line->setAttribute('costo_por_kg', round($costoPorKg, 4));
            $costoTotalKg += $costoPorKg;

            // Disponibilidad: cuántos kg de referencia se pueden fabricar con este stock
            if ($fraccion > 0) {
                $stockEnKg       = (float) $rm->stock_disponible * $factor;
                $disponibilidades[] = $stockEnKg / $fraccion;
            }

            return $line;
        });

        $disponibilidadKg = count($disponibilidades) > 0
            ? round(min($disponibilidades), 4)
            : null;

        return response()->json([
            'data' => [
                'lines'                      => $lines,
                'costo_total_kg_referencia'  => round($costoTotalKg, 4),
                'disponibilidad_kg'          => $disponibilidadKg,
            ],
            'message' => 'OK',
        ]);
    }

    public function store(ReferenceFormulaLineRequest $request, FinearomReference $finearomReference): JsonResponse
    {
        $validated = $request->validated();
        $validated['finearom_reference_id'] = $finearomReference->id;

        $line = ReferenceFormulaLine::updateOrCreate(
            [
                'finearom_reference_id' => $finearomReference->id,
                'raw_material_id'       => $validated['raw_material_id'],
            ],
            [
                'porcentaje' => $validated['porcentaje'],
                'notas'      => $validated['notas'] ?? null,
            ]
        );

        $line->load('rawMaterial');

        return response()->json([
            'data'    => $line,
            'message' => $line->wasRecentlyCreated
                ? 'Línea de fórmula creada correctamente.'
                : 'Línea de fórmula actualizada correctamente.',
        ], $line->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(FinearomReference $finearomReference, ReferenceFormulaLine $referenceFormulaLine): JsonResponse
    {
        if ($referenceFormulaLine->finearom_reference_id !== $finearomReference->id) {
            return response()->json(['message' => 'La línea no pertenece a esta referencia.'], 422);
        }

        $referenceFormulaLine->delete();

        return response()->json([
            'data'    => null,
            'message' => 'Línea de fórmula eliminada correctamente.',
        ]);
    }
}
