<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;

class ProjectLabelController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:project list');
    }

    public function aplicacion(Project $project): JsonResponse
    {
        $items = match ($project->tipo) {
            'Colección'      => $project->requests()->with('fragrance')->get()->map(fn ($r) => [
                'codigo'           => $r->fragrance?->codigo,
                'nombre_fragancia' => $r->fragrance?->nombre,
                'dosis'            => $r->porcentaje,
                'tipo'             => 'Colección',
                'tipo_producto'    => $project->tipo_producto,
                'fecha'            => optional($r->created_at)->format('Y-m-d'),
            ]),
            'Desarrollo'     => $project->variants()->with('proposals.finearomReference')->get()->flatMap(fn ($v) => $v->proposals->map(fn ($p) => [
                'codigo'           => $p->finearomReference?->codigo,
                'nombre_fragancia' => $v->nombre,
                'dosis'            => null,
                'tipo'             => 'Desarrollo',
                'tipo_producto'    => $project->tipo_producto,
                'fecha'            => optional($p->created_at)->format('Y-m-d'),
            ])),
            'Fine Fragances' => $project->fragrances()->with('fineFragrance.house')->get()->map(fn ($pf) => [
                'codigo'           => $pf->fineFragrance?->codigo,
                'nombre_fragancia' => $pf->fineFragrance?->nombre,
                'dosis'            => $pf->gramos,
                'tipo'             => 'Fine',
                'tipo_producto'    => $project->tipo_producto,
                'fecha'            => optional($pf->created_at)->format('Y-m-d'),
            ]),
            default          => collect(),
        };

        return response()->json([
            'success' => true,
            'data'    => [
                'project' => [
                    'id'     => $project->id,
                    'nombre' => $project->nombre,
                    'tipo'   => $project->tipo,
                ],
                'items'   => $items->values(),
            ],
        ]);
    }

    public function cliente(Project $project): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'project' => [
                    'id'              => $project->id,
                    'nombre'          => $project->nombre,
                    'tipo'            => $project->tipo,
                    'cliente_nombre'  => $project->client?->client_name ?? $project->prospect?->nombre ?? $project->nombre_prospecto ?? '—',
                ],
            ],
        ]);
    }

    public function muestra(Project $project): JsonResponse
    {
        $sample = $project->sample;

        return response()->json([
            'success' => true,
            'data'    => [
                'project' => [
                    'id'     => $project->id,
                    'nombre' => $project->nombre,
                    'tipo'   => $project->tipo,
                ],
                'sample' => [
                    'cantidad'          => $sample?->cantidad,
                    'codigo'            => $sample?->codigo,
                    'fecha_vencimiento' => $sample?->fecha_vencimiento,
                    'observaciones'     => $sample?->observaciones,
                ],
            ],
        ]);
    }
}
