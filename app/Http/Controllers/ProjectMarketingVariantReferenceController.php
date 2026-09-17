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
 * Es un sync completo: llega el array entero. Las que traen `id` se
 * actualizan en su lugar (Potencial a la vista cuelga datos de la ejecutiva
 * de cada referencia), las nuevas se crean y las que no vienen se borran.
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
            $referencias = array_values($request->validated()['referencias']);
            $existentes  = $variant->references()->get()->keyBy('id');
            $conservadas = [];

            foreach ($referencias as $orden => $referencia) {
                $datos = [
                    'referencia' => $referencia['referencia'] ?? null,
                    'codigo'     => $referencia['codigo'] ?? null,
                    'aplicacion' => $referencia['aplicacion'] ?? null,
                    'dosis'      => $referencia['dosis'] ?? null,
                    'precio'     => $referencia['precio'] ?? null,
                    'orden'      => $orden,
                ];

                // Un id ajeno a esta variante se trata como referencia nueva
                $actual = $existentes->get($referencia['id'] ?? null);
                if ($actual) {
                    $actual->update($datos);
                    $conservadas[] = $actual->id;
                } else {
                    $conservadas[] = $variant->references()->create($datos)->id;
                }
            }

            $variant->references()->whereNotIn('id', $conservadas)->delete();
        });

        return response()->json([
            'success' => true,
            'data'    => $variant->load('references'),
            'message' => 'Referencias actualizadas',
        ]);
    }
}
