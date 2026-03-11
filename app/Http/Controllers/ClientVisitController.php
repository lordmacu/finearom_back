<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientVisit\ClientVisitStoreRequest;
use App\Http\Requests\ClientVisit\ClientVisitUpdateRequest;
use App\Models\ClientVisit;
use App\Models\ClientVisitCommitment;
use App\Services\GoogleCalendarService;
use App\Services\GoogleGmailService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientVisitController extends Controller
{
    public function __construct(
        private readonly GoogleCalendarService $calendarService,
        private readonly GoogleGmailService    $gmailService,
    ) {
        $this->middleware('can:client visit list')->only(['index', 'show']);
        $this->middleware('can:client visit create')->only(['store']);
        $this->middleware('can:client visit edit')->only(['update', 'addCommitment', 'updateCommitment']);
        $this->middleware('can:client visit delete')->only(['destroy', 'destroyCommitment']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ClientVisit::query()
            ->with([
                'client:id,client_name',
                'user:id,name',
                'commitments',
            ]);

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->input('estado'));
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->input('tipo'));
        }

        if ($request->filled('fecha_desde')) {
            $query->where('fecha_inicio', '>=', $request->input('fecha_desde'));
        }

        if ($request->filled('fecha_hasta')) {
            $query->where('fecha_inicio', '<=', $request->input('fecha_hasta'));
        }

        $visits = $query->orderBy('fecha_inicio', 'desc')->paginate(20);

        return response()->json($visits);
    }

    public function store(ClientVisitStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Si no viene user_id lo asigna al usuario autenticado
        $data['user_id'] = $data['user_id'] ?? auth()->id();

        $commitments = $data['commitments'] ?? [];
        unset($data['commitments']);

        $visit = ClientVisit::create($data);

        if (!empty($commitments)) {
            foreach ($commitments as $commitment) {
                $visit->commitments()->create($commitment);
            }
        }

        $visit->load(['client:id,client_name', 'user:id,name', 'commitments']);

        // Crear evento en Google Calendar si el usuario tiene Calendar conectado
        if ($data['create_calendar_event'] ?? false) {
            try {
                $userId = $visit->user_id;
                if ($this->calendarService->isConnected($userId)) {
                    $event = $this->calendarService->createEvent(
                        userId: $userId,
                        title: $visit->titulo,
                        start: Carbon::parse($visit->fecha_inicio),
                        end: $visit->fecha_fin ? Carbon::parse($visit->fecha_fin) : null,
                        description: $visit->notas,
                        location: $visit->lugar,
                    );
                    $visit->update([
                        'google_event_id'   => $event['id'],
                        'google_event_link' => $event['htmlLink'],
                    ]);
                    $visit->refresh();
                }
            } catch (\Throwable $e) {
                // El evento no se pudo crear en Calendar pero la visita ya quedó guardada
                \Illuminate\Support\Facades\Log::warning('[ClientVisit] Error creando evento en Calendar: ' . $e->getMessage());
            }
        }

        return response()->json([
            'data'    => $visit,
            'message' => 'Visita creada correctamente.',
        ], 201);
    }

    public function show(ClientVisit $clientVisit): JsonResponse
    {
        $clientVisit->load(['client:id,client_name', 'user:id,name', 'commitments']);

        return response()->json(['data' => $clientVisit]);
    }

    public function update(ClientVisitUpdateRequest $request, ClientVisit $clientVisit): JsonResponse
    {
        $clientVisit->update($request->validated());

        $clientVisit->load(['client:id,client_name', 'user:id,name', 'commitments']);

        return response()->json([
            'data'    => $clientVisit,
            'message' => 'Visita actualizada correctamente.',
        ]);
    }

    public function destroy(ClientVisit $clientVisit): JsonResponse
    {
        $clientVisit->delete();

        return response()->json(['message' => 'Visita eliminada correctamente.']);
    }

    public function addCommitment(Request $request, ClientVisit $clientVisit): JsonResponse
    {
        $data = $request->validate([
            'descripcion'    => 'required|string',
            'responsable'    => 'nullable|string|max:200',
            'fecha_estimada' => 'nullable|date_format:Y-m-d',
            'completado'     => 'nullable|boolean',
        ]);

        $commitment = $clientVisit->commitments()->create($data);

        return response()->json([
            'data'    => $commitment,
            'message' => 'Compromiso agregado correctamente.',
        ], 201);
    }

    public function updateCommitment(
        Request $request,
        ClientVisit $clientVisit,
        ClientVisitCommitment $commitment
    ): JsonResponse {
        $data = $request->validate([
            'descripcion'    => 'sometimes|string',
            'responsable'    => 'sometimes|nullable|string|max:200',
            'fecha_estimada' => 'sometimes|nullable|date_format:Y-m-d',
            'completado'     => 'sometimes|boolean',
        ]);

        $commitment->update($data);

        return response()->json([
            'data'    => $commitment->fresh(),
            'message' => 'Compromiso actualizado correctamente.',
        ]);
    }

    public function destroyCommitment(
        ClientVisit $clientVisit,
        ClientVisitCommitment $commitment
    ): JsonResponse {
        $commitment->delete();

        return response()->json(['message' => 'Compromiso eliminado correctamente.']);
    }

    public function createGmailDraft(Request $request, ClientVisit $clientVisit): JsonResponse
    {
        $userId = auth()->id();

        if (!$this->gmailService->isConnected($userId)) {
            return response()->json(['message' => 'Gmail no está conectado. Autoriza el acceso en Integraciones Google.'], 422);
        }

        $clientVisit->load(['client:id,client_name', 'user:id,name', 'commitments']);

        $subject = $request->input('subject', 'Resumen de reunión — ' . $clientVisit->titulo);
        $body    = $request->input('body');
        $to      = $request->input('to');

        try {
            $draft = $this->gmailService->createDraft($userId, $subject, $body, $to ?: null);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'data'    => $draft,
            'message' => 'Borrador creado en Gmail.',
        ]);
    }
}
