<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\GoogleTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleTaskController extends Controller
{
    public function __construct(
        private readonly GoogleTaskService $googleService
    ) {
        $this->middleware('auth:sanctum');
    }

    /**
     * Crea una tarea en Google Tasks a partir de un proyecto.
     */
    public function createFromProject(Request $request, Project $project): JsonResponse
    {
        $userId = $request->user()->id;

        if (!$this->googleService->isConnected($userId)) {
            return response()->json([
                'message' => 'No tienes Google Tasks conectado. Ve a Configuración > Integraciones.',
            ], 422);
        }

        $data = $request->validate([
            'title' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:2000',
            'due'   => 'nullable|date',
        ]);

        $title = $data['title'] ?? $project->nombre;

        $notes = $data['notes'] ?? implode("\n", array_filter([
            "Proyecto: {$project->nombre}",
            "Tipo: {$project->tipo}",
            $project->ejecutivo ? "Ejecutivo: {$project->ejecutivo}" : null,
            $project->client?->client_name
                ? "Cliente: {$project->client->client_name}"
                : ($project->nombre_prospecto ? "Cliente: {$project->nombre_prospecto}" : null),
        ]));

        $dueDate = $data['due'] ?? $project->fecha_calculada;

        try {
            $task = $this->googleService->createTask(
                userId: $userId,
                title: $title,
                notes: $notes,
                dueDate: $dueDate,
            );

            return response()->json([
                'success' => true,
                'data'    => $task,
                'message' => 'Tarea creada en Google Tasks',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
