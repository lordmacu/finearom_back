<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinearomReference\FinearomReferenceStoreRequest;
use App\Http\Requests\FinearomReference\FinearomReferenceUpdateRequest;
use App\Models\FinearomReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinearomReferenceController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:finearom reference list')->only(['index', 'show']);
        $this->middleware('can:finearom reference create')->only(['store']);
        $this->middleware('can:finearom reference edit')->only(['update', 'updatePrice']);
        $this->middleware('can:finearom reference delete')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = FinearomReference::query()->with(['evaluations']);

        // Filtro por tipo_producto
        if ($request->filled('tipo_producto')) {
            $query->where('tipo_producto', $request->tipo_producto);
        }

        // Filtro de búsqueda en codigo y nombre
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('codigo', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%");
            });
        }

        // Ordenamiento
        $sortBy = $request->input('sort_by', 'nombre');
        $allowedSorts = ['puntaje_promedio', 'nombre', 'codigo'];

        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'nombre';
        }

        if ($sortBy === 'puntaje_promedio') {
            // Ordenar por el mejor puntaje_promedio de sus evaluaciones (desc)
            $query->withMax('evaluations', 'puntaje_promedio')
                  ->orderByDesc('evaluations_max_puntaje_promedio');
        } else {
            $query->orderBy($sortBy);
        }

        $references = $query->paginate(30);

        // Agregar mejor_puntaje a cada referencia
        $references->getCollection()->transform(function (FinearomReference $reference) {
            $reference->mejor_puntaje = $reference->evaluations
                ->max('puntaje_promedio');

            return $reference;
        });

        return response()->json([
            'data'    => $references,
            'message' => 'OK',
        ]);
    }

    public function store(FinearomReferenceStoreRequest $request): JsonResponse
    {
        $reference = FinearomReference::create($request->validated());

        return response()->json([
            'data'    => $reference,
            'message' => 'Referencia creada correctamente',
        ], 201);
    }

    public function show(FinearomReference $finearomReference): JsonResponse
    {
        $finearomReference->load([
            'evaluations.evaluadoPor:id,name',
        ]);

        return response()->json([
            'data'    => $finearomReference,
            'message' => 'OK',
        ]);
    }

    public function update(FinearomReferenceUpdateRequest $request, FinearomReference $finearomReference): JsonResponse
    {
        $finearomReference->update($request->validated());

        return response()->json([
            'data'    => $finearomReference,
            'message' => 'Referencia actualizada correctamente',
        ]);
    }

    public function destroy(FinearomReference $finearomReference): JsonResponse
    {
        $finearomReference->delete();

        return response()->json([
            'message' => 'Referencia eliminada correctamente',
        ]);
    }

    public function updatePrice(Request $request, FinearomReference $finearomReference): JsonResponse
    {
        $request->validate([
            'precio' => ['required', 'numeric', 'min:0'],
        ]);

        $precioAnterior = $finearomReference->precio;
        $precioNuevo    = $request->precio;

        $finearomReference->priceHistory()->create([
            'precio_anterior'       => $precioAnterior,
            'precio_nuevo'          => $precioNuevo,
            'finearom_reference_id' => $finearomReference->id,
            'changed_by'            => auth()->id(),
        ]);

        $finearomReference->update(['precio' => $precioNuevo]);

        return response()->json([
            'data'    => $finearomReference->fresh(),
            'message' => 'Precio actualizado correctamente',
        ]);
    }
}
