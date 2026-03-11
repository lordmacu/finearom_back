<?php

namespace App\Http\Controllers;

use App\Models\ContributionMargin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContributionMarginController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:settings manage')->only(['index', 'store', 'update', 'destroy']);
        $this->middleware('can:project list')->only(['lookup']);
    }

    /// Retorna todos los márgenes agrupados por tipo_cliente, ordenados por volumen_min.
    public function index(): JsonResponse
    {
        $margins = ContributionMargin::orderBy('tipo_cliente')
            ->orderBy('volumen_min')
            ->get();

        $grouped = $margins->groupBy('tipo_cliente');

        return response()->json(['data' => $grouped], 200);
    }

    /// Crea un nuevo registro de margen de contribución.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo_cliente' => 'required|in:pareto,balance,none',
            'volumen_min'  => 'required|integer|min:0',
            'volumen_max'  => 'nullable|integer|min:0|gt:volumen_min',
            'factor'       => 'required|numeric|min:0.1|max:99',
            'descripcion'  => 'nullable|string|max:255',
            'activo'       => 'boolean',
        ]);

        $margin = ContributionMargin::create($validated);

        return response()->json(['data' => $margin, 'message' => 'Margen creado'], 201);
    }

    /// Actualiza un registro de margen de contribución existente.
    public function update(Request $request, ContributionMargin $contributionMargin): JsonResponse
    {
        $validated = $request->validate([
            'tipo_cliente' => 'sometimes|required|in:pareto,balance,none',
            'volumen_min'  => 'sometimes|required|integer|min:0',
            'volumen_max'  => 'nullable|integer|min:0',
            'factor'       => 'sometimes|required|numeric|min:0.1|max:99',
            'descripcion'  => 'nullable|string|max:255',
            'activo'       => 'boolean',
        ]);

        $contributionMargin->update($validated);

        return response()->json(['data' => $contributionMargin->fresh(), 'message' => 'Margen actualizado'], 200);
    }

    /// Elimina un registro de margen de contribución.
    public function destroy(ContributionMargin $contributionMargin): JsonResponse
    {
        $contributionMargin->delete();

        return response()->json(['message' => 'Margen eliminado'], 200);
    }

    /// Consulta el factor para un tipo_cliente y volumen dados.
    /// Usado por el frontend de proyectos para auto-rellenar el campo factor.
    /// GET /contribution-margins/lookup?tipo_cliente=pareto&volumen=100
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'tipo_cliente' => 'required|in:pareto,balance,none',
            'volumen'      => 'required|integer|min:0',
        ]);

        $factor = ContributionMargin::getFactorFor(
            $request->tipo_cliente,
            (int) $request->volumen
        );

        return response()->json(['factor' => $factor], 200);
    }
}
