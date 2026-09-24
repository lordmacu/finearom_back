<?php

namespace App\Http\Controllers;

use App\Http\Requests\Project\ProjectStoreRequest;
use App\Http\Requests\Project\ProjectUpdateRequest;
use App\Models\Client;
use App\Models\ContributionMargin;
use App\Models\Project;
use App\Models\User;
use App\Models\ProjectApplication;
use App\Models\ProjectEvaluation;
use App\Models\ProjectMarketing;
use App\Models\ProjectSample;
use App\Models\ProjectStatusHistory;
use App\Models\ProjectGoogleTaskConfig;
use App\Models\ProjectProductType;
use App\Services\GoogleTaskService;
use App\Services\ProjectMailService;
use App\Services\ProjectTimeService;
use App\Support\ProjectFactorPermission;
use App\Support\ProjectOwnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectTimeService $timeService,
        private readonly GoogleTaskService $googleTaskService,
        private readonly ProjectMailService $projectMailService,
        private readonly \App\Services\TrmService $trmService,
    ) {
        $this->middleware('can:project list')->only(['index', 'show', 'export', 'dashboard', 'dashboardClientes', 'ejecutivos', 'ejecutivosFiltro', 'desarrolladores', 'byClient', 'kpiStats']);
        $this->middleware('can:project create')->only(['store', 'duplicate']);
        $this->middleware('can:project edit')->only(['update', 'linkClient']);
        $this->middleware('can:project delete')->only(['destroy']);
        $this->middleware('can:project send creation')->only(['sendCreation', 'sendUpdate']);
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

    private function fechaFiltro(mixed $valor): ?string
    {
        if (!is_string($valor)) {
            return null;
        }
        $fecha = \DateTime::createFromFormat('!Y-m-d', $valor);

        return $fecha && $fecha->format('Y-m-d') === $valor ? $valor : null;
    }

    private function buildQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = Project::query()->with(['client:id,client_name', 'prospect:id,nombre', 'product:id,nombre', 'desarrollador:id,name']);

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
        // Ingeniero de desarrollo asignado: id del usuario o "sin_asignar"
        $desarrollador = $request->query('desarrollador_id');
        if ($desarrollador === 'sin_asignar') {
            $query->whereNull('desarrollador_id');
        } elseif (ctype_digit((string) $desarrollador)) {
            $query->where('desarrollador_id', (int) $desarrollador);
        }
        // Rango por fecha de creación (Y-m-d) para acotar el listado; una fecha
        // mal formada se ignora
        if ($desde = $this->fechaFiltro($request->query('desde'))) {
            $query->whereDate('fecha_creacion', '>=', $desde);
        }
        if ($hasta = $this->fechaFiltro($request->query('hasta'))) {
            $query->whereDate('fecha_creacion', '<=', $hasta);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', '%' . $search . '%')
                  ->orWhere('nombre_prospecto', 'like', '%' . $search . '%')
                  ->orWhere('tipo_producto', 'like', '%' . $search . '%')
                  ->orWhere('tipo', 'like', '%' . $search . '%')
                  ->orWhere('estado_externo', 'like', '%' . $search . '%')
                  ->orWhere('estado_interno', 'like', '%' . $search . '%')
                  ->orWhere('ejecutivo', 'like', '%' . $search . '%')
                  ->orWhere('id', (string) (int) $search)
                  ->orWhereHas('client', fn($cq) => $cq->where('client_name', 'like', '%' . $search . '%'))
                  ->orWhereHas('prospect', fn($cq) => $cq->where('nombre', 'like', '%' . $search . '%'));
            });
        }
        $validDepts = ['desarrollo', 'laboratorio', 'mercadeo', 'calidad', 'especiales', 'evaluaciones'];
        if ($departamento = $request->query('departamento')) {
            if (in_array($departamento, $validDepts)) {
                $query->where("estado_{$departamento}", false)
                      ->where('estado_interno', 'En proceso');
            }
        }

        // Filtro "Estado por área": estado_{dept}:1 (entregado) o estado_{dept}:0 (pendiente).
        // Independiente del tab "departamento" de arriba (ese siempre es pendiente + en proceso).
        $validAreaFields = array_map(fn($d) => "estado_{$d}", $validDepts);
        if ($areaEstado = $request->query('area_estado')) {
            [$field, $entregado] = array_pad(explode(':', $areaEstado, 2), 2, null);
            if (in_array($field, $validAreaFields, true) && in_array($entregado, ['0', '1'], true)) {
                $query->where($field, $entregado === '1');
            }
        }

        return $query;
    }

    /**
     * "Tipo de producto" con opción de escribir uno nuevo: si llega
     * nuevo_tipo_producto (y no un product_id existente), busca-o-crea el
     * ProjectProductType bajo la categoría elegida y lo deja como product_id.
     * Sin categoría no hay dónde archivarlo — se ignora en silencio.
     */
    private function resolveProductType(array &$data): void
    {
        $nuevo = trim((string) ($data['nuevo_tipo_producto'] ?? ''));
        unset($data['nuevo_tipo_producto']);

        if ($nuevo === '' || !empty($data['product_id'])) {
            return;
        }

        $categoriaId = $data['product_category_id'] ?? null;
        if (!$categoriaId) {
            return;
        }

        $objetivo = \App\Support\ProductTypeCatalog::normalizar($nuevo);
        $existente = ProjectProductType::where('product_category_id', $categoriaId)
            ->get()
            ->first(fn($t) => \App\Support\ProductTypeCatalog::normalizar($t->nombre) === $objetivo);

        $productType = $existente ?? ProjectProductType::create([
            'nombre'              => $nuevo,
            'product_category_id' => $categoriaId,
            'active'              => true,
        ]);

        $data['product_id']    = $productType->id;
        $data['tipo_producto'] = $productType->nombre;
    }

    public function store(ProjectStoreRequest $request): JsonResponse
    {
        $project = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $envelopeTypeIds = $data['envelope_type_ids'] ?? null;
            unset($data['envelope_type_ids']);

            $this->resolveProductType($data);

            // Secciones visibles en el detalle: default todas si no se envió
            if (!isset($data['secciones_visibles'])) {
                $data['secciones_visibles'] = ['desarrollo', 'evaluaciones', 'regulatoria', 'marketing', 'comercial'];
            }

            // Si viene ejecutivo_id, resolver el nombre del usuario
            if (!empty($data['ejecutivo_id'])) {
                $user = User::find($data['ejecutivo_id']);
                $data['ejecutivo'] = $user?->name ?? $data['ejecutivo'] ?? null;
            } elseif (empty($data['ejecutivo'])) {
                $data['ejecutivo'] = auth()->user()->name;
                $data['ejecutivo_id'] = auth()->id();
            }

            // Quien no ve el factor tampoco lo escribe: queda el automático
            if (!ProjectFactorPermission::canView($request->user())) {
                unset($data['factor']);
            }

            // Auto-poblar factor desde el default_factor del cliente si no se envió explícitamente
            if (!isset($data['factor']) && !empty($data['client_id'])) {
                $clientForFactor = Client::find($data['client_id']);
                if ($clientForFactor && $clientForFactor->default_factor) {
                    $data['factor'] = $clientForFactor->default_factor;
                }
            }

            // Factor vacío ("Auto") sin default del cliente: que aplique el
            // DEFAULT de la columna (NOT NULL), no un null en el insert
            if (($data['factor'] ?? null) === null) {
                unset($data['factor']);
            }

            $project = Project::create(array_merge(
                $data,
                [
                    'fecha_creacion' => $request->fecha_creacion ?? today(),
                    'estado_externo' => 'Sin definir',
                    'estado_interno' => 'En proceso',
                ]
            ));

            if (!empty($envelopeTypeIds)) {
                $project->envelopeTypes()->sync($envelopeTypeIds);
            }

            ProjectSample::create(['project_id' => $project->id]);
            ProjectApplication::create(['project_id' => $project->id]);
            ProjectEvaluation::create(['project_id' => $project->id]);
            ProjectMarketing::create(['project_id' => $project->id]);

            $project->update(['fecha_calculada' => $this->timeService->calculate($project->fresh('marketingYCalidad'))]);

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

        // El correo de creación no sale al crear: lo dispara el botón
        // "Enviar creación de proyecto" (sendCreation), que abre el hilo.

        return response()->json([
            'success' => true,
            'data'    => $project->load(['client', 'product', 'sample', 'application', 'evaluation', 'marketingYCalidad', 'envelopeTypes']),
            'message' => 'Proyecto creado',
        ], 201);
    }

    public function show(Project $project): JsonResponse
    {
        $project->load([
            'client',
            'prospect',
            'product',
            'productCategory',
            'envelopeTypes',
            'catalogItems',
            'sample',
            'application',
            'evaluation',
            'evaluation.benchmarkReference',
            'marketingYCalidad',
            'marketingVariants',
            'variants.proposals.finearomReference',
            'variants.benchmarkReference',
            'requests.fragrance',
            'fragrances.fineFragrance',
            'desarrollador',
        ]);

        return response()->json([
            'success'    => true,
            'data'       => $project,
            'can_manage' => ProjectOwnership::canManage(auth()->user(), $project),
        ]);
    }

    public function update(ProjectUpdateRequest $request, Project $project): JsonResponse
    {
        $project = DB::transaction(function () use ($request, $project) {
            $validated = $request->validated();
            $envelopeTypeIds = array_key_exists('envelope_type_ids', $validated) ? $validated['envelope_type_ids'] : null;
            unset($validated['envelope_type_ids']);

            $this->resolveProductType($validated);

            // Factor vacío: se conserva el actual (la columna es NOT NULL)
            if (array_key_exists('factor', $validated) && $validated['factor'] === null) {
                unset($validated['factor']);
            }

            // Quien no ve el factor tampoco lo escribe; si cambia el potencial en
            // Kg se recalcula solo, como hace el formulario de quien sí lo ve
            if (!ProjectFactorPermission::canView($request->user())) {
                unset($validated['factor']);
                $factorAuto = $this->factorAutomatico($project, $validated);
                if ($factorAuto !== null) {
                    $validated['factor'] = $factorAuto;
                }
            }

            // Si viene ejecutivo_id, resolver el nombre del usuario
            if (!empty($validated['ejecutivo_id'])) {
                $user = User::find($validated['ejecutivo_id']);
                $validated['ejecutivo'] = $user?->name ?? $validated['ejecutivo'] ?? $project->ejecutivo;
            }

            $project->update($validated);

            if ($envelopeTypeIds !== null) {
                $project->envelopeTypes()->sync($envelopeTypeIds);
            }

            $project->update(['fecha_calculada' => $this->timeService->calculate($project->fresh('marketingYCalidad'))]);

            return $project;
        });

        return response()->json([
            'success' => true,
            'data'    => $project->fresh()->load('envelopeTypes'),
            'message' => 'Proyecto actualizado',
        ]);
    }

    /**
     * Factor por márgenes de contribución (tipo de cliente + Kg/año) o, si
     * ningún rango aplica, el default del cliente. Null si el Kg no cambió.
     */
    private function factorAutomatico(Project $project, array $validated): ?float
    {
        if (!array_key_exists('potencial_anual_kg', $validated) || !$validated['potencial_anual_kg']) {
            return null;
        }
        if ((float) $validated['potencial_anual_kg'] === (float) $project->potencial_anual_kg) {
            return null;
        }

        $client = Client::find($validated['client_id'] ?? $project->client_id);
        if (!$client) {
            return null;
        }

        $factor = $client->client_type
            ? ContributionMargin::getFactorFor($client->client_type, (int) $validated['potencial_anual_kg'])
            : null;

        return $factor ?? ($client->default_factor ? (float) $client->default_factor : null);
    }

    public function destroy(Project $project): JsonResponse
    {
        $project->delete();

        return response()->json(['success' => true, 'message' => 'Proyecto eliminado']);
    }

    public function ejecutivos(): JsonResponse
    {
        // Solo los ejecutivos comerciales activos, cruzados con users por email para obtener user_id
        $ejecutivos = \DB::table('executives as e')
            ->join('users as u', \DB::raw('LOWER(e.email) COLLATE utf8mb4_general_ci'), '=', \DB::raw('LOWER(u.email) COLLATE utf8mb4_general_ci'))
            ->where('e.is_active', true)
            ->select('u.id', 'e.name', 'e.email')
            ->orderBy('e.name')
            ->get();

        return response()->json(['success' => true, 'data' => $ejecutivos]);
    }

    /**
     * Ejecutivos para FILTRAR el listado (no para asignar): a diferencia de
     * ejecutivos(), esto sale directo de projects.ejecutivo, incluyendo
     * nombres de proyectos importados de la otra plataforma que no tienen
     * usuario ni registro en `executives` — si no, el filtro no los alcanza.
     */
    public function ejecutivosFiltro(): JsonResponse
    {
        $ejecutivos = Project::query()
            ->whereNotNull('ejecutivo')
            ->where('ejecutivo', '!=', '')
            ->distinct()
            ->orderBy('ejecutivo')
            ->pluck('ejecutivo');

        return response()->json(['success' => true, 'data' => $ejecutivos]);
    }

    /** Usuarios con rol Desarrollo (para asignar el ingeniero del proyecto). */
    public function desarrolladores(): JsonResponse
    {
        $desarrolladores = User::role('Desarrollo')
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $desarrolladores]);
    }

    public function duplicate(Project $project): JsonResponse
    {
        $newProject = DB::transaction(function () use ($project) {
            $attrs = $project->only([
                'nombre', 'client_id', 'product_id', 'product_category_id',
                'tipo', 'rango_min', 'rango_max', 'volumen', 'precio', 'dosis',
                'trm', 'factor', 'costo_perfumacion_especifico', 'costo_perfumacion_tonelada',
                'tipo_etiquetado', 'max_variantes',
                'base_cliente', 'proactivo', 'homologacion', 'tipo_homologacion',
                'tipo_desarrollo', 'area_aplicacion', 'seleccion_envase_aplicacion', 'requiere_piramides', 'internacional',
                'tipo_producto', 'fecha_requerida',
            ]);

            $attrs['nombre']         = $attrs['nombre'] . ' (copia)';
            $attrs['fecha_creacion'] = today();
            $attrs['ejecutivo']      = auth()->user()->name;
            $attrs['estado_externo'] = 'Sin definir';
            $attrs['estado_interno'] = 'En proceso';

            $newProject = Project::create($attrs);

            $envelopeTypeIds = $project->envelopeTypes()->pluck('envelope_types.id');
            if ($envelopeTypeIds->isNotEmpty()) {
                $newProject->envelopeTypes()->sync($envelopeTypeIds);
            }

            ProjectSample::create(['project_id' => $newProject->id]);
            ProjectApplication::create(['project_id' => $newProject->id]);
            ProjectEvaluation::create(['project_id' => $newProject->id]);
            ProjectMarketing::create(['project_id' => $newProject->id]);

            $newProject->update(['fecha_calculada' => $this->timeService->calculate($newProject->fresh('marketingYCalidad'))]);

            return $newProject;
        });

        // El duplicado tampoco envía correo solo: el hilo lo abre el botón
        // "Enviar creación de proyecto" en el detalle de la copia.

        return response()->json([
            'success' => true,
            'data'    => $newProject->load(['client', 'product', 'envelopeTypes']),
            'message' => 'Proyecto duplicado',
        ], 201);
    }

    /**
     * Botón "Enviar creación de proyecto": envía el correo project_created
     * (resumen por áreas). El primero abre el hilo; los siguientes van como Re:.
     */
    public function sendCreation(Project $project): JsonResponse
    {
        if (!ProjectOwnership::canManage(auth()->user(), $project)) {
            return response()->json([
                'success' => false,
                'message' => 'Solo la ejecutiva del proyecto puede enviar sus correos.',
            ], 403);
        }

        $abreHilo = !$project->email_thread_message_id;
        $sent = $this->projectMailService->send($project, 'created');

        if (!$sent) {
            return response()->json([
                'success' => false,
                'message' => 'No se envió: no hay destinatarios configurados para "Proyecto: creación" o el correo falló (ver log).',
            ], 422);
        }

        // Ingeniero asignado antes de abrir el hilo: su aviso sale ahora, como
        // respuesta al correo de creación (solo la primera vez, no en reenvíos)
        if ($abreHilo && $project->desarrollador_id && ($engineer = User::find($project->desarrollador_id))) {
            $this->projectMailService->sendEngineerAssigned($project, $engineer);
        }

        return response()->json([
            'success' => true,
            'message' => 'Correo de creación enviado',
        ], 200);
    }

    /**
     * Botón "Enviar modificación": envía el correo project_updated con el diff
     * (qué cambió desde el último correo) dentro del mismo hilo del proyecto.
     */
    public function sendUpdate(Project $project): JsonResponse
    {
        if (!ProjectOwnership::canManage(auth()->user(), $project)) {
            return response()->json([
                'success' => false,
                'message' => 'Solo la ejecutiva del proyecto puede enviar sus correos.',
            ], 403);
        }

        $result = $this->projectMailService->sendUpdate($project);

        if (!$result['sent']) {
            $message = match ($result['reason']) {
                'sin_hilo'    => 'Primero envía el correo de creación del proyecto.',
                'sin_cambios' => 'No hay cambios desde el último correo enviado.',
                default       => 'No se envió: no hay destinatarios configurados o el correo falló (ver log).',
            };

            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Correo de modificación enviado',
        ], 200);
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

    public function dashboard(Request $request): JsonResponse
    {
        $ejecutivo  = $request->query('ejecutivo');
        $clientId   = $request->query('client_id');
        $categoryId = $request->query('category_id');
        $year       = (int) ($request->query('year', now()->year));

        // Base scope with optional filters applied to all queries
        $base = function () use ($ejecutivo, $clientId, $categoryId) {
            $q = Project::query();
            if ($ejecutivo)  $q->where('ejecutivo', $ejecutivo);
            if ($clientId)   $q->where('client_id', $clientId);
            if ($categoryId) $q->where('product_category_id', $categoryId);
            return $q;
        };

        $byTipo = $base()
            ->selectRaw('tipo, COUNT(*) as total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $byEstadoExterno = $base()
            ->selectRaw('estado_externo, COUNT(*) as total')
            ->groupBy('estado_externo')
            ->pluck('total', 'estado_externo');

        $byEstadoInterno = $base()
            ->selectRaw('estado_interno, COUNT(*) as total')
            ->groupBy('estado_interno')
            ->pluck('total', 'estado_interno');

        $byEjecutivo = $base()
            ->selectRaw('ejecutivo, COUNT(*) as total')
            ->whereNotNull('ejecutivo')
            ->groupBy('ejecutivo')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $byMonth = $base()
            ->selectRaw("DATE_FORMAT(fecha_creacion, '%Y-%m') as mes, COUNT(*) as total")
            ->whereNotNull('fecha_creacion')
            ->where('fecha_creacion', '>=', now()->subYear())
            ->groupBy('mes')
            ->orderBy('mes')
            ->get();

        $totals = [
            'total'      => $base()->count(),
            'ganados'    => $base()->where('estado_externo', 'Ganado')->count(),
            'perdidos'   => $base()->where('estado_externo', 'Perdido')->count(),
            'sin_definir' => $base()->where('estado_externo', 'Sin definir')->count(),
            'entregados' => $base()->where('estado_interno', 'Entregado')->count(),
            'en_proceso' => $base()->where('estado_interno', 'En proceso')->count(),
        ];

        // fecha_externo es la fecha real en que se marcó Ganado/Perdido (setExternalStatus);
        // updated_at se corre con cualquier edición posterior y no sirve para esto.
        $winsAnio = $base()
            ->where('estado_externo', 'Ganado')
            ->whereYear('fecha_externo', $year)
            ->count();

        $winsPorMes = $base()
            ->where('estado_externo', 'Ganado')
            ->whereYear('fecha_externo', $year)
            ->selectRaw("DATE_FORMAT(fecha_externo, '%Y-%m') as mes, COUNT(*) as total, SUM(potencial_anual_usd) as total_usd")
            ->groupBy('mes')
            ->orderBy('mes')
            ->get();

        $potencialPorProbabilidad = $base()
            ->where('estado_externo', 'Sin definir')
            ->whereNotNull('probabilidad_cierre')
            ->selectRaw('probabilidad_cierre, SUM(potencial_anual_usd) as total_usd, SUM(potencial_anual_kg) as total_kg, COUNT(*) as cantidad')
            ->groupBy('probabilidad_cierre')
            ->get()
            ->keyBy('probabilidad_cierre');

        $pronosticoAnual = $base()
            ->where('estado_externo', 'Sin definir')
            ->whereNotNull('probabilidad_cierre')
            ->whereNotNull('potencial_anual_usd')
            ->get(['probabilidad_cierre', 'potencial_anual_usd'])
            ->sum(function ($p) {
                $peso = match($p->probabilidad_cierre) {
                    'alto'  => 0.8,
                    'medio' => 0.5,
                    'bajo'  => 0.2,
                    default => 0,
                };
                return $p->potencial_anual_usd * $peso;
            });

        // Pronóstico del año en curso: basado en fecha_cierre_estimada + frecuencia_compra_estimada
        // Calcula cuántos despachos caben en el año desde la fecha estimada de cierre
        $pronosticoAnioCurso = $base()
            ->where('estado_externo', 'Sin definir')
            ->whereNotNull('fecha_cierre_estimada')
            ->whereNotNull('frecuencia_compra_estimada')
            ->whereNotNull('potencial_anual_usd')
            ->where('frecuencia_compra_estimada', '>', 0)
            ->whereYear('fecha_cierre_estimada', $year)
            ->get(['fecha_cierre_estimada', 'frecuencia_compra_estimada', 'potencial_anual_usd'])
            ->sum(function ($p) {
                $mescierre        = (int) \Carbon\Carbon::parse($p->fecha_cierre_estimada)->format('m');
                $mesesRestantes   = max(0, 12 - $mescierre);
                $despachosPosibles = (int) floor($mesesRestantes * $p->frecuencia_compra_estimada / 12);
                $ingresoPorDespacho = $p->potencial_anual_usd / $p->frecuencia_compra_estimada;
                return $despachosPosibles * $ingresoPorDespacho;
            });

        // Potencial total en KG (proyectos sin definir: abiertos)
        $potencialKgTotal = $base()
            ->where('estado_externo', 'Sin definir')
            ->whereNotNull('potencial_anual_kg')
            ->sum('potencial_anual_kg');

        // Proyectos activos por departamento (estado_interno = 'En proceso', booleano aún en false = pendiente)
        $enProceso = $base()->where('estado_interno', 'En proceso');
        $byProceso = [
            'Desarrollo'  => (clone $enProceso)->where('estado_desarrollo', false)->count(),
            'Laboratorio' => (clone $enProceso)->where('estado_laboratorio', false)->count(),
            'Mercadeo'    => (clone $enProceso)->where('estado_mercadeo', false)->count(),
            'Calidad'     => (clone $enProceso)->where('estado_calidad', false)->count(),
            'Especiales'  => (clone $enProceso)->where('estado_especiales', false)->count(),
        ];

        // Top clientes por volumen de proyectos
        $topClientes = $base()
            ->whereNotNull('client_id')
            ->selectRaw('client_id, COUNT(*) as total')
            ->groupBy('client_id')
            ->orderByDesc('total')
            ->limit(10)
            ->with('client:id,client_name')
            ->get()
            ->map(fn ($r) => [
                'client_id'   => $r->client_id,
                'client_name' => $r->client?->client_name ?? '—',
                'total'       => $r->total,
            ]);

        // Promedio de precio por categoría
        $byCategoria = $base()
            ->whereNotNull('product_category_id')
            ->selectRaw('product_category_id, COUNT(*) as cantidad, AVG(costo_perfumacion_especifico) as precio_promedio, SUM(potencial_anual_usd) as potencial_usd')
            ->groupBy('product_category_id')
            ->orderByDesc('cantidad')
            ->get()
            ->map(function ($r) {
                $r->load('productCategory:id,name');
                return [
                    'categoria'      => $r->productCategory?->name ?? 'Sin categoría',
                    'cantidad'       => (int) $r->cantidad,
                    'precio_promedio' => $r->precio_promedio !== null ? round((float) $r->precio_promedio, 2) : null,
                    'potencial_usd'  => round((float) $r->potencial_usd, 2),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'totals'                   => $totals,
                'by_tipo'                  => $byTipo,
                'by_estado_externo'        => $byEstadoExterno,
                'by_estado_interno'        => $byEstadoInterno,
                'by_ejecutivo'             => $byEjecutivo,
                'by_month'                 => $byMonth,
                'wins_anio'                => $winsAnio,
                'wins_por_mes'             => $winsPorMes,
                'potencial_probabilidad'   => $potencialPorProbabilidad,
                'pronostico_anual_usd'     => round($pronosticoAnual, 2),
                'pronostico_anio_curso_usd' => round($pronosticoAnioCurso, 2),
                'potencial_kg_total'       => round((float) $potencialKgTotal, 2),
                'by_proceso'               => $byProceso,
                'top_clientes'             => $topClientes,
                'by_categoria'             => $byCategoria,
                'filters' => [
                    'year'        => $year,
                    'ejecutivo'   => $ejecutivo,
                    'client_id'   => $clientId ? (int) $clientId : null,
                    'category_id' => $categoryId ? (int) $categoryId : null,
                ],
            ],
        ]);
    }

    /**
     * Pipeline de alta probabilidad, desglosado por cliente: solo proyectos
     * `probabilidad_cierre = alto` aún sin definir (abiertos), con su fecha de cierre
     * estimada y el potencial en USD — más su equivalente en COP a la TRM
     * elegida (por defecto, la de hoy).
     */
    public function dashboardClientes(Request $request): JsonResponse
    {
        $ejecutivo = $request->query('ejecutivo');
        $trmParam  = $request->query('trm');
        $trm       = is_numeric($trmParam) ? (float) $trmParam : $this->trmService->getTrm();

        $projects = Project::query()
            ->where('probabilidad_cierre', 'alto')
            ->where('estado_externo', 'Sin definir')
            ->when($ejecutivo, fn ($q) => $q->where('ejecutivo', $ejecutivo))
            ->with('client:id,client_name')
            ->orderBy('fecha_cierre_estimada')
            ->get([
                'id', 'nombre', 'client_id', 'nombre_prospecto', 'ejecutivo',
                'fecha_cierre_estimada', 'potencial_anual_usd', 'potencial_anual_kg',
            ]);

        $clientes = $projects
            ->groupBy(fn ($p) => $p->client_id ? "c{$p->client_id}" : 'p:' . $p->nombre_prospecto)
            ->map(function ($items) use ($trm) {
                $first    = $items->first();
                $totalUsd = (float) $items->sum('potencial_anual_usd');

                return [
                    'cliente'   => $first->client?->client_name ?? $first->nombre_prospecto ?? 'Sin nombre',
                    'client_id' => $first->client_id,
                    'proyectos' => $items->map(fn ($p) => [
                        'id'                    => $p->id,
                        'nombre'                => $p->nombre,
                        'ejecutivo'             => $p->ejecutivo,
                        'fecha_cierre_estimada' => $p->fecha_cierre_estimada?->format('Y-m-d'),
                        'potencial_usd'         => round((float) $p->potencial_anual_usd, 2),
                        'potencial_kg'          => $p->potencial_anual_kg !== null ? round((float) $p->potencial_anual_kg, 2) : null,
                    ])->values(),
                    'fecha_cierre_mas_proxima' => $items->pluck('fecha_cierre_estimada')->filter()->sort()->first()?->format('Y-m-d'),
                    'total_potencial_usd'      => round($totalUsd, 2),
                    'total_potencial_cop'      => round($totalUsd * $trm, 2),
                ];
            })
            ->sortByDesc('total_potencial_usd')
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'trm_usada'           => $trm,
                'total_potencial_usd' => round((float) $clientes->sum('total_potencial_usd'), 2),
                'total_potencial_cop' => round((float) $clientes->sum('total_potencial_cop'), 2),
                'clientes'            => $clientes,
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

        $porTecnico = Project::where('estado_interno', 'Entregado')
            ->whereNotNull('ejecutivo_laboratorio')
            ->whereNotNull('dias_diferencia')
            ->selectRaw('ejecutivo_laboratorio as tecnico, COUNT(*) as total, SUM(CASE WHEN dias_diferencia <= 0 THEN 1 ELSE 0 END) as a_tiempo, AVG(dias_diferencia) as promedio_dias')
            ->groupBy('ejecutivo_laboratorio')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'tecnico'       => $r->tecnico,
                'total'         => $r->total,
                'a_tiempo'      => $r->a_tiempo,
                'porcentaje'    => $r->total > 0 ? round(($r->a_tiempo / $r->total) * 100, 1) : 0,
                'promedio_dias' => round($r->promedio_dias, 1),
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'total_entregados'         => $total,
                'entregados_a_tiempo'      => $aTiempo,
                'porcentaje_a_tiempo'      => $total > 0 ? round(($aTiempo / $total) * 100, 1) : 0,
                'promedio_dias_diferencia' => $promedio,
                'por_tipo'                 => $porTipo,
                'por_tecnico'              => $porTecnico,
            ],
        ]);
    }
}
