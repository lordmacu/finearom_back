<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinearomEvaluation\FinearomEvaluationStoreRequest;
use App\Http\Requests\FinearomEvaluation\FinearomEvaluationUpdateRequest;
use App\Models\FinearomEvaluation;
use App\Models\FinearomReference;
use Illuminate\Http\JsonResponse;

class FinearomEvaluationController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:finearom reference edit')->only(['store', 'update', 'destroy']);
    }

    public function store(FinearomEvaluationStoreRequest $request, FinearomReference $finearomReference): JsonResponse
    {
        $data = $request->validated();
        $data['finearom_reference_id'] = $finearomReference->id;
        $data['evaluado_por']          = auth()->id();

        // Auto-calcular puntaje_promedio si no se envía y ambos puntajes están presentes
        if (
            !isset($data['puntaje_promedio']) &&
            isset($data['puntaje_agradabilidad']) &&
            isset($data['puntaje_intensidad']) &&
            $data['puntaje_agradabilidad'] !== null &&
            $data['puntaje_intensidad'] !== null
        ) {
            $data['puntaje_promedio'] = round(
                ($data['puntaje_agradabilidad'] + $data['puntaje_intensidad']) / 2,
                1
            );
        }

        $evaluation = FinearomEvaluation::create($data);
        $evaluation->load('evaluadoPor:id,name');

        return response()->json([
            'data'    => $evaluation,
            'message' => 'Evaluación creada correctamente',
        ], 201);
    }

    public function update(
        FinearomEvaluationUpdateRequest $request,
        FinearomReference $finearomReference,
        FinearomEvaluation $finearomEvaluation
    ): JsonResponse {
        $data = $request->validated();

        // Recalcular puntaje_promedio si no se envía explícitamente
        $agradabilidad = $data['puntaje_agradabilidad'] ?? $finearomEvaluation->puntaje_agradabilidad;
        $intensidad    = $data['puntaje_intensidad']    ?? $finearomEvaluation->puntaje_intensidad;

        if (!array_key_exists('puntaje_promedio', $data) && $agradabilidad !== null && $intensidad !== null) {
            $data['puntaje_promedio'] = round(((float) $agradabilidad + (float) $intensidad) / 2, 1);
        }

        $finearomEvaluation->update($data);
        $finearomEvaluation->load('evaluadoPor:id,name');

        return response()->json([
            'data'    => $finearomEvaluation,
            'message' => 'Evaluación actualizada correctamente',
        ]);
    }

    public function destroy(FinearomReference $finearomReference, FinearomEvaluation $finearomEvaluation): JsonResponse
    {
        $finearomEvaluation->delete();

        return response()->json([
            'message' => 'Evaluación eliminada correctamente',
        ]);
    }
}
