<?php

namespace App\Http\Controllers;

use App\Http\Requests\Project\ProjectMarketingVariantReferenceRequest;
use App\Models\Project;
use App\Models\ProjectMarketingVariant;
use App\Support\MarketingVariantPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Referencias de una variante de Marketing. Pertenecen a Desarrollo.
 *
 * Es un sync completo: llega el array entero y reemplaza lo que había.
 * Como Desarrollo es dueño de todas las columnas de la tabla, borrar y
 * recrear no pisa trabajo de nadie más.
 */
class ProjectMarketingVariantReferenceController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:' . MarketingVariantPermissions::TECHNICAL);
    }

    public function sync(
        ProjectMarketingVariantReferenceRequest $request,
        Project $project,
        ProjectMarketingVariant $variant,
    ): JsonResponse {
        abort_if($variant->project_id !== $project->id, 404);

        // Solo el ingeniero de desarrollo asignado al proyecto crea referencias
        // — sin excepción de rol (ni admin/super-admin/Administrador).
        $user = auth()->user();
        if ((int) $project->desarrollador_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Solo puedes crear referencias en proyectos asignados a ti.',
            ], 403);
        }

        DB::transaction(function () use ($request, $variant) {
            $variant->references()->delete();

            foreach (array_values($request->validated()['referencias']) as $orden => $referencia) {
                $variant->references()->create([
                    'referencia' => $referencia['referencia'] ?? null,
                    'codigo'     => $referencia['codigo'] ?? null,
                    'aplicacion' => $referencia['aplicacion'] ?? null,
                    'dosis'      => $referencia['dosis'] ?? null,
                    'orden'      => $orden,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'data'    => $variant->load('references'),
            'message' => 'Referencias actualizadas',
        ]);
    }
}
