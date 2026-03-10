<?php

namespace App\Http\Controllers;

use App\Http\Requests\Project\ProjectStoreRequest;
use App\Http\Requests\Project\ProjectUpdateRequest;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\ProjectApplication;
use App\Models\ProjectEvaluation;
use App\Models\ProjectMarketing;
use App\Models\ProjectSample;
use App\Models\ProjectStatusHistory;
use App\Models\ProjectGoogleTaskConfig;
use App\Services\GoogleTaskService;
use App\Services\ProjectTimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectTimeService $timeService,
        private readonly GoogleTaskService $googleTaskService,
    ) {
        $this->middleware('can:project list')->only(['index', 'show', 'export', 'dashboard', 'ejecutivos', 'byClient', 'kpiStats']);
        $this->middleware('can:project create')->only(['store', 'duplicate']);
        $this->middleware('can:project edit')->only(['update', 'linkClient']);
        $this->middleware('can:project delete')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $allowedSortFields = ['id', 'nombre', 'tipo', 'estado_externo', 'estado_interno', 'ejecutivo', 'fecha_creacion', 'fecha_calculada', 'fecha_requerida'];
        $sortField = in_array($request->query('sort_field'), $allowedSortFields) ? $request->query('sort_field') : 'id';
        $sortOrder = strtolower($request->query('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $projects = $this->buildQuery($request)->orderBy($sortField, $sortOrder)->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $projects->items(),
            'meta'    => [
                'total'        => $projects->total(),
                'per_page'     => 20,
                'current_page' => $projects->currentPage(),
            ],
        ]);
    }

    private function buildQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = Project::query()->with(['client:id,client_name', 'prospect:id,nombre', 'product:id,nombre']);

        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }
        if ($estadoExterno = $request->query('estado_externo')) {
            $query->where('estado_externo', $estadoExterno);
        }
        if ($estadoInterno = $request->query('estado_interno')) {
            $query->where('estado_interno', $estadoInterno);
        }
        if ($ejecutivo = $request->query('ejecutivo')) {
            $query->where('ejecutivo', $ejecutivo);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', '%' . $search . '%')
                  ->orWhere('nombre_prospecto', 'like', '%' . $search . '%')
                  ->orWhereHas('client', fn($cq) => $cq->where('client_name', 'like', '%' . $search . '%'))
                  ->orWhereHas('prospect', fn($cq) => $cq->where('nombre', 'like', '%' . $search . '%'));
            });
        }
        $validDepts = ['desarrollo', 'laboratorio', 'mercadeo', 'calidad', 'especiales'];
        if ($departamento = $request->query('departamento')) {
            if (in_array($departamento, $validDepts)) {
                $query->where("estado_{$departamento}", false);
            }
        }

        return $query;
    }

    public function store(ProjectStoreRequest $request): JsonResponse
    {
        $project = DB::transaction(function () use ($request) {
            $data = $request->validated();

            // Si viene ejecutivo_id, resolver el nombre del usuario
            if (!empty($data['ejecutivo_id'])) {
                $user = User::find($data['ejecutivo_id']);
                $data['ejecutivo'] = $user?->name ?? $data['ejecutivo'] ?? null;
            } elseif (empty($data['ejecutivo'])) {
                $data['ejecutivo'] = auth()->user()->name;
                $data['ejecutivo_id'] = auth()->id();
            }

            $project = Project::create(array_merge(
                $data,
                [
                    'fecha_creacion' => $request->fecha_creacion ?? today(),
                    'estado_externo' => 'En espera',
                    'estado_interno' => 'En proceso',
                ]
            ));

            ProjectSample::create(['project_id' => $project->id]);
            ProjectApplication::create(['project_id' => $project->id]);
            ProjectEvaluation::create(['project_id' => $project->id]);
            ProjectMarketing::create(['project_id' => $project->id]);

            $fechaCalculada = $this->timeService->calculate(
                $project->fresh(['client', 'application', 'evaluation', 'marketingYCalidad', 'variants', 'fragrances'])
            );

            if ($fechaCalculada) {
                $project->update(['fecha_calculada' => $fechaCalculada]);
            }

            ProjectStatusHistory::create([
                'project_id'  => $project->id,
                'tipo'        => 'creacion',
                'descripcion' => "Proyecto creado — Tipo: {$project->tipo}",
                'ejecutivo'   => auth()->user()->name,
            ]);

            return $project;
        });

        // Guardar config de Google Tasks si viene en el request (post-transaction, blindado)
        $googleConfig = $request->input('google_task_config');
        if (is_array($googleConfig)) {
            try {
                foreach (['on_create', 'on_status_change', 'near_deadline'] as $trigger) {
                    $userIds = $googleConfig[$trigger]['user_ids'] ?? [];
                    if (!empty($userIds)) {
                        ProjectGoogleTaskConfig::create([
                            'project_id' => $project->id,
                            'trigger'    => $trigger,
                            'user_ids'   => array_values(array_unique(array_map('intval', $userIds))),
                        ]);
                    }
                }
            } catch (\Throwable) {
                // No romper la creación del proyecto si falla la config de Tasks
            }
        }

        // Disparar tareas on_create (silencioso — fallo no afecta respuesta)
        try {
            $config = ProjectGoogleTaskConfig::where('project_id', $project->id)
                ->where('trigger', 'on_create')
                ->first();
            if ($config && !empty($config->user_ids)) {
                $this->googleTaskService->createTaskForUsers(
                    userIds: $config->user_ids,
                    title: "Seguimiento: {$project->nombre}",
                    notes: "Proyecto creado en Finearom.\nTipo: {$project->tipo}",
                    dueDate: $project->fecha_calculada,
                );
            }
        } catch (\Throwable) {
            // Silencioso — Google Tasks no debe bloquear la creación del proyecto
        }

        return response()->json([
            'success' => true,
            'data'    => $project->load(['client', 'product', 'sample', 'application', 'evaluation', 'marketingYCalidad']),
            'message' => 'Proyecto creado',
        ], 201);
    }

    public function show(Project $project): JsonResponse
    {
        $project->load([
            'client',
            'prospect',
            'product',
            'sample',
            'application',
            'evaluation',
            'marketingYCalidad',
            'variants.proposals.finearomReference',
            'requests.fragrance',
            'fragrances.fineFragrance',
        ]);

        return response()->json(['success' => true, 'data' => $project]);
    }

    public function update(ProjectUpdateRequest $request, Project $project): JsonResponse
    {
        $recalculateFields = ['rango_min', 'rango_max', 'volumen', 'tipo', 'homologacion', 'product_id'];

        $project = DB::transaction(function () use ($request, $project, $recalculateFields) {
            $validated = $request->validated();

            // Si viene ejecutivo_id, resolver el nombre del usuario
            if (!empty($validated['ejecutivo_id'])) {
                $user = User::find($validated['ejecutivo_id']);
                $validated['ejecutivo'] = $user?->name ?? $validated['ejecutivo'] ?? $project->ejecutivo;
            }

            $project->update($validated);

            $needsRecalculation = false;
            foreach ($recalculateFields as $field) {
                if (array_key_exists($field, $validated)) {
                    $needsRecalculation = true;
                    break;
                }
            }

            if ($needsRecalculation) {
                $fechaCalculada = $this->timeService->calculate(
                    $project->fresh(['client', 'application', 'evaluation', 'marketingYCalidad', 'variants', 'fragrances'])
                );

                if ($fechaCalculada) {
                    $project->update(['fecha_calculada' => $fechaCalculada]);
                }
            }

            return $project;
        });

        return response()->json([
            'success' => true,
            'data'    => $project->fresh(),
            'message' => 'Proyecto actualizado',
        ]);
    }

    public function destroy(Project $project): JsonResponse
    {
        $project->delete();

        return response()->json(['success' => true, 'message' => 'Proyecto eliminado']);
    }

    public function ejecutivos(): JsonResponse
    {
        // Usar DB::table en lugar de User:: para evitar los $appends (system_permissions/system_roles)
        // que disparan N*2 queries extra contra model_has_permissions y model_has_roles
        $ejecutivos = \DB::table('users')
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $ejecutivos]);
    }

    public function duplicate(Project $project): JsonResponse
    {
        $newProject = DB::transaction(function () use ($project) {
            $attrs = $project->only([
                'nombre', 'client_id', 'product_id', 'tipo',
                'rango_min', 'rango_max', 'volumen', 'trm', 'factor',
                'base_cliente', 'proactivo', 'homologacion', 'internacional',
                'tipo_producto', 'fecha_requerida',
            ]);

            $attrs['nombre']         = $attrs['nombre'] . ' (copia)';
            $attrs['fecha_creacion'] = today();
            $attrs['ejecutivo']      = auth()->user()->name;
            $attrs['estado_externo'] = 'En espera';
            $attrs['estado_interno'] = 'En proceso';

            $newProject = Project::create($attrs);

            ProjectSample::create(['project_id' => $newProject->id]);
            ProjectApplication::create(['project_id' => $newProject->id]);
            ProjectEvaluation::create(['project_id' => $newProject->id]);
            ProjectMarketing::create(['project_id' => $newProject->id]);

            $fechaCalculada = $this->timeService->calculate(
                $newProject->fresh(['client', 'application', 'evaluation', 'marketingYCalidad', 'variants', 'fragrances'])
            );
            if ($fechaCalculada) {
                $newProject->update(['fecha_calculada' => $fechaCalculada]);
            }

            return $newProject;
        });

        return response()->json([
            'success' => true,
            'data'    => $newProject->load(['client', 'product']),
            'message' => 'Proyecto duplicado',
        ], 201);
    }

    public function export(Request $request): StreamedResponse
    {
        $projects = $this->buildQuery($request)->orderBy('id', 'desc')->get();

        return response()->streamDownload(function () use ($projects) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

            fputcsv($out, [
                'ID', 'Nombre', 'Cliente', 'Tipo', 'Producto',
                'Estado Externo', 'Estado Interno', 'Ejecutivo',
                'Rango Min', 'Rango Max', 'Volumen',
                'Fecha Creación', 'Fecha Requerida', 'Fecha Calculada', 'Fecha Entrega',
            ]);

            foreach ($projects as $p) {
                fputcsv($out, [
                    $p->id,
                    $p->nombre,
                    $p->client?->client_name ?? $p->prospect?->nombre ?? $p->nombre_prospecto ?? '',
                    $p->tipo,
                    $p->product?->nombre ?? '',
                    $p->estado_externo,
                    $p->estado_interno,
                    $p->ejecutivo ?? '',
                    $p->rango_min,
                    $p->rango_max,
                    $p->volumen,
                    $p->fecha_creacion,
                    $p->fecha_requerida,
                    $p->fecha_calculada,
                    $p->fecha_entrega,
                ]);
            }

            fclose($out);
        }, 'proyectos_' . now()->format('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function dashboard(): JsonResponse
    {
        $byTipo = Project::query()
            ->selectRaw('tipo, COUNT(*) as total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $byEstadoExterno = Project::query()
            ->selectRaw('estado_externo, COUNT(*) as total')
            ->groupBy('estado_externo')
            ->pluck('total', 'estado_externo');

        $byEstadoInterno = Project::query()
            ->selectRaw('estado_interno, COUNT(*) as total')
            ->groupBy('estado_interno')
            ->pluck('total', 'estado_interno');

        $byEjecutivo = Project::query()
            ->selectRaw('ejecutivo, COUNT(*) as total')
            ->whereNotNull('ejecutivo')
            ->groupBy('ejecutivo')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $byMonth = Project::query()
            ->selectRaw("DATE_FORMAT(fecha_creacion, '%Y-%m') as mes, COUNT(*) as total")
            ->whereNotNull('fecha_creacion')
            ->where('fecha_creacion', '>=', now()->subYear())
            ->groupBy('mes')
            ->orderBy('mes')
            ->get();

        $totals = [
            'total'      => Project::count(),
            'ganados'    => Project::where('estado_externo', 'Ganado')->count(),
            'perdidos'   => Project::where('estado_externo', 'Perdido')->count(),
            'en_espera'  => Project::where('estado_externo', 'En espera')->count(),
            'entregados' => Project::where('estado_interno', 'Entregado')->count(),
            'en_proceso' => Project::where('estado_interno', 'En proceso')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'totals'           => $totals,
                'by_tipo'          => $byTipo,
                'by_estado_externo' => $byEstadoExterno,
                'by_estado_interno' => $byEstadoInterno,
                'by_ejecutivo'     => $byEjecutivo,
                'by_month'         => $byMonth,
            ],
        ]);
    }

    public function linkClient(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
        ]);

        $project->update([
            'client_id'        => $request->client_id,
            'prospect_id'      => null,
            'nombre_prospecto' => null,
            'email_prospecto'  => null,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $project->fresh(['client']),
            'message' => 'Cliente vinculado al proyecto',
        ]);
    }

    public function byClient(Client $client): JsonResponse
    {
        $projects = $client->projects()
            ->orderByDesc('created_at')
            ->get([
                'id',
                'nombre',
                'tipo',
                'estado_externo',
                'estado_interno',
                'fecha_creacion',
                'fecha_calculada',
            ]);

        return response()->json([
            'success' => true,
            'data'    => $projects,
        ]);
    }

    public function kpiStats(): JsonResponse
    {
        $entregados = Project::where('estado_interno', 'Entregado')->whereNotNull('dias_diferencia');

        $total      = $entregados->count();
        $aTiempo    = (clone $entregados)->where('dias_diferencia', '<=', 0)->count();
        $promedio   = $total > 0 ? round((clone $entregados)->avg('dias_diferencia'), 1) : null;

        $porTipo = Project::where('estado_interno', 'Entregado')
            ->whereNotNull('dias_diferencia')
            ->selectRaw('tipo, COUNT(*) as total, SUM(CASE WHEN dias_diferencia <= 0 THEN 1 ELSE 0 END) as a_tiempo, AVG(dias_diferencia) as promedio_dias')
            ->groupBy('tipo')
            ->get()
            ->map(fn ($row) => [
                'tipo'          => $row->tipo,
                'total'         => $row->total,
                'a_tiempo'      => $row->a_tiempo,
                'porcentaje'    => $row->total > 0 ? round(($row->a_tiempo / $row->total) * 100, 1) : 0,
                'promedio_dias' => round($row->promedio_dias, 1),
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'total_entregados'        => $total,
                'entregados_a_tiempo'     => $aTiempo,
                'porcentaje_a_tiempo'     => $total > 0 ? round(($aTiempo / $total) * 100, 1) : 0,
                'promedio_dias_diferencia' => $promedio,
                'por_tipo'                => $porTipo,
            ],
        ]);
    }
}
