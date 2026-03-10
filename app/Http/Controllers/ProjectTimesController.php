<?php

namespace App\Http\Controllers;

use App\Models\TimeSample;
use App\Models\TimeApplication;
use App\Models\TimeEvaluation;
use App\Models\TimeMarketing;
use App\Models\TimeQuality;
use App\Models\TimeResponse;
use App\Models\TimeHomologation;
use App\Models\TimeFine;
use App\Models\GroupClassification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectTimesController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:project catalog manage');
    }

    public function samples(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => TimeSample::orderBy('tipo_cliente')->orderBy('rango_min')->get()]);
    }

    public function updateSample(Request $request, TimeSample $timeSample): JsonResponse
    {
        $request->validate(['valor' => 'required|numeric|min:0']);
        $timeSample->update(['valor' => $request->valor]);
        return response()->json(['success' => true, 'data' => $timeSample->fresh(), 'message' => 'Actualizado']);
    }

    public function applications(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => TimeApplication::with('product')->orderBy('tipo_cliente')->orderBy('rango_min')->get()]);
    }

    public function updateApplication(Request $request, TimeApplication $timeApplication): JsonResponse
    {
        $request->validate(['valor' => 'required|numeric|min:0']);
        $timeApplication->update(['valor' => $request->valor]);
        return response()->json(['success' => true, 'data' => $timeApplication->fresh(), 'message' => 'Actualizado']);
    }

    public function evaluations(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => TimeEvaluation::orderBy('solicitud')->orderBy('grupo')->get()]);
    }

    public function updateEvaluation(Request $request, TimeEvaluation $timeEvaluation): JsonResponse
    {
        $request->validate(['valor' => 'required|numeric|min:0']);
        $timeEvaluation->update(['valor' => $request->valor]);
        return response()->json(['success' => true, 'data' => $timeEvaluation->fresh(), 'message' => 'Actualizado']);
    }

    public function marketing(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => TimeMarketing::orderBy('solicitud')->orderBy('grupo')->get()]);
    }

    public function updateMarketing(Request $request, TimeMarketing $timeMarketing): JsonResponse
    {
        $request->validate(['valor' => 'required|numeric|min:0']);
        $timeMarketing->update(['valor' => $request->valor]);
        return response()->json(['success' => true, 'data' => $timeMarketing->fresh(), 'message' => 'Actualizado']);
    }

    public function quality(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => TimeQuality::orderBy('solicitud')->orderBy('grupo')->get()]);
    }

    public function updateQuality(Request $request, TimeQuality $timeQuality): JsonResponse
    {
        $request->validate(['valor' => 'required|numeric|min:0']);
        $timeQuality->update(['valor' => $request->valor]);
        return response()->json(['success' => true, 'data' => $timeQuality->fresh(), 'message' => 'Actualizado']);
    }

    public function responses(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => TimeResponse::orderBy('grupo')->orderBy('num_variantes_min')->get()]);
    }

    public function updateResponse(Request $request, TimeResponse $timeResponse): JsonResponse
    {
        $request->validate(['valor' => 'required|numeric|min:0']);
        $timeResponse->update(['valor' => $request->valor]);
        return response()->json(['success' => true, 'data' => $timeResponse->fresh(), 'message' => 'Actualizado']);
    }

    public function homologations(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => TimeHomologation::orderBy('grupo')->orderBy('num_variantes_min')->get()]);
    }

    public function updateHomologation(Request $request, TimeHomologation $timeHomologation): JsonResponse
    {
        $request->validate(['valor' => 'required|numeric|min:0']);
        $timeHomologation->update(['valor' => $request->valor]);
        return response()->json(['success' => true, 'data' => $timeHomologation->fresh(), 'message' => 'Actualizado']);
    }

    public function fine(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => TimeFine::orderBy('tipo_cliente')->orderBy('num_fragrances_min')->get()]);
    }

    public function updateFine(Request $request, TimeFine $timeFine): JsonResponse
    {
        $request->validate(['valor' => 'required|numeric|min:0']);
        $timeFine->update(['valor' => $request->valor]);
        return response()->json(['success' => true, 'data' => $timeFine->fresh(), 'message' => 'Actualizado']);
    }

    public function groupClassifications(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => GroupClassification::orderBy('tipo_cliente')->orderBy('rango_min')->get()]);
    }

    public function updateGroupClassification(Request $request, GroupClassification $groupClassification): JsonResponse
    {
        $request->validate(['valor' => 'required|integer|min:1|max:5']);
        $groupClassification->update(['valor' => $request->valor]);
        return response()->json(['success' => true, 'data' => $groupClassification->fresh(), 'message' => 'Actualizado']);
    }

    // ─── STORE methods ────────────────────────────────────────────────────────

    public function storeSample(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rango_min'    => 'required|numeric|min:0',
            'rango_max'    => 'required|numeric|gt:rango_min',
            'tipo_cliente' => ['required', Rule::in(['pareto', 'balance', 'none'])],
            'valor'        => 'required|integer|min:0',
        ]);
        $row = TimeSample::create($data);
        return response()->json(['success' => true, 'data' => $row, 'message' => 'Creado'], 201);
    }

    public function storeApplication(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rango_min'    => 'required|numeric|min:0',
            'rango_max'    => 'required|numeric|gt:rango_min',
            'tipo_cliente' => ['required', Rule::in(['pareto', 'balance', 'none'])],
            'product_id'   => 'nullable|exists:products,id',
            'valor'        => 'required|integer|min:0',
        ]);
        $row = TimeApplication::create($data);
        return response()->json(['success' => true, 'data' => $row->load('product'), 'message' => 'Creado'], 201);
    }

    public function storeEvaluation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'solicitud' => ['required', Rule::in(['En Cabina', 'Estabilidad', 'En uso', 'Triangular'])],
            'grupo'     => 'required|integer|min:1|max:6',
            'valor'     => 'required|numeric|min:0',
        ]);
        $row = TimeEvaluation::create($data);
        return response()->json(['success' => true, 'data' => $row, 'message' => 'Creado'], 201);
    }

    public function storeMarketing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'solicitud' => ['required', Rule::in([
                'Descripción Olfativa', 'Pirámide Olfativa', 'Caja', 'Presentación',
                'Presentación Cero', 'Dummie Digital', 'Dummie Fisico', 'Investigación De Mercado',
            ])],
            'grupo' => 'required|integer|min:1|max:4',
            'valor' => 'required|numeric|min:0',
        ]);
        $row = TimeMarketing::create($data);
        return response()->json(['success' => true, 'data' => $row, 'message' => 'Creado'], 201);
    }

    public function storeQuality(Request $request): JsonResponse
    {
        $data = $request->validate([
            'solicitud' => ['required', Rule::in([
                'MSDS Mezclas', 'Alergénos', 'IFRA', 'Ficha Técnica', 'Certificado De Solvente',
            ])],
            'grupo' => 'required|integer|min:1|max:6',
            'valor' => 'required|numeric|min:0',
        ]);
        $row = TimeQuality::create($data);
        return response()->json(['success' => true, 'data' => $row, 'message' => 'Creado'], 201);
    }

    public function storeResponse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grupo'              => 'required|integer|min:1|max:6',
            'num_variantes_min'  => 'required|integer|min:0',
            'num_variantes_max'  => 'required|integer|gte:num_variantes_min',
            'valor'              => 'required|integer|min:0',
        ]);
        $row = TimeResponse::create($data);
        return response()->json(['success' => true, 'data' => $row, 'message' => 'Creado'], 201);
    }

    public function storeHomologation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grupo'              => 'required|integer|min:1|max:6',
            'num_variantes_min'  => 'required|integer|min:0',
            'num_variantes_max'  => 'required|integer|gte:num_variantes_min',
            'valor'              => 'required|integer|min:0',
        ]);
        $row = TimeHomologation::create($data);
        return response()->json(['success' => true, 'data' => $row, 'message' => 'Creado'], 201);
    }

    public function storeFine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo_cliente'       => ['required', Rule::in(['pareto', 'balance', 'none'])],
            'num_fragrances_min' => 'required|integer|min:0',
            'num_fragrances_max' => 'required|integer|gte:num_fragrances_min',
            'valor'              => 'required|integer|min:0',
        ]);
        $row = TimeFine::create($data);
        return response()->json(['success' => true, 'data' => $row, 'message' => 'Creado'], 201);
    }

    public function storeGroupClassification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rango_min'    => 'required|numeric|min:0',
            'rango_max'    => 'required|numeric|gt:rango_min',
            'tipo_cliente' => ['required', Rule::in(['pareto', 'balance', 'none'])],
            'valor'        => 'required|integer|min:1|max:6',
        ]);
        $row = GroupClassification::create($data);
        return response()->json(['success' => true, 'data' => $row, 'message' => 'Creado'], 201);
    }

    // ─── DESTROY methods ──────────────────────────────────────────────────────

    public function destroySample(TimeSample $timeSample): JsonResponse
    {
        $timeSample->delete();
        return response()->json(['success' => true, 'message' => 'Eliminado']);
    }

    public function destroyApplication(TimeApplication $timeApplication): JsonResponse
    {
        $timeApplication->delete();
        return response()->json(['success' => true, 'message' => 'Eliminado']);
    }

    public function destroyEvaluation(TimeEvaluation $timeEvaluation): JsonResponse
    {
        $timeEvaluation->delete();
        return response()->json(['success' => true, 'message' => 'Eliminado']);
    }

    public function destroyMarketing(TimeMarketing $timeMarketing): JsonResponse
    {
        $timeMarketing->delete();
        return response()->json(['success' => true, 'message' => 'Eliminado']);
    }

    public function destroyQuality(TimeQuality $timeQuality): JsonResponse
    {
        $timeQuality->delete();
        return response()->json(['success' => true, 'message' => 'Eliminado']);
    }

    public function destroyResponse(TimeResponse $timeResponse): JsonResponse
    {
        $timeResponse->delete();
        return response()->json(['success' => true, 'message' => 'Eliminado']);
    }

    public function destroyHomologation(TimeHomologation $timeHomologation): JsonResponse
    {
        $timeHomologation->delete();
        return response()->json(['success' => true, 'message' => 'Eliminado']);
    }

    public function destroyFine(TimeFine $timeFine): JsonResponse
    {
        $timeFine->delete();
        return response()->json(['success' => true, 'message' => 'Eliminado']);
    }

    public function destroyGroupClassification(GroupClassification $groupClassification): JsonResponse
    {
        $groupClassification->delete();
        return response()->json(['success' => true, 'message' => 'Eliminado']);
    }
}
