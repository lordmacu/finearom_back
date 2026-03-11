<?php

namespace App\Http\Controllers;

use App\Models\CorazonFormulaLine;
use App\Models\RawMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorazonFormulaController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:raw material edit');
    }

    private function toKgFactor(string $unidad): float
    {
        return match ($unidad) {
            'g'  => 1 / 1000,
            'ml' => 1 / 1000,
            default => 1.0,
        };
    }

    /**
     * Lista los ingredientes de un corazón con costo calculado y costo total.
     */
    public function index(RawMaterial $rawMaterial): JsonResponse
    {
        abort_if($rawMaterial->tipo !== 'corazon', 422, 'Esta materia prima no es un corazón.');

        $lines = $rawMaterial->corazonComponents()->with('rawMaterial')->get();

        $costoTotalKg = 0.0;
        $lines = $lines->map(function (CorazonFormulaLine $line) use (&$costoTotalKg) {
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
                'lines'              => $lines,
                'costo_total_kg'     => round($costoTotalKg, 4),
                'suma_porcentajes'   => round($lines->sum(fn($l) => (float) $l->porcentaje), 4),
            ],
            'message' => 'OK',
        ]);
    }

    /**
     * Agrega o actualiza un ingrediente en la fórmula del corazón.
     */
    public function store(Request $request, RawMaterial $rawMaterial): JsonResponse
    {
        abort_if($rawMaterial->tipo !== 'corazon', 422, 'Esta materia prima no es un corazón.');

        $validated = $request->validate([
            'raw_material_id' => ['required', 'integer', 'exists:raw_materials,id'],
            'porcentaje'      => ['required', 'numeric', 'min:0.0001', 'max:100'],
            'notas'           => ['nullable', 'string'],
        ]);

        abort_if(
            (int) $validated['raw_material_id'] === $rawMaterial->id,
            422,
            'Un corazón no puede ser ingrediente de sí mismo.'
        );

        $line = CorazonFormulaLine::updateOrCreate(
            [
                'corazon_id'      => $rawMaterial->id,
                'raw_material_id' => $validated['raw_material_id'],
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
                ? 'Ingrediente agregado correctamente.'
                : 'Ingrediente actualizado correctamente.',
        ], $line->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Elimina un ingrediente de la fórmula del corazón.
     */
    public function destroy(RawMaterial $rawMaterial, CorazonFormulaLine $corazonFormulaLine): JsonResponse
    {
        abort_if($rawMaterial->tipo !== 'corazon', 422, 'Esta materia prima no es un corazón.');

        if ($corazonFormulaLine->corazon_id !== $rawMaterial->id) {
            return response()->json(['message' => 'El ingrediente no pertenece a este corazón.'], 422);
        }

        $corazonFormulaLine->delete();

        return response()->json(['message' => 'Ingrediente eliminado correctamente.']);
    }
}
